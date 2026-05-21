<?php
/**
 * Read-only inspection of TEC migration state on lwptsa.net.
 * Lists: calendar mappings, recent sync history, count of tribe_events with/without _outlook_event_id.
 */
const SHARED_SECRET = 'lwptsa-tec-inspect-v2-1778573580';
if (!isset($_GET['secret']) || $_GET['secret'] !== SHARED_SECRET) {
    http_response_code(403); echo 'Forbidden'; exit;
}

set_time_limit(120);
ini_set('memory_limit', '256M');
header('Content-Type: text/plain; charset=utf-8');

function get_token() {
    $url = getenv('IDENTITY_ENDPOINT') . '?' . http_build_query([
        'api-version' => getenv('ENTRAID_API_VERSION') ?: '2019-08-01',
        'resource'    => getenv('MYSQL_IDENTITY_RESOURCE_URL') ?: 'https://ossrdbms-aad.database.windows.net',
        'client_id'   => getenv('ENTRA_CLIENT_ID'),
    ]);
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['X-IDENTITY-HEADER: ' . getenv('IDENTITY_HEADER')]);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    $r = curl_exec($ch); curl_close($ch);
    return json_decode($r, true)['access_token'] ?? null;
}

$token = get_token();
if (!$token) { echo "FAIL: no token\n"; exit(1); }

$host = getenv('DATABASE_HOST');
$user = getenv('DATABASE_USERNAME');
$db   = getenv('DATABASE_NAME');

mysqli_report(MYSQLI_REPORT_OFF);
$m = mysqli_init();
$m->ssl_set(NULL, NULL, NULL, NULL, NULL);
if (!@$m->real_connect($host, $user, $token, $db, 3306, NULL,
        MYSQLI_CLIENT_SSL | MYSQLI_CLIENT_SSL_DONT_VERIFY_SERVER_CERT)) {
    echo "FAIL connect: " . mysqli_connect_error() . "\n"; exit(1);
}
echo "Connected to $db on $host\n\n";

echo "=== TEC calendar mappings ===\n";
$res = $m->query("SELECT * FROM wp_azure_tec_calendar_mappings");
if ($res) {
    while ($row = $res->fetch_assoc()) {
        foreach ($row as $k => $v) {
            $vs = is_string($v) && strlen($v) > 200 ? substr($v, 0, 200) . "..." : $v;
            echo "  $k = $vs\n";
        }
        echo "---\n";
    }
    if ($res->num_rows === 0) echo "  (no rows)\n";
} else { echo "  ERR: " . $m->error . "\n"; }

echo "\n=== TEC sync history (last 10) ===\n";
$res = $m->query("SELECT * FROM wp_azure_tec_sync_history ORDER BY id DESC LIMIT 10");
if ($res) {
    if ($res->num_rows === 0) {
        echo "  (no rows - sync has never run successfully)\n";
    } else {
        while ($row = $res->fetch_assoc()) {
            foreach ($row as $k => $v) {
                $vs = is_string($v) && strlen($v) > 150 ? substr($v, 0, 150) . "..." : $v;
                echo "  $k = $vs\n";
            }
            echo "---\n";
        }
    }
} else { echo "  ERR: " . $m->error . "\n"; }

echo "\n=== TEC sync queue (anything pending) ===\n";
$res = $m->query("SELECT COUNT(*) AS c FROM wp_azure_tec_sync_queue");
echo "  pending: " . ($res ? $res->fetch_assoc()['c'] : 'ERR') . "\n";

echo "\n=== TEC sync conflicts ===\n";
$res = $m->query("SELECT COUNT(*) AS c FROM wp_azure_tec_sync_conflicts");
echo "  conflicts: " . ($res ? $res->fetch_assoc()['c'] : 'ERR') . "\n";

echo "\n=== Tribe events analysis ===\n";
$res = $m->query("SELECT COUNT(*) AS c FROM wp_posts WHERE post_type='tribe_events' AND post_status IN ('publish','future','draft')");
$total = $res ? $res->fetch_assoc()['c'] : 0;
echo "  total tribe_events posts (publish/future/draft): $total\n";

$res = $m->query("SELECT COUNT(DISTINCT p.ID) AS c FROM wp_posts p
    INNER JOIN wp_postmeta pm ON p.ID = pm.post_id
    WHERE p.post_type='tribe_events' AND pm.meta_key='_outlook_event_id' AND pm.meta_value <> ''");
$with_outlook = $res ? $res->fetch_assoc()['c'] : 0;
echo "  events WITH _outlook_event_id: $with_outlook\n";
echo "  events MISSING _outlook_event_id: " . ($total - $with_outlook) . "\n";

$res = $m->query("SELECT COUNT(DISTINCT p.ID) AS c FROM wp_posts p
    INNER JOIN wp_postmeta pm ON p.ID = pm.post_id
    WHERE p.post_type='tribe_events' AND pm.meta_key='_outlook_sync_status'");
echo "  events with sync metadata: " . ($res ? $res->fetch_assoc()['c'] : 0) . "\n";

echo "\n=== Sync status breakdown ===\n";
$res = $m->query("SELECT pm.meta_value AS status, COUNT(*) AS c FROM wp_postmeta pm
    INNER JOIN wp_posts p ON p.ID = pm.post_id
    WHERE pm.meta_key='_outlook_sync_status' AND p.post_type='tribe_events'
    GROUP BY pm.meta_value");
if ($res) while ($row = $res->fetch_assoc()) echo "  " . $row['status'] . " : " . $row['c'] . "\n";

echo "\n=== Latest 5 tribe_events (titles + dates) ===\n";
$res = $m->query("SELECT p.ID, p.post_title, p.post_date,
    (SELECT meta_value FROM wp_postmeta WHERE post_id=p.ID AND meta_key='_EventStartDate' LIMIT 1) AS event_start,
    (SELECT meta_value FROM wp_postmeta WHERE post_id=p.ID AND meta_key='_outlook_event_id' LIMIT 1) AS outlook_id
    FROM wp_posts p WHERE p.post_type='tribe_events' AND p.post_status='publish'
    ORDER BY p.post_date DESC LIMIT 5");
if ($res) while ($row = $res->fetch_assoc()) {
    $oid = $row['outlook_id'] ? substr($row['outlook_id'], 0, 30) . "..." : "(none)";
    echo "  #" . $row['ID'] . "  " . $row['post_title'] . "  start=" . $row['event_start'] . "  outlook=" . $oid . "\n";
}

$m->close();
echo "\nDone.\n";
