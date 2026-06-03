<?php
/**
 * Orders Reports — Query iterator.
 *
 * Translates a report config into an iterable stream of (WC_Order,
 * WC_Order_Item_Product|null) pairs. One pair per yielded row.
 *
 * Implemented as a PHP generator so callers (the CSV exporter, the
 * preview renderer) can consume one row at a time without ever holding
 * more than one batch of orders in memory.
 *
 * Filter strategy:
 *   - status + date_range applied via wc_get_orders (DB-side filtering)
 *   - product / category / tag filters applied in PHP per line item
 *     (acceptable at PTA scale — typically <500 orders per export)
 */

if (!defined('ABSPATH')) {
    exit;
}

class Azure_Orders_Reports_Query {

    const BATCH_SIZE   = 200;
    const HARD_ROW_CAP = 50000;

    /**
     * Resolve a config into a normalised {from, to} (Y-m-d H:i:s) pair,
     * applying any preset (last_7_days, previous_month, etc.) against
     * the current clock. Returns null if no date constraint.
     *
     * @return array{from:?string,to:?string}
     */
    public static function resolve_date_range(array $config) {
        $dr = isset($config['date_range']) && is_array($config['date_range']) ? $config['date_range'] : array();
        $preset   = isset($dr['preset']) ? (string) $dr['preset'] : '';
        $to_today = !empty($dr['to_today']);
        if ($preset !== '') {
            $range = self::evaluate_preset($preset);
            // Preset already rolls `to` forward to "end of today", so
            // to_today is a no-op here — but be defensive.
            if ($to_today) {
                $range['to'] = current_time('mysql');
            }
            return $range;
        }
        $from = isset($dr['from']) && $dr['from'] !== '' ? self::normalize_date_string((string) $dr['from']) : null;
        $to   = isset($dr['to'])   && $dr['to']   !== '' ? self::normalize_date_string((string) $dr['to'])   : current_time('mysql');
        // Explicit "always end on today" overrides any stored `to`.
        if ($to_today) {
            $to = current_time('mysql');
        }
        return array('from' => $from, 'to' => $to);
    }

    /**
     * Convert any reasonable date input (HTML datetime-local
     * "YYYY-MM-DDTHH:MM", MySQL "YYYY-MM-DD HH:MM:SS", or a plain
     * date "YYYY-MM-DD") into MySQL DATETIME format so wc_get_orders
     * can parse it reliably. Returns the original string if it can't
     * be parsed — keeps the failure visible rather than silently
     * substituting now().
     */
    private static function normalize_date_string($raw) {
        $raw = trim($raw);
        if ($raw === '') return $raw;
        // HTML datetime-local inputs produce "YYYY-MM-DDTHH:MM" (no
        // seconds, no timezone). PHP's strtotime handles the T just
        // fine, but wc_get_orders historically matches zero rows when
        // the from-side has T and the to-side has a space, so we
        // canonicalise to MySQL format here.
        $ts = strtotime($raw);
        if ($ts === false) return $raw;
        return date('Y-m-d H:i:s', $ts);
    }

    /**
     * Map a preset slug to {from, to} timestamps in site-local time.
     *
     * @return array{from:string,to:string}
     */
    public static function evaluate_preset($preset) {
        $tz   = wp_timezone();
        $now  = new DateTimeImmutable('now', $tz);
        $today = $now->setTime(23, 59, 59);
        switch ($preset) {
            case 'last_7_days':
                return array(
                    'from' => $now->modify('-7 days')->setTime(0, 0, 0)->format('Y-m-d H:i:s'),
                    'to'   => $today->format('Y-m-d H:i:s'),
                );
            case 'last_30_days':
                return array(
                    'from' => $now->modify('-30 days')->setTime(0, 0, 0)->format('Y-m-d H:i:s'),
                    'to'   => $today->format('Y-m-d H:i:s'),
                );
            case 'previous_month':
                $first_this = $now->modify('first day of this month')->setTime(0, 0, 0);
                $first_prev = $first_this->modify('-1 month');
                $last_prev  = $first_this->modify('-1 second');
                return array(
                    'from' => $first_prev->format('Y-m-d H:i:s'),
                    'to'   => $last_prev->format('Y-m-d H:i:s'),
                );
            case 'previous_quarter':
                $month = (int) $now->format('n');
                $year  = (int) $now->format('Y');
                $current_q = (int) ceil($month / 3);
                $prev_q    = $current_q - 1;
                $q_year    = $year;
                if ($prev_q < 1) { $prev_q = 4; $q_year--; }
                $start_month = (($prev_q - 1) * 3) + 1;
                $end_month   = $start_month + 2;
                $from = (new DateTimeImmutable(sprintf('%04d-%02d-01 00:00:00', $q_year, $start_month), $tz));
                $to   = $from->modify('+3 months')->modify('-1 second');
                return array('from' => $from->format('Y-m-d H:i:s'), 'to' => $to->format('Y-m-d H:i:s'));
            case 'previous_year':
                // Kept for backwards compatibility — any saved report
                // config from earlier versions that selected "Previous
                // year" still resolves correctly. UI no longer surfaces
                // this preset; "This school year" is the replacement.
                $year = (int) $now->format('Y') - 1;
                return array(
                    'from' => sprintf('%04d-01-01 00:00:00', $year),
                    'to'   => sprintf('%04d-12-31 23:59:59', $year),
                );
            case 'this_school_year':
                // School year starts Aug 1 of the current year if today
                // is Aug-Dec, else Aug 1 of the prior year. End of range
                // is "now" (end of today), not next July 31 — the user
                // wants a "year-to-date" feel.
                $month      = (int) $now->format('n');
                $year       = (int) $now->format('Y');
                $start_year = ($month >= 8) ? $year : ($year - 1);
                return array(
                    'from' => sprintf('%04d-08-01 00:00:00', $start_year),
                    'to'   => $now->setTime(23, 59, 59)->format('Y-m-d H:i:s'),
                );
        }
        return array('from' => null, 'to' => current_time('mysql'));
    }

    /**
     * Total rows that would be produced by ::iter() for this config.
     * Useful for "Matched X rows" summaries in the UI. Iterates without
     * materialising rows — counts only.
     */
    public function count(array $config) {
        $n = 0;
        foreach ($this->iter($config) as $_) {
            $n++;
            if ($n >= self::HARD_ROW_CAP) break;
        }
        return $n;
    }

    /**
     * Generator yielding [WC_Order $order, WC_Order_Item_Product|null $item]
     * tuples. $item is null when granularity = 'order' (one row per order);
     * when granularity = 'line_item' (default) the order is repeated once
     * per line item that passes the product/category/tag filters.
     *
     * @return Generator<array{0:WC_Order,1:?WC_Order_Item_Product}>
     */
    public function iter(array $config) {
        if (!function_exists('wc_get_orders')) {
            return;
        }
        $granularity   = isset($config['granularity']) ? (string) $config['granularity'] : 'line_item';
        $filters       = isset($config['filters']) && is_array($config['filters']) ? $config['filters'] : array();
        $statuses      = !empty($filters['statuses']) ? (array) $filters['statuses'] : array('processing', 'on-hold', 'completed', 'pending');
        $product_ids   = !empty($filters['product_ids'])  ? array_map('intval', (array) $filters['product_ids'])  : array();
        $category_ids  = !empty($filters['category_ids']) ? array_map('intval', (array) $filters['category_ids']) : array();
        $tag_ids       = !empty($filters['tag_ids'])      ? array_map('intval', (array) $filters['tag_ids'])      : array();

        // If category/tag filters are set, resolve them to product IDs once
        // upfront and intersect with the explicit product_ids filter (if any).
        $resolved_pids = $product_ids;
        if (!empty($category_ids) || !empty($tag_ids)) {
            $resolved_pids = self::resolve_product_ids_from_taxonomies($product_ids, $category_ids, $tag_ids);
            // If no products match any of the supplied taxonomies, the result is empty.
            if (empty($resolved_pids)) {
                return;
            }
        }
        $needs_item_filter = !empty($resolved_pids);
        $resolved_pid_lookup = array_flip($resolved_pids);

        $date_range = self::resolve_date_range($config);

        $emitted = 0;
        $page = 1;
        do {
            $args = array(
                'limit'   => self::BATCH_SIZE,
                'page'    => $page,
                'orderby' => 'date',
                'order'   => 'ASC',
                'status'  => $statuses,
                'type'    => 'shop_order',
                'return'  => 'objects',
            );
            if (!empty($date_range['from']) && !empty($date_range['to'])) {
                $args['date_created'] = $date_range['from'] . '...' . $date_range['to'];
            } elseif (!empty($date_range['from'])) {
                $args['date_created'] = '>=' . $date_range['from'];
            } elseif (!empty($date_range['to'])) {
                $args['date_created'] = '<=' . $date_range['to'];
            }

            $batch = wc_get_orders($args);
            if (empty($batch)) break;

            foreach ($batch as $order) {
                if (!$order instanceof WC_Order) continue;

                if ($granularity === 'order') {
                    if ($needs_item_filter && !self::order_contains_any_pid($order, $resolved_pid_lookup)) {
                        continue;
                    }
                    yield array($order, null);
                    $emitted++;
                    if ($emitted >= self::HARD_ROW_CAP) return;
                } else {
                    foreach ($order->get_items() as $item) {
                        if (!$item instanceof WC_Order_Item_Product) continue;
                        if ($needs_item_filter && !isset($resolved_pid_lookup[(int) $item->get_product_id()])) {
                            continue;
                        }
                        yield array($order, $item);
                        $emitted++;
                        if ($emitted >= self::HARD_ROW_CAP) return;
                    }
                }
            }

            if (count($batch) < self::BATCH_SIZE) break;
            $page++;
        } while (true);
    }

    private static function order_contains_any_pid(WC_Order $order, array $pid_lookup) {
        foreach ($order->get_items() as $item) {
            if (!$item instanceof WC_Order_Item_Product) continue;
            if (isset($pid_lookup[(int) $item->get_product_id()])) {
                return true;
            }
        }
        return false;
    }

    /**
     * Resolve category + tag IDs into a set of matching product IDs.
     *
     * Semantics: UNION across all three dimensions. A report that
     * explicitly lists product_ids AND a taxonomy filter means
     * "match line items for any of these specific products OR any
     * product in these categories OR any product with these tags".
     *
     * Two prior-bug fixes embedded here:
     *
     * 1. Explicit `product_ids` are always preserved verbatim,
     *    independent of post_status. Previously, intersecting the
     *    explicit list against published-only tax results meant a
     *    saved report would silently zero out the moment an admin
     *    moved one of its listed products to draft (or trash).
     *
     * 2. Taxonomy queries include draft / private / pending posts
     *    in addition to publish. The auto-updater on parent-facing
     *    products is published, but post-season the same product
     *    is often demoted to draft to hide it from the storefront;
     *    historical orders should still report against the tag.
     */
    private static function resolve_product_ids_from_taxonomies(array $explicit_product_ids, array $category_ids, array $tag_ids) {
        $tax_pids = array();

        if (!empty($category_ids) || !empty($tag_ids)) {
            $tax_args = array(
                'post_type'      => 'product',
                'post_status'    => array('publish', 'private', 'draft', 'pending'),
                'posts_per_page' => -1,
                'fields'         => 'ids',
                'no_found_rows'  => true,
                'tax_query'      => array('relation' => 'OR'),
            );
            if (!empty($category_ids)) {
                $tax_args['tax_query'][] = array(
                    'taxonomy' => 'product_cat',
                    'field'    => 'term_id',
                    'terms'    => $category_ids,
                );
            }
            if (!empty($tag_ids)) {
                $tax_args['tax_query'][] = array(
                    'taxonomy' => 'product_tag',
                    'field'    => 'term_id',
                    'terms'    => $tag_ids,
                );
            }
            $tax_pids = array_map('intval', (array) get_posts($tax_args));
        }

        if (!empty($explicit_product_ids) && !empty($tax_pids)) {
            return array_values(array_unique(array_merge($explicit_product_ids, $tax_pids)));
        }
        if (!empty($explicit_product_ids)) {
            return array_values(array_map('intval', $explicit_product_ids));
        }
        return $tax_pids;
    }
}
