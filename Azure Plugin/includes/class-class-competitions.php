<?php
/**
 * Class competitions: purchases by teacher, never dollars.
 *
 * Teachers, grades, and class sizes are edited under System → Classes.
 * That roster is the source of truth. Child Info’s teacher dropdown is
 * filled from it. Each qualifying line item counts once for the teacher
 * saved on that order item.
 */

if (!defined('ABSPATH')) {
    exit;
}

class Azure_Class_Competitions {

    const SIZES_KEY = 'donations_class_sizes';
    const ROSTER_KEY = 'class_roster';
    const COMPS_KEY = 'donations_class_competitions';

    /** @var array<int,array{name:string,grade:string,students:int}>|null */
    private static $roster_cache = null;

    public static function normalize_teacher($name) {
        $name = strtolower(trim(preg_replace('/\s+/', ' ', (string) $name)));
        return $name;
    }

    /**
     * One grade, or a mixed class such as 4/5. Blank stays blank.
     *
     * @param mixed $raw
     * @return string
     */
    public static function sanitize_grade_level($raw) {
        $raw = trim((string) $raw);
        if ($raw === '') {
            return '';
        }
        $parts = preg_split('#[/,]#', $raw);
        $out = array();
        foreach ((array) $parts as $part) {
            $part = strtolower(trim((string) $part));
            $part = preg_replace('/\s+/', '', $part);
            if ($part === 'k' || $part === 'kindergarten') {
                $token = 'K';
            } elseif ($part === 'prek' || $part === 'pre-k' || $part === 'pk') {
                $token = 'PreK';
            } elseif (preg_match('/^(\d{1,2})(st|nd|rd|th)?$/', $part, $m)) {
                $n = (int) $m[1];
                if ($n < 1 || $n > 12) {
                    continue;
                }
                $token = (string) $n;
            } else {
                continue;
            }
            if (!in_array($token, $out, true)) {
                $out[] = $token;
            }
        }
        return implode('/', $out);
    }

    /**
     * @param mixed $raw
     * @return array<int,array{name:string,grade:string,students:int}>
     */
    public static function sanitize_roster($raw) {
        $out = array();
        $seen = array();
        if (!is_array($raw)) {
            return $out;
        }
        foreach ($raw as $row) {
            if (!is_array($row)) {
                continue;
            }
            $name = isset($row['name']) ? sanitize_text_field($row['name']) : '';
            $name = trim(preg_replace('/\s+/', ' ', $name));
            if ($name === '') {
                continue;
            }
            $key = self::normalize_teacher($name);
            if ($key === '' || isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $students = isset($row['students']) ? (int) $row['students'] : 0;
            if ($students < 0) {
                $students = 0;
            }
            if ($students > 500) {
                $students = 500;
            }
            $out[] = array(
                'name'     => $name,
                'grade'    => self::sanitize_grade_level(isset($row['grade']) ? $row['grade'] : ''),
                'students' => $students,
            );
        }
        return $out;
    }

    /**
     * Teacher names in roster order. Seeds once from the old Child Info
     * dropdown and the saved student counts when no roster exists yet.
     *
     * @return string[]
     */
    public static function teacher_list() {
        $names = array();
        foreach (self::get_roster() as $row) {
            $names[] = $row['name'];
        }
        return $names;
    }

    /**
     * @return array<int,array{name:string,grade:string,students:int}>
     */
    public static function get_roster() {
        if (self::$roster_cache !== null) {
            return self::$roster_cache;
        }
        if (!class_exists('Azure_Settings')) {
            return array();
        }
        $all = Azure_Settings::get_all_settings();
        if (is_array($all) && array_key_exists(self::ROSTER_KEY, $all) && is_array($all[self::ROSTER_KEY])) {
            self::$roster_cache = self::sanitize_roster($all[self::ROSTER_KEY]);
            return self::$roster_cache;
        }
        $seeded = self::seed_roster_from_legacy();
        if ($seeded === null) {
            return array();
        }
        self::persist_roster($seeded, false);
        return self::$roster_cache;
    }

    /**
     * @param mixed $raw
     * @return array<int,array{name:string,grade:string,students:int}>
     */
    public static function save_roster($raw) {
        $roster = self::sanitize_roster($raw);
        self::persist_roster($roster, true);
        return $roster;
    }

    /**
     * Update student counts without touching names or grades.
     *
     * @param mixed $raw
     */
    public static function apply_student_counts($raw) {
        $roster = self::get_roster();
        $names = array();
        foreach ($roster as $row) {
            $names[] = $row['name'];
        }
        $sizes = self::sanitize_class_sizes($raw, $names);
        foreach ($roster as $i => $row) {
            if (isset($sizes[$row['name']])) {
                $roster[$i]['students'] = (int) $sizes[$row['name']];
            }
        }
        self::persist_roster($roster, true);
    }

    /**
     * @param array<int,array{name:string,grade:string,students:int}> $roster
     * @param bool $sync_empty An explicit save may clear the dropdown. A seed must not.
     */
    private static function persist_roster($roster, $sync_empty = false) {
        self::$roster_cache = $roster;
        if (!class_exists('Azure_Settings')) {
            return;
        }
        $names = array();
        $sizes = array();
        foreach ($roster as $row) {
            $names[] = $row['name'];
            $sizes[$row['name']] = (int) $row['students'];
        }
        Azure_Settings::update_setting(self::ROSTER_KEY, $roster);
        Azure_Settings::update_setting(self::SIZES_KEY, $sizes);
        if (class_exists('Azure_Product_Fields_Module') && ($names || $sync_empty)) {
            Azure_Product_Fields_Module::sync_teacher_options($names);
        }
    }

    /**
     * @return array<int,array{name:string,grade:string,students:int}>|null
     */
    private static function seed_roster_from_legacy() {
        $stored = Azure_Settings::get_setting(self::SIZES_KEY, array());
        if (!is_array($stored)) {
            $stored = array();
        }
        $from_field = null;
        if (class_exists('Azure_Product_Fields_Module')) {
            $from_field = Azure_Product_Fields_Module::product_field_teacher_names();
        }
        if ($from_field === null && !$stored) {
            return null;
        }
        $names = is_array($from_field) ? $from_field : array();
        if (!$names && $stored) {
            $names = array_keys($stored);
        }
        $rows = array();
        foreach ($names as $name) {
            $name = trim((string) $name);
            if ($name === '') {
                continue;
            }
            $students = 0;
            foreach ($stored as $key => $count) {
                if (self::normalize_teacher($key) === self::normalize_teacher($name)) {
                    $students = (int) $count;
                    break;
                }
            }
            $rows[] = array(
                'name'     => $name,
                'grade'    => '',
                'students' => $students,
            );
        }
        return self::sanitize_roster($rows);
    }

    public static function sanitize_class_sizes($raw, $teachers) {
        $out = array();
        if (!is_array($raw)) {
            $raw = array();
        }
        $teachers = is_array($teachers) ? $teachers : array();
        $by_key = array();
        foreach ($raw as $teacher => $size) {
            $by_key[self::normalize_teacher($teacher)] = (int) $size;
        }
        foreach ($teachers as $teacher) {
            $teacher = trim((string) $teacher);
            if ($teacher === '') {
                continue;
            }
            $size = isset($by_key[self::normalize_teacher($teacher)])
                ? (int) $by_key[self::normalize_teacher($teacher)]
                : 0;
            if ($size < 0) {
                $size = 0;
            }
            if ($size > 500) {
                $size = 500;
            }
            $out[$teacher] = $size;
        }
        return $out;
    }

    public static function get_class_sizes() {
        $roster = self::get_roster();
        if ($roster) {
            $out = array();
            foreach ($roster as $row) {
                $out[$row['name']] = (int) $row['students'];
            }
            return $out;
        }
        $stored = class_exists('Azure_Settings') ? Azure_Settings::get_setting(self::SIZES_KEY, array()) : array();
        return self::sanitize_class_sizes($stored, self::teacher_list());
    }

    /**
     * Sum of the class-size list. Pass a map to total a known set
     * without reading settings.
     *
     * @param array<string, int>|null $sizes
     */
    public static function student_total($sizes = null) {
        if ($sizes === null) {
            $sizes = self::get_class_sizes();
        }
        $total = 0;
        foreach ((array) $sizes as $size) {
            $total += max(0, (int) $size);
        }
        return $total;
    }

    public static function sanitize_competitions($raw) {
        $out = array();
        if (!is_array($raw)) {
            return $out;
        }
        $seen = array();
        foreach ($raw as $row) {
            if (!is_array($row)) {
                continue;
            }
            $id = isset($row['id']) ? (int) $row['id'] : 0;
            $name = isset($row['name']) ? sanitize_text_field($row['name']) : '';
            $type = isset($row['source_type']) ? sanitize_text_field($row['source_type']) : '';
            if ($type !== 'product' && $type !== 'campaign') {
                continue;
            }
            $source_id = isset($row['source_id']) ? (int) $row['source_id'] : 0;
            if ($name === '' || $source_id <= 0) {
                continue;
            }
            $show_count = !empty($row['show_count']);
            $show_percent = !empty($row['show_percent']);
            if (!$show_count && !$show_percent) {
                $show_count = true;
            }
            if ($id <= 0 || isset($seen[$id])) {
                $id = empty($seen) ? 1 : (max(array_keys($seen)) + 1);
            }
            $seen[$id] = true;
            $out[] = array(
                'id'           => $id,
                'name'         => $name,
                'source_type'  => $type,
                'source_id'    => $source_id,
                'show_count'   => $show_count,
                'show_percent' => $show_percent,
            );
        }
        return $out;
    }

    public static function get_competitions() {
        return self::sanitize_competitions(
            Azure_Settings::get_setting(self::COMPS_KEY, array())
        );
    }

    public static function get_competition($id) {
        $id = (int) $id;
        foreach (self::get_competitions() as $row) {
            if ((int) $row['id'] === $id) {
                return $row;
            }
        }
        return null;
    }

    /**
     * @param int $count
     * @param int $size
     * @return int|null
     */
    public static function percent($count, $size) {
        $count = (int) $count;
        $size = (int) $size;
        if ($size <= 0) {
            return null;
        }
        return (int) min(100, round(100 * $count / $size));
    }

    /**
     * Build leaderboard rows. Never includes money.
     *
     * @param string[] $teachers  Canonical teacher names.
     * @param array    $sizes     teacher => int
     * @param array    $purchases [{teacher, grade}] one entry per line item
     * @param bool     $show_count
     * @param bool     $show_percent
     * @return array
     */
    public static function build_rows($teachers, $sizes, $purchases, $show_count, $show_percent) {
        $rows = array();
        $show_count = (bool) $show_count;
        $show_percent = (bool) $show_percent;
        if (!$show_count && !$show_percent) {
            $show_count = true;
        }

        $counts = array();
        $grades = array();
        foreach ((array) $purchases as $purchase) {
            $key = self::normalize_teacher(isset($purchase['teacher']) ? $purchase['teacher'] : '');
            if ($key === '') {
                continue;
            }
            if (!isset($counts[$key])) {
                $counts[$key] = 0;
                $grades[$key] = array();
            }
            $counts[$key]++;
            $grade = isset($purchase['grade']) ? trim((string) $purchase['grade']) : '';
            if ($grade !== '') {
                $grades[$key][$grade] = true;
            }
        }

        foreach ((array) $teachers as $teacher) {
            $teacher = trim((string) $teacher);
            if ($teacher === '') {
                continue;
            }
            $key = self::normalize_teacher($teacher);
            $count = isset($counts[$key]) ? (int) $counts[$key] : 0;
            $size = 0;
            if (isset($sizes[$teacher])) {
                $size = (int) $sizes[$teacher];
            } else {
                foreach ((array) $sizes as $name => $n) {
                    if (self::normalize_teacher($name) === $key) {
                        $size = (int) $n;
                        break;
                    }
                }
            }
            $grade_list = isset($grades[$key]) ? implode(', ', array_keys($grades[$key])) : '';
            $pct = $show_percent ? self::percent($count, $size) : null;
            $rows[] = array(
                'teacher'      => $teacher,
                'grade'        => $grade_list,
                'count'        => $count,
                'size'         => $size,
                'percent'      => $pct,
                'show_count'   => $show_count,
                'show_percent' => $show_percent,
            );
        }

        usort($rows, function ($a, $b) {
            $ap = $a['percent'] === null ? -1 : $a['percent'];
            $bp = $b['percent'] === null ? -1 : $b['percent'];
            if ($bp !== $ap) {
                return $bp - $ap;
            }
            if ($b['count'] !== $a['count']) {
                return $b['count'] - $a['count'];
            }
            return strcasecmp($a['teacher'], $b['teacher']);
        });

        return $rows;
    }

    public static function competition_rows($competition) {
        if (!is_array($competition)) {
            return array();
        }
        return self::build_rows(
            self::teacher_list(),
            self::get_class_sizes(),
            self::load_purchases($competition),
            !empty($competition['show_count']),
            !empty($competition['show_percent'])
        );
    }

    public static function is_wag_campaign_source($competition) {
        if (!is_array($competition) || (isset($competition['source_type']) ? $competition['source_type'] : '') !== 'campaign') {
            return false;
        }
        $id = isset($competition['source_id']) ? (int) $competition['source_id'] : 0;
        if ($id <= 0 || !class_exists('Azure_Donations_Module')) {
            return false;
        }
        return $id === (int) Azure_Donations_Module::get_wag_campaign_id();
    }

    /**
     * Teacher + grade from WooCommerce line-item meta (product fields).
     *
     * @param array $meta meta_key => meta_value
     * @return array{teacher:string,grade:string}|null
     */
    public static function purchase_from_item_meta($meta) {
        if (!is_array($meta)) {
            return null;
        }
        $teacher = '';
        $grade = '';

        $raw = self::maybe_unserialize_meta(isset($meta['_azure_product_fields_raw']) ? $meta['_azure_product_fields_raw'] : null);
        if (is_array($raw)) {
            foreach ($raw as $field) {
                if (!is_array($field)) {
                    continue;
                }
                $val = isset($field['value']) ? trim((string) $field['value']) : '';
                if ($val === '') {
                    continue;
                }
                $hay = strtolower(
                    (isset($field['field_key']) ? $field['field_key'] : '') . ' '
                    . (isset($field['label']) ? $field['label'] : '')
                );
                if (strpos($hay, 'teacher') !== false) {
                    $teacher = $val;
                } elseif (strpos($hay, 'grade') !== false || preg_match('/\byear\b/', $hay)) {
                    $grade = $val;
                }
            }
        }

        $children = self::maybe_unserialize_meta(isset($meta['_azure_pf_children']) ? $meta['_azure_pf_children'] : null);
        if (is_array($children)) {
            foreach ($children as $child) {
                if (!is_array($child)) {
                    continue;
                }
                if ($teacher === '' && !empty($child['teacher'])) {
                    $teacher = trim((string) $child['teacher']);
                }
                if ($grade === '' && !empty($child['grade'])) {
                    $grade = trim((string) $child['grade']);
                }
            }
        }

        foreach ($meta as $key => $value) {
            if (!is_scalar($value) && $value !== null) {
                continue;
            }
            $val = trim((string) $value);
            if ($val === '') {
                continue;
            }
            $hay = strtolower((string) $key);
            if ($teacher === '' && strpos($hay, 'teacher') !== false) {
                $teacher = $val;
            } elseif ($grade === '' && (strpos($hay, 'grade') !== false || strpos($hay, 'childsgrade') !== false)) {
                $grade = $val;
            }
        }

        if ($teacher === '') {
            return null;
        }
        return array(
            'teacher' => $teacher,
            'grade'   => $grade,
        );
    }

    /**
     * @param array $competition
     * @return array [{teacher, grade}]
     */
    public static function load_purchases($competition) {
        $item_ids = self::qualifying_order_item_ids($competition);
        return self::purchases_from_order_item_ids($item_ids);
    }

    /**
     * Paid line items that belong to the competition source.
     * WAG campaigns use the same mapped products as the progress bar.
     *
     * @param array $competition
     * @return int[]
     */
    public static function qualifying_order_item_ids($competition) {
        global $wpdb;
        $out = array();
        if (!is_array($competition) || !$wpdb) {
            return $out;
        }
        $type = isset($competition['source_type']) ? $competition['source_type'] : '';
        $source_id = isset($competition['source_id']) ? (int) $competition['source_id'] : 0;
        if ($source_id <= 0) {
            return $out;
        }

        $product_ids = array();
        $variation_ids = array();
        if ($type === 'product') {
            $product_ids[] = $source_id;
            $variation_ids[] = $source_id;
        } elseif ($type === 'campaign' && self::is_wag_campaign_source($competition) && class_exists('Azure_Donations_Module')) {
            $mapped = Azure_Donations_Module::wag_mapped_ids();
            $product_ids = isset($mapped['products']) ? $mapped['products'] : array();
            $variation_ids = isset($mapped['variations']) ? $mapped['variations'] : array();
        }

        foreach (self::paid_line_item_ids_for_catalog($product_ids, $variation_ids) as $id) {
            $out[(int) $id] = true;
        }

        if ($type === 'campaign') {
            foreach (self::paid_line_item_ids_for_campaign_records($source_id) as $id) {
                $out[(int) $id] = true;
            }
        }

        return array_map('intval', array_keys($out));
    }

    /**
     * @param int[] $item_ids
     * @return array
     */
    public static function purchases_from_order_item_ids($item_ids) {
        global $wpdb;
        $item_ids = array_values(array_filter(array_map('intval', (array) $item_ids)));
        if (empty($item_ids) || !$wpdb) {
            return array();
        }
        $meta_table = $wpdb->prefix . 'woocommerce_order_itemmeta';
        if ($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $meta_table)) !== $meta_table) {
            return array();
        }
        $in = implode(',', $item_ids);
        $rows = $wpdb->get_results("SELECT order_item_id, meta_key, meta_value FROM {$meta_table} WHERE order_item_id IN ({$in})");
        $by_item = array();
        foreach ((array) $rows as $row) {
            $id = (int) $row->order_item_id;
            if (!isset($by_item[$id])) {
                $by_item[$id] = array();
            }
            $by_item[$id][$row->meta_key] = $row->meta_value;
        }
        $out = array();
        foreach ($item_ids as $id) {
            $purchase = self::purchase_from_item_meta(isset($by_item[$id]) ? $by_item[$id] : array());
            if ($purchase) {
                $out[] = $purchase;
            }
        }
        return $out;
    }

    /**
     * @param int[] $product_ids
     * @param int[] $variation_ids
     * @return int[]
     */
    public static function paid_line_item_ids_for_catalog($product_ids, $variation_ids) {
        $product_ids = array_values(array_unique(array_filter(array_map('intval', (array) $product_ids))));
        $variation_ids = array_values(array_unique(array_filter(array_map('intval', (array) $variation_ids))));
        if (empty($product_ids) && empty($variation_ids)) {
            return array();
        }
        $match = array();
        if (!empty($product_ids)) {
            $match[] = 'pm.meta_value IN (' . implode(',', $product_ids) . ')';
        }
        if (!empty($variation_ids)) {
            $match[] = 'vm.meta_value IN (' . implode(',', $variation_ids) . ')';
        }
        return self::paid_order_item_ids(implode(' OR ', $match));
    }

    /**
     * @param int $campaign_id
     * @return int[]
     */
    public static function paid_line_item_ids_for_campaign_records($campaign_id) {
        global $wpdb;
        $campaign_id = (int) $campaign_id;
        if ($campaign_id <= 0 || !$wpdb || !class_exists('Azure_Database')) {
            return array();
        }
        $records = Azure_Database::get_table_name('donation_records');
        if (!$records) {
            return array();
        }
        $match = "EXISTS (
            SELECT 1 FROM {$records} r
            WHERE r.campaign_id = " . $campaign_id . "
              AND r.order_id = i.order_id
              AND r.product_id > 0
              AND (r.product_id = pm.meta_value OR r.product_id = vm.meta_value)
        )";
        return self::paid_order_item_ids($match);
    }

    /**
     * Paid line items matching $match_sql, read from the order item tables.
     *
     * Deliberately not WooCommerce's `wc_order_product_lookup`: that table is
     * filled by the Analytics batch (`wc-admin_process_pending_orders_batch`),
     * which on this site runs roughly twice a day, so the board sat hours
     * behind checkout and parents saw donations missing. The order item rows
     * exist the moment an order is paid. `pm` / `vm` are the line item's
     * `_product_id` and `_variation_id`.
     *
     * @param string $match_sql already-safe fragment, matching on pm/vm/i
     * @return int[]
     */
    private static function paid_order_item_ids($match_sql) {
        global $wpdb;
        if (!$wpdb || $match_sql === '') {
            return array();
        }
        $items = $wpdb->prefix . 'woocommerce_order_items';
        if ($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $items)) !== $items) {
            return array();
        }
        $itemmeta = $wpdb->prefix . 'woocommerce_order_itemmeta';
        $orders = $wpdb->prefix . 'wc_orders';
        $paid = "'wc-completed','wc-processing'";
        if ($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $orders)) === $orders) {
            $join = "INNER JOIN {$orders} o ON o.id = i.order_id";
            $where = "o.type = 'shop_order' AND o.status IN ({$paid})";
        } else {
            $join = "INNER JOIN {$wpdb->posts} o ON o.ID = i.order_id";
            $where = "o.post_status IN ({$paid})";
        }
        $sql = "SELECT i.order_item_id
                FROM {$items} i
                {$join}
                LEFT JOIN {$itemmeta} pm
                  ON pm.order_item_id = i.order_item_id AND pm.meta_key = '_product_id'
                LEFT JOIN {$itemmeta} vm
                  ON vm.order_item_id = i.order_item_id AND vm.meta_key = '_variation_id'
                WHERE i.order_item_type = 'line_item'
                  AND {$where}
                  AND ({$match_sql})";
        $found = $wpdb->get_col($sql);
        return array_values(array_unique(array_filter(array_map('intval', (array) $found))));
    }

    private static function maybe_unserialize_meta($value) {
        if ($value === null || $value === '') {
            return $value;
        }
        if (function_exists('maybe_unserialize')) {
            return maybe_unserialize($value);
        }
        if (!is_string($value)) {
            return $value;
        }
        $trim = trim($value);
        if ($trim === '' || ($trim[0] !== 'a' && $trim[0] !== 'O')) {
            return $value;
        }
        $out = @unserialize($trim);
        return $out === false ? $value : $out;
    }

    /**
     * Lane tint + sweater colour + marker image, one set per lane.
     * Tints stay pale; the wolf sweater carries the colour.
     *
     * @return array<int, array{tint:string,sweater:string,image:int}>
     */
    public static function track_palette() {
        return array(
            array('tint' => '#e4f1f8', 'sweater' => '#3f7fc0', 'image' => 1),
            array('tint' => '#e5f5ec', 'sweater' => '#4f9d6b', 'image' => 2),
            array('tint' => '#f8eee2', 'sweater' => '#d2883a', 'image' => 3),
            array('tint' => '#eee8f6', 'sweater' => '#7d68b5', 'image' => 4),
            array('tint' => '#f5f0dc', 'sweater' => '#b99f2f', 'image' => 5),
            array('tint' => '#f6e8ee', 'sweater' => '#c25d8b', 'image' => 6),
            array('tint' => '#e2f2f1', 'sweater' => '#3f9c96', 'image' => 7),
            array('tint' => '#e8eef6', 'sweater' => '#6b84b0', 'image' => 8),
            array('tint' => '#f4ebe2', 'sweater' => '#a97a4c', 'image' => 9),
            array('tint' => '#f7e6e6', 'sweater' => '#c65c5c', 'image' => 10),
        );
    }

    /**
     * URL of the rendered wolf marker for a lane.
     *
     * @param int $image 1-based index into assets/race/wolf-N.png
     * @return string
     */
    public static function wolf_image_url($image) {
        $image = max(1, (int) $image);
        $base = defined('AZURE_PLUGIN_URL') ? AZURE_PLUGIN_URL : '';
        return $base . 'assets/race/wolf-' . $image . '.png';
    }

    /**
     * Normalise the shortcode's enable_link value. Only http(s) URLs pass.
     *
     * @param mixed $raw
     * @return string '' when not linkable
     */
    public static function sanitize_link($raw) {
        $raw = is_string($raw) ? trim($raw) : '';
        if ($raw === '') {
            return '';
        }
        $url = function_exists('esc_url_raw') ? esc_url_raw($raw, array('http', 'https')) : $raw;
        if (!is_string($url) || $url === '' || !preg_match('#^https?://#i', $url)) {
            return '';
        }
        return $url;
    }

    /**
     * @param array  $competition
     * @param array  $rows
     * @param string $link Optional URL; when set the whole board is a link.
     * @return string
     */
    public static function render_table($competition, $rows, $link = '') {
        if (!is_array($competition)) {
            return '';
        }
        $show_count = !empty($competition['show_count']);
        $show_percent = !empty($competition['show_percent']);
        if (!$show_count && !$show_percent) {
            $show_count = true;
        }
        $link = self::sanitize_link($link);
        $palette = self::track_palette();
        ob_start();
        if ($link !== '') {
            printf(
                '<a class="pta-class-race-link" href="%s" aria-label="%s">',
                esc_url($link),
                esc_attr($competition['name'])
            );
        }
        ?>
        <div class="pta-class-competition pta-class-race<?php echo $link !== '' ? ' pta-class-race--linked' : ''; ?>">
            <div class="pta-class-race-head">
                <h3 class="pta-class-competition-title"><?php echo esc_html($competition['name']); ?></h3>
                <p class="pta-class-race-kicker"><?php esc_html_e('Distance is % of class donated', 'azure-plugin'); ?></p>
            </div>
            <?php if (empty($rows)): ?>
                <p class="pta-class-race-empty"><?php esc_html_e('Add teachers under System → Classes.', 'azure-plugin'); ?></p>
            <?php else: ?>
                <ol class="pta-class-race-track">
                    <?php foreach (array_values($rows) as $i => $row): ?>
                        <?php
                        $lane = $palette[$i % count($palette)];
                        $progress = $row['percent'] === null ? 0 : max(0, min(100, (int) $row['percent']));
                        $score_bits = array();
                        if ($show_count) {
                            $score_bits[] = sprintf(
                                /* translators: %d: number of WAG line items */
                                _n('%d Donation', '%d Donations', (int) $row['count'], 'azure-plugin'),
                                (int) $row['count']
                            );
                        }
                        if ($show_percent) {
                            $score_bits[] = $row['percent'] === null
                                ? '—'
                                : ((int) $row['percent'] . '%');
                        }
                        $aria = $row['teacher'];
                        if ($row['grade'] !== '') {
                            $aria .= ', ' . $row['grade'];
                        }
                        if ($score_bits) {
                            $aria .= ', ' . implode(', ', $score_bits);
                        }
                        ?>
                        <li class="pta-class-race-lane" style="<?php echo esc_attr('--lane:' . $lane['tint'] . ';--sweater:' . $lane['sweater'] . ';--progress:' . $progress . ';'); ?>" aria-label="<?php echo esc_attr($aria); ?>">
                            <div class="pta-class-race-meta">
                                <span class="pta-class-race-num" aria-hidden="true"><?php echo (int) ($i + 1); ?></span>
                                <span class="pta-class-race-names">
                                    <span class="pta-class-race-teacher"><?php echo esc_html($row['teacher']); ?></span>
                                </span>
                            </div>
                            <div class="pta-class-race-run">
                                <span class="pta-class-race-start" aria-hidden="true"></span>
                                <span class="pta-class-race-trail" aria-hidden="true" style="<?php echo esc_attr('width: calc(62px + (100% - 124px) * ' . $progress . ' / 100);'); ?>"></span>
                                <span class="pta-class-race-finish" aria-hidden="true"></span>
                                <span class="pta-class-race-runner" style="<?php echo esc_attr('left: calc(10px + (100% - 124px) * ' . $progress . ' / 100);'); ?>">
                                    <img class="pta-class-race-wolf" src="<?php echo esc_url(self::wolf_image_url($lane['image'])); ?>" alt="" width="104" height="54" loading="lazy" decoding="async" />
                                </span>
                            </div>
                            <div class="pta-class-race-score">
                                <?php if ($show_percent): ?>
                                    <span class="pta-class-race-pct"><?php echo $row['percent'] === null ? '—' : esc_html((int) $row['percent'] . '%'); ?></span>
                                <?php endif; ?>
                                <?php if ($show_count): ?>
                                    <span class="pta-class-race-count"><?php echo esc_html($score_bits[0]); ?></span>
                                <?php endif; ?>
                            </div>
                        </li>
                    <?php endforeach; ?>
                </ol>
            <?php endif; ?>
        </div>
        <?php
        if ($link !== '') {
            echo '</a>';
        }
        return ob_get_clean();
    }

    public static function shortcode($atts) {
        $atts = shortcode_atts(array(
            'id'          => 0,
            'enable_link' => '',
        ), $atts, 'class-competition');
        $id = (int) $atts['id'];
        $link = self::sanitize_link($atts['enable_link']);
        $comps = self::get_competitions();
        if (empty($comps)) {
            return '';
        }
        $comp = $id > 0 ? self::get_competition($id) : $comps[0];
        if (!$comp) {
            return '';
        }
        wp_enqueue_style(
            'pta-donations-frontend',
            AZURE_PLUGIN_URL . 'css/donations-frontend.css',
            array(),
            defined('AZURE_PLUGIN_VERSION') ? AZURE_PLUGIN_VERSION : null
        );
        return self::render_table($comp, self::competition_rows($comp), $link);
    }
}
