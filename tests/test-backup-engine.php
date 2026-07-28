<?php
/**
 * Regression checks for Azure_Backup_Engine's file selection.
 *
 * Exclusion matching decides what does and does not end up in a backup
 * archive, so both directions matter: an over-broad pattern silently drops
 * real content from the only copy of the site, and an under-broad one bloats
 * the archive with caches and logs.
 *
 * Run:  php tests/test-backup-engine.php
 */

require __DIR__ . '/wp-shim.php';
require __DIR__ . '/../Azure Plugin/includes/class-backup-engine.php';

$t = new TestRunner('Azure_Backup_Engine');

WP_Shim::reset();
$engine = new Azure_Backup_Engine('backup_test', sys_get_temp_dir());

// ---------------------------------------------------------------------------
// 1. Split size is clamped to a sane floor
// ---------------------------------------------------------------------------

$t->equals(400 * 1024 * 1024, read_private($engine, 'split_every'), 'default split size is 400 MB');

WP_Shim::$settings['backup_split_size'] = 5;
$t->equals(25 * 1024 * 1024, read_private(new Azure_Backup_Engine('b', sys_get_temp_dir()), 'split_every'), 'an absurdly small split size is clamped to 25 MB');

WP_Shim::$settings['backup_split_size'] = 1024;
$t->equals(1024 * 1024 * 1024, read_private(new Azure_Backup_Engine('b', sys_get_temp_dir()), 'split_every'), 'a large split size is honoured');

unset(WP_Shim::$settings['backup_split_size']);

// ---------------------------------------------------------------------------
// 2. should_exclude — wp-content "others" backup
// ---------------------------------------------------------------------------

$others = Azure_Backup_Engine::get_others_exclusions();

function excluded($engine, $path, $patterns) {
    return call_private($engine, 'should_exclude', array($path, $patterns));
}

// Entities backed up separately must be skipped here.
$must_exclude = array(
    'uploads/2026/01/photo.png'        => 'uploads are archived as their own entity',
    'plugins/azure-plugin/main.php'    => 'plugins are archived as their own entity',
    'themes/wilder/style.css'          => 'themes are archived as their own entity',
    'mu-plugins/loader.php'            => 'mu-plugins are archived as their own entity',
    'cache/object/abc.php'             => 'caches are excluded',
    'upgrade/tmp/x.zip'                => 'the upgrade scratch directory is excluded',
    'ai1wm-backups/site.wpress'        => 'other plugins\' backup directories are excluded',
    'temp_backup_1234/plugin/x.php'    => 'temp_backup_ prefixed directories are excluded',
    'node_modules/pkg/index.js'        => 'node_modules is excluded',
    '.git/config'                      => 'a root .git directory is excluded',
    'themes/wilder/.git/config'        => 'a nested .git directory is excluded',
    'plugins/foo/node_modules/a.js'    => 'nested node_modules is excluded',
    'debug.log'                        => 'the root debug.log is excluded',
);
foreach ($must_exclude as $path => $why) {
    $t->check(excluded($engine, $path, $others), "excludes '{$path}' — {$why}");
}

// A log left somewhere other than the root is still just a log. Probe this
// with an isolated pattern list, otherwise the broader 'plugins' / 'themes'
// directory patterns would match first and the check would prove nothing.
$t->check(excluded($engine, 'languages/debug.log', $others), "excludes 'debug.log' below the root");
$t->check(excluded($engine, 'a/b/c/debug.log', array('debug.log')), "the 'debug.log' pattern matches at any depth");
$t->check(!excluded($engine, 'a/b/my-debug.log', array('debug.log')), "the 'debug.log' pattern does not match 'my-debug.log'");
$t->check(!excluded($engine, 'a/b/debug.log.bak', array('debug.log')), "the 'debug.log' pattern does not match 'debug.log.bak'");

// Real content must survive. These are the near-misses that a substring match
// would wrongly swallow.
$must_keep = array(
    'languages/en_US.mo'                  => 'translation files are content',
    'fonts/inter.woff2'                   => 'uploaded fonts are content',
    'my-uploads/notes.txt'                => "a directory merely containing 'uploads' is not the uploads dir",
    'plugins-backup-notes.txt'            => "a file starting with 'plugins' is not the plugins dir",
    'themes-readme.md'                     => "a file starting with 'themes' is not the themes dir",
    'uploads-policy.pdf'                   => "a file starting with 'uploads' is not the uploads dir",
    'docs/cache-policy.md'                => "a filename containing 'cache' is not the cache dir",
    'gitignore-notes.md'                  => 'a file with a git-like name is content',
);
foreach ($must_keep as $path => $why) {
    $t->check(!excluded($engine, $path, $others), "keeps '{$path}' — {$why}");
}

// ---------------------------------------------------------------------------
// 3. should_exclude — plugins backup
// ---------------------------------------------------------------------------

$plugin_excl = Azure_Backup_Engine::get_plugin_exclusions();
$t->check(excluded($engine, 'my-plugin/node_modules/dep.js', $plugin_excl), 'plugin backup excludes node_modules');
$t->check(excluded($engine, 'my-plugin/cache/compiled.php', $plugin_excl), 'plugin backup excludes cache dirs');
$t->check(!excluded($engine, 'my-plugin/includes/class-main.php', $plugin_excl), 'plugin backup keeps source files');
$t->check(!excluded($engine, 'my-plugin/assets/cached-sprite.png', $plugin_excl), "plugin backup keeps a file named 'cached-…'");

// ---------------------------------------------------------------------------
// 4. collect_files — walks the tree, applies exclusions and selections
// ---------------------------------------------------------------------------

$root = sys_get_temp_dir() . '/pta-backup-engine-test-' . getmypid();
$tree = array(
    'plugins/alpha/alpha.php'              => '<?php // alpha',
    'plugins/alpha/includes/helper.php'    => '<?php // helper',
    'plugins/alpha/node_modules/dep.js'    => 'module.exports = 1;',
    'plugins/beta/beta.php'                => '<?php // beta',
    'plugins/beta/.git/config'             => '[core]',
    'plugins/single-file.php'              => '<?php // single',
);
foreach ($tree as $rel => $contents) {
    $full = $root . '/' . $rel;
    @mkdir(dirname($full), 0777, true);
    file_put_contents($full, $contents);
}

function collected($engine, $source, $exclude = array(), $selected = array()) {
    $files = call_private($engine, 'collect_files', array($source, $exclude, $selected));
    $rel = array_map(function ($pair) { return $pair[1]; }, $files);
    sort($rel);
    return $rel;
}

$all = collected($engine, $root . '/plugins', Azure_Backup_Engine::get_plugin_exclusions());
$t->equals(
    array('alpha/alpha.php', 'alpha/includes/helper.php', 'beta/beta.php', 'single-file.php'),
    $all,
    'collect_files walks subdirectories and drops excluded ones'
);

$only_alpha = collected($engine, $root . '/plugins', Azure_Backup_Engine::get_plugin_exclusions(), array('alpha'));
$t->equals(
    array('alpha/alpha.php', 'alpha/includes/helper.php'),
    $only_alpha,
    'a selected subdirectory limits the archive to that plugin'
);

$single = collected($engine, $root . '/plugins', array(), array('single-file'));
$t->equals(
    array('single-file.php'),
    $single,
    'a selected single-file plugin is picked up by its .php filename'
);

$both = collected($engine, $root . '/plugins', Azure_Backup_Engine::get_plugin_exclusions(), array('alpha', 'beta'));
$t->equals(
    array('alpha/alpha.php', 'alpha/includes/helper.php', 'beta/beta.php'),
    $both,
    'multiple selected plugins are combined'
);

$t->equals(array(), collected($engine, $root . '/does-not-exist'), 'a missing source directory yields no files');

// Relative paths must never be absolute or contain the source prefix, or the
// archive unpacks to the wrong place on restore.
foreach ($all as $rel) {
    $t->check($rel !== '' && $rel[0] !== '/' && strpos($rel, $root) === false, "relative path '{$rel}' is archive-relative");
}

// Cleanup.
$rrmdir = function ($dir) use (&$rrmdir) {
    foreach (array_diff(scandir($dir), array('.', '..')) as $f) {
        $p = $dir . '/' . $f;
        is_dir($p) ? $rrmdir($p) : unlink($p);
    }
    rmdir($dir);
};
if (is_dir($root)) {
    $rrmdir($root);
}

exit($t->finish() > 0 ? 1 : 0);
