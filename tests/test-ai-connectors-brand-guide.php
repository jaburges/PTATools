<?php
/**
 * AI Connectors brand guide: the setting, what pta_get_brand_guide reads
 * from the page, and every place an assistant is told to follow it.
 *
 * Run: php tests/test-ai-connectors-brand-guide.php
 */

require_once __DIR__ . '/ai-connectors-shim.php';
require_once AZURE_PLUGIN_PATH . 'includes/class-private-storage.php';
require_once AZURE_PLUGIN_PATH . 'includes/class-ai-connectors-module.php';
Azure_AI_Connectors_Module::load();

// ─── Post shims ──────────────────────────────────────────────────────

class BG_Posts {
    /** @var array<int,object> */
    public static $posts = array();
    public static $theme_mods = array();
    public static $attachments = array();

    public static function add($id, $type, $status, $title, $content) {
        self::$posts[$id] = (object) array(
            'ID' => $id, 'post_type' => $type, 'post_status' => $status, 'post_title' => $title,
            'post_content' => $content, 'post_modified' => '2026-11-12 09:00:00', 'post_author' => 1,
        );
    }
}
function get_post($id) { return BG_Posts::$posts[(int) $id] ?? null; }
function get_the_title($post) { $post = is_object($post) ? $post : get_post($post); return $post ? $post->post_title : ''; }
function get_permalink($post) { $post = is_object($post) ? $post : get_post($post); return 'https://example.test/?page_id=' . $post->ID; }
function get_theme_mod($name, $default = false) { return BG_Posts::$theme_mods[$name] ?? $default; }
function wp_get_attachment_image_url($id, $size = 'thumbnail') { return BG_Posts::$attachments[(int) $id] ?? false; }
function get_post_thumbnail_id($post) { return (int) get_post_meta(is_object($post) ? $post->ID : $post, '_thumbnail_id', true); }
function get_posts($args = array()) {
    return array_values(array_filter(BG_Posts::$posts, function ($p) {
        return $p->post_status === 'publish' && get_post_thumbnail_id($p);
    }));
}
if (!function_exists('esc_url')) {
    function esc_url($url) { return htmlspecialchars((string) $url, ENT_QUOTES); }
}
function wc_get_product($id = 0) { return false; }
function wc_get_orders($args = array()) { return array(); }

$t = new TestRunner('AI Connectors brand guide');
$BG = 'Azure_AI_Connectors_Brand_Guide';

$GUIDE = <<<HTML
<!-- wp:paragraph -->
<p>How Lincoln Elementary PTA looks &amp; sounds.</p>
<!-- /wp:paragraph -->
<!-- wp:heading -->
<h2 class="wp-block-heading">Colours</h2>
<!-- /wp:heading -->
<!-- wp:table -->
<figure class="wp-block-table"><table><thead><tr><th>Colour</th><th>Hex</th><th>Use</th></tr></thead><tbody>
<tr><td>Royal Blue</td><td>#003bb3</td><td>Banners and links</td></tr>
<tr><td>Sunshine</td><td>#FFC934</td><td>Buttons, with dark text</td></tr>
<tr><td>Paper</td><td>#fff</td><td>Cards</td></tr>
</tbody></table></figure>
<!-- /wp:table -->
<!-- wp:heading -->
<h2 class="wp-block-heading">Typography</h2>
<!-- /wp:heading -->
<!-- wp:heading {"level":3} -->
<h3 class="wp-block-heading">Headings</h3>
<!-- /wp:heading -->
<!-- wp:paragraph -->
<p>Jost, bold 700</p>
<!-- /wp:paragraph -->
<!-- wp:paragraph -->
<p>Body: Noto Serif 400</p>
<!-- /wp:paragraph -->
<!-- wp:heading -->
<h2 class="wp-block-heading">Images</h2>
<!-- /wp:heading -->
<!-- wp:image -->
<figure class="wp-block-image"><img src="https://example.test/wp-content/uploads/signups.png" alt="Volunteer signups artwork"/></figure>
<!-- /wp:image -->
<!-- wp:image -->
<figure class="wp-block-image"><img src="/wp-content/uploads/logo.png" alt="PTA logo &amp; tagline"/></figure>
<!-- /wp:image -->
<!-- wp:heading -->
<h2 class="wp-block-heading">Agent prompt</h2>
<!-- /wp:heading -->
<!-- wp:group {"className":"pta-brand-prompt"} -->
<div class="wp-block-group pta-brand-prompt"><div class="wp-block-group__inner-container">
<p>Write warmly.</p>
<div><p>Buttons use #FFC934.</p></div>
</div></div>
<!-- /wp:group -->
<!-- wp:paragraph -->
<p>Questions? Ask the board.</p>
<!-- /wp:paragraph -->
HTML;

function setup_guide($enabled, $page_id) {
    AI_Test::reset();
    WP_Shim::$settings = array('enable_calendar' => true, 'enable_ai_connectors' => true);
    AI_Test::add_user(1, array('administrator'), 'admin');
    AI_Test::add_user(2, array('shop_manager'), 'shop');
    AI_Test::add_user(3, array('azuread'), 'entra');
    AI_Test::add_user(4, array('newsletter_editor'), 'writer');
    AI_Test::add_user(5, array('subscriber'), 'parent');
    Azure_AI_Connectors_Settings::save(array_merge(
        Azure_AI_Connectors_Settings::defaults(),
        array('brand_guide_enabled' => $enabled, 'brand_guide_page_id' => $page_id)
    ));
    Azure_AI_Connectors_Registry::reset();
}

function mcp($user_id, $method, array $params = array(), array $scopes = null) {
    $scopes = $scopes === null ? Azure_AI_Connectors_Policy::scopes() : $scopes;
    AI_Test::login($user_id);
    $token = (object) array('id' => 90 + $user_id, 'token_type' => 'pat', 'name' => 'test', 'scope' => implode(' ', $scopes));
    return Azure_AI_Connectors_MCP::handle_payload(
        array('jsonrpc' => '2.0', 'id' => 1, 'method' => $method, 'params' => $params),
        array('user' => wp_get_current_user(), 'token' => $token, 'scopes' => $scopes, 'client_label' => 'Test client')
    );
}

function listed($user_id, array $scopes = null) {
    return array_map(function ($tool) { return $tool['name']; }, mcp($user_id, 'tools/list', array(), $scopes)['result']['tools']);
}

function tool_result($user_id, $name, array $args = array()) {
    $r = mcp($user_id, 'tools/call', array('name' => $name, 'arguments' => $args));
    return $r['result'];
}

BG_Posts::add(10, 'page', 'private', 'Brand Guide', $GUIDE);
BG_Posts::add(11, 'page', 'trash', 'Old guide', $GUIDE);
BG_Posts::add(12, 'post', 'publish', 'A post', $GUIDE);

// ─── Settings ────────────────────────────────────────────────────────

$d = Azure_AI_Connectors_Settings::defaults();
$t->check($d['brand_guide_enabled'] === false && $d['brand_guide_page_id'] === 0, 'the brand guide is off with no page by default');
$clean = Azure_AI_Connectors_Settings::sanitize(array('brand_guide_enabled' => '1', 'brand_guide_page_id' => '10'));
$t->check($clean['brand_guide_enabled'] === true && $clean['brand_guide_page_id'] === 10, 'the checkbox and page id are saved as a boolean and an integer');
$clean = Azure_AI_Connectors_Settings::sanitize(array('brand_guide_page_id' => '-4'));
$t->check($clean['brand_guide_enabled'] === false && $clean['brand_guide_page_id'] === 0, 'an unticked box saves off, and a negative id saves as no page');

// ─── Off ─────────────────────────────────────────────────────────────

setup_guide(false, 10);
$t->check(!$BG::active(), 'a selected page does nothing while the box is unticked');
$t->check($BG::summary() === null, 'pta_whoami reports no brand guide');
$init = mcp(1, 'initialize', array('protocolVersion' => '2025-06-18'));
$t->check(strpos($init['result']['instructions'], 'brand guide') === false, 'server instructions do not mention a brand guide');
$t->check(!in_array('pta_get_brand_guide', listed(1), true), 'the brand guide tool is hidden, even from an administrator');
$t->check(strpos(Azure_AI_Connectors_Registry::get('pta_update_content')['description'], 'brand guide') === false, 'the update tool says nothing about a brand guide');
$whoami = tool_result(1, 'pta_whoami')['structuredContent'];
$t->check(array_key_exists('brand_guide', $whoami) && $whoami['brand_guide'] === null, 'pta_whoami has brand_guide: null');
$call = tool_result(1, 'pta_get_brand_guide');
$t->check(!empty($call['isError']), 'calling the hidden tool directly is refused');

foreach (array(array(true, 0, 'no page chosen'), array(true, 99, 'a page that does not exist'), array(true, 11, 'a page in the trash'), array(true, 12, 'a post instead of a page')) as $case) {
    setup_guide($case[0], $case[1]);
    $t->check(!$BG::active(), 'ticked with ' . $case[2] . ' is not in use');
}

// ─── On ──────────────────────────────────────────────────────────────

setup_guide(true, 10);
$t->check($BG::active(), 'ticked with a private page is in use');
$init = mcp(1, 'initialize', array('protocolVersion' => '2025-06-18'));
$t->check(strpos($init['result']['instructions'], 'call pta_get_brand_guide') !== false, 'server instructions tell every assistant to call pta_get_brand_guide');
$t->check(strpos($init['result']['instructions'], 'confirm=true') !== false, 'the usual instructions are still there');

$t->check(in_array('pta_get_brand_guide', listed(1), true), 'an administrator gets the brand guide tool');
$t->check(in_array('pta_get_brand_guide', listed(2), true), 'a shop manager gets it (read-only tool)');
$t->check(in_array('pta_get_brand_guide', listed(3), true), 'an Entra account gets it');
$t->check(in_array('pta_get_brand_guide', listed(4), true), 'a writer with only edit_posts gets it');
$t->check(!in_array('pta_get_brand_guide', listed(5), true), 'a parent account, which cannot edit content, does not');
$t->check(in_array('pta_get_brand_guide', listed(1, array(Azure_AI_Connectors_Policy::SCOPE_READ)), true), 'a read-only connection gets it');

foreach (array('pta_create_content', 'pta_update_content') as $name) {
    $t->check(strpos(Azure_AI_Connectors_Registry::get($name)['description'], 'call pta_get_brand_guide') !== false, $name . ' tells the assistant to read the brand guide first');
}
$t->check(strpos(Azure_AI_Connectors_Registry::get('pta_list_content')['description'], 'brand guide') === false, 'read tools are not cluttered with the reminder');

$whoami = tool_result(1, 'pta_whoami')['structuredContent'];
$t->equals(10, $whoami['brand_guide']['page_id'], 'pta_whoami names the brand guide page');
$t->equals('pta_get_brand_guide', $whoami['brand_guide']['tool'], 'and the tool to read it');

$skill = Azure_AI_Connectors_Skill::render(wp_get_current_user());
$t->check(strpos($skill, '7. This site has a brand guide.') !== false, 'the skill file gains a brand guide rule');

// ─── What the tool returns ───────────────────────────────────────────

$res = tool_result(4, 'pta_get_brand_guide');
$t->check(empty($res['isError']), 'pta_get_brand_guide succeeds');
$g = $res['structuredContent'];
$t->equals('Brand Guide', $g['title'], 'it returns the page title');
$t->equals('https://example.test/?page_id=10', $g['url'], 'and its address');
$t->equals("Write warmly.\nButtons use #FFC934.", $g['agent_prompt'], 'the agent prompt is the text of the pta-brand-prompt block, nested blocks included and nothing after it');
$t->equals(
    array(
        array('hex' => '#003BB3', 'label' => 'Royal Blue | Banners and links'),
        array('hex' => '#FFC934', 'label' => 'Sunshine | Buttons, with dark text'),
        array('hex' => '#FFF', 'label' => 'Paper | Cards'),
    ),
    $g['colors'],
    'each colour comes back once, uppercased, labelled with its table row'
);
$t->equals("Headings\nJost, bold 700\nBody: Noto Serif 400", $g['fonts'], 'fonts are the Typography section, including its sub-headings');
$t->equals(
    array(
        array('url' => 'https://example.test/wp-content/uploads/signups.png', 'alt' => 'Volunteer signups artwork'),
        array('url' => 'https://example.test/wp-content/uploads/logo.png', 'alt' => 'PTA logo & tagline'),
    ),
    $g['images'],
    'images come back with absolute addresses and decoded alt text'
);
$t->check(strpos($g['content'], 'How Lincoln Elementary PTA looks & sounds.') === 0, 'the full page text is included, entities decoded');
$t->check(strpos($g['content'], '<') === false && strpos($g['content'], 'wp:') === false, 'with no markup or block comments');
$t->check($g['truncated'] === false, 'a normal page is not truncated');
$t->check(strpos($g['how_to_use'], 'agent_prompt') !== false, 'it explains how to apply the guide');

$rows = $GLOBALS['wpdb']->get_results("SELECT * FROM wp_azure_ai_audit WHERE tool = 'pta_get_brand_guide' AND status = 'ok'");
$t->check(count($rows) === 1, 'reading the brand guide is audited like any tool call');

// ─── Fallbacks and edge cases ────────────────────────────────────────

$t->equals(
    "Be kind.\nMore\nKeep it short.",
    $BG::prompt('<h2>Intro</h2><p>Hi</p><h2>Agent Prompt</h2><p>Be kind.</p><h3>More</h3><p>Keep it short.</p><h2>Images</h2><p>x</p>'),
    'without the class, the prompt is the "Agent prompt" section, sub-headings included, up to the next heading of its level'
);
$t->equals('', $BG::prompt('<p>No prompt here</p>'), 'a page with no prompt returns an empty prompt');
$t->equals(
    "Line one\nLine two",
    $BG::prompt('<pre class="wp-block-preformatted pta-brand-prompt">Line one<br>Line two</pre><p>after</p>'),
    'a preformatted prompt keeps its line breaks'
);
$t->equals(
    array(array('hex' => '#123ABC', 'label' => 'Primary')),
    $BG::colors("Primary: #123abc\nAgain #123ABC\nNot a colour #12345 or #ggg"),
    'colours ignore repeats and things that are not hex codes'
);
$long = '<p>' . str_repeat('a', $BG::MAX_TEXT + 50) . '</p>';
BG_Posts::add(13, 'page', 'publish', 'Long', $long);
$g = $BG::read(get_post(13));
$t->check($g['truncated'] === true && strlen($g['content']) === $BG::MAX_TEXT, 'a very long page is cut to ' . $BG::MAX_TEXT . ' characters and says so');

// ─── Starter page ────────────────────────────────────────────────────

BG_Posts::$theme_mods['custom_logo'] = 50;
BG_Posts::$attachments[50] = 'https://example.test/wp-content/uploads/logo-full.png';
BG_Posts::$attachments[60] = 'https://example.test/wp-content/uploads/fair.jpg';
BG_Posts::add(20, 'post', 'publish', 'Fall Fair', '<p>x</p>');
WP_Shim::$post_meta[20]['_thumbnail_id'] = 60;
$starter = $BG::starter_content();
$t->check(strpos($starter, 'class="wp-block-preformatted pta-brand-prompt"') !== false, 'the starter page puts the agent prompt in a pta-brand-prompt block');
$t->check(strpos($starter, '<!-- wp:preformatted {"className":"pta-brand-prompt"} -->') !== false, 'with the class saved in the block attributes, so the editor keeps it');
$t->check(strpos($starter, 'logo-full.png') !== false, 'it shows the site logo');
$t->check(strpos($starter, 'fair.jpg') !== false && strpos($starter, 'alt="Fall Fair"') !== false, 'and recent featured images as examples, titled when they have no alt text');
$t->check(strpos($starter, 'Test Site') !== false, 'it uses the site name');
BG_Posts::add(21, 'page', 'private', 'Brand Guide', $starter);
$g = $BG::read(get_post(21));
$t->check(strpos($g['agent_prompt'], 'You are editing pages on the Test Site website.') === 0, 'the starter prompt reads back through the tool');
$t->equals(5, count($g['colors']), 'its five example colours read back');
$t->check(strpos($g['fonts'], 'Headings:') === 0, 'its typography section reads back');
$t->equals(2, count($g['images']), 'its logo and example image read back');

// ─── The guide written for the origin site (docs/brand-guide) ────────

BG_Posts::add(30, 'page', 'private', 'Brand Guide', file_get_contents(dirname(__DIR__) . '/docs/brand-guide/wilder-ptsa-brand-guide.html'));
$g = $BG::read(get_post(30));
$hexes = array_map(function ($c) { return $c['hex']; }, $g['colors']);
$t->equals(array('#003BB3', '#FFC934', '#2554C1', '#4444C1', '#EE2224', '#0987F5', '#202020', '#4A4A4A', '#EEEEEE', '#FFFFFF', '#DDDDDD'), $hexes, 'the docs guide yields its ten brand colours in table order, then the email divider');
$t->check(strpos($g['agent_prompt'], "EMAIL AND NEWSLETTERS\n") !== false && strpos($g['agent_prompt'], '"Hi Wilder Families,"') !== false, 'the prompt carries the email rules and greeting');
$t->check(strpos($g['agent_prompt'], 'Say "Wilder Families", never "parents"') !== false && strpos($g['agent_prompt'], 'Say "Students"') !== false, 'and the audience wording');
$t->check(strpos($g['agent_prompt'], '<Month D, YYYY>') !== false, 'with escaped angle brackets decoded back');
$t->check(strpos($g['colors'][0]['label'], 'Royal Blue') === 0, 'labelled from the colour table');
$end = 'summarise your changes.';
$t->check(strpos($g['agent_prompt'], 'You are editing pages on the') === 0 && substr($g['agent_prompt'], -strlen($end)) === $end, 'the whole agent prompt reads back');
$t->check(strpos($g['agent_prompt'], "VOICE\n- Warm") !== false, 'with its line breaks');
$t->check(strpos($g['fonts'], 'Jost') !== false && strpos($g['fonts'], 'Noto Serif') !== false, 'fonts name Jost and Noto Serif');
$t->equals(7, count($g['images']), 'the logo, four example images and the two example emails read back');

exit($t->finish() > 0 ? 1 : 0);
