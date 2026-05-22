<?php
/**
 * Auction Module - Winner, outbid, and notification emails
 *
 * Templates render inline HTML with a small reusable wrapper so the markup
 * stays consistent across messages.
 *
 * Sending strategy (best deliverability path first):
 *   1. AcyMailing's mailer (if AcyMailing\Helpers\MailerHelper is loaded).
 *      AcyMailing handles SMTP/DKIM/SPF via its own Email Service / SMTP
 *      configuration, which historically lands in inboxes far better than
 *      Azure Communication Services on this site.
 *   2. wp_mail() fallback. Whatever filter chain WordPress has wins, so if
 *      AcyMailing is missing or its mailer throws, the email still attempts
 *      to send through the site's normal mail path.
 *
 * The site admin can force the wp_mail fallback by setting the
 * `auction_email_use_acymailing` option to `0`.
 */

if (!defined('ABSPATH')) {
    exit;
}

class Azure_Auction_Emails {

    /**
     * Send "You won the auction" email to the winner with checkout link.
     *
     * @param WC_Order $order
     * @param int      $product_id
     * @param float    $winning_amount
     * @return bool
     */
    public function send_winner_email($order, $product_id, $winning_amount) {
        if (!$order || !$order->get_id()) {
            return false;
        }
        $to = $order->get_billing_email();
        $customer_name = $order->get_billing_first_name();

        // Last-line fallback: if the order has no billing email (lifecycle
        // ensure_billing_from_user normally fixes this, but old orders or
        // edge cases may still slip through), look up the WP user record
        // attached to the order.
        if (empty($to) && $order->get_user_id()) {
            $user = get_userdata($order->get_user_id());
            if ($user && $user->user_email) {
                $to = $user->user_email;
                if (empty($customer_name)) {
                    $customer_name = $user->display_name ?: $user->user_login;
                }
            }
        }
        if (empty($to)) {
            if (class_exists('Azure_Logger')) {
                Azure_Logger::warning('Auction: winner email skipped, no recipient', array(
                    'order_id'   => $order->get_id(),
                    'product_id' => $product_id,
                ));
            }
            return false;
        }
        if (empty($customer_name)) {
            $customer_name = $to;
        }
        $product = wc_get_product($product_id);
        $item_name = $product ? $product->get_name() : __('Auction item', 'azure-plugin');

        // Both URLs are signed with the order_key, so they work whether the
        // recipient is signed in or not. If they happen to be signed in,
        // their session is preserved through the Stripe checkout flow.
        $payment_url    = $order->get_checkout_payment_url();
        $view_order_url = $order->get_view_order_url();
        $order_number   = $order->get_order_number();
        $order_total    = wc_price($order->get_total());

        $subject = sprintf(__('Congratulations! You won %s', 'azure-plugin'), $item_name);

        $rows = array(
            array('label' => __('Order number', 'azure-plugin'),     'value' => '#' . esc_html($order_number)),
            array('label' => __('Item', 'azure-plugin'),             'value' => esc_html($item_name)),
            array('label' => __('Winning bid', 'azure-plugin'),      'value' => wc_price($winning_amount)),
            array('label' => __('Order total', 'azure-plugin'),      'value' => $order_total),
            array(
                'label' => __('Status', 'azure-plugin'),
                'value' => '<span style="color: #b26100;">' . esc_html__('Pending payment', 'azure-plugin') . '</span>',
            ),
        );

        $intro_html = sprintf(
            wp_kses(
                __('You won <strong>%1$s</strong> with a winning bid of <strong>%2$s</strong>. To complete your purchase, please pay using the link below.', 'azure-plugin'),
                array('strong' => array())
            ),
            esc_html($item_name),
            wc_price($winning_amount)
        );

        $footer_html = sprintf(
            wp_kses(
                __('The %1$sPay now%2$s link is signed with your order key, so it works whether you\'re signed in or not. If you\'re signed in, your session is preserved through Stripe checkout.', 'azure-plugin'),
                array('strong' => array())
            ),
            '<strong>',
            '</strong>'
        );
        $footer_html .= '<br/><br/>';
        $footer_html .= sprintf(
            wp_kses(
                __('You can also %1$sview this order in My Account%2$s.', 'azure-plugin'),
                array('a' => array('href' => array()))
            ),
            '<a href="' . esc_url($view_order_url) . '">',
            '</a>'
        );

        $message = $this->render_email_html(array(
            'heading'      => __('Congratulations - you won the auction!', 'azure-plugin'),
            'heading_color'=> '#1a7f37',
            'customer'     => $customer_name,
            'intro_html'   => $intro_html,
            'rows'         => $rows,
            'cta_label'    => __('Pay now via Stripe', 'azure-plugin'),
            'cta_url'      => $payment_url,
            'footer_html'  => $footer_html,
        ));

        $sent = $this->send_via_best_path($to, $subject, $message, array(
            'order_id'   => $order->get_id(),
            'product_id' => $product_id,
            'context'    => 'winner',
        ));
        return $sent['ok'];
    }

    /**
     * Send "You've been outbid" email to a previously high bidder who has
     * just been displaced. Called from Azure_Auction_Bids::place_bid()
     * after a successful displacement is detected.
     *
     * @param int   $product_id
     * @param int   $previous_high_user_id
     * @param float $previous_bid_amount
     * @param float $new_high_amount
     * @return bool
     */
    public function send_outbid_email($product_id, $previous_high_user_id, $previous_bid_amount, $new_high_amount) {
        $previous_high_user_id = (int) $previous_high_user_id;
        if (!$previous_high_user_id) {
            return false;
        }
        $user = get_userdata($previous_high_user_id);
        if (!$user || empty($user->user_email)) {
            return false;
        }
        $product = function_exists('wc_get_product') ? wc_get_product($product_id) : null;
        if (!$product) {
            return false;
        }

        $to             = $user->user_email;
        $customer_name  = $user->display_name ?: $user->user_login;
        $item_name      = $product->get_name();
        $product_url    = get_permalink($product_id);

        // Best-effort time-remaining hint. _auction_bidding_end is stored
        // either as mysql datetime or as a unix timestamp string.
        $end_ts = 0;
        $bidding_end = get_post_meta($product_id, '_auction_bidding_end', true);
        if (is_numeric($bidding_end)) {
            $end_ts = (int) $bidding_end;
        } elseif (!empty($bidding_end)) {
            $end_ts = (int) strtotime($bidding_end);
        }
        $remaining_html = '';
        if ($end_ts && $end_ts > time()) {
            $remaining_html = '<p style="text-align: center; color: #b26100; font-weight: 600; margin: 12px 0 0;">'
                . sprintf(esc_html__('Bidding closes in %s.', 'azure-plugin'), human_time_diff(time(), $end_ts))
                . '</p>';
        }

        $subject = sprintf(__('You\'ve been outbid on %s', 'azure-plugin'), $item_name);

        $rows = array(
            array('label' => __('Item', 'azure-plugin'),             'value' => esc_html($item_name)),
            array('label' => __('Your previous bid', 'azure-plugin'), 'value' => wc_price($previous_bid_amount)),
            array(
                'label' => __('Current high bid', 'azure-plugin'),
                'value' => '<span style="color: #1a7f37;">' . wc_price($new_high_amount) . '</span>',
            ),
        );

        $intro_html = sprintf(
            wp_kses(
                __('Someone just placed a higher bid on <strong>%s</strong>. You\'re no longer the top bidder.', 'azure-plugin'),
                array('strong' => array())
            ),
            esc_html($item_name)
        );

        $footer_html = esc_html__('Don\'t miss out — click the button above to place a higher bid before the auction ends.', 'azure-plugin');

        $message = $this->render_email_html(array(
            'heading'         => __('You\'ve been outbid!', 'azure-plugin'),
            'heading_color'   => '#b26100',
            'customer'        => $customer_name,
            'intro_html'      => $intro_html,
            'rows'            => $rows,
            'after_rows_html' => $remaining_html,
            'cta_label'       => __('Place a higher bid', 'azure-plugin'),
            'cta_url'         => $product_url,
            'footer_html'     => $footer_html,
        ));

        $sent = $this->send_via_best_path($to, $subject, $message, array(
            'product_id'            => $product_id,
            'previous_high_user_id' => $previous_high_user_id,
            'context'               => 'outbid',
        ));
        return $sent['ok'];
    }

    /**
     * Shared HTML wrapper for auction emails. Inline styles only (most email
     * clients strip <style> blocks). Returns a complete HTML body.
     *
     * @param array $args {
     *   @type string $heading        Big heading text.
     *   @type string $heading_color  CSS color for the heading.
     *   @type string $customer       "Hi $customer," personalization name.
     *   @type string $intro_html     One paragraph of intro copy (HTML allowed).
     *   @type array  $rows           Array of ['label'=>..., 'value'=>...] rows
     *                                 to render in a key/value details table.
     *   @type string $after_rows_html Optional HTML inserted between the
     *                                 details table and the CTA button.
     *   @type string $cta_label      Button text.
     *   @type string $cta_url        Button href.
     *   @type string $footer_html    Optional footer paragraph (HTML allowed).
     * }
     * @return string
     */
    private function render_email_html($args) {
        $defaults = array(
            'heading'         => '',
            'heading_color'   => '#333',
            'customer'        => '',
            'intro_html'      => '',
            'rows'            => array(),
            'after_rows_html' => '',
            'cta_label'       => '',
            'cta_url'         => '',
            'footer_html'     => '',
        );
        $a = array_merge($defaults, $args);

        ob_start();
        ?>
        <div style="font-family: Arial, sans-serif; max-width: 600px; margin: 0 auto; color: #333; line-height: 1.5;">
            <h2 style="color: <?php echo esc_attr($a['heading_color']); ?>; margin: 0 0 16px;"><?php echo esc_html($a['heading']); ?></h2>
            <?php if (!empty($a['customer'])): ?>
            <p><?php printf(esc_html__('Hi %s,', 'azure-plugin'), esc_html($a['customer'])); ?></p>
            <?php endif; ?>
            <?php if (!empty($a['intro_html'])): ?>
            <p><?php echo $a['intro_html']; ?></p>
            <?php endif; ?>

            <?php if (!empty($a['rows'])): ?>
            <table cellpadding="0" cellspacing="0" border="0" style="margin: 20px 0; border-collapse: collapse; width: 100%; background: #f6f7f7; border-radius: 4px;">
                <tr>
                    <td style="padding: 16px 20px;">
                        <table cellpadding="6" cellspacing="0" border="0" style="border-collapse: collapse; width: 100%; font-size: 14px;">
                            <?php foreach ($a['rows'] as $row): ?>
                            <tr>
                                <td style="color: #555;"><?php echo esc_html($row['label']); ?></td>
                                <td style="font-weight: 600; text-align: right;"><?php echo $row['value']; ?></td>
                            </tr>
                            <?php endforeach; ?>
                        </table>
                    </td>
                </tr>
            </table>
            <?php endif; ?>

            <?php if (!empty($a['after_rows_html'])): ?>
                <?php echo $a['after_rows_html']; ?>
            <?php endif; ?>

            <?php if (!empty($a['cta_label']) && !empty($a['cta_url'])): ?>
            <p style="margin: 25px 0; text-align: center;">
                <a href="<?php echo esc_url($a['cta_url']); ?>" style="background: #0073aa; color: #fff; padding: 14px 32px; text-decoration: none; border-radius: 3px; display: inline-block; font-weight: 600; font-size: 16px;"><?php echo esc_html($a['cta_label']); ?></a>
            </p>
            <?php endif; ?>

            <?php if (!empty($a['footer_html'])): ?>
            <hr style="border: none; border-top: 1px solid #e0e0e0; margin: 30px 0;" />
            <p style="font-size: 13px; color: #666;"><?php echo $a['footer_html']; ?></p>
            <?php endif; ?>
        </div>
        <?php
        return ob_get_clean();
    }

    /**
     * Send an HTML email through the best available path.
     *
     * Tries AcyMailing's MailerHelper first (better deliverability on this
     * site — AcyMailing has DKIM + Email Service credits configured). Falls
     * back to wp_mail() if AcyMailing is missing or its send fails.
     *
     * Returns ['ok' => bool, 'method' => 'acymailing'|'wp_mail'].
     *
     * @param string $to       Recipient email address.
     * @param string $subject  Subject line.
     * @param string $body_html  Pre-rendered HTML body.
     * @param array  $context  Log context (order_id, product_id, etc.).
     * @return array
     */
    private function send_via_best_path($to, $subject, $body_html, array $context = array()) {
        $use_acy = (int) get_option('auction_email_use_acymailing', 1) === 1;
        $acy_available = class_exists('\\AcyMailing\\Helpers\\MailerHelper');

        if ($use_acy && $acy_available) {
            try {
                // AcyMailing's MailerHelper extends their PHPMailer fork. The
                // constructor pre-loads the configured From, sending method
                // (SMTP / Email Service / etc), DKIM, and bounce address from
                // wp_acym_configuration, so we only need to set subject/body
                // and the recipient.
                $mailer = new \AcyMailing\Helpers\MailerHelper();
                $mailer->report = false;            // don't enqueue WP admin notices on failure
                $mailer->autoAddUser = false;       // don't add transactional recipients to Acy lists
                $mailer->isHTML(true);
                $mailer->Subject = $subject;
                $mailer->Body    = $body_html;
                // AltBody is the plaintext fallback for clients without HTML.
                // wp_strip_all_tags + a single newline normalize gets us 95%
                // of the way there without bringing in a full markdown lib.
                $mailer->AltBody = trim(preg_replace('/\n{3,}/', "\n\n", wp_strip_all_tags($body_html)));
                $mailer->addAddress($to);

                $ok = $mailer->send();
                if ($ok) {
                    if (class_exists('Azure_Logger')) {
                        Azure_Logger::info('Auction: email sent via AcyMailing', array_merge($context, array(
                            'email' => $to,
                        )));
                    }
                    return array('ok' => true, 'method' => 'acymailing');
                }

                // Acy returned false — log the report message and fall through
                // to wp_mail so the user still gets the email.
                if (class_exists('Azure_Logger')) {
                    Azure_Logger::warning('Auction: AcyMailing send returned false, falling back to wp_mail', array_merge($context, array(
                        'email'      => $to,
                        'acy_error'  => isset($mailer->ErrorInfo) ? $mailer->ErrorInfo : '',
                        'acy_report' => isset($mailer->reportMessage) ? $mailer->reportMessage : '',
                    )));
                }
            } catch (\Throwable $e) {
                if (class_exists('Azure_Logger')) {
                    Azure_Logger::warning('Auction: AcyMailing send threw, falling back to wp_mail', array_merge($context, array(
                        'email'     => $to,
                        'exception' => $e->getMessage(),
                    )));
                }
            }
        }

        $headers = array('Content-Type: text/html; charset=UTF-8');
        $ok = wp_mail($to, $subject, $body_html, $headers);
        if (class_exists('Azure_Logger')) {
            if ($ok) {
                Azure_Logger::info('Auction: email sent via wp_mail', array_merge($context, array(
                    'email' => $to,
                )));
            } else {
                Azure_Logger::error('Auction: email failed (both paths)', array_merge($context, array(
                    'email'         => $to,
                    'acy_attempted' => $use_acy && $acy_available,
                )));
            }
        }
        return array('ok' => (bool) $ok, 'method' => 'wp_mail');
    }
}
