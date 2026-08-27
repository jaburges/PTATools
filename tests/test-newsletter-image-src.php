<?php
/**
 * Newsletter Review must export the image URL the editor actually set.
 *
 * GrapesJS image components store src as a model *property*. getHtml()
 * (used by Review & Test) reads that property and overwrites attributes.src.
 * Updating only addAttributes() / the canvas <img> leaves Review showing
 * the template placeholder (placehold.co "Feature Image").
 *
 * Run: php tests/test-newsletter-image-src.php
 */

require_once __DIR__ . '/wp-shim.php';

$t = new TestRunner('Newsletter image src export');

$editor_js = file_get_contents(dirname(__DIR__) . '/Azure Plugin/js/newsletter-editor.js');
$t->check($editor_js !== false && strlen($editor_js) > 100, 'newsletter-editor.js is readable');

/**
 * GrapesJS ComponentImage.getAttrToHTML(): property `src` wins.
 */
function gjs_image_html($component) {
    $src = isset($component['src']) ? $component['src'] : '';
    $attrs = isset($component['attributes']) ? $component['attributes'] : array();
    if ($src !== '') {
        $attrs['src'] = $src;
    }
    $alt = isset($attrs['alt']) ? $attrs['alt'] : '';
    return '<img src="' . $attrs['src'] . '" alt="' . $alt . '">';
}

$placeholder = 'https://placehold.co/270x180/e8e8e8/999999?text=Feature+Image';
$chosen = 'https://example.com/uploads/open-house.jpg';

$only_attributes = array(
    'src' => $placeholder,
    'attributes' => array('src' => $chosen, 'alt' => 'Open House'),
);
$t->check(
    strpos(gjs_image_html($only_attributes), $placeholder) !== false,
    'addAttributes-only still exports the placeholder (GrapesJS property wins)'
);
$t->check(
    strpos(gjs_image_html($only_attributes), $chosen) === false,
    'addAttributes-only does not export the chosen URL'
);

$set_property = array(
    'src' => $chosen,
    'attributes' => array('src' => $chosen, 'alt' => 'Open House'),
);
$t->check(
    strpos(gjs_image_html($set_property), $chosen) !== false,
    'set(src) exports the chosen URL'
);
$t->check(
    strpos(gjs_image_html($set_property), $placeholder) === false,
    'set(src) drops the placeholder from export'
);

$select_handler = '';
if (preg_match('/mediaFrame\.on\(\'select\',\s*function\(\)\s*\{(.*?)mediaFrame\.open\(\)/s', $editor_js, $m)) {
    $select_handler = $m[1];
}
$t->check($select_handler !== '', 'media library select handler is present');

$t->check(
    preg_match('/\.(set\(\s*[\'"]src[\'"]|set\(\s*\{\s*src\s*:)/', $select_handler) === 1,
    'media library select sets the GrapesJS src property (not just attributes)'
);

$t->check(
    preg_match("/name:\\s*'src'[\\s\\S]{0,80}changeProp:\\s*1/", $editor_js) === 1
    || preg_match("/name:\\s*\"src\"[\\s\\S]{0,80}changeProp:\\s*1/", $editor_js) === 1,
    'Image URL trait updates the src property so typed URLs survive Review'
);

exit($t->finish() === 0 ? 0 : 1);
