<?php
/**
 * Forms: schema sanitising, submission validation, spam guards, CSV
 * escaping and theme CSS.
 *
 * Run: php tests/test-forms-schema.php
 */

require __DIR__ . '/wp-shim.php';

function sanitize_text_field($s) {
    $s = strip_tags((string) $s);
    $s = preg_replace('/[\r\n\t ]+/', ' ', $s);
    return trim($s);
}
function sanitize_textarea_field($s) {
    return trim(strip_tags((string) $s));
}
function is_email($e) {
    return filter_var($e, FILTER_VALIDATE_EMAIL) ? $e : false;
}
function wp_kses($s, $allowed) {
    $tags = '';
    foreach (array_keys($allowed) as $tag) {
        $tags .= '<' . $tag . '>';
    }
    $s = strip_tags((string) $s, $tags);
    return preg_replace('/\s+on[a-z]+\s*=\s*("[^"]*"|\'[^\']*\'|[^\s>]+)/i', '', $s);
}
function wp_kses_post($s) {
    return wp_kses($s, array('a' => 1, 'strong' => 1, 'em' => 1, 'b' => 1, 'i' => 1, 'br' => 1, 'p' => 1, 'ul' => 1, 'ol' => 1, 'li' => 1, 'span' => 1));
}

require __DIR__ . '/../Azure Plugin/includes/class-forms-schema.php';
require __DIR__ . '/../Azure Plugin/includes/class-forms-themes.php';

$t = new TestRunner('Forms schema');

// ─── Sanitising ───────────────────────────────────────────────────────

$schema = Azure_Forms_Schema::sanitize_schema(array(
    array('type' => 'heading', 'label' => 'About you', 'required' => true),
    array('type' => 'text', 'label' => 'First name', 'width' => 'half', 'prefill' => 'first_name', 'onclick' => 'x'),
    array('type' => 'text', 'label' => 'First name'),
    array('type' => 'script', 'label' => 'Bad'),
    'not-a-field',
    array('type' => 'email', 'label' => 'Email', 'required' => true, 'maxlength' => 99999),
    array('type' => 'select', 'label' => 'Grade', 'options' => "K\n1\n1\n\n2"),
    array('type' => 'text', 'label' => 'Website'),
    array('type' => 'number', 'label' => '2nd child age', 'min' => '0', 'max' => 'abc'),
    array('type' => 'consent', 'label' => 'I agree to the <a href="/t" onclick="evil()">terms</a><script>x</script>'),
    array('type' => 'text', 'label' => 'Prefill junk', 'prefill' => 'password'),
    array('type' => 'paragraph', 'content' => '<p>Hi <img src=x onerror=alert(1)></p>', 'width' => 'half'),
));

$t->equals(10, count($schema), 'unknown types and non-arrays are dropped');
$t->equals(array('id', 'type', 'label', 'width'), array_keys($schema[0]), 'heading keeps only id/type/label/width (no required)');
$t->check(!array_key_exists('onclick', $schema[1]), 'unknown attributes are dropped');
$t->equals('first_name', $schema[1]['name'], 'name derived from label');
$t->equals('half', $schema[1]['width'], 'half width kept');
$t->equals('first_name', $schema[1]['prefill'], 'valid prefill kept');
$t->equals('first_name_2', $schema[2]['name'], 'duplicate names are made unique');
$t->equals(254, $schema[3]['maxlength'], 'maxlength clamped to the type cap');
$t->equals(array('K', '1', '2'), $schema[4]['options'], 'options split, de-duplicated, blanks removed');
$t->equals('f_website', $schema[5]['name'], 'reserved name "website" is prefixed');
$t->equals('f_2nd_child_age', $schema[6]['name'], 'leading digit is prefixed');
$t->equals(0.0, $schema[6]['min'], 'numeric min kept');
$t->equals(null, $schema[6]['max'], 'non-numeric max becomes null');
$t->check(strpos($schema[7]['label'], '<a href="/t"') !== false, 'consent label keeps links');
$t->check(strpos($schema[7]['label'], 'onclick') === false && strpos($schema[7]['label'], '<script') === false, 'consent label strips handlers and scripts');
$t->equals('', $schema[8]['prefill'], 'unknown prefill source is cleared');
$t->equals('full', $schema[9]['width'], 'paragraph is always full width');
$t->check(strpos($schema[9]['content'], '<img') === false, 'paragraph content is kses-filtered');

$ids = array_column($schema, 'id');
$t->equals(count($ids), count(array_unique($ids)), 'field ids are unique');

$dupe_ids = Azure_Forms_Schema::sanitize_schema(array(
    array('id' => 'a', 'type' => 'text', 'label' => 'One'),
    array('id' => 'a', 'type' => 'text', 'label' => 'Two'),
    array('id' => 'Bad Id!', 'type' => 'text', 'label' => 'Three'),
));
$t->equals(array('a', 'fld_2', 'fld_3'), array_column($dupe_ids, 'id'), 'duplicate and invalid ids are replaced');

$from_json = Azure_Forms_Schema::sanitize_schema('[{"type":"text","label":"Name"}]');
$t->equals('name', $from_json[0]['name'] ?? null, 'accepts a JSON string');
$t->equals(array(), Azure_Forms_Schema::sanitize_schema('not json'), 'bad JSON gives an empty schema');

$many = array_fill(0, 200, array('type' => 'text', 'label' => 'x'));
$t->equals(Azure_Forms_Schema::MAX_FIELDS, count(Azure_Forms_Schema::sanitize_schema($many)), 'field count is capped');

// ─── Validation ───────────────────────────────────────────────────────

$form = Azure_Forms_Schema::sanitize_schema(array(
    array('type' => 'text', 'label' => 'Name', 'required' => true, 'maxlength' => 10),
    array('type' => 'email', 'label' => 'Email', 'required' => true),
    array('type' => 'phone', 'label' => 'Phone'),
    array('type' => 'select', 'label' => 'Grade', 'options' => array('K', '1')),
    array('type' => 'checkboxes', 'label' => 'Days', 'options' => array('Mon', 'Tue'), 'required' => true),
    array('type' => 'radio', 'label' => 'Size', 'options' => array('S', 'M')),
    array('type' => 'number', 'label' => 'Count', 'min' => 1, 'max' => 5),
    array('type' => 'date', 'label' => 'When'),
    array('type' => 'consent', 'label' => 'I agree', 'required' => true),
    array('type' => 'textarea', 'label' => 'Notes'),
    array('type' => 'heading', 'label' => 'Ignored'),
));

$ok = Azure_Forms_Schema::validate_submission($form, array(
    'name' => '  Jamie  ',
    'email' => 'jamie@example.com',
    'phone' => '+1 (425) 555-0100',
    'grade' => 'K',
    'days' => array('Mon', 'Tue', 'Mon'),
    'size' => 'M',
    'count' => '3',
    'when' => '2026-10-15',
    'i_agree' => 'yes',
    'notes' => "Line one\nLine two",
    'extra' => 'not in schema',
));
$t->equals(array(), $ok['errors'], 'a valid submission has no errors');
$t->equals('Jamie', $ok['data']['name'], 'values are trimmed');
$t->equals(array('Mon', 'Tue'), $ok['data']['days'], 'checkbox values are de-duplicated');
$t->equals('yes', $ok['data']['i_agree'], 'consent stored as yes');
$t->check(!array_key_exists('extra', $ok['data']), 'unknown inputs are ignored');
$t->check(!array_key_exists('ignored', $ok['data']), 'layout fields store nothing');

$bad = Azure_Forms_Schema::validate_submission($form, array(
    'name' => 'Far too long a name',
    'email' => 'not-an-email',
    'phone' => '12',
    'grade' => 'Z',
    'days' => array('Sun'),
    'size' => 'XL',
    'count' => '9',
    'when' => '2026-02-30',
    'notes' => array('x'),
));
foreach (array('name', 'email', 'phone', 'grade', 'days', 'size', 'count', 'when', 'i_agree', 'notes') as $field) {
    $t->check(isset($bad['errors'][$field]), "invalid {$field} is rejected");
}

$empty = Azure_Forms_Schema::validate_submission($form, array());
$t->check(isset($empty['errors']['name'], $empty['errors']['email'], $empty['errors']['days'], $empty['errors']['i_agree']), 'required fields are enforced');
$t->check(!isset($empty['errors']['phone']) && !isset($empty['errors']['grade']), 'optional fields may be empty');

$t->equals('jamie@example.com', Azure_Forms_Schema::first_email($form, $ok['data']), 'first_email finds the email value');

// ─── Spam guards ──────────────────────────────────────────────────────

$secret = 'test-secret';
$now = 1_800_000_000;
$token = Azure_Forms_Schema::timing_token($now - 10, $secret);
$t->equals('', Azure_Forms_Schema::check_guard('', $token, '8', $now, $secret), 'a normal submission passes');
$t->equals('', Azure_Forms_Schema::check_guard('', $token, null, $now, $secret), 'no-JS submission passes on the token alone');
$t->equals('honeypot', Azure_Forms_Schema::check_guard('http://spam', $token, '8', $now, $secret), 'a filled honeypot is rejected');
$t->equals('too_fast', Azure_Forms_Schema::check_guard('', Azure_Forms_Schema::timing_token($now - 1, $secret), '8', $now, $secret), 'a token under 3 s old is too fast');
$t->equals('too_fast', Azure_Forms_Schema::check_guard('', $token, '1.2', $now, $secret), 'JS elapsed under 3 s is too fast');
$t->equals('bad_token', Azure_Forms_Schema::check_guard('', ($now - 600) . '.' . substr($token, -16), '8', $now, $secret), 'a forged timestamp fails the HMAC');
$t->equals('bad_token', Azure_Forms_Schema::check_guard('', '', '8', $now, $secret), 'a missing token is rejected');
$t->equals('bad_token', Azure_Forms_Schema::check_guard('', Azure_Forms_Schema::timing_token($now - 10, 'other'), '8', $now, $secret), 'a token signed with another secret is rejected');

// ─── CSV ──────────────────────────────────────────────────────────────

$t->equals("'=HYPERLINK(\"x\")", Azure_Forms_Schema::csv_cell('=HYPERLINK("x")'), 'formula cells are neutralised');
$t->equals("'@SUM(A1)", Azure_Forms_Schema::csv_cell('@SUM(A1)'), '@ cells are neutralised');
$t->equals("'-2+3", Azure_Forms_Schema::csv_cell('-2+3'), 'arithmetic is neutralised');
$t->equals('+1 425 555 0100', Azure_Forms_Schema::csv_cell('+1 425 555 0100'), 'phone numbers are left alone');
$t->equals('-5', Azure_Forms_Schema::csv_cell('-5'), 'negative numbers are left alone');
$t->equals('Mon; Tue', Azure_Forms_Schema::csv_cell(array('Mon', 'Tue')), 'arrays are joined');
$t->equals("\"a\",\"say \"\"hi\"\"\",\"'=1+1\"\n", Azure_Forms_Schema::csv_line(array('a', 'say "hi"', '=1+1')), 'csv_line quotes and escapes');

// ─── Rendering ────────────────────────────────────────────────────────

$html = Azure_Forms_Schema::render_fields($form, 'f1', array('name' => '"><script>', 'days' => array('Tue')));
$t->check(strpos($html, 'name="pta_f[name]"') !== false, 'inputs post under pta_f[]');
$t->check(strpos($html, 'name="pta_f[days][]"') !== false, 'checkboxes post as an array');
$t->check(strpos($html, '<script>') === false, 'round-tripped values are escaped');
$t->check((bool) preg_match('/value="Tue" checked/', $html), 'checked values are restored');
$t->check(strpos($html, 'type="tel"') !== false && strpos($html, 'autocomplete="email"') !== false, 'phone and email get the right input types');

// ─── Themes ───────────────────────────────────────────────────────────

WP_Shim::reset();
Azure_Forms_Themes::reset_request_state();

$t->equals('default', Azure_Forms_Themes::get_theme('missing')['slug'], 'unknown theme falls back to default');

$saved = Azure_Forms_Themes::save_theme(array(
    'slug' => 'Wilder Green!',
    'label' => 'Wilder <b>green</b>',
    'accent_color' => '#0a7d3b',
    'bg_color' => 'red; } body { display:none',
    'border_radius' => 99,
    'header_align' => 'justify',
));
$t->equals('wilder-green', $saved['slug'] ?? null, 'theme slug is cleaned');
$t->equals('Wilder green', $saved['label'], 'theme label is plain text');
$t->equals('#0a7d3b', $saved['accent_color'], 'valid colour kept');
$t->equals('#ffffff', $saved['bg_color'], 'invalid colour falls back');
$t->equals(32, $saved['border_radius'], 'radius clamped');
$t->equals('left', $saved['header_align'], 'unknown alignment falls back');

$t->equals(null, Azure_Forms_Themes::save_theme(array('slug' => '!!!')), 'a theme with no usable slug is not saved');
Azure_Forms_Themes::save_theme(array('slug' => 'default', 'accent_color' => '#000000'));
$t->equals('#2271b1', Azure_Forms_Themes::get_theme('default')['accent_color'], 'the builtin default cannot be overwritten');

$css = Azure_Forms_Themes::css_for_theme($saved);
$t->check(strpos($css, '.pta-form-theme-wilder-green{') === 0, 'CSS is scoped to the theme class');
$t->check(strpos($css, '--pta-accent:#0a7d3b;') !== false, 'CSS carries the accent variable');
$t->check(strpos($css, 'display:none') === false, 'nothing user-typed leaks into CSS');

$first = Azure_Forms_Themes::style_tag_once($saved);
$second = Azure_Forms_Themes::style_tag_once($saved);
$t->check(strpos($first, '<style id="pta-form-theme-wilder-green">') === 0, 'first use prints a style tag');
$t->equals('', $second, 'second use on the same page prints nothing');
$t->check(strpos(Azure_Forms_Themes::style_tag_once(Azure_Forms_Themes::get_theme('default')), 'pta-form-theme-default') !== false, 'a different theme on the same page still prints');

$from = Azure_Forms_Themes::from_upnext(array('slug' => 'pta-blue', 'label' => 'PTA blue', 'accent_color' => '#123456', 'events_per_page' => 9), 'from-upnext');
$t->equals('#123456', $from['accent_color'], 'from_upnext copies branding');
$t->check(!array_key_exists('events_per_page', $from), 'from_upnext ignores non-branding keys');

Azure_Forms_Themes::delete_theme('wilder-green');
$t->check(!Azure_Forms_Themes::theme_exists('wilder-green'), 'themes can be deleted');
$t->check(Azure_Forms_Themes::theme_exists('default'), 'the default theme survives deletes');

// ─── Child, grade and teacher fields ──────────────────────────────────

Azure_Forms_Schema::set_option_source('grade', function () { return array('K', '1', '2', '1'); });
Azure_Forms_Schema::set_option_source('teacher', function () { return array(); });

$pta = Azure_Forms_Schema::sanitize_schema(array(
    array('type' => 'child', 'label' => "Child's name", 'required' => true),
    array('type' => 'grade', 'label' => 'Grade', 'options' => array('ignored')),
    array('type' => 'teacher', 'label' => 'Teacher'),
));
$t->equals(array('child_s_name', 'grade', 'teacher'), array_column($pta, 'name'), 'PTA field names');
$t->check(!isset($pta[1]['options']), 'grade does not store its own options');
$t->equals(array('K', '1', '2'), Azure_Forms_Schema::dynamic_options('grade'), 'grade choices come from the source, de-duplicated');

$ok = Azure_Forms_Schema::validate_submission($pta, array('child_s_name' => 'Sam', 'grade' => '1', 'teacher' => 'Ms. Free Text'));
$t->equals(array(), $ok['errors'], 'valid child, grade and free-text teacher');
$bad = Azure_Forms_Schema::validate_submission($pta, array('child_s_name' => '', 'grade' => '9'));
$t->check(isset($bad['errors']['child_s_name']) && isset($bad['errors']['grade']), 'required child and unknown grade are rejected');

$html = Azure_Forms_Schema::render_fields($pta, 'p');
$t->check(strpos($html, 'list="p-child_s_name-children"') !== false && strpos($html, '<datalist id="p-child_s_name-children">') !== false, 'child field gets a suggestion list');
$t->check(strpos($html, '<option value="2">2</option>') !== false, 'grade renders as a dropdown of its choices');
$t->check((bool) preg_match('/<input type="text" id="p-teacher"/', $html), 'teacher is a text box when there is no roster');

Azure_Forms_Schema::set_option_source('teacher', function () { return array('Ms. A', 'Mr. B'); });
$t->check(strpos(Azure_Forms_Schema::render_fields($pta, 'p'), '<select id="p-teacher"') !== false, 'teacher is a dropdown when there is a roster');
$roster = Azure_Forms_Schema::validate_submission($pta, array('child_s_name' => 'Sam', 'teacher' => 'Ms. Free Text'));
$t->check(isset($roster['errors']['teacher']), 'teacher must be on the roster when there is one');

// ─── Children (repeating) and registration mapping ───────────────────

$reg = Azure_Forms_Schema::sanitize_schema(array(
    array('type' => 'text', 'label' => 'First name', 'required' => true, 'profile' => 'first_name'),
    array('type' => 'text', 'label' => 'Last name', 'profile' => 'last_name'),
    array('type' => 'email', 'label' => 'Email', 'profile' => 'email'),
    array('type' => 'email', 'label' => 'Second email', 'profile' => 'email'),
    array('type' => 'text', 'label' => 'Wrong type', 'profile' => 'email'),
    array('type' => 'phone', 'label' => 'Cell', 'profile' => 'phone'),
    array('type' => 'text', 'label' => 'Bogus', 'profile' => 'is_admin'),
    array('type' => 'children', 'label' => 'Kids', 'required' => true, 'max_children' => 99, 'width' => 'half'),
    array('type' => 'children', 'label' => 'Kids again', 'details_required' => false, 'max_children' => 0),
));
$t->equals('email', $reg[2]['profile'] ?? null, 'profile target is kept on a field of the right type');
$t->check(!isset($reg[3]['profile']), 'a profile target is used by one field only');
$t->check(!isset($reg[4]['profile']), 'a profile target needs a matching field type');
$t->check(!isset($reg[6]['profile']), 'unknown profile targets are dropped');
$t->equals(10, $reg[7]['max_children'], 'max children is capped at 10');
$t->equals(1, $reg[8]['max_children'], 'max children is at least 1');
$t->check($reg[7]['details_required'] === true && $reg[8]['details_required'] === false, 'details_required defaults on and can be turned off');
$t->equals('full', $reg[7]['width'], 'children field is always full width');
$t->equals(
    array('first_name' => 'first_name', 'last_name' => 'last_name', 'email' => 'email', 'phone' => 'cell', 'children' => 'kids'),
    Azure_Forms_Schema::profile_map($reg),
    'profile_map points each target at its field, and children at the first Children field'
);

$kids = array($reg[7]);
$good = Azure_Forms_Schema::validate_submission($kids, array('kids' => array(
    array('name' => ' Sam Smith ', 'grade' => '1', 'teacher' => 'Ms. A'),
    array('name' => '', 'grade' => '', 'teacher' => ''),
    array('name' => 'Ava Smith', 'grade' => 'K', 'teacher' => 'Mr. B'),
)));
$t->equals(array(), $good['errors'], 'valid children pass');
$t->equals(array(
    array('name' => 'Sam Smith', 'grade' => '1', 'teacher' => 'Ms. A'),
    array('name' => 'Ava Smith', 'grade' => 'K', 'teacher' => 'Mr. B'),
), $good['data']['kids'] ?? null, 'children are trimmed and empty rows dropped');

$none = Azure_Forms_Schema::validate_submission($kids, array('kids' => array(array('name' => '', 'grade' => '', 'teacher' => ''))));
$t->equals('Please add at least one child.', $none['errors']['kids'] ?? null, 'a required Children field needs at least one child');
$noname = Azure_Forms_Schema::validate_submission($kids, array('kids' => array(array('name' => '', 'grade' => '1', 'teacher' => 'Ms. A'))));
$t->check(strpos($noname['errors']['kids'] ?? '', 'name') !== false, 'a child with details but no name is rejected');
$nodetail = Azure_Forms_Schema::validate_submission($kids, array('kids' => array(array('name' => 'Sam', 'grade' => '1'))));
$t->check(strpos($nodetail['errors']['kids'] ?? '', 'grade and teacher') !== false, 'grade and teacher are required when details_required');
$offlist = Azure_Forms_Schema::validate_submission($kids, array('kids' => array(array('name' => 'Sam', 'grade' => '9', 'teacher' => 'Ms. A'))));
$t->check(isset($offlist['errors']['kids']), 'a grade that is not on the list is rejected');
$offroster = Azure_Forms_Schema::validate_submission($kids, array('kids' => array(array('name' => 'Sam', 'grade' => '1', 'teacher' => 'Dr. Evil'))));
$t->check(isset($offroster['errors']['kids']), 'a teacher that is not on the roster is rejected');
$junk = Azure_Forms_Schema::validate_submission($kids, array('kids' => 'Sam'));
$t->check(isset($junk['errors']['kids']), 'a non-list value counts as no children');
$nested = Azure_Forms_Schema::validate_submission($kids, array('kids' => array(array('name' => array('x'), 'grade' => '1', 'teacher' => 'Ms. A'))));
$t->check(isset($nested['errors']['kids']), 'array values inside a child are treated as empty');
$many = array();
for ($i = 0; $i < 15; $i++) {
    $many[] = array('name' => 'Kid ' . $i, 'grade' => '1', 'teacher' => 'Ms. A');
}
$capped = Azure_Forms_Schema::validate_submission($kids, array('kids' => $many));
$t->equals(10, count($capped['data']['kids'] ?? array()), 'extra children beyond the maximum are ignored');
$loose = Azure_Forms_Schema::validate_submission(array($reg[8]), array('kids_again' => array(array('name' => 'Sam'))));
$t->equals(array(array('name' => 'Sam', 'grade' => '', 'teacher' => '')), $loose['data']['kids_again'] ?? null, 'grade and teacher are optional when details_required is off');

$t->equals('Sam Smith (Grade 1, Ms. A); Ava Smith (Grade K, Mr. B)', Azure_Forms_Schema::display_value($good['data']['kids']), 'children display as one line');
$t->equals('Sam', Azure_Forms_Schema::display_value(array(array('name' => 'Sam', 'grade' => '', 'teacher' => ''))), 'a child without details shows just the name');
$t->equals('Sam Smith (Grade 1, Ms. A); Ava Smith (Grade K, Mr. B)', Azure_Forms_Schema::csv_cell($good['data']['kids']), 'children export to one CSV cell');
$t->equals('Paint, Glue', Azure_Forms_Schema::display_value(array('Paint', 'Glue')), 'checkbox arrays still display as a list');

$kid_html = Azure_Forms_Schema::render_fields($kids, 'r');
$t->check(strpos($kid_html, 'data-name="kids" data-max="10"') !== false, 'children field carries its maximum');
$t->check(strpos($kid_html, 'name="pta_f[kids][0][name]"') !== false, 'child inputs are named by index');
$t->check(strpos($kid_html, '<select id="r-kids-0-grade" name="pta_f[kids][0][grade]" required') !== false, 'the first child grade is a required dropdown');
$t->check(strpos($kid_html, '<option value="Ms. A">Ms. A</option>') !== false, 'teacher choices come from the roster');
$t->check(strpos($kid_html, 'class="pta-form__child-add" hidden') !== false, 'the add button stays hidden until JavaScript wires it');
$refill = Azure_Forms_Schema::render_fields($kids, 'r', array('kids' => $good['data']['kids']));
$t->check(strpos($refill, 'name="pta_f[kids][1][name]" value="Ava Smith"') !== false, 'a re-shown form keeps every child');

// ─── Show-if ──────────────────────────────────────────────────────────

$cond = Azure_Forms_Schema::sanitize_schema(array(
    array('type' => 'text', 'label' => 'Early', 'show_if' => array('field' => 'pickup', 'value' => 'Yes')),
    array('type' => 'radio', 'label' => 'Pickup', 'options' => array('Yes', 'No'), 'required' => true),
    array('type' => 'text', 'label' => 'Address', 'required' => true, 'show_if' => array('field' => 'pickup', 'value' => 'yes')),
    array('type' => 'heading', 'label' => 'Pickup details', 'show_if' => array('field' => 'pickup', 'value' => 'Yes')),
    array('type' => 'textarea', 'label' => 'Directions', 'show_if' => array('field' => 'address', 'value' => '')),
    array('type' => 'text', 'label' => 'Self', 'show_if' => array('field' => 'self', 'value' => 'x')),
));
$t->check(!isset($cond[0]['show_if']), 'show_if on a later field is dropped');
$t->equals(array('field' => 'pickup', 'value' => 'yes'), $cond[2]['show_if'] ?? null, 'show_if on an earlier field is kept');
$t->check(isset($cond[3]['show_if']), 'layout fields can be conditional');
$t->check(!isset($cond[5]['show_if']), 'a field cannot depend on itself');

$no = Azure_Forms_Schema::validate_submission($cond, array('pickup' => 'No', 'address' => 'should be dropped', 'directions' => 'x'));
$t->equals(array(), $no['errors'], 'hidden required fields are not enforced');
$t->check(!array_key_exists('address', $no['data']) && !array_key_exists('directions', $no['data']), 'hidden fields and their dependants store nothing');

$yes = Azure_Forms_Schema::validate_submission($cond, array('pickup' => 'Yes', 'address' => ''));
$t->check(isset($yes['errors']['address']), 'revealed required fields are enforced');
$chain = Azure_Forms_Schema::validate_submission($cond, array('pickup' => 'Yes', 'address' => '1 Main St', 'directions' => 'Back door'));
$t->equals('Back door', $chain['data']['directions'] ?? null, 'a chained field shows once its controller is answered');

$cond_html = Azure_Forms_Schema::render_fields($cond, 'c');
$t->check(strpos($cond_html, 'data-name="address" data-show-if="pickup" data-show-value="yes"') !== false, 'conditional fields carry data-show-if');
$t->check(strpos($cond_html, '<h3 class="pta-form__heading" data-show-if="pickup"') !== false, 'conditional headings carry data-show-if');
$t->check(!preg_match('/data-show-if="[^"]*"[^>]*\shidden/', $cond_html), 'conditional fields are not hidden server-side, so the form still works without JavaScript');

$t->check(Azure_Forms_Schema::value_matches(array('Paint', 'Glue'), 'glue'), 'value_matches handles checkbox arrays');
$t->check(!Azure_Forms_Schema::value_matches(null, ''), 'value_matches: empty want needs an answer');

// ─── Turnstile ────────────────────────────────────────────────────────

if (!function_exists('add_shortcode')) {
    function add_shortcode($tag, $cb) { return true; }
}
require __DIR__ . '/../Azure Plugin/includes/class-forms-module.php';

WP_Shim::reset();
$t->check(!Azure_Forms_Module::turnstile_active(array('turnstile' => true)), 'Turnstile is inactive without keys');
WP_Shim::$options[Azure_Forms_Module::TURNSTILE_OPTION] = array('site_key' => 'site', 'secret_key' => 'secret');
$t->check(!Azure_Forms_Module::turnstile_active(array('turnstile' => false)), 'Turnstile is inactive when the form has it off');
$t->check(Azure_Forms_Module::turnstile_active(array('turnstile' => true)), 'Turnstile is active with keys and the form setting');

$t->equals('captcha', Azure_Forms_Module::verify_turnstile('', '1.2.3.4'), 'a missing token fails without calling Cloudflare');
$t->equals(0, count(WP_Shim::$http_calls), 'no HTTP call for an empty token');

WP_Shim::on_request('turnstile/v0/siteverify', 200, array('success' => true));
$t->equals('', Azure_Forms_Module::verify_turnstile('tok', '1.2.3.4'), 'a verified token passes');
$t->equals('secret', WP_Shim::$http_calls[0]['args']['body']['secret'] ?? null, 'the secret key is sent to Cloudflare');

WP_Shim::$http_responses = array();
WP_Shim::on_request('turnstile/v0/siteverify', 200, array('success' => false, 'error-codes' => array('invalid-input-response')));
$t->equals('captcha', Azure_Forms_Module::verify_turnstile('tok', '1.2.3.4'), 'a rejected token fails');

WP_Shim::$http_responses = array();
WP_Shim::on_request('turnstile/v0/siteverify', 503, 'down');
$t->equals('', Azure_Forms_Module::verify_turnstile('tok', '1.2.3.4'), 'a Cloudflare outage does not block submissions');
$t->check(WP_Shim::logged('Turnstile verify unavailable'), 'the outage is logged');

$t->check(Azure_Forms_Module::normalize_settings(array('turnstile' => '1'))['turnstile'] === true, 'turnstile setting is a boolean');
$t->check(Azure_Forms_Module::normalize_settings(array('registration' => 1))['registration'] === true, 'registration setting is a boolean');
$t->check(Azure_Forms_Module::normalize_settings(array())['registration'] === false, 'registration is off by default');

exit($t->finish() > 0 ? 1 : 0);
