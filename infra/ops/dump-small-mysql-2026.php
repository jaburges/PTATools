<?php
/**
 * Plugin Name: One-off — dump wilderptsa-wpdb-small to blob
 * Token-gated. Deletes nothing on the DB. Remove after use.
 *
 * GET ?pta_small_dump=TOKEN
 * GET ?pta_small_dump=TOKEN&trigger=1
 * GET ?pta_small_dump=TOKEN&status=1
 */
if (!defined('ABSPATH')) {
    return;
}

add_action('init', function () {
    if (empty($_GET['pta_small_dump'])) {
        return;
    }
    $expected = 'a8f3c91e2b7d4e6f0a1b2c3d4e5f6789';
    if (!hash_equals($expected, (string) $_GET['pta_small_dump'])) {
        status_header(403);
        header('Content-Type: text/plain');
        echo 'forbidden';
        exit;
    }

    nocache_headers();
    header('Content-Type: application/json');

    $host = 'wilderptsa-wpdb-small.mysql.database.azure.com';
    $db   = defined('DB_NAME') ? DB_NAME : 'wilderptsa_c20b298090_database';
    $user = 'ptsadbadmin';
    $pass = getenv('PTSA_SMALL_DUMP_PASSWORD') ?: (isset($_SERVER['PTSA_SMALL_DUMP_PASSWORD']) ? $_SERVER['PTSA_SMALL_DUMP_PASSWORD'] : '');
    // App Service app settings appear in getenv
    if (!$pass && isset($_ENV['PTSA_SMALL_DUMP_PASSWORD'])) {
        $pass = $_ENV['PTSA_SMALL_DUMP_PASSWORD'];
    }

    $out = array(
        'time' => current_time('mysql'),
        'host' => $host,
        'pass_set' => $pass !== '',
    );

    if (!$pass) {
        $out['error'] = 'PTSA_SMALL_DUMP_PASSWORD app setting missing';
        echo wp_json_encode($out, JSON_PRETTY_PRINT);
        exit;
    }

    $state_file = WP_CONTENT_DIR . '/uploads/.pta-small-dump-state.json';

    if (!empty($_GET['status'])) {
        $out['state'] = file_exists($state_file) ? json_decode(file_get_contents($state_file), true) : null;
        echo wp_json_encode($out, JSON_PRETTY_PRINT);
        exit;
    }

    // Probe connectivity + counts
    mysqli_report(MYSQLI_REPORT_OFF);
    $mysqli = mysqli_init();
    mysqli_ssl_set($mysqli, null, null, null, null, null);
    if (!@$mysqli->real_connect($host, $user, $pass, $db, 3306, null, MYSQLI_CLIENT_SSL)) {
        $out['error'] = 'connect_failed: ' . mysqli_connect_error();
        echo wp_json_encode($out, JSON_PRETTY_PRINT);
        exit;
    }
    $tables = (int) $mysqli->query("SELECT COUNT(*) c FROM information_schema.tables WHERE table_schema='" . $mysqli->real_escape_string($db) . "'")->fetch_assoc()['c'];
    $pages = (int) $mysqli->query("SELECT COUNT(*) c FROM wp_posts WHERE post_type='page' AND post_status!='auto-draft'")->fetch_assoc()['c'];
    $orders_hpos = 0;
    $r = $mysqli->query("SELECT COUNT(*) c FROM wp_wc_orders");
    if ($r) {
        $orders_hpos = (int) $r->fetch_assoc()['c'];
    }
    $orders_legacy = (int) $mysqli->query("SELECT COUNT(*) c FROM wp_posts WHERE post_type='shop_order'")->fetch_assoc()['c'];
    $users = (int) $mysqli->query("SELECT COUNT(*) c FROM wp_users")->fetch_assoc()['c'];
    $out['probe'] = compact('tables', 'pages', 'orders_hpos', 'orders_legacy', 'users');

    if (empty($_GET['trigger'])) {
        $mysqli->close();
        echo wp_json_encode($out, JSON_PRETTY_PRINT);
        exit;
    }

    // Run mysqldump to a local gz then upload via backup storage settings
    $tmp = WP_CONTENT_DIR . '/uploads/.pta-small-dump.sql.gz';
    @unlink($tmp);
    $cmd = sprintf(
        'mysqldump --host=%s --user=%s --password=%s --ssl-mode=REQUIRED --single-transaction --quick --routines --triggers --events --default-character-set=utf8mb4 %s 2>/tmp/pta-small-dump.err | gzip -c > %s',
        escapeshellarg($host),
        escapeshellarg($user),
        escapeshellarg($pass),
        escapeshellarg($db),
        escapeshellarg($tmp)
    );
    // safer: put password in env for child
    $descriptors = array(0 => array('pipe', 'r'), 1 => array('pipe', 'w'), 2 => array('pipe', 'w'));
    $env = $_ENV;
    $env['MYSQL_PWD'] = $pass;
    $cmd2 = sprintf(
        'mysqldump --host=%s --user=%s --ssl-mode=REQUIRED --single-transaction --quick --routines --triggers --events --default-character-set=utf8mb4 %s | gzip -c > %s',
        escapeshellarg($host),
        escapeshellarg($user),
        escapeshellarg($db),
        escapeshellarg($tmp)
    );
    $proc = proc_open($cmd2, $descriptors, $pipes, null, $env);
    $stderr = '';
    if (is_resource($proc)) {
        fclose($pipes[0]);
        stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $code = proc_close($proc);
    } else {
        $code = -1;
        $stderr = 'proc_open failed';
    }

    $size = file_exists($tmp) ? filesize($tmp) : 0;
    $out['dump'] = array('exit' => $code, 'size' => $size, 'stderr' => substr($stderr, 0, 500));

    if ($size < 1000) {
        // PHP fallback: stream tables
        $fh = gzopen($tmp, 'wb9');
        gzwrite($fh, "SET foreign_key_checks = 0;\n");
        $tres = $mysqli->query("SHOW TABLES");
        $tlist = array();
        while ($row = $tres->fetch_row()) {
            $tlist[] = $row[0];
        }
        foreach ($tlist as $table) {
            $create = $mysqli->query("SHOW CREATE TABLE `{$table}`")->fetch_row();
            gzwrite($fh, "DROP TABLE IF EXISTS `{$table}`;\n" . $create[1] . ";\n");
            $offset = 0;
            $batch = 500;
            while (true) {
                $q = $mysqli->query("SELECT * FROM `{$table}` LIMIT {$batch} OFFSET {$offset}");
                if (!$q || $q->num_rows === 0) {
                    break;
                }
                while ($row = $q->fetch_assoc()) {
                    $cols = array_map(function ($c) { return '`' . $c . '`'; }, array_keys($row));
                    $vals = array_map(function ($v) use ($mysqli) {
                        if ($v === null) {
                            return 'NULL';
                        }
                        return "'" . $mysqli->real_escape_string($v) . "'";
                    }, array_values($row));
                    gzwrite($fh, 'INSERT INTO `' . $table . '` (' . implode(',', $cols) . ') VALUES (' . implode(',', $vals) . ");\n");
                }
                $offset += $batch;
            }
        }
        gzwrite($fh, "SET foreign_key_checks = 1;\n");
        gzclose($fh);
        $size = filesize($tmp);
        $out['dump']['fallback'] = 'php';
        $out['dump']['size'] = $size;
    }

    // Upload to blob using plugin storage settings
    $azure_plugin_path = defined('AZURE_PLUGIN_PATH') ? AZURE_PLUGIN_PATH : ABSPATH . 'wp-content/plugins/Azure Plugin/';
    foreach (array(
        'includes/class-backup-azure-storage.php',
        'includes/class-settings.php',
    ) as $f) {
        $p = $azure_plugin_path . $f;
        if (file_exists($p)) {
            require_once $p;
        }
    }

    $blob = 'wilder-ptsa/2026/07/13/SMALL-wpdb-small-mysqldump.sql.gz';
    $uploaded = false;
    $upload_err = null;
    if (class_exists('Azure_Backup_Azure_Storage')) {
        try {
            $storage = new Azure_Backup_Azure_Storage();
            if (method_exists($storage, 'upload_file')) {
                $uploaded = $storage->upload_file($tmp, $blob);
            } elseif (method_exists($storage, 'upload')) {
                $uploaded = $storage->upload($tmp, $blob);
            }
        } catch (\Throwable $e) {
            $upload_err = $e->getMessage();
        }
    }

    // Direct REST upload if needed
    if (!$uploaded) {
        $sa  = class_exists('Azure_Settings') ? (Azure_Settings::get_setting('backup_storage_account_name', '') ?: Azure_Settings::get_setting('backup_storage_account', '')) : '';
        $key = class_exists('Azure_Settings') ? (Azure_Settings::get_setting('backup_storage_account_key', '') ?: Azure_Settings::get_setting('backup_storage_key', '')) : '';
        $cn  = class_exists('Azure_Settings') ? (Azure_Settings::get_setting('backup_storage_container_name', '') ?: Azure_Settings::get_setting('backup_container_name', 'wordpress-backups')) : 'wordpress-backups';
        if ($sa && $key) {
            $url = sprintf('https://%s.blob.core.windows.net/%s/%s', $sa, $cn ?: 'wordpress-backups', $blob);
            $date = gmdate('D, d M Y H:i:s T');
            $size = filesize($tmp);
            $canonical = "PUT\n\napplication/octet-stream\n\nx-ms-blob-type:BlockBlob\nx-ms-date:{$date}\nx-ms-version:2020-10-02\n/{$sa}/" . ($cn ?: 'wordpress-backups') . "/{$blob}";
            // Use simpler SharedKey via az-like approach: x-ms-blob-type + date
            $header_res = "x-ms-blob-type:BlockBlob\nx-ms-date:{$date}\nx-ms-version:2020-10-02";
            $canonicalized_headers = "x-ms-blob-type:BlockBlob\nx-ms-date:{$date}\nx-ms-version:2020-10-02\n";
            $canonicalized_resource = "/{$sa}/" . ($cn ?: 'wordpress-backups') . "/{$blob}";
            $string_to_sign = "PUT\n\n\n{$size}\n\napplication/octet-stream\n\n\n\n\n\n\n{$canonicalized_headers}{$canonicalized_resource}";
            $sig = base64_encode(hash_hmac('sha256', $string_to_sign, base64_decode($key), true));
            $auth = "SharedKey {$sa}:{$sig}";
            $ch = curl_init($url);
            curl_setopt_array($ch, array(
                CURLOPT_CUSTOMREQUEST => 'PUT',
                CURLOPT_HTTPHEADER => array(
                    "x-ms-blob-type: BlockBlob",
                    "x-ms-date: {$date}",
                    "x-ms-version: 2020-10-02",
                    "Content-Type: application/octet-stream",
                    "Content-Length: {$size}",
                    "Authorization: {$auth}",
                ),
                CURLOPT_UPLOAD => true,
                CURLOPT_INFILE => fopen($tmp, 'rb'),
                CURLOPT_INFILESIZE => $size,
                CURLOPT_RETURNTRANSFER => true,
            ));
            $resp = curl_exec($ch);
            $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);
            $uploaded = ($code >= 200 && $code < 300);
            $upload_err = $uploaded ? null : "http_{$code}: " . substr((string) $resp, 0, 300);
        } else {
            $upload_err = 'no storage credentials';
        }
    }

    $state = array(
        'blob' => $blob,
        'size' => $size,
        'uploaded' => (bool) $uploaded,
        'upload_err' => $upload_err,
        'probe' => $out['probe'],
        'completed_at' => current_time('mysql'),
    );
    file_put_contents($state_file, wp_json_encode($state));
    $out['state'] = $state;
    $mysqli->close();
    echo wp_json_encode($out, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    exit;
});
