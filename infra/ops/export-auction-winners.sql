-- =============================================================================
-- Auction Winners + Order/Payment Backup
-- =============================================================================
-- One-off read-only SELECT for backing up the winning bidder of every ended
-- (or "sold") auction, joined to its WooCommerce order and payment status.
--
-- Run in phpMyAdmin / MySQL Workbench / Azure MySQL Query editor.
--
-- Assumptions:
--   * Table prefix is `wp_`. Find/replace `wp_` if different.
--   * WooCommerce is using CLASSIC order storage (orders in wp_posts).
--     If HPOS is enabled (WC 8.x+ "High-Performance Order Storage"), swap
--     the `wp_posts ord` / `wp_postmeta order_*` joins for `wp_wc_orders` /
--     `wp_wc_orders_meta`. The MU-plugin variant
--     (infra/ops/export-auction-winners.php) handles both automatically.
--   * Winners are derived from the bids table (canonical) AND cross-checked
--     against the cached `_auction_winner_user_id` postmeta — discrepancies
--     show up because both columns are returned.
--
-- "Paid yet" rule:
--   PAID    -> order.post_status IN ('wc-processing','wc-completed')
--              OR `_paid_date` is non-empty
--   NOT PAID-> everything else (sub-classified in the `payment_state` column)
-- =============================================================================

SELECT
    p.ID                                              AS product_id,
    p.post_title                                      AS auction_title,
    status_meta.meta_value                            AS auction_status,
    COALESCE(ended_meta.meta_value, sold_meta.meta_value) AS ended_or_sold_at,

    -- Winner (from cached postmeta)
    CAST(COALESCE(winner_meta.meta_value, '0') AS UNSIGNED) AS winner_user_id,
    u.user_login                                      AS winner_username,
    u.user_email                                      AS winner_email,
    u.display_name                                    AS winner_name,
    CAST(winning_amount_meta.meta_value AS DECIMAL(14,2)) AS winning_amount_cached,

    -- Winner (re-derived from the bids table as a sanity check)
    top_bid.user_id                                   AS top_bidder_user_id_from_table,
    top_bid.bid_amount                                AS top_bid_from_table,
    top_bid.created_at                                AS top_bid_at,

    -- Order + payment
    CAST(COALESCE(winner_order_meta.meta_value, sold_order_meta.meta_value, '0') AS UNSIGNED) AS order_id,
    ord.post_status                                   AS order_status,
    CAST(order_total_meta.meta_value AS DECIMAL(14,2)) AS order_total,
    paid_date_meta.meta_value                         AS paid_date,
    txn_meta.meta_value                               AS transaction_id,
    CASE
        WHEN ord.ID IS NULL
            THEN 'NO ORDER CREATED'
        WHEN ord.post_status IN ('wc-processing','wc-completed')
            THEN 'PAID'
        WHEN paid_date_meta.meta_value IS NOT NULL
             AND paid_date_meta.meta_value <> ''
            THEN 'PAID (by _paid_date)'
        WHEN ord.post_status = 'wc-pending'
            THEN 'NOT PAID (pending)'
        WHEN ord.post_status = 'wc-on-hold'
            THEN 'NOT PAID (on hold)'
        WHEN ord.post_status = 'wc-cancelled'
            THEN 'NOT PAID (cancelled)'
        WHEN ord.post_status = 'wc-refunded'
            THEN 'REFUNDED'
        WHEN ord.post_status = 'wc-failed'
            THEN 'NOT PAID (failed)'
        ELSE CONCAT('UNKNOWN (', COALESCE(ord.post_status, 'null'), ')')
    END                                               AS payment_state

FROM wp_posts p

-- Only auctions that ended or were bought-it-now
JOIN wp_postmeta status_meta
  ON status_meta.post_id   = p.ID
 AND status_meta.meta_key  = '_auction_status'
 AND status_meta.meta_value IN ('ended','sold')

-- Timestamps
LEFT JOIN wp_postmeta ended_meta
  ON ended_meta.post_id = p.ID AND ended_meta.meta_key = '_auction_ended_at'
LEFT JOIN wp_postmeta sold_meta
  ON sold_meta.post_id  = p.ID AND sold_meta.meta_key  = '_auction_sold_at'

-- Cached winner postmeta
LEFT JOIN wp_postmeta winner_meta
  ON winner_meta.post_id = p.ID AND winner_meta.meta_key = '_auction_winner_user_id'
LEFT JOIN wp_postmeta winning_amount_meta
  ON winning_amount_meta.post_id = p.ID AND winning_amount_meta.meta_key = '_auction_winning_amount'

-- Order IDs (winner vs sold/BIN)
LEFT JOIN wp_postmeta winner_order_meta
  ON winner_order_meta.post_id = p.ID AND winner_order_meta.meta_key = '_auction_winner_order_id'
LEFT JOIN wp_postmeta sold_order_meta
  ON sold_order_meta.post_id   = p.ID AND sold_order_meta.meta_key   = '_auction_sold_order_id'

-- Winner user
LEFT JOIN wp_users u
  ON u.ID = CAST(winner_meta.meta_value AS UNSIGNED)

-- Canonical top bid from the bids table (max amount, latest if tied)
LEFT JOIN (
    SELECT b.product_id, b.user_id, b.bid_amount, b.created_at
    FROM wp_azure_auction_bids b
    INNER JOIN (
        SELECT product_id,
               MAX(bid_amount) AS max_amount
        FROM wp_azure_auction_bids
        GROUP BY product_id
    ) m
      ON m.product_id = b.product_id
     AND m.max_amount = b.bid_amount
    -- If two rows tie on amount, keep the latest
    INNER JOIN (
        SELECT product_id, bid_amount, MAX(created_at) AS latest_at
        FROM wp_azure_auction_bids
        GROUP BY product_id, bid_amount
    ) latest
      ON latest.product_id = b.product_id
     AND latest.bid_amount = b.bid_amount
     AND latest.latest_at  = b.created_at
) top_bid
  ON top_bid.product_id = p.ID

-- Order row (classic CPT order storage)
LEFT JOIN wp_posts ord
  ON ord.ID = CAST(COALESCE(winner_order_meta.meta_value, sold_order_meta.meta_value, '0') AS UNSIGNED)
 AND ord.post_type = 'shop_order'

-- Order meta (totals + payment indicators)
LEFT JOIN wp_postmeta order_total_meta
  ON order_total_meta.post_id = ord.ID AND order_total_meta.meta_key = '_order_total'
LEFT JOIN wp_postmeta paid_date_meta
  ON paid_date_meta.post_id   = ord.ID AND paid_date_meta.meta_key   = '_paid_date'
LEFT JOIN wp_postmeta txn_meta
  ON txn_meta.post_id         = ord.ID AND txn_meta.meta_key         = '_transaction_id'

WHERE p.post_type = 'product'
ORDER BY p.ID;
