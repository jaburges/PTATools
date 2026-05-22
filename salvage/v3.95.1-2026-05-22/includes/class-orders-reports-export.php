<?php
/**
 * Orders Reports — Streaming CSV exporter.
 *
 * Writes a CSV-with-.xls-extension file to either a download stream (for
 * direct exports) or an arbitrary file handle (for scheduled email
 * attachments in Ship 2).
 *
 * Key properties:
 *   - UTF-8 BOM emitted as the first bytes so Excel auto-detects encoding.
 *   - CSV-injection guard: any cell whose first character is one of
 *     `=`, `+`, `-`, `@` is prefixed with a single quote.
 *   - Streamed row-by-row from the query generator — no full buffer.
 *   - One header row from the column registry labels.
 */

if (!defined('ABSPATH')) {
    exit;
}

class Azure_Orders_Reports_Export {

    /**
     * Stream the report to the HTTP response and exit. Used by the
     * direct-export admin-post handler.
     *
     * @param array $config  report config (filters, columns, granularity)
     * @param string $filename  filename for the download (no extension)
     * @return void  Sends Content-Disposition headers and exits.
     */
    public function stream_download(array $config, $filename = 'orders-report') {
        $safe_name = sanitize_file_name(($filename ?: 'orders-report') . '-' . date('Ymd-His') . '.xls');

        nocache_headers();
        header('Content-Type: application/vnd.ms-excel; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $safe_name . '"');
        header('X-Content-Type-Options: nosniff');

        $out = fopen('php://output', 'w');
        $this->write_to_handle($config, $out);
        fclose($out);
        exit;
    }

    /**
     * Write the report to a given file handle. Returns the number of
     * data rows written (excludes the header row).
     */
    public function write_to_handle(array $config, $handle) {
        if (!is_resource($handle)) {
            return 0;
        }
        // UTF-8 BOM so Excel opens the file as UTF-8 without prompting.
        fwrite($handle, "\xEF\xBB\xBF");

        $granularity = isset($config['granularity']) ? (string) $config['granularity'] : 'line_item';
        $registry    = Azure_Orders_Reports_Columns::all();
        $selected    = $this->normalise_columns($config, $registry, $granularity);

        // Header row.
        $headers = array();
        foreach ($selected as $col) {
            $headers[] = $col['label'];
        }
        fputcsv($handle, $headers);

        // Body.
        $query = new Azure_Orders_Reports_Query();
        $rows  = 0;
        foreach ($query->iter($config) as $tuple) {
            list($order, $item) = $tuple;
            $row = array();
            foreach ($selected as $col) {
                $val = '';
                try {
                    $val = call_user_func($col['resolver'], $order, $item, array());
                } catch (\Throwable $e) {
                    $val = '';
                }
                $row[] = self::guard_csv_cell((string) $val);
            }
            fputcsv($handle, $row);
            $rows++;

            if ($rows % 200 === 0 && function_exists('fflush')) {
                fflush($handle);
            }
        }
        return $rows;
    }

    /**
     * Resolve the config's `columns` list against the registry and the
     * active granularity. Drops unknown / mis-granularity columns
     * silently rather than erroring (saved reports remain usable when
     * Product Fields are renamed or removed).
     *
     * @return array<int,array<string,mixed>>  ordered list of column defs
     */
    private function normalise_columns(array $config, array $registry, $granularity) {
        $picked = isset($config['columns']) && is_array($config['columns']) ? $config['columns'] : array();
        $out = array();
        foreach ($picked as $entry) {
            $key = is_array($entry) ? (isset($entry['key']) ? (string) $entry['key'] : '') : (string) $entry;
            if ($key === '' || !isset($registry[$key])) continue;
            $col = $registry[$key];
            if (!in_array($granularity, $col['granularity'], true)) continue;
            $out[] = $col;
        }
        // Fall back to default if nothing valid picked.
        if (empty($out)) {
            foreach (Azure_Orders_Reports_Columns::default_columns_for_granularity($granularity) as $k) {
                if (isset($registry[$k])) {
                    $out[] = $registry[$k];
                }
            }
        }
        return $out;
    }

    private static function guard_csv_cell($value) {
        if ($value === '' || $value === null) return '';
        $first = substr($value, 0, 1);
        if (in_array($first, array('=', '+', '-', '@'), true)) {
            return "'" . $value;
        }
        return $value;
    }
}
