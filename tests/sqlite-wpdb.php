<?php
/**
 * A $wpdb over in-memory SQLite, for code whose correctness depends on real
 * SQL (unique keys, conditional updates). Supports the calls the plugin
 * makes: prepare, query, get_results, get_row, get_var, get_col.
 * "INSERT IGNORE" is translated to SQLite's "INSERT OR IGNORE".
 *
 * Pass a MySQL PDO instead to run the same calls against a real server.
 * Like mysqli under WordPress, UPDATE then reports rows changed, not rows
 * matched.
 */

class Sqlite_WPDB {
    public $prefix = 'wp_';
    public $last_error = '';
    public $queries = array();
    private $pdo;

    public function __construct(?PDO $pdo = null) {
        $this->pdo = $pdo ?: new PDO('sqlite::memory:');
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    }

    public function is_mysql() {
        return $this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql';
    }

    public function exec($sql) {
        $this->pdo->exec($sql);
    }

    public function get_charset_collate() {
        return '';
    }

    public function prepare($sql, ...$args) {
        if (count($args) === 1 && is_array($args[0])) {
            $args = $args[0];
        }
        $pdo = $this->pdo;
        $i = 0;
        return preg_replace_callback('/%[sdf]/', function ($m) use (&$i, $args, $pdo) {
            $v = $args[$i++] ?? null;
            if ($m[0] === '%d') {
                return (string) (int) $v;
            }
            if ($m[0] === '%f') {
                return (string) (float) $v;
            }
            return $pdo->quote((string) $v);
        }, $sql);
    }

    public function query($sql) {
        if (!$this->is_mysql()) {
            $sql = preg_replace('/^\s*INSERT IGNORE/i', 'INSERT OR IGNORE', $sql);
        }
        $this->queries[] = $sql;
        $stmt = $this->pdo->query($sql);
        if (preg_match('/^\s*SELECT/i', $sql)) {
            return count($stmt->fetchAll());
        }
        return $stmt->rowCount();
    }

    public function get_results($sql, $output = 'OBJECT') {
        $this->queries[] = $sql;
        $rows = $this->pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);
        return $output === ARRAY_A ? $rows : array_map(function ($r) { return (object) $r; }, $rows);
    }

    public function get_row($sql, $output = 'OBJECT') {
        $rows = $this->get_results($sql, $output);
        return $rows[0] ?? null;
    }

    public function get_var($sql) {
        $this->queries[] = $sql;
        $v = $this->pdo->query($sql)->fetchColumn();
        return $v === false ? null : $v;
    }

    public function get_col($sql) {
        $this->queries[] = $sql;
        return $this->pdo->query($sql)->fetchAll(PDO::FETCH_COLUMN);
    }
}

if (!defined('ARRAY_A')) {
    define('ARRAY_A', 'ARRAY_A');
}

/**
 * A $wpdb with an empty ticket inventory table. SQLite by default; set
 * PTA_TEST_MYSQL_DSN="mysql:host=127.0.0.1;port=3307;dbname=t" (and
 * PTA_TEST_MYSQL_USER / _PASS) to use MySQL and the plugin's own CREATE TABLE.
 */
function tickets_test_wpdb() {
    $dsn = getenv('PTA_TEST_MYSQL_DSN');
    if (!$dsn) {
        $db = new Sqlite_WPDB();
        $db->exec("CREATE TABLE wp_azure_ticket_inventory (
            id INTEGER PRIMARY KEY AUTOINCREMENT, product_id INTEGER NOT NULL, section_id TEXT NOT NULL, seat_key TEXT NOT NULL,
            status TEXT NOT NULL, hold_key TEXT NOT NULL DEFAULT '', user_id INTEGER NOT NULL DEFAULT 0, order_id INTEGER NOT NULL DEFAULT 0,
            expires_at TEXT, created_at TEXT NOT NULL, UNIQUE (product_id, section_id, seat_key))");
        return $db;
    }
    $db = new Sqlite_WPDB(new PDO($dsn, getenv('PTA_TEST_MYSQL_USER') ?: 'root', getenv('PTA_TEST_MYSQL_PASS') ?: ''));
    preg_match('/dbDelta\("(CREATE TABLE \{\$table\} \(.*?\) )"/s', file_get_contents(dirname(__DIR__) . '/Azure Plugin/includes/class-tickets-inventory.php'), $ddl);
    $db->exec('DROP TABLE IF EXISTS wp_azure_ticket_inventory');
    $db->exec(str_replace('{$table}', 'wp_azure_ticket_inventory', $ddl[1]) . 'DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_520_ci');
    return $db;
}
