<?php
/**
 * CSS for PTA Roles Directory Beaver Builder Module
 */

FLBuilderCSS::responsive_rule(array(
    'settings'     => $settings,
    'setting_name' => 'margin',
    'selector'     => ".fl-node-$id .fl-pta-roles-directory",
    'prop'         => 'margin',
));

FLBuilderCSS::responsive_rule(array(
    'settings'     => $settings,
    'setting_name' => 'padding',
    'selector'     => ".fl-node-$id .fl-pta-roles-directory",
    'prop'         => 'padding',
));

// Background color
if (!empty($settings->background_color)) {
    ?>
    .fl-node-<?php echo $id; ?> .pta-role-item {
        background-color: #<?php echo FLBuilderColor::hex_or_rgb($settings->background_color); ?>;
    }
    <?php
}

// Text color
if (!empty($settings->text_color)) {
    ?>
    .fl-node-<?php echo $id; ?> .pta-role-item,
    .fl-node-<?php echo $id; ?> .pta-role-name,
    .fl-node-<?php echo $id; ?> .pta-role-description {
        color: #<?php echo FLBuilderColor::hex_or_rgb($settings->text_color); ?>;
    }
    <?php
}

// Border color
if (!empty($settings->border_color)) {
    ?>
    .fl-node-<?php echo $id; ?> .pta-role-item {
        border-color: #<?php echo FLBuilderColor::hex_or_rgb($settings->border_color); ?>;
    }
    <?php
}

// Status colors
if (!empty($settings->open_color)) {
    ?>
    .fl-node-<?php echo $id; ?> .pta-role-item.pta-status-open {
        border-left-color: #<?php echo FLBuilderColor::hex_or_rgb($settings->open_color); ?>;
    }
    .fl-node-<?php echo $id; ?> .pta-role-status.pta-status-open {
        background-color: rgba(<?php echo FLBuilderColor::hex_or_rgb($settings->open_color); ?>, 0.1);
        color: #<?php echo FLBuilderColor::hex_or_rgb($settings->open_color); ?>;
    }
    <?php
}

if (!empty($settings->filled_color)) {
    ?>
    .fl-node-<?php echo $id; ?> .pta-role-item.pta-status-filled {
        border-left-color: #<?php echo FLBuilderColor::hex_or_rgb($settings->filled_color); ?>;
    }
    .fl-node-<?php echo $id; ?> .pta-role-status.pta-status-filled {
        background-color: rgba(<?php echo FLBuilderColor::hex_or_rgb($settings->filled_color); ?>, 0.1);
        color: #<?php echo FLBuilderColor::hex_or_rgb($settings->filled_color); ?>;
    }
    <?php
}

if (!empty($settings->partial_color)) {
    ?>
    .fl-node-<?php echo $id; ?> .pta-role-item.pta-status-partial {
        border-left-color: #<?php echo FLBuilderColor::hex_or_rgb($settings->partial_color); ?>;
    }
    .fl-node-<?php echo $id; ?> .pta-role-status.pta-status-partial {
        background-color: rgba(<?php echo FLBuilderColor::hex_or_rgb($settings->partial_color); ?>, 0.1);
        color: #<?php echo FLBuilderColor::hex_or_rgb($settings->partial_color); ?>;
    }
    <?php
}

// Item spacing
if (!empty($settings->item_spacing)) {
    ?>
    .fl-node-<?php echo $id; ?> .pta-roles-directory {
        gap: <?php echo $settings->item_spacing; ?>px;
    }
    <?php
}

// Typography
FLBuilderCSS::typography_field_rule(array(
    'settings'    => $settings,
    'setting_name' => 'title_typography',
    'selector'    => ".fl-node-$id .pta-role-name",
));

FLBuilderCSS::typography_field_rule(array(
    'settings'    => $settings,
    'setting_name' => 'content_typography',
    'selector'    => ".fl-node-$id .pta-role-description, .fl-node-$id .pta-role-department, .fl-node-$id .pta-role-count",
));

















