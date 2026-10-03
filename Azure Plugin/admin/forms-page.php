<?php
/**
 * Communications > Forms: list, builder, entries and themes.
 */

if (!defined('ABSPATH')) {
    exit;
}

$forms_enabled = class_exists('Azure_Forms_Module') && !empty($settings['enable_forms']);
$base_url = admin_url('admin.php?page=azure-plugin-forms');
$tab = isset($_GET['tab']) ? sanitize_key(wp_unslash($_GET['tab'])) : '';
$form_ref = isset($_GET['form']) ? sanitize_text_field(wp_unslash($_GET['form'])) : '';
?>
<div class="wrap azure-forms-admin">
    <h1 class="wp-heading-inline"><?php esc_html_e('Forms', 'azure-plugin'); ?></h1>

<?php if (!$forms_enabled) : ?>
    <div class="notice notice-info inline">
        <p>
            <?php esc_html_e('The Forms module is off. Turn it on from the PTA Tools dashboard to build forms and collect entries.', 'azure-plugin'); ?>
            <a class="button" href="<?php echo esc_url(admin_url('admin.php?page=azure-plugin')); ?>"><?php esc_html_e('Open dashboard', 'azure-plugin'); ?></a>
        </p>
    </div>
</div>
<?php
    return;
endif;

$module = Azure_Forms_Module::get_instance();

// ─── Entries ─────────────────────────────────────────────────────────
if ($tab === 'entries') :
    $form = $module->get_form((int) $form_ref);
    if (!$form) {
        echo '<div class="notice notice-error inline"><p>' . esc_html__('Form not found.', 'azure-plugin') . '</p></div></div>';
        return;
    }
    $status = isset($_GET['status']) ? sanitize_key(wp_unslash($_GET['status'])) : '';
    $paged = max(1, isset($_GET['paged']) ? (int) $_GET['paged'] : 1);
    $result = $module->get_entries($form['id'], $status, $paged);
    $inputs = Azure_Forms_Schema::input_fields($form['schema']);
    $columns = array_slice($inputs, 0, 3);
    $pages = max(1, (int) ceil($result['total'] / Azure_Forms_Module::PER_PAGE));
    $export_url = wp_nonce_url(admin_url('admin-post.php?action=azure_forms_export&form=' . $form['id'] . ($status !== '' ? '&status=' . $status : '')), 'azure_forms_export');
    $focus_entry = isset($_GET['entry']) ? $module->get_entry((int) $_GET['entry']) : null;
    if ($focus_entry && $focus_entry['form_id'] !== $form['id']) {
        $focus_entry = null;
    }

    $render_detail = function ($entry) use ($inputs) {
        $known = array();
        $out = '<table class="widefat striped azure-forms-entry-table"><tbody>';
        foreach ($inputs as $field) {
            $known[$field['name']] = true;
            $value = Azure_Forms_Schema::display_value($entry['data'][$field['name']] ?? '');
            $out .= '<tr><th scope="row">' . esc_html(wp_strip_all_tags($field['label'])) . '</th><td>' . nl2br(esc_html($value)) . '</td></tr>';
        }
        foreach ($entry['data'] as $name => $value) {
            if (!isset($known[$name])) {
                $out .= '<tr><th scope="row">' . esc_html($name) . '</th><td>' . nl2br(esc_html(Azure_Forms_Schema::display_value($value))) . '</td></tr>';
            }
        }
        $out .= '</tbody></table>';
        return $out;
    };
    ?>
    <a href="<?php echo esc_url($base_url); ?>" class="page-title-action"><?php esc_html_e('All forms', 'azure-plugin'); ?></a>
    <a href="<?php echo esc_url($base_url . '&form=' . $form['id']); ?>" class="page-title-action"><?php esc_html_e('Edit form', 'azure-plugin'); ?></a>
    <hr class="wp-header-end">

    <h2><?php echo esc_html(sprintf(__('Entries: %s', 'azure-plugin'), $form['title'])); ?></h2>

    <?php if ($focus_entry) : ?>
        <div class="azure-forms-focus-entry" data-entry="<?php echo (int) $focus_entry['id']; ?>" data-status="<?php echo esc_attr($focus_entry['status']); ?>">
            <h3><?php echo esc_html(sprintf(__('Entry #%1$d, %2$s', 'azure-plugin'), $focus_entry['id'], get_date_from_gmt($focus_entry['created_at'], 'M j, Y g:i a'))); ?></h3>
            <?php echo $render_detail($focus_entry); ?>
        </div>
    <?php endif; ?>

    <div class="azure-forms-entries-bar">
        <ul class="subsubsub">
            <?php
            $filters = array('' => __('All', 'azure-plugin'), 'new' => __('New', 'azure-plugin'), 'read' => __('Read', 'azure-plugin'), 'spam' => __('Spam', 'azure-plugin'));
            $links = array();
            foreach ($filters as $key => $label) {
                $url = $base_url . '&tab=entries&form=' . $form['id'] . ($key !== '' ? '&status=' . $key : '');
                $links[] = '<li><a href="' . esc_url($url) . '"' . ($status === $key ? ' class="current" aria-current="page"' : '') . '>' . esc_html($label) . '</a>';
            }
            echo implode(' | </li>', $links) . '</li>';
            ?>
        </ul>
        <a class="button" href="<?php echo esc_url($export_url); ?>"><?php esc_html_e('Export CSV', 'azure-plugin'); ?></a>
    </div>

    <table class="wp-list-table widefat fixed striped azure-forms-entries">
        <thead>
            <tr>
                <th scope="col" class="column-date"><?php esc_html_e('Submitted', 'azure-plugin'); ?></th>
                <?php foreach ($columns as $field) : ?>
                    <th scope="col"><?php echo esc_html(wp_strip_all_tags($field['label'])); ?></th>
                <?php endforeach; ?>
                <th scope="col" class="column-actions"><?php esc_html_e('Actions', 'azure-plugin'); ?></th>
            </tr>
        </thead>
        <tbody>
        <?php if (empty($result['rows'])) : ?>
            <tr><td colspan="<?php echo count($columns) + 2; ?>"><?php esc_html_e('No entries yet.', 'azure-plugin'); ?></td></tr>
        <?php endif; ?>
        <?php foreach ($result['rows'] as $entry) : ?>
            <tr class="azure-forms-entry status-<?php echo esc_attr($entry['status']); ?>" data-entry="<?php echo (int) $entry['id']; ?>" data-status="<?php echo esc_attr($entry['status']); ?>">
                <td class="column-date">
                    <?php if ($entry['status'] === 'new') : ?><span class="azure-forms-dot" title="<?php esc_attr_e('New', 'azure-plugin'); ?>"></span><?php endif; ?>
                    <?php echo esc_html(get_date_from_gmt($entry['created_at'], 'M j, Y g:i a')); ?>
                    <?php if ($entry['user_id']) : ?>
                        <br><a href="<?php echo esc_url(get_edit_user_link($entry['user_id'])); ?>"><?php esc_html_e('Signed-in user', 'azure-plugin'); ?></a>
                    <?php endif; ?>
                </td>
                <?php foreach ($columns as $field) : ?>
                    <td><?php echo esc_html(wp_trim_words(Azure_Forms_Schema::display_value($entry['data'][$field['name']] ?? ''), 12)); ?></td>
                <?php endforeach; ?>
                <td class="column-actions">
                    <button type="button" class="button-link azure-forms-entry-view" aria-expanded="false"><?php esc_html_e('View', 'azure-plugin'); ?></button>
                    |
                    <?php if ($entry['status'] === 'spam') : ?>
                        <button type="button" class="button-link azure-forms-entry-op" data-op="not_spam"><?php esc_html_e('Not spam', 'azure-plugin'); ?></button>
                    <?php else : ?>
                        <button type="button" class="button-link azure-forms-entry-op" data-op="spam"><?php esc_html_e('Spam', 'azure-plugin'); ?></button>
                    <?php endif; ?>
                    |
                    <button type="button" class="button-link button-link-delete azure-forms-entry-op" data-op="delete"><?php esc_html_e('Delete', 'azure-plugin'); ?></button>
                </td>
            </tr>
            <tr class="azure-forms-entry-detail" data-entry="<?php echo (int) $entry['id']; ?>" hidden>
                <td colspan="<?php echo count($columns) + 2; ?>"><?php echo $render_detail($entry); ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>

    <?php if ($pages > 1) : ?>
        <div class="tablenav bottom"><div class="tablenav-pages">
            <?php
            echo paginate_links(array(
                'base'    => add_query_arg('paged', '%#%'),
                'format'  => '',
                'current' => $paged,
                'total'   => $pages,
            ));
            ?>
        </div></div>
    <?php endif; ?>
</div>
<?php
    return;
endif;

// ─── Settings ────────────────────────────────────────────────────────
if ($tab === 'settings') :
    $keys = Azure_Forms_Module::turnstile_keys();
    ?>
    <a href="<?php echo esc_url($base_url); ?>" class="page-title-action"><?php esc_html_e('All forms', 'azure-plugin'); ?></a>
    <hr class="wp-header-end">
    <nav class="nav-tab-wrapper">
        <a class="nav-tab" href="<?php echo esc_url($base_url); ?>"><?php esc_html_e('Forms', 'azure-plugin'); ?></a>
        <a class="nav-tab" href="<?php echo esc_url($base_url . '&tab=themes'); ?>"><?php esc_html_e('Themes', 'azure-plugin'); ?></a>
        <a class="nav-tab nav-tab-active" href="<?php echo esc_url($base_url . '&tab=settings'); ?>"><?php esc_html_e('Settings', 'azure-plugin'); ?></a>
    </nav>
    <?php if (!empty($_GET['saved'])) : ?>
        <div class="notice notice-success is-dismissible"><p><?php esc_html_e('Settings saved.', 'azure-plugin'); ?></p></div>
    <?php endif; ?>

    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
        <?php wp_nonce_field('azure_forms_settings'); ?>
        <input type="hidden" name="action" value="azure_forms_settings">
        <h2><?php esc_html_e('Cloudflare Turnstile', 'azure-plugin'); ?></h2>
        <p class="description"><?php esc_html_e('An optional "verify you are human" check, turned on per form in its Settings. Create a widget for this site\'s domain at dash.cloudflare.com > Turnstile and paste its keys here.', 'azure-plugin'); ?></p>
        <table class="form-table" role="presentation">
            <tr>
                <th scope="row"><label for="turnstile_site_key"><?php esc_html_e('Site key', 'azure-plugin'); ?></label></th>
                <td><input type="text" class="regular-text code" id="turnstile_site_key" name="turnstile_site_key" value="<?php echo esc_attr($keys['site_key']); ?>" autocomplete="off"></td>
            </tr>
            <tr>
                <th scope="row"><label for="turnstile_secret_key"><?php esc_html_e('Secret key', 'azure-plugin'); ?></label></th>
                <td><input type="password" class="regular-text code" id="turnstile_secret_key" name="turnstile_secret_key" value="" autocomplete="new-password"
                           placeholder="<?php echo $keys['secret_key'] !== '' ? esc_attr__('Saved — leave blank to keep', 'azure-plugin') : ''; ?>"></td>
            </tr>
            <?php if ($keys['site_key'] !== '' || $keys['secret_key'] !== '') : ?>
            <tr>
                <th scope="row"><?php esc_html_e('Remove keys', 'azure-plugin'); ?></th>
                <td><label><input type="checkbox" name="turnstile_clear" value="1"> <?php esc_html_e('Clear both keys (turns the check off on every form)', 'azure-plugin'); ?></label></td>
            </tr>
            <?php endif; ?>
        </table>
        <h2><?php esc_html_e('Parent registration', 'azure-plugin'); ?></h2>
        <p class="description"><?php esc_html_e('The page with your registration form. A "Register a new parent account" link to it appears under the My Account sign-in form.', 'azure-plugin'); ?></p>
        <table class="form-table" role="presentation">
            <tr>
                <th scope="row"><label for="register_page"><?php esc_html_e('Registration page', 'azure-plugin'); ?></label></th>
                <td><?php
                    wp_dropdown_pages(array(
                        'name'              => 'register_page',
                        'id'                => 'register_page',
                        'selected'          => (int) get_option(Azure_Forms_Registration::PAGE_OPTION, 0),
                        'show_option_none'  => __('— No link —', 'azure-plugin'),
                        'option_none_value' => 0,
                    ));
                ?></td>
            </tr>
        </table>
        <?php submit_button(__('Save settings', 'azure-plugin')); ?>
    </form>
</div>
<?php
    return;
endif;

// ─── Themes ──────────────────────────────────────────────────────────
if ($tab === 'themes') : ?>
    <a href="<?php echo esc_url($base_url); ?>" class="page-title-action"><?php esc_html_e('All forms', 'azure-plugin'); ?></a>
    <hr class="wp-header-end">
    <nav class="nav-tab-wrapper">
        <a class="nav-tab" href="<?php echo esc_url($base_url); ?>"><?php esc_html_e('Forms', 'azure-plugin'); ?></a>
        <a class="nav-tab nav-tab-active" href="<?php echo esc_url($base_url . '&tab=themes'); ?>"><?php esc_html_e('Themes', 'azure-plugin'); ?></a>
        <a class="nav-tab" href="<?php echo esc_url($base_url . '&tab=settings'); ?>"><?php esc_html_e('Settings', 'azure-plugin'); ?></a>
    </nav>
    <p class="description"><?php esc_html_e('Themes set the colours, frame, header and footer of a form. Pick one per form in its Settings, or override it on a page with [pta_form id="2" theme="your-theme"].', 'azure-plugin'); ?></p>

    <div class="azure-forms-themes" id="azure-forms-themes">
        <div class="azure-forms-themes__list">
            <ul class="azure-forms-theme-list"></ul>
            <p><button type="button" class="button azure-forms-theme-new"><?php esc_html_e('New theme', 'azure-plugin'); ?></button></p>
            <div class="azure-forms-theme-copy">
                <label for="azure-forms-upnext-source"><strong><?php esc_html_e('Copy from an up-next theme', 'azure-plugin'); ?></strong></label>
                <select id="azure-forms-upnext-source"></select>
                <button type="button" class="button azure-forms-theme-copy-btn"><?php esc_html_e('Copy', 'azure-plugin'); ?></button>
                <p class="description"><?php esc_html_e('Copies the colours, frame, header and footer. The up-next theme is not changed.', 'azure-plugin'); ?></p>
            </div>
        </div>
        <div class="azure-forms-themes__editor"></div>
        <div class="azure-forms-themes__preview">
            <h3><?php esc_html_e('Preview', 'azure-plugin'); ?></h3>
            <div class="azure-forms-preview-box"></div>
        </div>
    </div>
</div>
<?php
    return;
endif;

// ─── Builder ─────────────────────────────────────────────────────────
if ($form_ref !== '') :
    $form = $form_ref === 'new' ? null : $module->get_form((int) $form_ref);
    if ($form_ref !== 'new' && !$form) {
        echo '<div class="notice notice-error inline"><p>' . esc_html__('Form not found.', 'azure-plugin') . '</p></div></div>';
        return;
    }
    $boot = array(
        'id'       => $form ? $form['id'] : 0,
        'title'    => $form ? $form['title'] : '',
        'slug'     => $form ? $form['slug'] : '',
        'status'   => $form ? $form['status'] : 'draft',
        'schema'   => $form ? $form['schema'] : array(
            array('id' => 'fld_1', 'type' => 'text', 'label' => __('First name', 'azure-plugin'), 'name' => 'first_name', 'required' => true, 'width' => 'half', 'prefill' => 'first_name'),
            array('id' => 'fld_2', 'type' => 'text', 'label' => __('Last name', 'azure-plugin'), 'name' => 'last_name', 'required' => true, 'width' => 'half', 'prefill' => 'last_name'),
            array('id' => 'fld_3', 'type' => 'email', 'label' => __('Email', 'azure-plugin'), 'name' => 'email', 'required' => true, 'width' => 'full', 'prefill' => 'email'),
        ),
        'settings' => $form ? $form['settings'] : Azure_Forms_Module::default_settings(),
        'legacy_forminator_id' => $form ? $form['legacy_forminator_id'] : 0,
        'notify'   => Azure_Forms_Module::notify_summary($form ? $form['id'] : 0),
    );
    ?>
    <a href="<?php echo esc_url($base_url); ?>" class="page-title-action"><?php esc_html_e('All forms', 'azure-plugin'); ?></a>
    <?php if ($form) : ?>
        <a href="<?php echo esc_url($base_url . '&tab=entries&form=' . $form['id']); ?>" class="page-title-action"><?php esc_html_e('Entries', 'azure-plugin'); ?></a>
    <?php endif; ?>
    <hr class="wp-header-end">

    <script type="application/json" id="azure-forms-boot"><?php echo wp_json_encode($boot, JSON_HEX_TAG | JSON_HEX_AMP); ?></script>

    <div class="azure-forms-builder" id="azure-forms-builder">
        <div class="azure-forms-topbar">
            <label class="screen-reader-text" for="azure-forms-title"><?php esc_html_e('Form title', 'azure-plugin'); ?></label>
            <input type="text" id="azure-forms-title" class="azure-forms-title" placeholder="<?php esc_attr_e('Form title', 'azure-plugin'); ?>">
            <label for="azure-forms-status"><?php esc_html_e('Status', 'azure-plugin'); ?></label>
            <select id="azure-forms-status">
                <option value="draft"><?php esc_html_e('Draft (editors only)', 'azure-plugin'); ?></option>
                <option value="open"><?php esc_html_e('Open', 'azure-plugin'); ?></option>
                <option value="closed"><?php esc_html_e('Closed', 'azure-plugin'); ?></option>
            </select>
            <button type="button" class="button button-primary azure-forms-save"><?php esc_html_e('Save form', 'azure-plugin'); ?></button>
            <span class="azure-forms-save-state" role="status" aria-live="polite"></span>
            <span class="azure-forms-shortcode-wrap" hidden>
                <code class="azure-forms-shortcode"></code>
                <button type="button" class="button button-small azure-forms-copy"><?php esc_html_e('Copy shortcode', 'azure-plugin'); ?></button>
            </span>
        </div>

        <nav class="nav-tab-wrapper azure-forms-tabs">
            <button type="button" class="nav-tab nav-tab-active" data-pane="build"><?php esc_html_e('Build', 'azure-plugin'); ?></button>
            <button type="button" class="nav-tab" data-pane="preview"><?php esc_html_e('Preview', 'azure-plugin'); ?></button>
            <button type="button" class="nav-tab" data-pane="settings"><?php esc_html_e('Settings', 'azure-plugin'); ?></button>
        </nav>

        <div class="azure-forms-pane" data-pane="build">
            <div class="azure-forms-columns">
                <div class="azure-forms-palette">
                    <h3><?php esc_html_e('Add a field', 'azure-plugin'); ?></h3>
                    <p class="description"><?php esc_html_e('Click or drag onto the form.', 'azure-plugin'); ?></p>
                    <ul class="azure-forms-palette-list"></ul>
                </div>
                <div class="azure-forms-canvas-wrap">
                    <ul class="azure-forms-canvas" aria-label="<?php esc_attr_e('Form fields', 'azure-plugin'); ?>"></ul>
                    <p class="azure-forms-empty"></p>
                </div>
                <div class="azure-forms-inspector" aria-live="polite"></div>
            </div>
        </div>

        <div class="azure-forms-pane" data-pane="preview" hidden>
            <p class="description"><?php esc_html_e('This is the same markup visitors see, including unsaved changes. The submit button is disabled here.', 'azure-plugin'); ?></p>
            <div class="azure-forms-preview-box"></div>
        </div>

        <div class="azure-forms-pane" data-pane="settings" hidden>
            <table class="form-table" role="presentation">
                <tr class="azure-forms-notify-row" hidden>
                    <th scope="row"><label for="azure-forms-notify"><?php esc_html_e('Send responses to', 'azure-plugin'); ?></label></th>
                    <td><input type="text" class="large-text" id="azure-forms-notify" autocomplete="off" placeholder="webmaster@example.org">
                        <p class="description"><?php esc_html_e('Each response is emailed to these addresses. Separate several with commas. Add {submitter_email} to also send a copy to the person who filled it in. Leave empty to send nothing.', 'azure-plugin'); ?></p>
                        <p class="description azure-forms-notify-readonly" hidden><?php esc_html_e('Only people who can manage rules can change this.', 'azure-plugin'); ?></p>
                        <p class="azure-forms-notify-paused" style="color:#b32d2e;" hidden></p>
                        <p class="azure-forms-notify-links" hidden></p>
                        <div class="azure-forms-notify-others" hidden></div></td>
                </tr>
                <tr>
                    <th scope="row"><label for="afs-submit_label"><?php esc_html_e('Submit button', 'azure-plugin'); ?></label></th>
                    <td><input type="text" class="regular-text" id="afs-submit_label" data-setting="submit_label"></td>
                </tr>
                <tr>
                    <th scope="row"><label for="afs-success_message"><?php esc_html_e('Success message', 'azure-plugin'); ?></label></th>
                    <td><textarea class="large-text" rows="3" id="afs-success_message" data-setting="success_message"></textarea>
                        <p class="description"><?php esc_html_e('Shown in place of the form after it is sent. Links and bold text are allowed.', 'azure-plugin'); ?></p></td>
                </tr>
                <tr>
                    <th scope="row"><label for="afs-redirect_url"><?php esc_html_e('Redirect to', 'azure-plugin'); ?></label></th>
                    <td><input type="url" class="regular-text" id="afs-redirect_url" data-setting="redirect_url" placeholder="https://">
                        <p class="description"><?php esc_html_e('Optional. Sends people to this page instead of showing the success message.', 'azure-plugin'); ?></p></td>
                </tr>
                <tr>
                    <th scope="row"><?php esc_html_e('Who can fill it in', 'azure-plugin'); ?></th>
                    <td><label><input type="checkbox" data-setting="require_login"> <?php esc_html_e('Only signed-in members', 'azure-plugin'); ?></label>
                        <p class="description"><?php esc_html_e('Signed-in members always get their name and email filled in for fields set to do so.', 'azure-plugin'); ?></p></td>
                </tr>
                <tr>
                    <th scope="row"><label for="afs-limit_per_person"><?php esc_html_e('Limit per person', 'azure-plugin'); ?></label></th>
                    <td><input type="number" min="0" max="100" class="small-text" id="afs-limit_per_person" data-setting="limit_per_person">
                        <p class="description"><?php esc_html_e('0 means no limit. Counted by account for signed-in members, otherwise by the first email field.', 'azure-plugin'); ?></p></td>
                </tr>
                <tr>
                    <th scope="row"><label for="afs-close_at"><?php esc_html_e('Close automatically', 'azure-plugin'); ?></label></th>
                    <td><input type="datetime-local" id="afs-close_at" data-setting="close_at">
                        <p class="description"><?php esc_html_e('Optional, in the site time zone.', 'azure-plugin'); ?></p></td>
                </tr>
                <tr>
                    <th scope="row"><label for="afs-closed_message"><?php esc_html_e('Closed message', 'azure-plugin'); ?></label></th>
                    <td><input type="text" class="large-text" id="afs-closed_message" data-setting="closed_message">
                        <p class="description"><?php esc_html_e('Optional. Shown to visitors when the form is closed; leave empty to show nothing.', 'azure-plugin'); ?></p></td>
                </tr>
                <tr>
                    <th scope="row"><label for="afs-theme"><?php esc_html_e('Theme', 'azure-plugin'); ?></label></th>
                    <td><select id="afs-theme" data-setting="theme"></select>
                        <a href="<?php echo esc_url($base_url . '&tab=themes'); ?>"><?php esc_html_e('Manage themes', 'azure-plugin'); ?></a></td>
                </tr>
                <tr>
                    <th scope="row"><label for="afs-retention_days"><?php esc_html_e('Keep entries for', 'azure-plugin'); ?></label></th>
                    <td><input type="number" min="0" max="3650" class="small-text" id="afs-retention_days" data-setting="retention_days"> <?php esc_html_e('days', 'azure-plugin'); ?>
                        <p class="description"><?php esc_html_e('Older entries are deleted daily. 0 keeps them forever.', 'azure-plugin'); ?></p></td>
                </tr>
                <tr>
                    <th scope="row"><?php esc_html_e('Spam check', 'azure-plugin'); ?></th>
                    <td><label><input type="checkbox" data-setting="turnstile"> <?php esc_html_e('Ask visitors to verify they are human (Cloudflare Turnstile)', 'azure-plugin'); ?></label>
                        <p class="description"><?php esc_html_e('Usually not needed: every form already has a hidden trap field, a time check and a rate limit. Turn this on if a form still gets spam.', 'azure-plugin'); ?></p>
                        <p class="description azure-forms-turnstile-missing" style="color:#b32d2e;">
                            <?php
                            printf(
                                wp_kses(__('Add your Turnstile keys in <a href="%s">Forms &gt; Settings</a> first; until then this has no effect.', 'azure-plugin'), array('a' => array('href' => true))),
                                esc_url($base_url . '&tab=settings')
                            );
                            ?>
                        </p></td>
                </tr>
                <tr>
                    <th scope="row"><?php esc_html_e('Parent registration', 'azure-plugin'); ?></th>
                    <td><label><input type="checkbox" data-setting="registration"> <?php esc_html_e('Create a parent account from each response', 'azure-plugin'); ?></label>
                        <p class="description"><?php esc_html_e('The account stays locked until the parent clicks the activation link we email them, then they choose a password. Their children and emergency contact are saved to Family Info. Accounts not activated within 8 days are deleted. Choose where each answer is saved in the field settings.', 'azure-plugin'); ?></p>
                        <div class="azure-forms-registration-check" hidden></div></td>
                </tr>
                <tr>
                    <th scope="row"><label for="azure-forms-slug"><?php esc_html_e('Slug', 'azure-plugin'); ?></label></th>
                    <td><input type="text" class="regular-text" id="azure-forms-slug">
                        <p class="description"><?php esc_html_e('Lets you use [pta_form slug="…"] instead of the id.', 'azure-plugin'); ?></p></td>
                </tr>
                <tr>
                    <th scope="row"><label for="azure-forms-legacy"><?php esc_html_e('Replaces old form', 'azure-plugin'); ?></label></th>
                    <td><input type="number" min="0" class="small-text" id="azure-forms-legacy">
                        <p class="description"><?php esc_html_e('Optional. Enter the id from an old [forminator_form id="…"] shortcode and pages that still carry it will show this form instead, without editing the page.', 'azure-plugin'); ?></p></td>
                </tr>
            </table>
        </div>
    </div>
</div>
<?php
    return;
endif;

// ─── List ────────────────────────────────────────────────────────────
$forms = $module->get_forms();
$status_labels = array('draft' => __('Draft', 'azure-plugin'), 'open' => __('Open', 'azure-plugin'), 'closed' => __('Closed', 'azure-plugin'));
?>
    <a href="<?php echo esc_url($base_url . '&form=new'); ?>" class="page-title-action"><?php esc_html_e('Add new form', 'azure-plugin'); ?></a>
    <hr class="wp-header-end">
    <nav class="nav-tab-wrapper">
        <a class="nav-tab nav-tab-active" href="<?php echo esc_url($base_url); ?>"><?php esc_html_e('Forms', 'azure-plugin'); ?></a>
        <a class="nav-tab" href="<?php echo esc_url($base_url . '&tab=themes'); ?>"><?php esc_html_e('Themes', 'azure-plugin'); ?></a>
        <a class="nav-tab" href="<?php echo esc_url($base_url . '&tab=settings'); ?>"><?php esc_html_e('Settings', 'azure-plugin'); ?></a>
    </nav>

    <p class="description">
        <?php
        printf(
            wp_kses(
                __('To email someone when a form is submitted, add a rule in <a href="%1$s">System &gt; Rules</a> with the "Form submitted" trigger. Write the email itself in <a href="%2$s">Emails &gt; Messages</a>.', 'azure-plugin'),
                array('a' => array('href' => true))
            ),
            esc_url(admin_url('admin.php?page=azure-plugin-system&tab=rules')),
            esc_url(admin_url('admin.php?page=azure-plugin-emails&tab=messages'))
        );
        ?>
    </p>

    <table class="wp-list-table widefat fixed striped azure-forms-list">
        <thead>
            <tr>
                <th scope="col"><?php esc_html_e('Form', 'azure-plugin'); ?></th>
                <th scope="col"><?php esc_html_e('Shortcode', 'azure-plugin'); ?></th>
                <th scope="col" class="column-status"><?php esc_html_e('Status', 'azure-plugin'); ?></th>
                <th scope="col" class="column-entries"><?php esc_html_e('Entries', 'azure-plugin'); ?></th>
                <th scope="col" class="column-date"><?php esc_html_e('Updated', 'azure-plugin'); ?></th>
            </tr>
        </thead>
        <tbody>
        <?php if (empty($forms)) : ?>
            <tr><td colspan="5"><?php
                printf(
                    wp_kses(__('No forms yet. <a href="%s">Create your first form</a>.', 'azure-plugin'), array('a' => array('href' => true))),
                    esc_url($base_url . '&form=new')
                );
            ?></td></tr>
        <?php endif; ?>
        <?php foreach ($forms as $form) :
            $shortcode = '[pta_form id="' . $form['id'] . '"]';
            $open = Azure_Forms_Module::is_accepting($form);
            ?>
            <tr data-form="<?php echo (int) $form['id']; ?>">
                <td>
                    <strong><a class="row-title" href="<?php echo esc_url($base_url . '&form=' . $form['id']); ?>"><?php echo esc_html($form['title']); ?></a></strong>
                    <div class="row-actions">
                        <span><a href="<?php echo esc_url($base_url . '&form=' . $form['id']); ?>"><?php esc_html_e('Edit', 'azure-plugin'); ?></a> | </span>
                        <span><a href="<?php echo esc_url($base_url . '&tab=entries&form=' . $form['id']); ?>"><?php esc_html_e('Entries', 'azure-plugin'); ?></a> | </span>
                        <span><button type="button" class="button-link azure-forms-duplicate"><?php esc_html_e('Duplicate', 'azure-plugin'); ?></button> | </span>
                        <span class="trash"><button type="button" class="button-link button-link-delete azure-forms-delete"><?php esc_html_e('Delete', 'azure-plugin'); ?></button></span>
                    </div>
                </td>
                <td>
                    <code><?php echo esc_html($shortcode); ?></code>
                    <button type="button" class="button button-small azure-forms-copy" data-copy="<?php echo esc_attr($shortcode); ?>"><?php esc_html_e('Copy', 'azure-plugin'); ?></button>
                </td>
                <td class="column-status">
                    <span class="azure-forms-status status-<?php echo esc_attr($form['status']); ?>"><?php echo esc_html($status_labels[$form['status']] ?? $form['status']); ?></span>
                    <?php if ($form['status'] === 'open' && !$open) : ?>
                        <br><small><?php esc_html_e('Past its close date', 'azure-plugin'); ?></small>
                    <?php endif; ?>
                </td>
                <td class="column-entries">
                    <a href="<?php echo esc_url($base_url . '&tab=entries&form=' . $form['id']); ?>"><?php echo (int) $form['entry_count']; ?></a>
                    <?php if ($form['new_count'] > 0) : ?>
                        <span class="azure-forms-badge"><?php echo esc_html(sprintf(__('%d new', 'azure-plugin'), $form['new_count'])); ?></span>
                    <?php endif; ?>
                </td>
                <td class="column-date"><?php echo esc_html($form['updated_at'] ? get_date_from_gmt($form['updated_at'], 'M j, Y') : ''); ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</div>
