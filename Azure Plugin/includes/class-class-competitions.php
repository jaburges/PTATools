<?php
/**
 * Class competitions: purchases by teacher, never dollars.
 *
 * Teachers and class sizes still come from Child Info / the Donations grid.
 * Each qualifying line item (WAG gift, custom WAG amount, or chosen product)
 * counts once for the teacher saved on that order item.
 */

if (!defined('ABSPATH')) {
    exit;
}

class Azure_Class_Competitions {

    const SIZES_KEY = 'donations_class_sizes';
    const COMPS_KEY = 'donations_class_competitions';

    public static function normalize_teacher($name) {
        $name = strtolower(trim(preg_replace('/\s+/', ' ', (string) $name)));
        return $name;
    }

    public static function teacher_list() {
        if (class_exists('Azure_Product_Fields_Module')) {
            $opts = Azure_Product_Fields_Module::get_teacher_options();
            if (is_array($opts)) {
                return array_values(array_filter(array_map('strval', $opts), 'strlen'));
            }
        }
        return array();
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
        return self::sanitize_class_sizes(
            Azure_Settings::get_setting(self::SIZES_KEY, array()),
            self::teacher_list()
        );
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
        global $wpdb;
        $product_ids = array_values(array_unique(array_filter(array_map('intval', (array) $product_ids))));
        $variation_ids = array_values(array_unique(array_filter(array_map('intval', (array) $variation_ids))));
        if ((empty($product_ids) && empty($variation_ids)) || !$wpdb) {
            return array();
        }
        $lookup = $wpdb->prefix . 'wc_order_product_lookup';
        if ($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $lookup)) !== $lookup) {
            return array();
        }
        $match = array();
        if (!empty($product_ids)) {
            $match[] = 'l.product_id IN (' . implode(',', $product_ids) . ')';
        }
        if (!empty($variation_ids)) {
            $match[] = 'l.variation_id IN (' . implode(',', $variation_ids) . ')';
        }
        return self::paid_lookup_item_ids($lookup, implode(' OR ', $match));
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
        $lookup = $wpdb->prefix . 'wc_order_product_lookup';
        if (!$records || $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $lookup)) !== $lookup) {
            return array();
        }
        $match = "EXISTS (
            SELECT 1 FROM {$records} r
            WHERE r.campaign_id = " . $campaign_id . "
              AND r.order_id = l.order_id
              AND r.product_id > 0
              AND (r.product_id = l.product_id OR r.product_id = l.variation_id)
        )";
        return self::paid_lookup_item_ids($lookup, $match);
    }

    /**
     * @param string $lookup
     * @param string $match_sql already-safe fragment
     * @return int[]
     */
    private static function paid_lookup_item_ids($lookup, $match_sql) {
        global $wpdb;
        $orders = $wpdb->prefix . 'wc_orders';
        $paid = "'wc-completed','wc-processing'";
        if ($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $orders)) === $orders) {
            $sql = "SELECT l.order_item_id
                    FROM {$lookup} l
                    INNER JOIN {$orders} o ON o.id = l.order_id
                    WHERE o.type = 'shop_order'
                      AND o.status IN ({$paid})
                      AND ({$match_sql})";
        } else {
            $sql = "SELECT l.order_item_id
                    FROM {$lookup} l
                    INNER JOIN {$wpdb->posts} o ON o.ID = l.order_id
                    WHERE o.post_status IN ({$paid})
                      AND ({$match_sql})";
        }
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

    public static function render_table($competition, $rows) {
        if (!is_array($competition)) {
            return '';
        }
        $show_count = !empty($competition['show_count']);
        $show_percent = !empty($competition['show_percent']);
        if (!$show_count && !$show_percent) {
            $show_count = true;
        }
        $palette = self::track_palette();
        ob_start();
        ?>
        <div class="pta-class-competition pta-class-race">
            <div class="pta-class-race-head">
                <h3 class="pta-class-competition-title"><?php echo esc_html($competition['name']); ?></h3>
                <p class="pta-class-race-kicker"><?php esc_html_e('Classroom race — each wolf is a class. Distance is purchases ÷ class size.', 'azure-plugin'); ?></p>
            </div>
            <?php if (empty($rows)): ?>
                <p class="pta-class-race-empty"><?php esc_html_e('Add teachers in Child Info, then enter class sizes.', 'azure-plugin'); ?></p>
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
                                _n('%d purchase', '%d purchases', (int) $row['count'], 'azure-plugin'),
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
                                    <span class="pta-class-race-grade"><?php echo $row['grade'] !== '' ? esc_html($row['grade']) : '—'; ?></span>
                                </span>
                            </div>
                            <div class="pta-class-race-run">
                                <span class="pta-class-race-start" aria-hidden="true"></span>
                                <span class="pta-class-race-finish" aria-hidden="true"></span>
                                <span class="pta-class-race-runner" style="<?php echo esc_attr('left: calc(10px + (100% - 148px) * ' . $progress . ' / 100);'); ?>">
                                    <img class="pta-class-race-wolf" src="<?php echo esc_url(self::wolf_image_url($lane['image'])); ?>" alt="" width="128" height="66" loading="lazy" decoding="async" />
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
        return ob_get_clean();
    }

    public static function shortcode($atts) {
        $atts = shortcode_atts(array(
            'id' => 0,
        ), $atts, 'class-competition');
        $id = (int) $atts['id'];
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
        return self::render_table($comp, self::competition_rows($comp));
    }
}
