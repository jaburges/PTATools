<?php
/**
 * Sidebar widget for a campaign progress meter, with the same styles as
 * [donation-progress style="…"].
 */

if (!defined('ABSPATH')) {
    exit;
}

require_once __DIR__ . '/class-donation-progress-art.php';

class Azure_Donation_Progress_Widget extends WP_Widget {

    public function __construct() {
        parent::__construct(
            'pta_donation_progress',
            __('Donation Progress', 'azure-plugin'),
            array(
                'description' => __('Campaign progress as a bar, growing tulip, oak tree, garden bed or thermometer.', 'azure-plugin'),
                'customize_selective_refresh' => true,
            )
        );
    }

    private function defaults($instance) {
        return wp_parse_args((array) $instance, array(
            'title'     => '',
            'campaign'  => 'giving-levels',
            'style'     => 'bar',
            'show_name' => 1,
        ));
    }

    public function widget($args, $instance) {
        if (!class_exists('Azure_Donations_Module')) {
            return;
        }
        $instance = $this->defaults($instance);
        $campaign = Azure_Donations_Module::resolve_progress_campaign($instance['campaign']);
        if (!$campaign) {
            return;
        }

        Azure_Donations_Module::enqueue_wag_styles();
        echo $args['before_widget'];
        $title = apply_filters('widget_title', $instance['title'], $instance, $this->id_base);
        if ($title !== '') {
            echo $args['before_title'] . esc_html($title) . $args['after_title'];
        }
        echo Azure_Donations_Module::render_progress_html($campaign, array(
            'show_heading' => !empty($instance['show_name']),
            'style'        => $instance['style'],
        ));
        echo $args['after_widget'];
    }

    public function form($instance) {
        $instance = $this->defaults($instance);
        $campaigns = class_exists('Azure_Donations_Module') ? Azure_Donations_Module::get_all_campaigns() : array();
        ?>
        <p>
            <label for="<?php echo esc_attr($this->get_field_id('title')); ?>"><?php esc_html_e('Title (optional)', 'azure-plugin'); ?></label>
            <input class="widefat" id="<?php echo esc_attr($this->get_field_id('title')); ?>" name="<?php echo esc_attr($this->get_field_name('title')); ?>" type="text" value="<?php echo esc_attr($instance['title']); ?>">
        </p>
        <p>
            <label for="<?php echo esc_attr($this->get_field_id('campaign')); ?>"><?php esc_html_e('Campaign', 'azure-plugin'); ?></label>
            <select class="widefat" id="<?php echo esc_attr($this->get_field_id('campaign')); ?>" name="<?php echo esc_attr($this->get_field_name('campaign')); ?>">
                <option value="giving-levels" <?php selected($instance['campaign'], 'giving-levels'); ?>><?php esc_html_e('Giving levels (Donation Items campaign)', 'azure-plugin'); ?></option>
                <?php foreach ($campaigns as $c): ?>
                    <option value="<?php echo (int) $c->id; ?>" <?php selected((string) $instance['campaign'], (string) $c->id); ?>>
                        <?php echo esc_html($c->name . (empty($c->is_active) ? ' (' . __('inactive', 'azure-plugin') . ')' : '')); ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </p>
        <p>
            <label for="<?php echo esc_attr($this->get_field_id('style')); ?>"><?php esc_html_e('Style', 'azure-plugin'); ?></label>
            <select class="widefat" id="<?php echo esc_attr($this->get_field_id('style')); ?>" name="<?php echo esc_attr($this->get_field_name('style')); ?>">
                <?php foreach (Azure_Donation_Progress_Art::STYLES as $key => $label): ?>
                    <option value="<?php echo esc_attr($key); ?>" <?php selected($instance['style'], $key); ?>><?php echo esc_html($label); ?></option>
                <?php endforeach; ?>
            </select>
        </p>
        <p>
            <input type="checkbox" class="checkbox" id="<?php echo esc_attr($this->get_field_id('show_name')); ?>" name="<?php echo esc_attr($this->get_field_name('show_name')); ?>" value="1" <?php checked(!empty($instance['show_name'])); ?>>
            <label for="<?php echo esc_attr($this->get_field_id('show_name')); ?>"><?php esc_html_e('Show campaign name', 'azure-plugin'); ?></label>
        </p>
        <?php
        return '';
    }

    public function update($new_instance, $old_instance) {
        unset($old_instance);
        $campaign = isset($new_instance['campaign']) ? trim((string) $new_instance['campaign']) : 'giving-levels';
        return array(
            'title'     => sanitize_text_field(isset($new_instance['title']) ? $new_instance['title'] : ''),
            'campaign'  => ctype_digit($campaign) ? $campaign : 'giving-levels',
            'style'     => Azure_Donation_Progress_Art::normalize_style(isset($new_instance['style']) ? $new_instance['style'] : 'bar'),
            'show_name' => empty($new_instance['show_name']) ? 0 : 1,
        );
    }
}
