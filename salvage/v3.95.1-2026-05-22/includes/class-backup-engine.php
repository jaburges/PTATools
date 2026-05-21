<?php
/**
 * Backup Engine - handles creation of split backup archives.
 *
 * Inspired by UpdraftPlus patterns: separate zips per component,
 * configurable split size for large entities (uploads), gzipped
 * database dumps, and mysqldump-first strategy.
 */

if (!defined('ABSPATH')) {
    exit;
}

class Azure_Backup_Engine {

    private $split_every;
    private $backup_dir;
    private $backup_id;
    private $progress_callback;

    public function __construct($backup_id, $backup_dir, $progress_callback = null) {
        $this->backup_id = $backup_id;
        $this->backup_dir = $backup_dir;
        $this->progress_callback = $progress_callback;

        $split_mb = intval(Azure_Settings::get_setting('backup_split_size', 400));
        $this->split_every = max($split_mb, 25) * 1024 * 1024;
    }

    /**
     * Report progress to the caller.
     */
    private function progress($pct, $status, $message) {
        if (is_callable($this->progress_callback)) {
            call_user_func($this->progress_callback, $pct, $status, $message);
        }
    }

    // ------------------------------------------------------------------
    // Database backup
    // ------------------------------------------------------------------

    /**
     * Dump the database to a gzipped SQL file.
     * Tries native mysqldump first, falls back to PHP.
     *
     * @return string Path to the .sql.gz file.
     */
    public function backup_database() {
        $gz_path = $this->backup_dir . '/' . $this->backup_id . '-db.sql.gz';

        $mysqldump = $this->find_mysqldump();
        if ($mysqldump) {
            Azure_Logger::info('Backup Engine: Using mysqldump binary: ' . $mysqldump, 'Backup');
            $ok = $this->dump_with_mysqldump($mysqldump, $gz_path);
            if ($ok) {
                return $gz_path;
            }
            Azure_Logger::warning('Backup Engine: mysqldump failed, falling back to PHP', 'Backup');
        }

        $this->dump_with_php($gz_path);
        return $gz_path;
    }

    private function find_mysqldump() {
        $candidates = array('mysqldump');
        if (defined('UPDRAFTPLUS_MYSQLDUMP_EXECUTABLE')) {
            array_unshift($candidates, UPDRAFTPLUS_MYSQLDUMP_EXECUTABLE);
        }

        if (DIRECTORY_SEPARATOR === '\\') {
            $candidates[] = 'C:\\Program Files\\MySQL\\MySQL Server 8.0\\bin\\mysqldump.exe';
            $candidates[] = 'C:\\Program Files\\MariaDB 10\\bin\\mysqldump.exe';
        } else {
            $candidates[] = '/usr/bin/mysqldump';
            $candidates[] = '/usr/local/bin/mysqldump';
            $candidates[] = '/usr/local/mysql/bin/mysqldump';
        }

        foreach ($candidates as $path) {
            $test = @shell_exec(escapeshellarg($path) . ' --version 2>&1');
            if ($test && stripos($test, 'mysqldump') !== false) {
                return $path;
            }
        }
        return null;
    }

    private function dump_with_mysqldump($binary, $gz_path) {
        $creds = $this->get_db_credentials();

        $tmp_defaults = tempnam(sys_get_temp_dir(), 'myd');
        $defaults_content = "[client]\nuser={$creds['user']}\npassword={$creds['pass']}\n";
        if ($creds['host']) {
            $defaults_content .= "host={$creds['host']}\n";
        }
        if ($creds['port']) {
            $defaults_content .= "port={$creds['port']}\n";
        }
        file_put_contents($tmp_defaults, $defaults_content);

        $cmd = escapeshellarg($binary)
             . ' --defaults-extra-file=' . escapeshellarg($tmp_defaults)
             . ' --quote-names --add-drop-table --skip-lock-tables'
             . ' --extended-insert --max_allowed_packet=1M'
             . ' ' . escapeshellarg($creds['name']);

        $descriptors = array(
            1 => array('pipe', 'w'),
            2 => array('pipe', 'w'),
        );

        $process = @proc_open($cmd, $descriptors, $pipes);
        if (!is_resource($process)) {
            @unlink($tmp_defaults);
            return false;
        }

        $gz = gzopen($gz_path, 'wb9');
        if (!$gz) {
            proc_close($process);
            @unlink($tmp_defaults);
            return false;
        }

        gzwrite($gz, "-- WordPress Database Backup (mysqldump)\n");
        gzwrite($gz, "-- Generated: " . date('Y-m-d H:i:s') . "\n");
        gzwrite($gz, "-- Site: " . get_site_url() . "\n\n");

        while (!feof($pipes[1])) {
            $chunk = fread($pipes[1], 65536);
            if ($chunk !== false && strlen($chunk) > 0) {
                gzwrite($gz, $chunk);
            }
            @set_time_limit(120);
        }

        fclose($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[2]);
        $exit = proc_close($process);
        gzclose($gz);
        @unlink($tmp_defaults);

        if ($exit !== 0) {
            Azure_Logger::error('Backup Engine: mysqldump exit code ' . $exit . ': ' . $stderr, 'Backup');
            @unlink($gz_path);
            return false;
        }

        Azure_Logger::info('Backup Engine: mysqldump completed, size: ' . size_format(filesize($gz_path)), 'Backup');
        return true;
    }

    private function dump_with_php($gz_path) {
        global $wpdb;

        $gz = gzopen($gz_path, 'wb6');
        if (!$gz) {
            throw new Exception('Failed to open database backup file for writing.');
        }

        $tables = $wpdb->get_results('SHOW TABLES', ARRAY_N);
        if (empty($tables)) {
            gzclose($gz);
            throw new Exception('No database tables found.');
        }

        gzwrite($gz, "-- WordPress Database Backup (PHP)\n");
        gzwrite($gz, "-- Generated: " . date('Y-m-d H:i:s') . "\n");
        gzwrite($gz, "-- Site: " . get_site_url() . "\n\n");
        gzwrite($gz, "SET foreign_key_checks = 0;\n\n");

        $total_tables = count($tables);

        foreach ($tables as $idx => $table) {
            $table_name = $table[0];
            $this->progress(null, 'running', "Dumping table: {$table_name} (" . ($idx + 1) . "/{$total_tables})");

            $create = $wpdb->get_row("SHOW CREATE TABLE `{$table_name}`", ARRAY_N);
            gzwrite($gz, "DROP TABLE IF EXISTS `{$table_name}`;\n");
            gzwrite($gz, $create[1] . ";\n\n");

            $max_packet = 1 * 1024 * 1024;
            $batch_size = 500;
            $offset = 0;

            while (true) {
                $rows = $wpdb->get_results(
                    $wpdb->prepare("SELECT * FROM `{$table_name}` LIMIT %d OFFSET %d", $batch_size, $offset),
                    ARRAY_A
                );
                if (empty($rows)) {
                    break;
                }

                $value_strings = array();
                $current_size = 0;

                foreach ($rows as $row) {
                    $values = array();
                    foreach ($row as $value) {
                        if (is_null($value)) {
                            $values[] = 'NULL';
                        } else {
                            $values[] = "'" . esc_sql($value) . "'";
                        }
                    }
                    $row_str = '(' . implode(',', $values) . ')';
                    $row_len = strlen($row_str);

                    if (!empty($value_strings) && ($current_size + $row_len) > $max_packet) {
                        gzwrite($gz, "INSERT INTO `{$table_name}` VALUES " . implode(',', $value_strings) . ";\n");
                        $value_strings = array();
                        $current_size = 0;
                    }

                    $value_strings[] = $row_str;
                    $current_size += $row_len;
                }

                if (!empty($value_strings)) {
                    gzwrite($gz, "INSERT INTO `{$table_name}` VALUES " . implode(',', $value_strings) . ";\n");
                }

                $offset += $batch_size;
                if (count($rows) < $batch_size) {
                    break;
                }
                @set_time_limit(120);
            }

            gzwrite($gz, "\n");
        }

        gzwrite($gz, "SET foreign_key_checks = 1;\n");
        gzclose($gz);

        Azure_Logger::info('Backup Engine: PHP DB dump completed, size: ' . size_format(filesize($gz_path)), 'Backup');
    }

    // ------------------------------------------------------------------
    // File-entity backups (plugins, themes, uploads, others)
    // ------------------------------------------------------------------

    /**
     * Create zip archives for a file entity with automatic splitting.
     *
     * @param string $entity    Entity name: plugins, themes, uploads, others
     * @param string $source    Source directory path.
     * @param array  $exclude   Directory/file patterns to exclude.
     * @param array  $selected  Optional list of subdirs to include (for granular plugin/theme selection).
     * @return array  List of archive file paths created.
     */
    public function backup_entity($entity, $source, $exclude = array(), $selected = array()) {
        if (!is_dir($source)) {
            Azure_Logger::warning("Backup Engine: Source directory not found: {$source}", 'Backup');
            return array();
        }

        $archives = array();
        $index = 0;
        $zip = null;
        $zip_path = null;
        $zip_size = 0;
        $file_count = 0;
        $last_heartbeat = time();

        $close_zip = function () use (&$zip, &$zip_path, &$archives, $entity) {
            if ($zip === null) return;

            $path = $zip_path;
            $result = $zip->close();
            $zip = null;
            clearstatcache(true, $path);

            if (!$result || !file_exists($path) || filesize($path) === 0) {
                Azure_Logger::warning("Backup Engine: Archive for '{$entity}' failed to finalize or was empty — this can happen if files were unreadable, disk space ran out, or the directory had no eligible files. Skipping this archive and continuing backup. Path: {$path}", 'Backup');
                @unlink($path);
                $archives = array_values(array_filter($archives, function ($p) use ($path) {
                    return $p !== $path;
                }));
            }
        };

        $open_new_zip = function () use (&$zip, &$zip_path, &$zip_size, &$index, &$archives, &$close_zip, $entity) {
            if ($zip !== null) {
                $this->progress(null, 'running', "Compressing {$entity} split archive...");
                @set_time_limit(900);
                $close_zip();
                $this->progress(null, 'running', "Split archive closed, continuing {$entity}...");
            }

            $index++;
            $suffix = $index === 1 ? '' : $index;
            $name = $this->backup_id . '-' . $entity . $suffix . '.zip';
            $zip_path = $this->backup_dir . '/' . $name;
            $archives[] = $zip_path;
            $zip_size = 0;

            $zip = new ZipArchive();
            $result = $zip->open($zip_path, ZipArchive::CREATE | ZipArchive::OVERWRITE);
            if ($result !== true) {
                throw new Exception("Failed to create zip archive: {$name} (error {$result})");
            }
        };

        $open_new_zip();

        try {
            $files = $this->collect_files($source, $exclude, $selected);
            $total_files = count($files);

            foreach ($files as $i => $file_info) {
                list($abs_path, $rel_path) = $file_info;

                if (!is_readable($abs_path)) {
                    continue;
                }

                $fsize = @filesize($abs_path);
                if ($fsize === false) {
                    continue;
                }

                // Split if adding this file exceeds threshold and zip already has content
                if ($zip_size > 0 && ($zip_size + $fsize) > $this->split_every) {
                    $open_new_zip();
                }

                $zip->addFile($abs_path, $rel_path);
                $zip_size += $fsize;
                $file_count++;

                // Heartbeat every 15 seconds
                if ((time() - $last_heartbeat) >= 15) {
                    $this->progress(null, 'running', "Archiving {$entity}: {$file_count}/{$total_files} files");
                    @set_time_limit(600);
                    $last_heartbeat = time();
                }
            }
        } finally {
            if ($zip !== null) {
                $this->progress(null, 'running', "Compressing {$entity} archive ({$file_count} files)...");
                @set_time_limit(900);
                $close_zip();
                $this->progress(null, 'running', "Finished compressing {$entity}");
            }
        }

        Azure_Logger::info("Backup Engine: {$entity} archived - {$file_count} files in " . count($archives) . " archive(s)", 'Backup');
        return $archives;
    }

    /**
     * Collect files from a source directory, applying exclusions and selections.
     *
     * @return array Array of [absolute_path, relative_path] pairs.
     */
    private function collect_files($source, $exclude = array(), $selected = array()) {
        $files = array();
        $source = rtrim($source, '/\\');

        // If specific subdirs are selected, only iterate those
        $roots = array();
        if (!empty($selected)) {
            foreach ($selected as $slug) {
                $sub = $source . DIRECTORY_SEPARATOR . $slug;
                if (is_dir($sub)) {
                    $roots[] = $sub;
                } elseif (file_exists($source . DIRECTORY_SEPARATOR . $slug . '.php')) {
                    $files[] = array(
                        $source . DIRECTORY_SEPARATOR . $slug . '.php',
                        $slug . '.php'
                    );
                }
            }
        } else {
            $roots[] = $source;
        }

        foreach ($roots as $root) {
            try {
                $dir = new RecursiveDirectoryIterator($root, RecursiveDirectoryIterator::SKIP_DOTS);
                $iter = new RecursiveIteratorIterator(
                    $dir,
                    RecursiveIteratorIterator::SELF_FIRST,
                    RecursiveIteratorIterator::CATCH_GET_CHILD
                );

                foreach ($iter as $item) {
                    try {
                        if (!$item->isFile()) {
                            continue;
                        }

                        $abs = $item->getPathname();
                        $rel = ltrim(str_replace($source, '', $abs), '/\\');
                        $rel = str_replace('\\', '/', $rel);

                        if ($this->should_exclude($rel, $exclude)) {
                            continue;
                        }

                        if ($item->isLink() && !file_exists($item->getRealPath())) {
                            continue;
                        }

                        $size = @$item->getSize();
                        if ($size === false || $size > 100 * 1024 * 1024) {
                            continue;
                        }

                        $files[] = array($abs, $rel);
                    } catch (\Exception $e) {
                        continue;
                    }
                }
            } catch (\Exception $e) {
                Azure_Logger::warning("Backup Engine: Error iterating {$root}: " . $e->getMessage(), 'Backup');
            }
        }

        return $files;
    }

    private function should_exclude($relative_path, $exclude_patterns) {
        $sep = '/';
        foreach ($exclude_patterns as $pattern) {
            if (strpos($relative_path, $sep . $pattern . $sep) !== false
                || strpos($relative_path, $pattern . $sep) === 0
                || $relative_path === $pattern) {
                return true;
            }
            if (substr($pattern, -1) === '_' && strpos($relative_path, $pattern) !== false) {
                return true;
            }
        }
        return false;
    }

    // ------------------------------------------------------------------
    // Manifest
    // ------------------------------------------------------------------

    /**
     * Build a manifest JSON describing the backup set.
     *
     * @param array $components Assoc array: entity => ['files' => [...], 'status' => ...]
     * @return string Path to the manifest file.
     */
    public function generate_manifest($components) {
        $manifest = array(
            'version'      => '2.0',
            'plugin'       => 'AzureSSO',
            'site_url'     => get_site_url(),
            'home_url'     => get_home_url(),
            'wp_version'   => get_bloginfo('version'),
            'php_version'  => PHP_VERSION,
            'timestamp'    => gmdate('c'),
            'backup_id'    => $this->backup_id,
            'components'   => $components,
        );

        $path = $this->backup_dir . '/' . $this->backup_id . '-manifest.json';
        file_put_contents($path, json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        return $path;
    }

    // ------------------------------------------------------------------
    // Helpers
    // ------------------------------------------------------------------

    private function get_db_credentials() {
        $host = DB_HOST;
        $port = null;

        if (strpos($host, ':') !== false) {
            list($host, $port) = explode(':', $host, 2);
        }

        return array(
            'host' => $host,
            'port' => $port,
            'user' => DB_USER,
            'pass' => DB_PASSWORD,
            'name' => DB_NAME,
        );
    }

    /**
     * Get the default exclusion list for wp-content "others" backup.
     */
    public static function get_others_exclusions() {
        return array(
            'uploads', 'plugins', 'themes', 'mu-plugins',
            'cache', 'backup', 'backups', 'temp_backup_',
            'upgrade', 'wflogs', 'debug.log', 'ai1wm-backups',
            'updraft', 'node_modules', '.git',
        );
    }

    /**
     * Get the default exclusion list for plugins backup.
     */
    public static function get_plugin_exclusions() {
        return array('node_modules', '.git', 'cache', 'backups', 'temp_backup_');
    }
}
