<?php
/**
 * Calendar → Printed Calendars tab.
 *
 * Pick a calendar and month, see the poster, then print it or save it as
 * a PDF. Header picture and poster text are saved per calendar. The
 * poster itself is rendered by Azure_Printed_Calendar::serve().
 */
if (!defined('ABSPATH')) {
    exit;
}

if (!class_exists('Azure_Printed_Calendar')) {
    echo '<div class="notice notice-error"><p>' . esc_html__('Printed calendars are not available.', 'azure-plugin') . '</p></div>';
    return;
}

$printed_calendars = Azure_Printed_Calendar::calendars();
$today = new DateTimeImmutable('now', wp_timezone());
$this_month = $today->modify('first day of this month');
$month_options = array();
for ($i = -2; $i <= 12; $i++) {
    $m = $this_month->modify(($i >= 0 ? '+' : '') . $i . ' months');
    $month_options[$m->format('Y-m')] = wp_date('F Y', $m->setTime(12, 0)->getTimestamp());
}
?>
<style>
.pta-print { display: grid; grid-template-columns: 400px minmax(0, 1fr); background: #fff; border: 1px solid #dcdcde; border-radius: 10px; overflow: hidden; margin-top: 16px; min-height: 640px; }
.pta-print-form { padding: 24px; display: flex; flex-direction: column; gap: 16px; border-right: 1px solid #f0f0f1; }
.pta-print-form h2 { margin: 0; font-size: 20px; }
.pta-print-form .lead { margin: -8px 0 0; color: #50575e; font-size: 13px; line-height: 1.45; }
.pta-print-form .f { display: block; font-weight: 600; font-size: 13px; color: #3c434a; margin-bottom: 6px; }
.pta-print-form select, .pta-print-form input[type="text"], .pta-print-form input[type="url"] { width: 100%; max-width: none; }
.pta-print .row2 { display: grid; grid-template-columns: 1fr 1fr; gap: 12px; }
.pta-print .seg { display: grid; grid-auto-flow: column; grid-auto-columns: 1fr; border: 1px solid #c3c4c7; border-radius: 6px; overflow: hidden; }
.pta-print .seg label { margin: 0; padding: 7px 4px; text-align: center; font-size: 13px; line-height: 1.2; border-left: 1px solid #dcdcde; cursor: pointer; }
.pta-print .seg label:first-child { border-left: 0; }
.pta-print .seg input { position: absolute; opacity: 0; pointer-events: none; }
.pta-print .seg small { display: block; color: #646970; font-size: 11px; }
.pta-print .seg label:has(input:checked) { background: #0f5fa8; color: #fff; }
.pta-print .seg label:has(input:checked) small { color: #d5e5f5; }
.pta-print .seg label:has(input:focus-visible) { box-shadow: inset 0 0 0 2px #72aee6; }
.pta-print .hint { font-size: 12px; color: #646970; margin-top: 5px; line-height: 1.4; }
.pta-print .hint.warn { color: #996800; }
.pta-print .headerpick { display: flex; gap: 10px; align-items: center; flex-wrap: wrap; }
.pta-print .thumb { width: 96px; height: 54px; border-radius: 6px; background: #f6f7f7 center / cover no-repeat; border: 0; padding: 0; cursor: pointer; outline: 1px solid #dcdcde; outline-offset: 1px; font-size: 12px; color: #646970; }
.pta-print .thumb.none { background-image: repeating-linear-gradient(45deg, #f6f7f7 0 8px, #fff 8px 16px); }
.pta-print .thumb.on { outline: 3px solid #0f5fa8; }
.pta-print .thumb:disabled { cursor: default; }
.pta-print .actions { margin-top: auto; display: flex; gap: 10px; flex-wrap: wrap; }
.pta-print details { border: 1px solid #dcdcde; border-radius: 8px; padding: 0 12px; }
.pta-print details[open] { padding-bottom: 12px; }
.pta-print summary { cursor: pointer; padding: 10px 0; font-weight: 600; }
.pta-print details .fld { margin-top: 10px; }
.pta-print details label { font-size: 12px; color: #3c434a; display: block; margin-bottom: 3px; }
.pta-print .save-row { display: flex; align-items: center; gap: 10px; margin-top: 12px; }
.pta-print .save-status { font-size: 12px; color: #646970; }
.pta-print-preview { background: #f0f0f1; display: flex; flex-direction: column; align-items: center; justify-content: center; padding: 24px; min-width: 0; }
.pta-print-frame { position: relative; background: #fff; box-shadow: 0 8px 24px rgba(0,0,0,.18); overflow: hidden; }
.pta-print-frame iframe { position: absolute; left: 0; top: 0; border: 0; transform-origin: 0 0; background: #fff; }
.pta-print-frame.loading::after { content: ""; position: absolute; inset: 0; background: rgba(255,255,255,.6); }
.pta-print .cap { margin-top: 14px; font-size: 13px; color: #50575e; text-align: center; }
.pta-print .cap .warn { display: block; color: #996800; margin-top: 4px; }
@media (max-width: 1100px) { .pta-print { grid-template-columns: 1fr; } .pta-print-form { border-right: 0; border-bottom: 1px solid #f0f0f1; } }
</style>

<?php if (empty($printed_calendars)): ?>
    <div class="notice notice-info inline" style="margin-top:16px"><p>
        <?php esc_html_e('No calendars are synced yet. Add one on the Sync tab, then come back here to print it.', 'azure-plugin'); ?>
    </p></div>
    <?php return; ?>
<?php endif; ?>

<div class="pta-print" id="pta-print">
    <form class="pta-print-form" onsubmit="return false">
        <h2><?php esc_html_e('Print a calendar', 'azure-plugin'); ?></h2>
        <p class="lead"><?php esc_html_e('Makes a wall poster from the live events. Print close to the start of the month so late changes are included.', 'azure-plugin'); ?></p>

        <div>
            <label class="f" for="pta-print-calendar"><?php esc_html_e('Calendar', 'azure-plugin'); ?></label>
            <select id="pta-print-calendar" name="calendar">
                <?php foreach ($printed_calendars as $id => $label): ?>
                    <option value="<?php echo esc_attr($id); ?>"><?php echo esc_html($label); ?></option>
                <?php endforeach; ?>
            </select>
        </div>

        <div class="row2">
            <div>
                <label class="f" for="pta-print-month"><?php esc_html_e('Month', 'azure-plugin'); ?></label>
                <select id="pta-print-month" name="month">
                    <?php foreach ($month_options as $value => $label): ?>
                        <option value="<?php echo esc_attr($value); ?>" <?php selected($value, $this_month->format('Y-m')); ?>><?php echo esc_html($label); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div>
                <span class="f"><?php esc_html_e('Days', 'azure-plugin'); ?></span>
                <div class="seg" role="radiogroup" aria-label="<?php esc_attr_e('Days', 'azure-plugin'); ?>">
                    <label><input type="radio" name="days" value="weekdays" checked><?php esc_html_e('Mon–Fri', 'azure-plugin'); ?></label>
                    <label><input type="radio" name="days" value="week"><?php esc_html_e('Full week', 'azure-plugin'); ?></label>
                </div>
            </div>
        </div>

        <div>
            <span class="f"><?php esc_html_e('Paper', 'azure-plugin'); ?></span>
            <div class="seg" role="radiogroup" aria-label="<?php esc_attr_e('Paper', 'azure-plugin'); ?>">
                <?php foreach (Azure_Printed_Calendar::PAPERS as $key => $paper): ?>
                    <label><input type="radio" name="paper" value="<?php echo esc_attr($key); ?>" <?php checked($key, 'a3'); ?>><?php echo esc_html($paper['label']); ?><small><?php echo esc_html($paper['note']); ?></small></label>
                <?php endforeach; ?>
            </div>
            <div class="hint"><?php esc_html_e('Text and lines stay sharp at any size, so a print shop can enlarge the PDF to A2, A1 or 24×36″. In the print dialog choose the same paper size, landscape, and no margins.', 'azure-plugin'); ?></div>
        </div>

        <div>
            <span class="f"><?php esc_html_e('Header picture', 'azure-plugin'); ?></span>
            <div class="headerpick">
                <button type="button" class="thumb" id="pta-print-thumb" aria-label="<?php esc_attr_e('Use the header picture', 'azure-plugin'); ?>"></button>
                <button type="button" class="thumb none" id="pta-print-thumb-none"><?php esc_html_e('None', 'azure-plugin'); ?></button>
                <button type="button" class="button button-small" id="pta-print-choose"><?php esc_html_e('Change picture…', 'azure-plugin'); ?></button>
            </div>
            <div class="hint" id="pta-print-header-hint"></div>
            <label style="display:flex;gap:8px;align-items:center;margin-top:10px">
                <input type="checkbox" name="qr" id="pta-print-qr" checked> <?php esc_html_e('QR code to the live calendar', 'azure-plugin'); ?>
            </label>
            <div class="hint" id="pta-print-qr-hint"></div>
        </div>

        <details id="pta-print-details">
            <summary><?php esc_html_e('Poster text for this calendar', 'azure-plugin'); ?></summary>
            <div class="fld">
                <label for="pta-print-kicker"><?php esc_html_e('Line above the title', 'azure-plugin'); ?></label>
                <input type="text" id="pta-print-kicker" data-setting="kicker" placeholder="<?php esc_attr_e('e.g. Lincoln Elementary · Art Docents', 'azure-plugin'); ?>">
            </div>
            <div class="fld">
                <label for="pta-print-subtitle"><?php esc_html_e('Line under the month', 'azure-plugin'); ?></label>
                <input type="text" id="pta-print-subtitle" data-setting="subtitle" placeholder="<?php esc_attr_e('e.g. Lessons are in the Art Room unless noted.', 'azure-plugin'); ?>">
            </div>
            <div class="fld">
                <label for="pta-print-location"><?php esc_html_e('Usual location (left off each event)', 'azure-plugin'); ?></label>
                <input type="text" id="pta-print-location" data-setting="usual_location" placeholder="<?php esc_attr_e('e.g. Art Room', 'azure-plugin'); ?>">
            </div>
            <div class="fld">
                <label for="pta-print-footer"><?php esc_html_e('Footer message', 'azure-plugin'); ?></label>
                <input type="text" id="pta-print-footer" data-setting="footer" placeholder="<?php esc_attr_e('e.g. Thank you, Art Docents!', 'azure-plugin'); ?>">
            </div>
            <div class="fld">
                <label for="pta-print-link"><?php esc_html_e('Live calendar link for the QR code', 'azure-plugin'); ?></label>
                <input type="url" id="pta-print-link" data-setting="link" placeholder="<?php esc_attr_e('Found automatically from the page that shows this calendar', 'azure-plugin'); ?>">
            </div>
            <div class="save-row">
                <button type="button" class="button button-primary" id="pta-print-save"><?php esc_html_e('Save for this calendar', 'azure-plugin'); ?></button>
                <span class="save-status" id="pta-print-save-status" aria-live="polite"></span>
            </div>
        </details>

        <div class="actions">
            <button type="button" class="button button-primary button-hero" id="pta-print-print">
                <span class="dashicons dashicons-printer" style="margin:12px 4px 0 0"></span><?php esc_html_e('Print or save as PDF', 'azure-plugin'); ?>
            </button>
            <a class="button button-hero" id="pta-print-open" href="#" target="_blank" rel="noopener"><?php esc_html_e('Open full size', 'azure-plugin'); ?></a>
        </div>
    </form>

    <div class="pta-print-preview">
        <div class="pta-print-frame loading" id="pta-print-frame">
            <iframe id="pta-print-iframe" title="<?php esc_attr_e('Poster preview', 'azure-plugin'); ?>"></iframe>
        </div>
        <div class="cap" id="pta-print-cap" aria-live="polite"></div>
    </div>
</div>
