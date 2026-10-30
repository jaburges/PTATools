<?php
/**
 * Tickets Module - ticket lookup and email delivery.
 * Tickets are created by Azure_Tickets_Module when the order is paid.
 */

if (!defined('ABSPATH')) {
    exit;
}

class Azure_Tickets_Generator {
    
    /**
     * Get tickets for an order
     * 
     * @param int $order_id
     * @return array
     */
    public function get_order_tickets($order_id) {
        global $wpdb;
        $tickets_table = $wpdb->prefix . 'azure_tickets';
        
        return $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$tickets_table} WHERE order_id = %d AND status = 'active' ORDER BY product_id, row_letter, seat_number, id",
            $order_id
        ));
    }
    
    /**
     * Get a single ticket by ID
     * 
     * @param int $ticket_id
     * @return object|null
     */
    public function get_ticket($ticket_id) {
        global $wpdb;
        $tickets_table = $wpdb->prefix . 'azure_tickets';
        
        return $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$tickets_table} WHERE id = %d",
            $ticket_id
        ));
    }
    
    /**
     * Get ticket by code
     * 
     * @param string $code
     * @return object|null
     */
    public function get_ticket_by_code($code) {
        global $wpdb;
        $tickets_table = $wpdb->prefix . 'azure_tickets';
        
        return $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$tickets_table} WHERE ticket_code = %s",
            strtoupper($code)
        ));
    }
    
    /**
     * Email an order's tickets to the buyer.
     *
     * @param int    $order_id
     * @param string $test_to Send a copy marked as a test here instead of to the buyer.
     * @return bool
     */
    public function send_ticket_email($order_id, $test_to = '') {
        $order = wc_get_order($order_id);
        if (!$order) {
            return false;
        }
        $tickets = $this->get_order_tickets($order_id);
        if (empty($tickets)) {
            return false;
        }
        $theme = Azure_Tickets_Config::email_theme((int) $tickets[0]->product_id);
        $subject = str_replace(
            array('{{event_name}}', '{{order_number}}'),
            array($theme['title'] !== '' ? $theme['title'] : __('your event', 'azure-plugin'), $order->get_order_number()),
            Azure_Settings::get_setting('tickets_email_subject', 'Your tickets for {{event_name}}')
        );
        $to = $order->get_billing_email();
        if ($test_to !== '') {
            /* translators: 1: email subject, 2: the buyer's email address */
            $subject = sprintf(__('[Test] %1$s (would go to %2$s)', 'azure-plugin'), $subject, $to);
            $to = $test_to;
        }
        $sent = wp_mail($to, $subject, $this->email_html($order, $tickets), array('Content-Type: text/html; charset=UTF-8'));
        if ($sent && $test_to === '') {
            $order->update_meta_data(self::EMAILED_META, time());
            $order->save_meta_data();
        }
        return $sent;
    }

    /** When the tickets were last emailed to the buyer (Unix time). */
    const EMAILED_META = '_pta_tickets_emailed';

    /**
     * @return int When the order's tickets were last emailed to the buyer; 0 if never.
     */
    public static function emailed_at($order) {
        return (int) $order->get_meta(self::EMAILED_META);
    }

    /**
     * The email as the buyer would get it, for previewing; '' without tickets.
     */
    public function preview_ticket_email($order_id) {
        $order = wc_get_order($order_id);
        $tickets = $order ? $this->get_order_tickets($order_id) : array();
        return $tickets ? $this->email_html($order, $tickets) : '';
    }

    /**
     * Whether the order bought this performance's cast & crew product.
     */
    private function is_cast_crew($order, $product_id) {
        $vip = Azure_Tickets_Config::vip_products($product_id);
        foreach ($order->get_items() as $item) {
            if (method_exists($item, 'get_product_id') && array_intersect(array((int) $item->get_product_id(), (int) $item->get_variation_id()), $vip)) {
                return true;
            }
        }
        return false;
    }

    /**
     * Short seat name for under a QR code: "D4", or the section for general admission.
     */
    private static function seat_short($ticket, $n) {
        if (!empty($ticket->row_letter)) {
            return $ticket->row_letter . (int) $ticket->seat_number;
        }
        /* translators: %d: ticket number within the order */
        return sprintf(__('Ticket %d', 'azure-plugin'), $n);
    }

    /**
     * Table layout and inline styles throughout: Gmail and Outlook drop
     * flexbox and most <style> rules.
     *
     * @param WC_Order $order
     * @param object[] $tickets Active tickets, sorted by performance, row and seat.
     */
    private function email_html($order, array $tickets) {
        $by_product = array();
        foreach ($tickets as $ticket) {
            $by_product[(int) $ticket->product_id][] = $ticket;
        }
        $first = (int) $tickets[0]->product_id;
        $theme = Azure_Tickets_Config::email_theme($first);
        $wallet_on = class_exists('Azure_Tickets_Apple_Wallet') && Azure_Tickets_Apple_Wallet::is_configured();
        $cast_crew = $this->is_cast_crew($order, $first);
        $org = (string) Azure_Settings::get_setting('org_name', get_bloginfo('name'));
        $sans = "-apple-system,'Segoe UI',Roboto,Helvetica,Arial,sans-serif";
        $serif = "Georgia,'Times New Roman',serif";
        $navy = '#10203f';
        $gold = '#f3d27a';
        $count = count($tickets);
        $title = $theme['title'] !== '' ? $theme['title'] : __('the show', 'azure-plugin');
        ob_start();
        ?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?php echo esc_html($theme['headline']); ?></title>
<style>
  @media (max-width: 620px) {
    .pta-wrap { width: 100% !important; }
    .pta-pad { padding-left: 20px !important; padding-right: 20px !important; }
    .pta-seat { font-size: 21px !important; }
    .pta-qr-row { padding-left: 6px !important; padding-right: 6px !important; }
    .pta-qr-cell { padding: 3px !important; }
    .pta-qr-inner { padding: 8px 6px !important; }
    .pta-qr-cell img { width: 92px !important; height: 92px !important; }
    .pta-wallet { font-size: 15px !important; padding: 13px 18px !important; }
  }
</style>
</head>
<body style="margin:0; padding:0; background:#0b1633;">
<div style="display:none; max-height:0; overflow:hidden;"><?php
    /* translators: 1: show name, 2: number of tickets */
    echo esc_html(sprintf(_n('Your ticket for %1$s: %2$d ticket with its QR code.', 'Your tickets for %1$s: %2$d tickets with their QR codes.', $count, 'azure-plugin'), $title, $count));
?></div>
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#0b1633;">
<tr><td align="center" style="padding:24px 12px;">
<table role="presentation" class="pta-wrap" width="600" cellpadding="0" cellspacing="0" style="width:600px; max-width:600px; background:#fdf8ec; border-radius:14px; overflow:hidden; border:2px solid #d9b45a;">

<?php if ($theme['banner'] !== ''): ?>
<tr><td style="background:<?php echo $navy; ?>; line-height:0;">
  <img src="<?php echo esc_url($theme['banner']); ?>" width="600" alt="<?php echo esc_attr($title); ?>" style="display:block; width:100%; height:auto; border:0;">
</td></tr>
<?php endif; ?>

<tr><td align="center" class="pta-pad" style="background:<?php echo $navy; ?>; padding:<?php echo $theme['banner'] !== '' ? '6px' : '28px'; ?> 32px 28px;">
  <div style="font-family:<?php echo $serif; ?>; color:<?php echo $gold; ?>; font-size:13px; letter-spacing:3px; text-transform:uppercase;">&#10022; <?php echo esc_html($cast_crew ? __('Cast & Crew Reserved Seating', 'azure-plugin') : __('Your tickets', 'azure-plugin')); ?> &#10022;</div>
  <div style="font-family:<?php echo $serif; ?>; color:#ffffff; font-size:30px; line-height:1.2; font-weight:bold; padding-top:8px;"><?php echo esc_html($theme['headline']); ?></div>
</td></tr>

<tr><td class="pta-pad" style="padding:28px 40px 8px; font-family:<?php echo $sans; ?>; color:#24304d; font-size:16px; line-height:1.6;">
  <p style="margin:0 0 12px;"><?php
    /* translators: %s: buyer's first name */
    echo esc_html(sprintf(__('Hi %s,', 'azure-plugin'), $order->get_billing_first_name()));
  ?></p>
  <p style="margin:0;"><?php
    echo $cast_crew
        /* translators: %s: show name */
        ? wp_kses(sprintf(__('Thank you for supporting our cast &amp; crew! Your reserved seats for <strong>%s</strong> are below.', 'azure-plugin'), esc_html($title)), array('strong' => array()))
        /* translators: %s: show name */
        : wp_kses(sprintf(__('Thank you for your order! Your tickets for <strong>%s</strong> are below.', 'azure-plugin'), esc_html($title)), array('strong' => array()));
    echo ' ' . esc_html($wallet_on ? __('Add them to Apple Wallet, or show the QR codes at the door.', 'azure-plugin') : __('Show the QR codes at the door.', 'azure-plugin'));
  ?></p>
</td></tr>

<?php foreach ($by_product as $product_id => $group):
    $ts = Azure_Tickets_Config::event_ts($product_id);
    $location = Azure_Tickets_Config::email_theme($product_id)['location'];
    $n = count($group);
?>
<tr><td class="pta-pad" style="padding:20px 40px 4px;">
  <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:<?php echo $navy; ?>; border-radius:10px;">
    <tr><td style="padding:18px 22px; font-family:<?php echo $sans; ?>; color:#ffffff; font-size:15px; line-height:1.7;">
      <?php if ($ts): ?>
      <div style="font-family:<?php echo $serif; ?>; color:<?php echo $gold; ?>; font-size:20px; font-weight:bold;"><?php echo esc_html(wp_date('l, F j, Y', $ts)); ?></div>
      <div>&#9733;&nbsp; <?php
        /* translators: 1: start time, 2: arrival time */
        echo wp_kses(sprintf(__('Show starts <strong>%1$s</strong> &middot; please arrive by %2$s', 'azure-plugin'), esc_html(wp_date('g:i A', $ts)), esc_html(wp_date('g:i', $ts - 15 * MINUTE_IN_SECONDS))), array('strong' => array()));
      ?></div>
      <?php endif; ?>
      <?php if ($location !== ''): ?><div>&#9733;&nbsp; <?php echo esc_html($location); ?></div><?php endif; ?>
      <div style="color:#b9c4dd; font-size:13px;"><?php
        /* translators: 1: order number, 2: number of tickets */
        echo esc_html(sprintf(_n('Order #%1$s · %2$d ticket', 'Order #%1$s · %2$d tickets', $n, 'azure-plugin'), $order->get_order_number(), $n));
      ?></div>
    </td></tr>
  </table>
</td></tr>

<tr><td class="pta-pad" style="padding:24px 40px 8px;">
  <div style="font-family:<?php echo $serif; ?>; color:<?php echo $navy; ?>; font-size:14px; letter-spacing:3px; text-transform:uppercase; padding-bottom:10px; text-align:center;"><?php echo esc_html(_n('Your seat', 'Your seats', $n, 'azure-plugin')); ?></div>
  <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="border:2px dashed #d9b45a; border-radius:10px; background:#ffffff;">
    <tr><td align="center" style="padding:14px 18px; font-family:<?php echo $sans; ?>; color:<?php echo $navy; ?>;">
      <?php foreach ($group as $ticket): ?>
      <div class="pta-seat" style="font-size:24px; font-weight:800; line-height:1.55;"><?php echo esc_html(Azure_Tickets_Config::ticket_seat_label($ticket)); ?></div>
      <?php endforeach; ?>
    </td></tr>
  </table>
</td></tr>

<?php if ($wallet_on): ?>
<tr><td align="center" class="pta-pad" style="padding:24px 40px 4px;">
  <?php for ($part = 0; $part * Azure_Tickets_Apple_Wallet::BUNDLE_MAX < $n; $part++):
      $in_part = min(Azure_Tickets_Apple_Wallet::BUNDLE_MAX, $n - $part * Azure_Tickets_Apple_Wallet::BUNDLE_MAX);
      if ($n === 1) {
          $label = __('Add to Apple Wallet', 'azure-plugin');
      } elseif ($in_part === $n) {
          /* translators: %d: number of tickets (2 or more) */
          $label = sprintf(__('Add all %d tickets to Apple Wallet', 'azure-plugin'), $n);
      } else {
          /* translators: %d: number of tickets */
          $label = sprintf(_n('Add %d ticket to Apple Wallet', 'Add %d tickets to Apple Wallet', $in_part, 'azure-plugin'), $in_part);
      }
  ?>
  <div style="padding:4px 0;"><a href="<?php echo esc_url(Azure_Tickets_Apple_Wallet::order_url($order->get_id(), $product_id, $part)); ?>" class="pta-wallet" style="display:inline-block; background:#000000; color:#ffffff; text-decoration:none; font-family:<?php echo $sans; ?>; font-size:17px; font-weight:600; padding:14px 28px; border-radius:10px;"><?php echo esc_html($label); ?></a></div>
  <?php endfor; ?>
  <div style="font-family:<?php echo $sans; ?>; color:#5b6684; font-size:13px; padding-top:8px;"><?php esc_html_e('On another phone? Just show the QR codes below at the door.', 'azure-plugin'); ?></div>
</td></tr>
<?php endif; ?>

<tr><td class="pta-pad pta-qr-row" style="padding:22px 28px 6px;">
  <div style="font-family:<?php echo $serif; ?>; color:<?php echo $navy; ?>; font-size:14px; letter-spacing:3px; text-transform:uppercase; padding-bottom:6px; text-align:center;"><?php echo esc_html(_n('Your ticket', 'Your tickets', $n, 'azure-plugin')); ?></div>
  <?php if ($n > 1): ?>
  <div style="font-family:<?php echo $sans; ?>; color:#5b6684; font-size:13px; text-align:center; padding-bottom:12px;"><?php esc_html_e('Each seat has its own code — we scan one per person.', 'azure-plugin'); ?></div>
  <?php endif; ?>
  <table role="presentation" width="100%" cellpadding="0" cellspacing="0">
  <?php foreach (array_chunk($group, 3) as $r => $row): ?>
    <tr>
    <?php foreach ($row as $i => $ticket): ?>
      <td class="pta-qr-cell" align="center" width="33%" style="padding:8px;">
        <table role="presentation" cellpadding="0" cellspacing="0" style="background:#ffffff; border:1px solid #e6dcc1; border-radius:10px;"><tr>
          <td class="pta-qr-inner" align="center" style="padding:12px 12px 10px; font-family:<?php echo $sans; ?>;">
            <img src="<?php echo esc_url(Azure_Tickets_QR::url($ticket->ticket_code)); ?>" width="140" height="140" alt="<?php echo esc_attr($ticket->ticket_code); ?>" style="display:block;">
            <div style="font-size:17px; font-weight:800; color:<?php echo $navy; ?>; padding-top:6px;"><?php echo esc_html(self::seat_short($ticket, $r * 3 + $i + 1)); ?></div>
            <div style="font-family:Menlo,Consolas,monospace; font-size:11px; color:#5b6684; letter-spacing:1px;"><?php echo esc_html($ticket->ticket_code); ?></div>
          </td>
        </tr></table>
      </td>
    <?php endforeach; ?>
    <?php for ($pad = count($row); $pad < 3 && $n > 3; $pad++): ?><td class="pta-qr-cell" width="33%" style="padding:8px;">&nbsp;</td><?php endfor; ?>
    </tr>
  <?php endforeach; ?>
  </table>
</td></tr>
<?php endforeach; ?>

<tr><td class="pta-pad" style="padding:18px 40px 26px; font-family:<?php echo $sans; ?>; color:#24304d; font-size:14px; line-height:1.6;">
  <div style="border-top:1px solid #e6dcc1; padding-top:16px;">
    <strong><?php esc_html_e('Good to know', 'azure-plugin'); ?></strong><br>
    <?php esc_html_e('Please arrive 15 minutes early so we can scan everyone in before curtain. Screenshots and printouts work too.', 'azure-plugin'); ?><br>
    <?php echo wp_kses(sprintf(
        /* translators: 1: contact email link, 2: order link */
        __('Questions? Email %1$s or %2$s.', 'azure-plugin'),
        '<a href="mailto:' . esc_attr($theme['contact']) . '" style="color:#2a4a8f;">' . esc_html($theme['contact']) . '</a>',
        '<a href="' . esc_url($order->get_view_order_url()) . '" style="color:#2a4a8f;">' . esc_html__('view your order', 'azure-plugin') . '</a>'
    ), array('a' => array('href' => array(), 'style' => array()))); ?>
  </div>
</td></tr>

<tr><td align="center" style="background:<?php echo $navy; ?>; padding:20px 32px; font-family:<?php echo $serif; ?>; color:<?php echo $gold; ?>; font-size:15px; font-style:italic;">
  <?php if ($theme['closing'] !== ''): ?>&ldquo;<?php echo esc_html($theme['closing']); ?>&rdquo;<?php endif; ?>
  <div style="font-family:<?php echo $sans; ?>; font-style:normal; color:#b9c4dd; font-size:12px; padding-top:<?php echo $theme['closing'] !== '' ? '8px' : '0'; ?>;"><?php echo esc_html($org); ?></div>
</td></tr>

</table>
</td></tr>
</table>
</body>
</html>
        <?php
        return ob_get_clean();
    }

    /**
     * Generate PDF of tickets
     * 
     * @param int $order_id
     * @param array $tickets
     * @return string|false Path to generated PDF or false on failure
     */
    public function generate_tickets_pdf($order_id, $tickets) {
        // Check if TCPDF or similar is available
        // For now, return false as PDF generation requires additional library
        // Could use mPDF, TCPDF, or Dompdf
        
        // Placeholder for PDF generation
        // In production, implement with a PDF library
        
        return false;
    }
    
    /**
     * Cancel a ticket
     * 
     * @param int $ticket_id
     * @return bool
     */
    public function cancel_ticket($ticket_id) {
        global $wpdb;
        $tickets_table = $wpdb->prefix . 'azure_tickets';
        
        $result = $wpdb->update(
            $tickets_table,
            array('status' => 'cancelled'),
            array('id' => $ticket_id)
        );
        
        return $result !== false;
    }
    
    /**
     * Resend ticket email
     * 
     * @param int $ticket_id
     * @return bool
     */
    public function resend_ticket_email($ticket_id) {
        $ticket = $this->get_ticket($ticket_id);
        if (!$ticket) {
            return false;
        }
        
        return $this->send_ticket_email($ticket->order_id);
    }
}

