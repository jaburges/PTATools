"""
End-to-end check of AI Connectors against a running WordPress + WooCommerce
site with PTA Tools active. Not run by tests/run-all.sh: it needs a live site.

Setup used for the 3.147.196 PR (PHP 8.3, MariaDB, WP-CLI):

    wp core download && wp config create --dbname=wp --dbuser=wp --dbpass=wp --dbhost=127.0.0.1 \
        --extra-php <<< "define('PTA_AI_CONNECTORS_ALLOW_HTTP', true); define('DISABLE_WP_CRON', true);"
    wp core install --url=http://localhost:8089 --title="Test PTA" --admin_user=admin --admin_password=admin \
        --admin_email=admin@example.org --skip-email
    wp rewrite structure '/%postname%/'
    wp plugin install woocommerce --activate
    ln -s "<repo>/Azure Plugin" wp-content/plugins/azure-plugin && wp plugin activate azure-plugin
    wp eval 'Azure_Settings::enable_module("ai_connectors", true); Azure_Settings::enable_module("calendar", true);'
    wp user create shop shop@example.org --role=shop_manager --user_pass=shop
    wp user create entra entra@example.org --role=azuread --user_pass=entra
    wp role create newsletter_editor "Newsletter Editor" && wp cap add newsletter_editor read edit_posts
    wp user create writer writer@example.org --role=newsletter_editor --user_pass=writer
    wp user create parent parent@example.org --role=subscriber --user_pass=parent
    # one product, one processing order, a saved "All orders" report, a published page "About"
    PHP_CLI_SERVER_WORKERS=6 php -S localhost:8089 router.php   # router sends unknown paths to index.php

Run:  WP_PATH=/path/to/site python3 tests/e2e/ai-connectors-e2e.py
Needs: pip install requests
"""
import base64, hashlib, json, os, re, secrets, subprocess, sys, urllib.parse
import requests

BASE = os.environ.get("PTA_E2E_BASE", "http://localhost:8089")
WP_PATH = os.environ.get("WP_PATH", "/tmp/wpsite")
MCP = BASE + "/wp-json/pta-tools/v1/mcp"
results = []

def check(ok, label, detail=""):
    results.append(ok)
    print(("PASS " if ok else "FAIL ") + label + (("  -- " + str(detail)[:400]) if (detail and not ok) else ""))

def wp_eval(code):
    out = subprocess.run(["wp", "eval", code], cwd=WP_PATH, capture_output=True, text=True)
    return out.stdout.strip().splitlines()[-1] if out.stdout.strip() else out.stderr

def rpc(token, method, params=None, id=1, headers=None):
    h = {"Content-Type": "application/json", "Accept": "application/json, text/event-stream"}
    if token:
        h["Authorization"] = "Bearer " + token
    if headers:
        h.update(headers)
    body = {"jsonrpc": "2.0", "method": method, "params": params or {}}
    if id is not None:
        body["id"] = id
    return requests.post(MCP, headers=h, data=json.dumps(body))

def call(token, name, args=None):
    r = rpc(token, "tools/call", {"name": name, "arguments": args or {}})
    return r.json()["result"]

# ── Discovery ─────────────────────────────────────────────
r = requests.post(MCP, json={"jsonrpc": "2.0", "id": 1, "method": "initialize", "params": {}})
check(r.status_code == 401, "MCP without a token is 401", r.status_code)
www = r.headers.get("WWW-Authenticate", "")
check('resource_metadata="' in www, "401 carries resource_metadata", www)
prm_url = re.search(r'resource_metadata="([^"]+)"', www).group(1)
prm = requests.get(prm_url).json()
check(prm["resource"] == MCP, "protected resource metadata names the MCP URL", prm)
check(requests.get(BASE + "/.well-known/oauth-protected-resource/wp-json/pta-tools/v1/mcp").json().get("resource") == MCP, "root .well-known protected resource (path form) works")
issuer = prm["authorization_servers"][0]
path = urllib.parse.urlparse(issuer).path
asm = requests.get(BASE + "/.well-known/oauth-authorization-server" + path)
check(asm.status_code == 200 and asm.json()["issuer"] == issuer, "RFC 8414 path-inserted metadata works", asm.text[:200])
oidc = requests.get(issuer + "/.well-known/openid-configuration")
check(oidc.status_code == 200 and oidc.json()["issuer"] == issuer, "issuer-appended openid-configuration works", oidc.status_code)
check(asm.headers.get("Access-Control-Allow-Origin") == "*", "metadata is CORS-open")
meta = asm.json()

# ── Dynamic client registration ───────────────────────────
redirect = "https://client.example/callback"
reg = requests.post(meta["registration_endpoint"], json={"client_name": "E2E Claude", "redirect_uris": [redirect], "token_endpoint_auth_method": "none", "grant_types": ["authorization_code", "refresh_token"], "response_types": ["code"]})
check(reg.status_code == 201, "client registration returns 201", reg.text)
client_id = reg.json()["client_id"]
bad = requests.post(meta["registration_endpoint"], json={"redirect_uris": ["http://evil.example/cb"]})
check(bad.status_code == 400 and bad.json()["error"] == "invalid_redirect_uri", "registration rejects a remote http redirect", bad.text)

# ── Authorize with login and consent ──────────────────────
verifier = secrets.token_urlsafe(48)
challenge = base64.urlsafe_b64encode(hashlib.sha256(verifier.encode()).digest()).rstrip(b"=").decode()
state = "st-" + secrets.token_hex(4)
q = {"response_type": "code", "client_id": client_id, "redirect_uri": redirect, "code_challenge": challenge, "code_challenge_method": "S256", "state": state, "scope": "pta:read pta:write", "resource": MCP}
s = requests.Session()
r = s.get(meta["authorization_endpoint"], params=q, allow_redirects=False)
check(r.status_code == 302 and "admin-post.php" in r.headers["Location"], "REST authorize hands off to admin-post", r.status_code)
r2 = s.get(r.headers["Location"], allow_redirects=False)
check(r2.status_code == 302 and "wp-login.php" in r2.headers["Location"], "a signed-out user is sent to the WordPress login", r2.headers.get("Location"))
login_redirect = urllib.parse.parse_qs(urllib.parse.urlparse(r2.headers["Location"]).query)["redirect_to"][0]
s.get(BASE + "/wp-login.php")
lr = s.post(BASE + "/wp-login.php", data={"log": "admin", "pwd": "admin", "wp-submit": "Log In", "redirect_to": login_redirect, "testcookie": "1"}, allow_redirects=False)
check(lr.status_code == 302 and "admin-post.php" in lr.headers.get("Location", ""), "login returns to the authorize request", lr.headers.get("Location"))
consent = s.get(lr.headers["Location"], allow_redirects=False)
html = consent.text
check(consent.status_code == 200 and "Allow E2E Claude to use your account?" in html, "consent screen names the app", html[:300])
check("Move a page or post to the trash (asks you to confirm first)" in html, "consent lists destructive tools with the confirm note")
check(consent.headers.get("X-Frame-Options") == "DENY", "consent screen cannot be framed")
form = dict(re.findall(r'<input type="hidden" name="([^"]+)" value="([^"]*)"', html))
form = {k: requests.utils.unquote(v).replace("&amp;", "&") for k, v in form.items()}
nonce = re.search(r'name="_pta_ai_nonce" value="([^"]+)"', html).group(1)
form["_pta_ai_nonce"] = nonce
form["decision"] = "allow"
form["allow_write"] = "1"
dec = s.post(BASE + "/wp-admin/admin-post.php", data=form, allow_redirects=False)
loc = dec.headers.get("Location", "")
check(dec.status_code == 302 and loc.startswith(redirect), "approval redirects to the client", loc)
qs = urllib.parse.parse_qs(urllib.parse.urlparse(loc).query)
check(qs.get("state") == [state] and qs.get("iss") == [issuer], "redirect carries state and iss", qs)
code = qs["code"][0]

bad_nonce = dict(form); bad_nonce["_pta_ai_nonce"] = "nope"
bn = s.post(BASE + "/wp-admin/admin-post.php", data=bad_nonce, allow_redirects=False)
check(bn.status_code == 400 and "expired" in bn.text, "a forged consent POST is refused", bn.status_code)

tok = requests.post(meta["token_endpoint"], data={"grant_type": "authorization_code", "code": code, "redirect_uri": redirect, "client_id": client_id, "code_verifier": "x" * 43, "resource": MCP})
check(tok.status_code == 400 and tok.json()["error"] == "invalid_grant", "wrong PKCE verifier is refused", tok.text)
# that attempt burned the code (single use); get a fresh one
r = s.get(meta["authorization_endpoint"], params=q, allow_redirects=False)
consent = s.get(r.headers["Location"], allow_redirects=False)
html = consent.text
form["_pta_ai_nonce"] = re.search(r'name="_pta_ai_nonce" value="([^"]+)"', html).group(1)
dec = s.post(BASE + "/wp-admin/admin-post.php", data=form, allow_redirects=False)
code = urllib.parse.parse_qs(urllib.parse.urlparse(dec.headers["Location"]).query)["code"][0]
tok = requests.post(meta["token_endpoint"], data={"grant_type": "authorization_code", "code": code, "redirect_uri": redirect, "client_id": client_id, "code_verifier": verifier, "resource": MCP})
check(tok.status_code == 200 and tok.headers.get("Cache-Control") == "no-store", "code exchange succeeds with no-store", tok.text)
pair = tok.json()
access = pair["access_token"]
check(pair["scope"] == "pta:read pta:write", "granted read and write", pair)

# ── MCP as administrator ──────────────────────────────────
init = rpc(access, "initialize", {"protocolVersion": "2025-11-25", "capabilities": {}, "clientInfo": {"name": "e2e", "version": "1"}})
check(init.status_code == 200 and init.json()["result"]["protocolVersion"] == "2025-11-25", "initialize over HTTP", init.text[:300])
n = rpc(access, "notifications/initialized", id=None)
check(n.status_code == 202 and n.text == "", "notification gets an empty 202", (n.status_code, n.text))
tl = rpc(access, "tools/list", headers={"MCP-Protocol-Version": "2025-11-25"}).json()["result"]["tools"]
names = sorted(t["name"] for t in tl)
check(len(names) == 18 and "pta_set_module" not in names, "administrator sees all 18 tools", names)
bad_ver = rpc(access, "tools/list", headers={"MCP-Protocol-Version": "1900-01-01"})
check(bad_ver.status_code == 400, "unsupported MCP-Protocol-Version header is 400")

who = call(access, "pta_whoami")
check(not who["isError"] and who["structuredContent"]["user"]["login"] == "admin", "whoami over HTTP", who)
lc = call(access, "pta_list_content", {"type": "page"})
check(not lc["isError"] and any(i["title"] == "About" for i in lc["structuredContent"]["items"]), "list pages", lc)
cr = call(access, "pta_create_content", {"title": "Bake sale \"recap\"", "content": "<p>It's done</p>"})
check(not cr["isError"] and cr["structuredContent"]["status"] == "draft", "create post defaults to draft", cr)
pid = cr["structuredContent"]["id"]
gc = call(access, "pta_get_content", {"id": pid})
check(gc["structuredContent"]["title"] == 'Bake sale \u201crecap\u201d' or gc["structuredContent"]["content"] == "<p>It's done</p>", "content saved without added slashes", gc["structuredContent"])
check(gc["structuredContent"]["content"] == "<p>It's done</p>", "apostrophes survive (wp_slash handled)", gc["structuredContent"]["content"])
up = call(access, "pta_update_content", {"id": pid, "status": "publish", "title": "Bake sale recap"})
check(not up["isError"] and up["structuredContent"]["status"] == "publish", "update publishes", up)
tr = call(access, "pta_trash_content", {"id": pid})
check(tr["isError"] and "confirm=true" in tr["content"][0]["text"], "trash without confirm is refused", tr)
tr = call(access, "pta_trash_content", {"id": pid, "confirm": True})
check(not tr["isError"] and tr["structuredContent"]["status"] == "trash", "trash with confirm moves to trash", tr)
st = wp_eval('echo get_post_status(%d);' % pid)
check(st == "trash", "post really is in the trash, not deleted", st)

lp = call(access, "pta_list_products")
check(not lp["isError"] and lp["structuredContent"]["products"][0]["name"] == "Spirit Wear Tee", "list products via PTSA handler", lp)
prod_id = lp["structuredContent"]["products"][0]["id"]
gp = call(access, "pta_get_product", {"id": prod_id})
check(not gp["isError"] and gp["structuredContent"]["regular_price"] == "20", "get product", gp)
upp = call(access, "pta_update_product", {"id": prod_id, "sale_price": "15", "status": "publish"})
check(not upp["isError"] and upp["structuredContent"]["sale_price"] == "15", "update product via PTSA handler", upp)
badst = call(access, "pta_update_product", {"id": prod_id, "status": "trash"})
check(badst["isError"], "update_product cannot trash through status", badst)
lo = call(access, "pta_list_orders")
check(not lo["isError"] and lo["structuredContent"]["orders"][0]["customer_email"] == "buyer@example.org", "list orders", lo)
go = call(access, "pta_get_order", {"id": lo["structuredContent"]["orders"][0]["id"]})
check(not go["isError"] and go["structuredContent"]["status"] == "processing", "get order", go)
cc = call(access, "pta_create_coupon", {"code": "PTA10", "amount": 10, "discount_type": "percent", "expires": "2030-01-31", "usage_limit": 50})
check(not cc["isError"] and cc["structuredContent"]["code"] == "pta10" and cc["structuredContent"]["status"] == "publish", "create coupon", cc)
dup = call(access, "pta_create_coupon", {"code": "PTA10", "amount": 5})
check(dup["isError"], "duplicate coupon code refused", dup)
lcp = call(access, "pta_list_coupons")
check(not lcp["isError"] and lcp["structuredContent"]["coupons"][0]["usage_limit"] == 50, "list coupons", lcp)
lr = call(access, "pta_list_reports")
check(not lr["isError"] and lr["structuredContent"]["reports"][0]["name"] == "All orders", "list saved reports", lr)
ex = call(access, "pta_export_report", {"report_id": lr["structuredContent"]["reports"][0]["id"], "include_csv": True})
check(not ex["isError"] and ex["structuredContent"]["rows"] >= 1 and "Spirit Wear Tee" in ex["structuredContent"].get("csv", ""), "export report with inline CSV", ex)
head = requests.head(ex["structuredContent"]["download_url"])
check(head.status_code == 200, "HEAD (link preview) does not use up the link", head.status_code)
dl = requests.get(ex["structuredContent"]["download_url"])
check(dl.status_code == 200 and "text/csv" in dl.headers.get("Content-Type", "") and "Spirit Wear Tee" in dl.text and "attachment" in dl.headers.get("Content-Disposition", ""), "download link returns the CSV", (dl.status_code, dl.headers, dl.text[:200]))
again_dl = requests.get(ex["structuredContent"]["download_url"])
check(again_dl.status_code == 404, "the download link works only once", again_dl.status_code)
left = wp_eval('echo count(glob(Azure_Private_Storage::dir("ai-exports") . "/export-*.csv"));')
check(left == "0", "the export file is deleted after the download", left)
dl2 = requests.get(ex["structuredContent"]["download_url"][:-4] + "AAAA")
check(dl2.status_code == 404, "a guessed download link is 404")
ev = call(access, "pta_list_events", {"from": "2020-01-01", "to": "2030-01-01"})
check(not ev["isError"] and "events" in ev["structuredContent"], "list events", ev)
sync = call(access, "pta_calendar_sync")
check(sync["isError"] and "mailbox is not configured" in sync["content"][0]["text"], "calendar sync runs the shared code path (fails cleanly without a mailbox)", sync)
mods = call(access, "pta_list_modules")
check(not mods["isError"] and {"module": "ai_connectors", "enabled": True} in mods["structuredContent"]["modules"], "list modules", mods)
gone = rpc(access, "tools/call", {"name": "pta_set_module", "arguments": {"module": "rsvp", "enabled": True, "confirm": True}}).json()
check(gone.get("error", {}).get("code") == -32602, "pta_set_module no longer exists", gone)

# ── Refresh ───────────────────────────────────────────────
rf = requests.post(meta["token_endpoint"], data={"grant_type": "refresh_token", "refresh_token": pair["refresh_token"], "client_id": client_id})
check(rf.status_code == 200 and rf.json()["refresh_token"] != pair["refresh_token"], "refresh rotates", rf.text)
again = requests.post(meta["token_endpoint"], data={"grant_type": "refresh_token", "refresh_token": pair["refresh_token"], "client_id": client_id})
check(again.status_code == 400, "old refresh token is refused")
dead = rpc(rf.json()["access_token"], "tools/list")
check(dead.status_code == 401 and 'error="invalid_token"' in dead.headers.get("WWW-Authenticate", ""), "refresh reuse revoked the connection", dead.status_code)

# ── Other roles via personal tokens ───────────────────────
def pat(login, scopes="array(\"pta:read\",\"pta:write\")"):
    return wp_eval('require_once AZURE_PLUGIN_PATH . "includes/class-ai-connectors-module.php"; Azure_AI_Connectors_Module::load(); $u = get_user_by("login", "%s"); $r = Azure_AI_Connectors_Tokens::create_pat($u->ID, "e2e", 7, %s); echo $r["token"];' % (login, scopes))

def names_for(token):
    return sorted(t["name"] for t in rpc(token, "tools/list").json()["result"]["tools"])

entra = pat("entra")
check(names_for(entra) == sorted(["pta_whoami", "pta_list_content", "pta_get_content", "pta_update_content", "pta_list_events"]), "Entra (azuread) user: read and update tools only", names_for(entra))
about = int(wp_eval('echo get_page_by_path("about")->ID;'))
et = call(entra, "pta_trash_content", {"id": about, "confirm": True})
check(et["isError"] and "Microsoft Entra" in et["content"][0]["text"], "Entra user cannot trash even with confirm", et)
check(wp_eval('echo get_post_status(%d);' % about) == "publish", "the page is untouched")
eu = call(entra, "pta_update_content", {"id": about, "excerpt": "Edited by Entra"})
check(not eu["isError"], "Entra user can update", eu)

shop = pat("shop")
shop_names = names_for(shop)
check(shop_names == sorted(["pta_whoami", "pta_list_events", "pta_list_content", "pta_get_content", "pta_update_content",
    "pta_list_products", "pta_get_product", "pta_update_product", "pta_list_orders", "pta_get_order",
    "pta_list_coupons", "pta_create_coupon", "pta_list_reports", "pta_export_report"]),
    "shop manager (real WooCommerce role): store tools plus read/update pages and posts", shop_names)
sc = call(shop, "pta_create_content", {"title": "x"})
check(sc["isError"] and "not create or trash" in sc["content"][0]["text"], "shop manager cannot create a post", sc)
st2 = call(shop, "pta_trash_content", {"id": about, "confirm": True})
check(st2["isError"] and "not create or trash" in st2["content"][0]["text"], "shop manager cannot trash a page even with confirm", st2)
check(wp_eval('echo get_post_status(%d);' % about) == "publish", "the page is still published")
su = call(shop, "pta_update_content", {"id": about, "excerpt": "Edited by shop"})
check(not su["isError"], "shop manager can update a page", su)
scc = call(shop, "pta_create_coupon", {"code": "SHOP5", "amount": 5})
check(not scc["isError"], "shop manager can create a coupon", scc)

writer = pat("writer")
check(names_for(writer) == sorted(["pta_whoami", "pta_list_content", "pta_get_content", "pta_create_content", "pta_update_content", "pta_list_events"]), "custom role follows its capabilities", names_for(writer))
wp_page = call(writer, "pta_create_content", {"type": "page", "title": "x"})
check(wp_page["isError"], "custom role without edit_pages cannot create a page", wp_page)
wpub = call(writer, "pta_create_content", {"title": "x", "status": "publish"})
check(wpub["isError"] and "publish" in wpub["content"][0]["text"], "custom role without publish_posts cannot publish", wpub)

parent = pat("parent")
check(names_for(parent) == ["pta_list_events", "pta_whoami"], "subscriber: whoami and events only", names_for(parent))
po = call(parent, "pta_list_orders")
check(po["isError"], "subscriber cannot call list_orders directly", po)

ro = pat("admin", 'array("pta:read")')
check("pta_trash_content" not in names_for(ro) and "pta_list_products" in names_for(ro), "read-only token hides write tools")

# ── CORS / origin ─────────────────────────────────────────
evil = rpc(access, "ping", headers={"Origin": "https://evil.example"})
check(evil.status_code == 403, "MCP refuses a foreign browser origin", evil.status_code)
pre = requests.options(MCP, headers={"Origin": "https://evil.example", "Access-Control-Request-Method": "POST"})
check("Access-Control-Allow-Origin" not in pre.headers, "preflight from a foreign origin gets no CORS grant", dict(pre.headers))
pre2 = requests.options(MCP, headers={"Origin": BASE, "Access-Control-Request-Method": "POST", "Access-Control-Request-Headers": "authorization"})
check(pre2.headers.get("Access-Control-Allow-Origin") == BASE and "Authorization" in pre2.headers.get("Access-Control-Allow-Headers", "") and "Access-Control-Allow-Credentials" not in pre2.headers, "preflight from the site origin is allowed without credentials", dict(pre2.headers))

# ── Rate limit ────────────────────────────────────────────
wp_eval('update_option("azure_ai_connectors_settings", array_merge(get_option("azure_ai_connectors_settings", array()), array("rate_limit_per_minute" => 3)));')
rl = pat("admin")
codes = [rpc(rl, "ping").status_code for _ in range(5)]
check(codes[:3] == [200, 200, 200] and codes[3] == 429, "per-token rate limit returns 429", codes)
wp_eval('delete_option("azure_ai_connectors_settings");')

# ── Admin screens ─────────────────────────────────────────
up_page = s.get(BASE + "/wp-admin/users.php?page=pta-ai-connectors")
check(up_page.status_code == 200 and "MCP server URL" in up_page.text and "E2E Claude" not in up_page.text and "Personal token" in up_page.text, "Users > AI Connectors renders; revoked and failed connections are not listed", up_page.status_code)
ad = s.get(BASE + "/wp-admin/admin.php?page=azure-plugin-ai-connectors&tab=audit")
check(ad.status_code == 200 and "pta_trash_content" in ad.text and "confirm_required" in ad.text, "admin audit log shows calls", ad.status_code)
for tab in ("connections", "apps", "tools", "settings"):
    pg = s.get(BASE + "/wp-admin/admin.php?page=azure-plugin-ai-connectors&tab=" + tab)
    check(pg.status_code == 200 and "AI Connectors" in pg.text and "Fatal" not in pg.text, "admin tab renders: " + tab)
import html as _h; skill_link = _h.unescape(re.search(r'href="([^"]*action=pta_ai_skill[^"]*)"', up_page.text).group(1))
sk = s.get(skill_link)
check(sk.status_code == 200 and sk.text.startswith("---\nname: localhost-pta-tools") and MCP in sk.text, "skill file downloads", sk.text[:120])

# PAT creation through the form, shown once
nonce = re.search(r'name="_wpnonce" value="([^"]+)"', up_page.text.split('pta_ai_create_pat')[1]).group(1)
cp = s.post(BASE + "/wp-admin/admin-post.php", data={"action": "pta_ai_create_pat", "_wpnonce": nonce, "token_name": "Form token", "expires_days": "30"}, allow_redirects=False)
shown = s.get(cp.headers["Location"])
m = re.search(r'value="(ptat_[A-Za-z0-9_\-]+)"', shown.text)
check(m is not None, "new token is shown after creating it")
again_page = s.get(cp.headers["Location"])
check(m and m.group(1) not in again_page.text, "and is not shown again on reload")
check(m and rpc(m.group(1), "ping").status_code == 200, "the form-created token works")

# Entra user cannot open admin settings
es = requests.Session(); es.get(BASE + "/wp-login.php")
es.post(BASE + "/wp-login.php", data={"log": "entra", "pwd": "entra", "wp-submit": "Log In", "testcookie": "1"})
ep = es.get(BASE + "/wp-admin/admin.php?page=azure-plugin-ai-connectors&tab=settings")
check("Only site administrators" in ep.text and "entra_restricted_roles" not in ep.text, "Entra user (manage_options) cannot see AI Connectors settings")
ep_nonce = re.search(r'name="_wpnonce" value="([^"]+)"', s.get(BASE + "/wp-admin/admin.php?page=azure-plugin-ai-connectors&tab=settings").text).group(1)
sv = es.post(BASE + "/wp-admin/admin-post.php", data={"action": "pta_ai_save_settings", "_wpnonce": ep_nonce, "entra_restricted_roles[]": "subscriber"})
check(sv.status_code in (403, 200) and ("Only site administrators" in sv.text or "link you followed has expired" in sv.text), "Entra user cannot save settings", (sv.status_code, sv.text[:200]))

print("\n%d/%d passed" % (sum(results), len(results)))
sys.exit(0 if all(results) else 1)
