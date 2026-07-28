<?php
/**
 * Regression checks for the OneDrive Media path and filename logic.
 *
 * These are the pure functions that decide *where* an imported OneDrive file
 * lands under wp-content/uploads and *whether* a local attachment can be
 * matched back to its OneDrive original. Getting them wrong produces exactly
 * the symptoms this site has hit before: media imported into the current
 * YYYY/MM instead of its real year, and "missing" originals that are actually
 * present in OneDrive under a slightly different WordPress-generated name.
 *
 * Run:  php tests/test-onedrive-media-paths.php
 */

require __DIR__ . '/wp-shim.php';
require __DIR__ . '/../Azure Plugin/includes/class-onedrive-media-graph-api.php';
require __DIR__ . '/../Azure Plugin/includes/class-onedrive-media-manager.php';

$t = new TestRunner('OneDrive Media paths & filenames');

WP_Shim::reset();
// Module disabled so the constructor returns before registering hooks or cron.
WP_Shim::$settings['enable_onedrive_media'] = false;
$mgr = new Azure_OneDrive_Media_Manager();
$api = new Azure_OneDrive_Media_GraphAPI();

function subdir($mgr, $parent_path, $base_folder = 'WordPress Media') {
    WP_Shim::$settings['onedrive_media_base_folder'] = $base_folder;
    return call_private($mgr, 'get_relative_upload_subdir', array($parent_path));
}

// ---------------------------------------------------------------------------
// 1. get_relative_upload_subdir — mapping a Graph parent path to uploads/YYYY/MM
// ---------------------------------------------------------------------------

$t->equals(
    '2019/02',
    subdir($mgr, '/drives/b!abc123/root:/WordPress Media/2019/02'),
    'year/month subfolder is extracted from a drive path'
);

$t->equals(
    '2019',
    subdir($mgr, '/drive/root:/WordPress Media/2019'),
    'a year-only subfolder is extracted'
);

$t->equals(
    '',
    subdir($mgr, '/drive/root:/WordPress Media'),
    'the base folder itself maps to no subfolder'
);

$t->equals(
    '',
    subdir($mgr, '/drive/root:/Some Other Folder/2019'),
    'an unrelated folder yields no subfolder'
);

// Graph returns parentReference.path percent-encoded. Failing to decode meant
// the base folder never matched and every file fell back to today's date.
$t->equals(
    '2019/02',
    subdir($mgr, '/drives/b!abc123/root:/WordPress%20Media/2019/02'),
    'a percent-encoded parent path still resolves (Graph encodes spaces)'
);

// A base folder that is a prefix of a sibling must not match it, or files land
// in a bogus uploads/Archive/2019/02 directory.
$t->equals(
    '',
    subdir($mgr, '/drives/b!abc123/root:/Media Archive/2019/02', 'Media'),
    'the base folder must match a whole path segment, not a prefix'
);

$t->equals(
    '2019/02',
    subdir($mgr, '/drives/b!abc123/root:/Media/2019/02', 'Media'),
    'an exact base folder segment still resolves'
);

$t->equals('', subdir($mgr, ''), 'an empty parent path yields no subfolder');

// ---------------------------------------------------------------------------
// 2. is_wp_thumbnail — generated size variants must not be imported as originals
// ---------------------------------------------------------------------------

$thumbs = array(
    'photo-150x150.png',
    'photo-300x200.jpg',
    'photo-1024x572.png',
    'Gemini_Generated_Image_izp1z5-768x768.png',
    // OneDrive appends "-1" when a name collides.
    'photo-300x200-1.jpg',
);
foreach ($thumbs as $name) {
    $t->check(call_private($mgr, 'is_wp_thumbnail', array($name)), "'{$name}' is treated as a generated thumbnail");
}

$originals = array(
    'photo.png',
    'NomCom-Web-banner-1-scaled.png',
    'Teachers-Favorite-List.png',
    'Wilder-Staff-Favorites-2026.pdf',
    'IMG_2697-rotated.jpeg',
    'report-2026.pdf',
);
foreach ($originals as $name) {
    $t->check(!call_private($mgr, 'is_wp_thumbnail', array($name)), "'{$name}' is treated as an original");
}

$t->note("is_wp_thumbnail() is name-only, so a genuine original named like 'poster-1920x1080.jpg' is");
$t->note("skipped as a thumbnail. Nothing in the filename distinguishes the two — if that bites, the");
$t->note("sync would need to check whether a same-stem original exists alongside it in the folder.");

// ---------------------------------------------------------------------------
// 3. find_in_index — matching a WordPress filename to its OneDrive original
// ---------------------------------------------------------------------------

$index = array(
    'photo.png'      => array('id' => 'A', 'name' => 'photo.png'),
    'img_2697.jpeg'  => array('id' => 'B', 'name' => 'IMG_2697.jpeg'),
    'flyer.pdf'      => array('id' => 'C', 'name' => 'flyer.pdf'),
);

function found_id($mgr, $filename, $index) {
    $hit = call_private($mgr, 'find_in_index', array($filename, $index));
    return $hit === null ? null : $hit['id'];
}

$t->equals('A', found_id($mgr, 'photo.png', $index), 'an exact filename matches');
$t->equals('A', found_id($mgr, 'PHOTO.PNG', $index), 'matching is case-insensitive');
$t->equals('A', found_id($mgr, 'photo-scaled.png', $index), 'the -scaled suffix is stripped');
$t->equals('A', found_id($mgr, 'photo-e1764958349687.png', $index), 'the -e{timestamp} edit suffix is stripped');
$t->equals('A', found_id($mgr, 'photo-300x200.png', $index), 'a -WxH size suffix is stripped');
$t->equals('B', found_id($mgr, 'IMG_2697-rotated.jpeg', $index), 'the -rotated suffix is stripped');

// WordPress stacks these suffixes: editing an already-downsized image produces
// "name-e{timestamp}-scaled.jpg". Stripping each suffix only once misses it.
$t->equals('A', found_id($mgr, 'photo-e1764958349687-scaled.png', $index), 'stacked -e{timestamp} then -scaled is stripped');
$t->equals('A', found_id($mgr, 'photo-scaled-300x200.png', $index), 'stacked -scaled then -WxH is stripped');
$t->equals('B', found_id($mgr, 'IMG_2697-rotated-scaled.jpeg', $index), 'stacked -rotated then -scaled is stripped');
$t->equals('B', found_id($mgr, 'IMG_2697-rotated-e1764958349687-scaled.jpeg', $index), 'three stacked suffixes are stripped');

$t->equals(null, found_id($mgr, 'not-here.png', $index), 'an absent file returns null');
$t->equals(null, found_id($mgr, 'photo.jpg', $index), 'a different extension is not a match');

// ---------------------------------------------------------------------------
// 4. GraphAPI::combine_paths
// ---------------------------------------------------------------------------

$t->equals('WordPress Media/2026/photo.png', call_private($api, 'combine_paths', array('WordPress Media/2026', 'photo.png')), 'paths join with a single slash');
$t->equals('WordPress Media/2026/photo.png', call_private($api, 'combine_paths', array('/WordPress Media/2026/', '/photo.png')), 'stray slashes are trimmed');
$t->equals('photo.png', call_private($api, 'combine_paths', array('', 'photo.png')), 'an empty base returns the path');
$t->equals('WordPress Media', call_private($api, 'combine_paths', array('WordPress Media', '')), 'an empty path returns the base');

// ---------------------------------------------------------------------------
// 5. GraphAPI::encode_path — Graph "root:/…:/" addresses
// ---------------------------------------------------------------------------

function encoded($api, $path) {
    return call_private($api, 'encode_path', array($path));
}

$t->equals('WordPress%20Media/2026', encoded($api, 'WordPress Media/2026'), 'spaces are percent-encoded, slashes are not');
$t->equals('WordPress%20Media/2026', encoded($api, '/WordPress Media/2026/'), 'leading and trailing slashes are dropped');
$t->equals('Media/a%2Bb.png', encoded($api, 'Media/a+b.png'), "'+' is encoded so it is not read as a space");
$t->equals('Media/note%231.png', encoded($api, 'Media/note#1.png'), "'#' is encoded instead of truncating the path");
$t->equals('Media/what%3F.png', encoded($api, 'Media/what?.png'), "'?' is encoded instead of starting a query string");
$t->equals('Media/a%26b.png', encoded($api, 'Media/a&b.png'), "'&' is encoded");
$t->equals('Caf%C3%A9/photo.png', encoded($api, 'Café/photo.png'), 'non-ASCII names are UTF-8 percent-encoded');
$t->equals('a/b', encoded($api, 'a//b'), 'empty segments are collapsed');
$t->equals('', encoded($api, ''), 'an empty path encodes to an empty string');

// ---------------------------------------------------------------------------
// 6. GraphAPI::format_file_data
// ---------------------------------------------------------------------------

$formatted = call_private($api, 'format_file_data', array(array(
    'id'                              => '01ABC',
    'name'                            => 'photo.png',
    'size'                            => 2048,
    'file'                            => array('mimeType' => 'image/png'),
    'lastModifiedDateTime'            => '2026-07-01T10:00:00Z',
    '@microsoft.graph.downloadUrl'    => 'https://example.test/dl',
    'parentReference'                 => array('path' => '/drive/root:/WordPress Media/2026/07'),
)));

$t->equals('01ABC', $formatted['id'], 'file id is mapped');
$t->equals('image/png', $formatted['mime_type'], 'mime type is read from the file facet');
$t->equals('https://example.test/dl', $formatted['download_url'], 'download url is mapped');
$t->equals(false, $formatted['is_folder'], 'a file is not reported as a folder');

$folder = call_private($api, 'format_file_data', array(array(
    'id' => '01FOLDER', 'name' => '2026', 'folder' => array('childCount' => 3),
)));
$t->equals(true, $folder['is_folder'], 'a folder is reported as a folder');
$t->equals('', $folder['mime_type'], 'a folder has no mime type');
$t->equals('', $folder['download_url'], 'a folder has no download url');

// A folder entry must never be mistaken for an importable file.
$t->check(empty($folder['download_url']), 'importers can detect a folder by its empty download url');

exit($t->finish() > 0 ? 1 : 0);
