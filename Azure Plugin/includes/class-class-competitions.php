<?php
/**
 * Class competitions: participation by teacher, never dollars.
 *
 * Teachers come from Child Info. Class sizes are staff-entered headcounts.
 * A child counts when any parent in their family has a qualifying gift.
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
     * @param int[] $parent_user_ids
     * @param int[] $donor_user_ids
     */
    public static function child_participates($parent_user_ids, $donor_user_ids) {
        if (empty($parent_user_ids) || empty($donor_user_ids)) {
            return false;
        }
        $parent_user_ids = array_filter(array_map('intval', (array) $parent_user_ids));
        $donor_user_ids = array_filter(array_map('intval', (array) $donor_user_ids));
        return $parent_user_ids && $donor_user_ids && (bool) array_intersect($parent_user_ids, $donor_user_ids);
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
     * @param string[] $teachers Canonical teacher names.
     * @param array    $sizes    teacher => int
     * @param array    $children [{id, teacher, grade, parent_ids}]
     * @param int[]    $donor_ids
     * @param bool     $show_count
     * @param bool     $show_percent
     * @return array
     */
    public static function build_rows($teachers, $sizes, $children, $donor_ids, $show_count, $show_percent) {
        $rows = array();
        $show_count = (bool) $show_count;
        $show_percent = (bool) $show_percent;
        if (!$show_count && !$show_percent) {
            $show_count = true;
        }

        $by_key = array();
        foreach ((array) $children as $child) {
            $key = self::normalize_teacher(isset($child['teacher']) ? $child['teacher'] : '');
            if ($key === '') {
                continue;
            }
            if (!isset($by_key[$key])) {
                $by_key[$key] = array();
            }
            $by_key[$key][] = $child;
        }

        foreach ((array) $teachers as $teacher) {
            $teacher = trim((string) $teacher);
            if ($teacher === '') {
                continue;
            }
            $key = self::normalize_teacher($teacher);
            $class_children = isset($by_key[$key]) ? $by_key[$key] : array();
            $grades = array();
            $participating = 0;
            $seen = array();
            foreach ($class_children as $child) {
                $grade = isset($child['grade']) ? trim((string) $child['grade']) : '';
                if ($grade !== '') {
                    $grades[$grade] = true;
                }
                $cid = isset($child['id']) ? (int) $child['id'] : 0;
                if ($cid && isset($seen[$cid])) {
                    continue;
                }
                $parents = isset($child['parent_ids']) ? $child['parent_ids'] : array();
                if (self::child_participates($parents, $donor_ids)) {
                    $participating++;
                    if ($cid) {
                        $seen[$cid] = true;
                    }
                }
            }
            $size = 0;
            if (isset($sizes[$teacher])) {
                $size = (int) $sizes[$teacher];
            } elseif (isset($by_key[$key])) {
                foreach ((array) $sizes as $name => $n) {
                    if (self::normalize_teacher($name) === $key) {
                        $size = (int) $n;
                        break;
                    }
                }
            }
            $pct = $show_percent ? self::percent($participating, $size) : null;
            $rows[] = array(
                'teacher'       => $teacher,
                'grade'         => implode(', ', array_keys($grades)),
                'count'         => $participating,
                'size'          => $size,
                'percent'       => $pct,
                'show_count'    => $show_count,
                'show_percent'  => $show_percent,
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
            self::load_children(),
            self::load_donor_user_ids($competition),
            !empty($competition['show_count']),
            !empty($competition['show_percent'])
        );
    }

    /**
     * @param array $competition
     * @return int[]
     */
    public static function load_donor_user_ids($competition) {
        global $wpdb;
        $ids = array();
        $type = isset($competition['source_type']) ? $competition['source_type'] : '';
        $source_id = isset($competition['source_id']) ? (int) $competition['source_id'] : 0;
        if ($source_id <= 0 || !$wpdb) {
            return $ids;
        }

        $records = class_exists('Azure_Database')
            ? Azure_Database::get_table_name('donation_records')
            : '';
        if ($records) {
            if ($type === 'campaign') {
                $found = $wpdb->get_col($wpdb->prepare(
                    "SELECT DISTINCT user_id FROM {$records} WHERE campaign_id = %d AND user_id > 0",
                    $source_id
                ));
            } else {
                $found = $wpdb->get_col($wpdb->prepare(
                    "SELECT DISTINCT user_id FROM {$records} WHERE product_id = %d AND user_id > 0",
                    $source_id
                ));
            }
            foreach ((array) $found as $uid) {
                $ids[(int) $uid] = true;
            }
        }

        if ($type === 'product') {
            $lookup = $wpdb->prefix . 'wc_order_product_lookup';
            $orders = $wpdb->prefix . 'wc_orders';
            $has_lookup = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $lookup));
            $has_orders = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $orders));
            if ($has_lookup === $lookup && $has_orders === $orders) {
                $found = $wpdb->get_col($wpdb->prepare(
                    "SELECT DISTINCT l.customer_id
                     FROM {$lookup} l
                     INNER JOIN {$orders} o ON o.id = l.order_id
                     WHERE (l.product_id = %d OR l.variation_id = %d)
                       AND l.customer_id > 0
                       AND o.type = 'shop_order'
                       AND o.status IN ('wc-processing','wc-completed','processing','completed')",
                    $source_id,
                    $source_id
                ));
                foreach ((array) $found as $uid) {
                    $ids[(int) $uid] = true;
                }
            }
        }

        return array_map('intval', array_keys($ids));
    }

    /**
     * @return array
     */
    public static function load_children() {
        global $wpdb;
        $out = array();
        if (!class_exists('Azure_Database') || !class_exists('Azure_User_Children')) {
            return $out;
        }
        $children_table = Azure_Database::get_table_name('user_children');
        $meta_table = Azure_Database::get_table_name('user_children_meta');
        $family_table = Azure_Database::get_table_name('connected_family');
        if (!$children_table) {
            return $out;
        }

        $kids = $wpdb->get_results("SELECT id, user_id, family_id FROM {$children_table} WHERE is_active = 1");
        if (empty($kids)) {
            return $out;
        }

        $families = array();
        if ($family_table) {
            foreach ((array) $wpdb->get_results("SELECT id, primary_user_id, secondary_user_id FROM {$family_table}") as $fam) {
                $families[(int) $fam->id] = array(
                    (int) $fam->primary_user_id,
                    (int) $fam->secondary_user_id,
                );
            }
        }

        $meta_by_child = array();
        if ($meta_table) {
            $keys = array_merge(
                Azure_User_Children::child_grade_meta_keys(),
                Azure_User_Children::child_teacher_meta_keys()
            );
            $in = implode(',', array_fill(0, count($keys), '%s'));
            $sql = $wpdb->prepare(
                "SELECT child_id, meta_key, meta_value FROM {$meta_table} WHERE meta_key IN ({$in})",
                $keys
            );
            foreach ((array) $wpdb->get_results($sql) as $row) {
                $cid = (int) $row->child_id;
                if (!isset($meta_by_child[$cid])) {
                    $meta_by_child[$cid] = array();
                }
                $meta_by_child[$cid][$row->meta_key] = $row->meta_value;
            }
        }

        foreach ($kids as $kid) {
            $cid = (int) $kid->id;
            $meta = isset($meta_by_child[$cid]) ? $meta_by_child[$cid] : array();
            $teacher = Azure_User_Children::teacher_from_meta($meta);
            $parents = array((int) $kid->user_id);
            $fid = (int) $kid->family_id;
            if ($fid && isset($families[$fid])) {
                $parents = array_merge($parents, $families[$fid]);
            }
            $parents = array_values(array_unique(array_filter(array_map('intval', $parents))));
            $out[] = array(
                'id'         => $cid,
                'teacher'    => $teacher,
                'grade'      => Azure_User_Children::grade_from_meta($meta),
                'parent_ids' => $parents,
            );
        }
        return $out;
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
        $colspan = 2 + ($show_count ? 1 : 0) + ($show_percent ? 1 : 0);
        ob_start();
        ?>
        <div class="pta-class-competition">
            <h3 class="pta-class-competition-title"><?php echo esc_html($competition['name']); ?></h3>
            <table class="pta-class-competition-table">
                <thead>
                    <tr>
                        <th><?php esc_html_e('Teacher', 'azure-plugin'); ?></th>
                        <th><?php esc_html_e('Grade', 'azure-plugin'); ?></th>
                        <?php if ($show_count): ?>
                            <th><?php esc_html_e('Participating kids', 'azure-plugin'); ?></th>
                        <?php endif; ?>
                        <?php if ($show_percent): ?>
                            <th><?php esc_html_e('% of class', 'azure-plugin'); ?></th>
                        <?php endif; ?>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($rows)): ?>
                        <tr>
                            <td colspan="<?php echo (int) $colspan; ?>"><?php esc_html_e('Add teachers in Child Info, then enter class sizes.', 'azure-plugin'); ?></td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($rows as $row): ?>
                            <tr>
                                <td><?php echo esc_html($row['teacher']); ?></td>
                                <td><?php echo $row['grade'] !== '' ? esc_html($row['grade']) : '—'; ?></td>
                                <?php if ($show_count): ?>
                                    <td><?php echo (int) $row['count']; ?></td>
                                <?php endif; ?>
                                <?php if ($show_percent): ?>
                                    <td><?php echo $row['percent'] === null ? '—' : esc_html((string) $row['percent'] . '%'); ?></td>
                                <?php endif; ?>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
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
