<?php
/**
 * PTA Tools → AI: Foundry connection, features, spending cap, backlog
 * processing and month-to-date usage.
 */

if (!defined('ABSPATH')) {
    exit;
}
if (!current_user_can('manage_options')) {
    wp_die(esc_html__('Permission denied', 'azure-plugin'));
}

if (!class_exists('Azure_AI_Module')) {
    ?>
    <div class="wrap">
        <h1><?php esc_html_e('AI', 'azure-plugin'); ?></h1>
        <div class="notice notice-info"><p>
            <?php
            printf(
                /* translators: %s: link to the PTA Tools main page */
                esc_html__('Turn on the AI module on the %s page to configure it.', 'azure-plugin'),
                '<a href="' . esc_url(admin_url('admin.php?page=azure-plugin')) . '">' . esc_html__('PTA Tools', 'azure-plugin') . '</a>'
            );
            ?>
        </p></div>
    </div>
    <?php
    return;
}

Azure_AI_Store::maybe_install();
$s = Azure_AI_Module::settings();
$configured = (new Azure_AI_Client())->is_configured();
$month_cost = Azure_AI_Store::month_cost();
$budget = (float) $s['ai_monthly_budget'];
$backlog = Azure_AI_Module::backlog_count();
$published = (int) wp_count_posts('post')->publish;
global $wpdb;
$summaries = (int) $wpdb->get_var($wpdb->prepare(
    "SELECT COUNT(*) FROM {$wpdb->postmeta} m JOIN {$wpdb->posts} p ON p.ID = m.post_id
     WHERE m.meta_key = %s AND p.post_status = 'publish' AND m.meta_value NOT LIKE %s",
    Azure_AI_Summary::META,
    '%"summary";s:0:"";%'
));
$usage = Azure_AI_Store::usage_report();
$languages = Azure_AI_Translate::supported_languages();
$nonce = wp_create_nonce('pta_ai_admin');
?>
<div class="wrap pta-ai-admin">
    <h1><?php esc_html_e('AI', 'azure-plugin'); ?></h1>

    <?php if (!empty($_GET['updated'])) : ?>
        <div class="notice notice-success is-dismissible"><p><?php esc_html_e('Settings saved.', 'azure-plugin'); ?></p></div>
    <?php endif; ?>

    <p><?php esc_html_e('Only published posts are sent to the AI service. Nothing about members, families or orders is sent.', 'azure-plugin'); ?></p>

    <h2><?php esc_html_e('Status', 'azure-plugin'); ?></h2>
    <table class="widefat striped" style="max-width:720px">
        <tbody>
            <tr><th><?php esc_html_e('Connection', 'azure-plugin'); ?></th>
                <td><?php echo $configured ? esc_html__('Endpoint and key set', 'azure-plugin') : '<strong>' . esc_html__('Not configured', 'azure-plugin') . '</strong>'; ?>
                    <button type="button" class="button" id="pta-ai-test" <?php disabled(!$configured); ?>><?php esc_html_e('Test connection', 'azure-plugin'); ?></button>
                    <span id="pta-ai-test-result"></span></td></tr>
            <tr><th><?php esc_html_e('Spent this month', 'azure-plugin'); ?></th>
                <td><?php printf(esc_html__('$%1$s of $%2$s budget', 'azure-plugin'), esc_html(number_format($month_cost, 2)), esc_html(number_format($budget, 2))); ?>
                    <?php if (Azure_AI_Store::over_budget()) : ?><strong> — <?php esc_html_e('budget reached, AI work is paused until next month', 'azure-plugin'); ?></strong><?php endif; ?></td></tr>
            <tr><th><?php esc_html_e('Posts with key points', 'azure-plugin'); ?></th>
                <td><?php echo esc_html($summaries . ' / ' . $published); ?></td></tr>
            <tr><th><?php esc_html_e('Posts with embeddings', 'azure-plugin'); ?></th>
                <td><?php echo esc_html(Azure_AI_Store::embedding_count() . ' / ' . $published); ?></td></tr>
            <tr><th><?php esc_html_e('Cached translations', 'azure-plugin'); ?></th>
                <td><?php echo esc_html(Azure_AI_Store::translation_count()); ?></td></tr>
            <tr><th><?php esc_html_e('Posts waiting', 'azure-plugin'); ?></th>
                <td><span id="pta-ai-backlog"><?php echo esc_html($backlog); ?></span>
                    <button type="button" class="button button-primary" id="pta-ai-run" <?php disabled(!$configured); ?>><?php esc_html_e('Process now', 'azure-plugin'); ?></button>
                    <span id="pta-ai-run-result"></span>
                    <ul id="pta-ai-run-errors" style="color:#b32d2e"></ul>
                    <p class="description"><?php esc_html_e('New and edited posts are processed automatically within an hour. Process now works through the backlog in batches while this page is open.', 'azure-plugin'); ?></p></td></tr>
        </tbody>
    </table>

    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
        <input type="hidden" name="action" value="pta_ai_save">
        <?php wp_nonce_field('pta_ai_save'); ?>

        <h2><?php esc_html_e('Azure AI Foundry', 'azure-plugin'); ?></h2>
        <table class="form-table">
            <tr><th><label for="ai_endpoint"><?php esc_html_e('Endpoint', 'azure-plugin'); ?></label></th>
                <td><input type="url" class="regular-text" id="ai_endpoint" name="ai_endpoint" value="<?php echo esc_attr($s['ai_endpoint']); ?>" placeholder="https://your-resource.cognitiveservices.azure.com/">
                    <p class="description"><?php esc_html_e('The resource endpoint from the Azure portal (Keys and Endpoint).', 'azure-plugin'); ?></p></td></tr>
            <tr><th><label for="ai_api_key"><?php esc_html_e('API key', 'azure-plugin'); ?></label></th>
                <td><input type="password" class="regular-text" id="ai_api_key" name="ai_api_key" value="" autocomplete="new-password" placeholder="<?php echo $s['ai_api_key'] !== '' ? esc_attr__('Saved — leave blank to keep', 'azure-plugin') : ''; ?>"></td></tr>
            <tr><th><label for="ai_chat_deployment"><?php esc_html_e('Chat deployment', 'azure-plugin'); ?></label></th>
                <td><input type="text" id="ai_chat_deployment" name="ai_chat_deployment" value="<?php echo esc_attr($s['ai_chat_deployment']); ?>"></td></tr>
            <tr><th><label for="ai_embed_deployment"><?php esc_html_e('Embedding deployment', 'azure-plugin'); ?></label></th>
                <td><input type="text" id="ai_embed_deployment" name="ai_embed_deployment" value="<?php echo esc_attr($s['ai_embed_deployment']); ?>"></td></tr>
        </table>

        <h2><?php esc_html_e('Features', 'azure-plugin'); ?></h2>
        <table class="form-table">
            <tr><th><?php esc_html_e('Key points', 'azure-plugin'); ?></th>
                <td><label><input type="checkbox" name="ai_feature_summary" value="1" <?php checked(!empty($s['ai_feature_summary'])); ?>> <?php esc_html_e('Summarise each post (editors can correct or hide it under the post editor)', 'azure-plugin'); ?></label><br>
                    <label><input type="checkbox" name="ai_show_label" value="1" <?php checked(!empty($s['ai_show_label'])); ?>> <?php esc_html_e('Label it as AI-generated', 'azure-plugin'); ?></label></td></tr>
            <tr><th><?php esc_html_e('Related stories', 'azure-plugin'); ?></th>
                <td><label><input type="checkbox" name="ai_feature_related" value="1" <?php checked(!empty($s['ai_feature_related'])); ?>> <?php esc_html_e('Pick related stories and Up Next by meaning rather than category', 'azure-plugin'); ?></label></td></tr>
            <tr><th><?php esc_html_e('Translation', 'azure-plugin'); ?></th>
                <td><label><input type="checkbox" name="ai_feature_translate" value="1" <?php checked(!empty($s['ai_feature_translate'])); ?>> <?php esc_html_e('Let readers translate posts', 'azure-plugin'); ?></label>
                    <fieldset style="margin-top:8px;columns:3;max-width:560px">
                        <?php foreach ($languages as $code => $lang) : ?>
                            <label style="display:block"><input type="checkbox" name="ai_languages[]" value="<?php echo esc_attr($code); ?>" <?php checked(in_array($code, (array) $s['ai_languages'], true)); ?>> <?php echo esc_html($lang[0] . ' (' . $lang[1] . ')'); ?></label>
                        <?php endforeach; ?>
                    </fieldset>
                    <p><label><?php esc_html_e('New translations per day, at most', 'azure-plugin'); ?>
                        <input type="number" min="0" max="1000" name="ai_translate_daily_cap" value="<?php echo esc_attr((int) $s['ai_translate_daily_cap']); ?>" style="width:80px"></label></p>
                    <p class="description"><?php esc_html_e('Each post is translated the first time someone asks for it in a language, then served from cache. Add the language switcher block to the header or the post template.', 'azure-plugin'); ?></p></td></tr>
        </table>

        <h2><?php esc_html_e('Spending', 'azure-plugin'); ?></h2>
        <table class="form-table">
            <tr><th><label for="ai_monthly_budget"><?php esc_html_e('Monthly budget (USD)', 'azure-plugin'); ?></label></th>
                <td><input type="number" step="0.01" min="0" id="ai_monthly_budget" name="ai_monthly_budget" value="<?php echo esc_attr($s['ai_monthly_budget']); ?>" style="width:100px">
                    <p class="description"><?php esc_html_e('All AI work stops for the rest of the calendar month (UTC) once the estimated spend reaches this. 0 turns it off.', 'azure-plugin'); ?></p></td></tr>
            <tr><th><?php esc_html_e('Prices per million tokens (USD)', 'azure-plugin'); ?></th>
                <td><label><?php esc_html_e('Chat input', 'azure-plugin'); ?> <input type="number" step="0.001" min="0" name="ai_price_input" value="<?php echo esc_attr($s['ai_price_input']); ?>" style="width:90px"></label>
                    <label><?php esc_html_e('Chat output', 'azure-plugin'); ?> <input type="number" step="0.001" min="0" name="ai_price_output" value="<?php echo esc_attr($s['ai_price_output']); ?>" style="width:90px"></label>
                    <label><?php esc_html_e('Embeddings', 'azure-plugin'); ?> <input type="number" step="0.001" min="0" name="ai_price_embed" value="<?php echo esc_attr($s['ai_price_embed']); ?>" style="width:90px"></label>
                    <p class="description"><?php esc_html_e('Used to estimate spend from token counts. Check the Azure pricing page for your model and region.', 'azure-plugin'); ?></p></td></tr>
            <tr><th><label for="ai_batch_size"><?php esc_html_e('Posts per hourly run', 'azure-plugin'); ?></label></th>
                <td><input type="number" min="1" max="100" id="ai_batch_size" name="ai_batch_size" value="<?php echo esc_attr((int) $s['ai_batch_size']); ?>" style="width:80px"></td></tr>
        </table>

        <?php submit_button(); ?>
    </form>

    <h2><?php esc_html_e('Usage this month', 'azure-plugin'); ?></h2>
    <?php if (!$usage) : ?>
        <p><?php esc_html_e('No AI calls yet this month.', 'azure-plugin'); ?></p>
    <?php else : ?>
        <table class="widefat striped" style="max-width:720px">
            <thead><tr><th><?php esc_html_e('Feature', 'azure-plugin'); ?></th><th><?php esc_html_e('Calls', 'azure-plugin'); ?></th><th><?php esc_html_e('Input tokens', 'azure-plugin'); ?></th><th><?php esc_html_e('Output tokens', 'azure-plugin'); ?></th><th><?php esc_html_e('Est. cost', 'azure-plugin'); ?></th></tr></thead>
            <tbody>
            <?php foreach ($usage as $row) : ?>
                <tr><td><?php echo esc_html($row->feature); ?></td>
                    <td><?php echo esc_html(number_format_i18n((int) $row->calls)); ?></td>
                    <td><?php echo esc_html(number_format_i18n((int) $row->prompt_tokens)); ?></td>
                    <td><?php echo esc_html(number_format_i18n((int) $row->completion_tokens)); ?></td>
                    <td>$<?php echo esc_html(number_format((float) $row->cost, 4)); ?></td></tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>
</div>
<script>
(function ($) {
    var nonce = <?php echo wp_json_encode($nonce); ?>;
    $('#pta-ai-test').on('click', function () {
        var $out = $('#pta-ai-test-result').text(<?php echo wp_json_encode(__('Testing…', 'azure-plugin')); ?>);
        $.post(ajaxurl, { action: 'pta_ai_test', nonce: nonce }).done(function (r) {
            $out.text(r && r.data ? r.data : 'No response');
        }).fail(function () { $out.text('Request failed'); });
    });
    $('#pta-ai-run').on('click', function () {
        var $btn = $(this).prop('disabled', true);
        var $out = $('#pta-ai-run-result');
        var total = 0;
        (function step() {
            $out.text(<?php echo wp_json_encode(__('Working…', 'azure-plugin')); ?> + ' ' + total);
            $.post(ajaxurl, { action: 'pta_ai_run_batch', nonce: nonce }).done(function (r) {
                if (!r || !r.success) {
                    $out.text(r && r.data ? r.data : 'Failed');
                    $btn.prop('disabled', false);
                    return;
                }
                total += r.data.processed;
                $('#pta-ai-backlog').text(r.data.remaining);
                if (r.data.errors.length) {
                    $('#pta-ai-run-errors').append($.map(r.data.errors, function (e) { return $('<li>').text(e); }));
                }
                if (r.data.locked) {
                    $out.text(<?php echo wp_json_encode(__('A batch is already running in the background. Try again in a few minutes.', 'azure-plugin')); ?>);
                    $btn.prop('disabled', false);
                } else if (r.data.over_budget) {
                    $out.text(<?php echo wp_json_encode(__('Stopped: monthly budget reached.', 'azure-plugin')); ?>);
                } else if (r.data.remaining > 0 && r.data.processed > 0) {
                    step();
                } else {
                    $out.text(<?php echo wp_json_encode(__('Done.', 'azure-plugin')); ?> + ' ' + total);
                    $btn.prop('disabled', false);
                }
            }).fail(function () {
                $out.text('Request failed');
                $btn.prop('disabled', false);
            });
        })();
    });
})(jQuery);
</script>
