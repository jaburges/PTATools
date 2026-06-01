<?php
/**
 * AcyMailing dynamic-text add-on: PTA Tools
 *
 * Registered with AcyMailing via the `acym_load_installed_integrations`
 * hook in Azure_AcyMailing_Addon_Loader. AcyMailing instantiates this
 * class to populate its Dynamic Text picker — the row of tiles you see
 * under "Dynamic text type" (Subscriber, Subscription, Time, Website,
 * WordPress user, …). This add-on appends a "PTA Tools" tile to that
 * row whose insertion panel offers ready-made `[up-next]` shortcode
 * variants for newsletter authors.
 *
 * IMPORTANT — class naming convention:
 *
 *   AcyMailing's PluginClass derives `$this->name` by stripping the
 *   `plgAcym` prefix from the class name and lowercasing the rest.
 *   So `plgAcymPtatools` → `$this->name === 'ptatools'`. All tags
 *   emitted by this plugin therefore use the prefix `{ptatools:…}`
 *   — for example `{ptatools:upcoming-events|columns:2}`.
 *
 * Lifecycle:
 *
 *   - `__construct()`         — declare display name + description.
 *   - `dynamicText($mailId)`  — return the description object so AcyMailing
 *                               renders the tile. Returning null hides it.
 *   - `textPopup()`           — render the HTML insertion panel under
 *                               "Content to insert". Each row binds to the
 *                               JS function `setTag('{ptatools:…}', jQuery(this))`
 *                               which AcyMailing exposes.
 *   - `replaceContent(&$email, $send)` — runs once per email (preview AND
 *                               send) and rewrites every `{ptatools:…}`
 *                               token into the live output of
 *                               `do_shortcode('[up-next …]')`. We use
 *                               `replaceContent` rather than
 *                               `replaceUserInformation` because the
 *                               upcoming-events block is the same for
 *                               every recipient.
 *
 * @package AzurePlugin
 * @since   3.116
 */

if (!defined('ABSPATH')) {
    exit;
}

// AcyMailing's WordPress build exposes the base class at
// `\AcyMailing\Libraries\acymPlugin` (lowercase "a") — that's the
// canonical namespace per the WP "Making a custom add-on" docs:
// https://docs.acymailing.com/developers/making-a-custom-add-on
// (The dynamic-text WP doc page shows AcyMailing\Core\AcymPlugin
//  but that's the Joomla namespace and doesn't exist on WP installs.)
use AcyMailing\Libraries\acymPlugin;

class plgAcymPtatools extends acymPlugin
{
    /**
     * Whitelist of `[up-next]` shortcode attributes we accept from
     * the parsed AcyMailing tag. Anything not in this list is dropped
     * so newsletter authors can't smuggle arbitrary `key:value` pairs
     * through and have them land as shortcode attributes (defensive
     * — the shortcode itself ignores unknown atts, but we don't want
     * AcyMailing internals like `parameters` or `id` to leak).
     *
     * Keys are kebab-case to match the shortcode's `shortcode_atts`
     * defaults in Azure_Upcoming_Module::render_upcoming_shortcode().
     *
     * @var array
     */
    private $allowed_attrs = array(
        'columns',
        'current-week',
        'next-week',
        'show-coming-up',
        'coming-up-days',
        'coming-up-title',
        'this-week-title',
        'next-week-title',
        'exclude-categories',
        'week-start',
        'show-time',
        'link-titles',
        'show-join-meeting',
        'show-empty',
        'empty-message',
        'cache',
    );

    public function __construct()
    {
        parent::__construct();

        $this->pluginDescription->name        = 'PTA Tools';
        $this->pluginDescription->category    = 'PTA Tools';
        $this->pluginDescription->description = '- Insert PTA Tools upcoming events ([up-next]) in newsletters';
    }

    /**
     * Tells AcyMailing to surface this plugin in the Dynamic Text picker.
     * Returning the description renders the tile; returning null hides it.
     */
    public function dynamicText($mailId)
    {
        return $this->pluginDescription;
    }

    /**
     * HTML for the "Content to insert" panel. Each clickable row calls
     * the AcyMailing-provided `setTag(token, element)` JS to insert
     * the token at the editor's cursor.
     *
     * For options-bearing tokens (e.g. columns:2) we deliberately
     * emit the full tag in `setTag(...)` rather than building a
     * config form, so authors get a 1-click insert that matches the
     * native "User name" / "User email" rows in the screenshot.
     * If they want to tweak after insert, the token is plain text in
     * the body and editable in place.
     */
    public function textPopup()
    {
        $name = $this->name; // 'ptatools' — see class docblock.

        $rows = array(
            array(
                'title' => 'Upcoming Events — default',
                'sub'   => 'This week + next week, single column. Use this for most newsletters.',
                'tag'   => '{' . $name . ':upcoming-events}',
            ),
            array(
                'title' => 'Upcoming Events — two columns',
                'sub'   => 'This week and next week side by side.',
                'tag'   => '{' . $name . ':upcoming-events|columns:2}',
            ),
            array(
                'title' => 'Upcoming Events — three columns',
                'sub'   => 'Best for wide newsletter templates only.',
                'tag'   => '{' . $name . ':upcoming-events|columns:3}',
            ),
            array(
                'title' => 'Upcoming Events — this week only',
                'sub'   => 'Hide the "Next week" section.',
                'tag'   => '{' . $name . ':upcoming-events|next-week:false}',
            ),
            array(
                'title' => 'Coming up — next 30 days',
                'sub'   => 'Skip the weekly sections; just list the next 30 days of events.',
                'tag'   => '{' . $name . ':upcoming-events|current-week:false|next-week:false|show-coming-up:true|coming-up-days:30}',
            ),
            array(
                'title' => 'Coming up — next 60 days',
                'sub'   => 'Same as above but 60 days ahead.',
                'tag'   => '{' . $name . ':upcoming-events|current-week:false|next-week:false|show-coming-up:true|coming-up-days:60}',
            ),
        );

        $html  = '<div class="grid-x acym__popup__listing">';
        $html .= '  <div class="cell">';
        $html .= '    <p style="margin:6px 12px 14px;color:#646970;">';
        $html .= esc_html__('Pick a preset to insert the corresponding [up-next] block. The token renders to live HTML at send and preview time.', 'azure-plugin');
        $html .= '    </p>';
        $html .= '  </div>';

        foreach ($rows as $row) {
            // Single-quotes inside the onclick string to keep the
            // outer double-quoted attribute clean. Tag values contain
            // pipes / colons which are HTML-safe; no further escaping
            // needed beyond JS-quoting.
            $tagJs = str_replace("'", "\\'", $row['tag']);
            $html .= '<div class="cell acym__row__no-listing acym__listing__row__popup" '
                  . 'style="cursor:pointer;padding:10px 12px;border-bottom:1px solid #f0f0f1;" '
                  . 'onclick="setTag(\'' . $tagJs . '\', jQuery(this));">';
            $html .= '  <div style="font-weight:600;color:#1d2327;">' . esc_html($row['title']) . '</div>';
            $html .= '  <div style="color:#646970;font-size:12px;margin-top:2px;">' . esc_html($row['sub']) . '</div>';
            $html .= '  <code style="display:inline-block;margin-top:4px;font-size:11px;background:#f6f7f7;padding:2px 6px;border-radius:3px;color:#2c3338;">' . esc_html($row['tag']) . '</code>';
            $html .= '</div>';
        }

        $html .= '  <div class="cell">';
        $html .= '    <p style="margin:14px 12px 6px;color:#646970;font-size:12px;">';
        $html .= esc_html__('All [up-next] shortcode attributes are honored. After inserting a preset you can edit the pipe-separated options directly in the body — for example |exclude-categories:Staff,Private.', 'azure-plugin');
        $html .= '    </p>';
        $html .= '  </div>';
        $html .= '</div>';

        echo $html;
    }

    /**
     * Same content for every recipient → use replaceContent (cheaper
     * than replaceUserInformation, which would render once per user).
     *
     * AcyMailing's $this->pluginHelper->extractTags() parses every
     * `{<this->name>:<id>|opt:val|opt:val}` occurrence into objects
     * with `id` and per-option properties. We allowlist the option
     * keys against `$allowed_attrs` and rebuild a shortcode call.
     *
     * @param object $email Reference to the email being prepared/sent.
     *                      Includes ->body and (for non-HTML) ->bodyText.
     * @param bool   $send  true at send time, false on preview.
     */
    public function replaceContent(&$email, $send = true)
    {
        $extractedTags = $this->pluginHelper->extractTags($email, $this->name);
        if (empty($extractedTags)) {
            return;
        }

        $tags = array();
        foreach ($extractedTags as $i => $oneTag) {
            if (isset($tags[$i])) {
                continue;
            }

            // We only support one tag identifier today. Future tags
            // (e.g. 'events-list') would branch here.
            if (!is_object($oneTag) || !isset($oneTag->id) || $oneTag->id !== 'upcoming-events') {
                continue;
            }

            $attrs = array();
            foreach (get_object_vars($oneTag) as $key => $value) {
                // Skip AcyMailing internals — only kebab-case attrs
                // from our allowlist make it through to the shortcode.
                if (!in_array($key, $this->allowed_attrs, true)) {
                    continue;
                }
                $attrs[] = $key . '="' . esc_attr((string) $value) . '"';
            }

            $shortcode = '[up-next' . (!empty($attrs) ? ' ' . implode(' ', $attrs) : '') . ']';
            $rendered  = do_shortcode($shortcode);

            // do_shortcode returns the input unchanged when the shortcode
            // isn't registered. Surface an empty string so the editor /
            // subscriber doesn't see the raw `[up-next]` string.
            if ($rendered === $shortcode) {
                $rendered = '';
            }

            $tags[$i] = $rendered;
        }

        if (!empty($tags)) {
            $this->pluginHelper->replaceTags($email, $tags);
        }
    }
}
