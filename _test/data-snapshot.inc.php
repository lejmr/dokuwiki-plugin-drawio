<?php

/**
 * Snapshot / diff helper for the wiki's data directory - lets a test assert
 * the *complete* set of files an operation touches, not just the one or two
 * files a hand-written assertion happens to check. (Not DOKU_INC.'data/':
 * see drawio_test_snapshot_data_dir()'s own comment for where a PHPUnit run
 * actually points it.)
 *
 * Plain PHP, no dependencies, one file - matches every other helper this
 * suite already has (e.g. acl.inc.php).
 *
 * Why this exists: the plugin's two live defects the night before this file
 * was written (a stray .tmp left behind by a failed rename, a .drawio source
 * that survived its image's delete) both lived in the same blind spot -
 * every existing test asserts that an *expected* file appeared with the
 * *expected* content, and nothing ever asserted that nothing *else* changed.
 * A leftover temp file, or a file that should have been deleted and wasn't,
 * is invisible to "the right file has the right bytes". Snapshotting the
 * whole data directory before an operation and diffing it after closes that
 * blind spot: anything not on the operation's allow-list fails the test.
 */

/**
 * Which subtrees of data/ this walks, and why.
 *
 * Included - anywhere a bug in this plugin could plausibly leave a footprint,
 * and where an unexpected touch is itself the finding:
 *
 *   media        the diagram images (and their .drawio sources - ordinary
 *                media files under the same tree).
 *   media_attic  previous revisions of media files.
 *   media_meta   the media changelog (.changes) and other per-media metadata.
 *   pages        wiki page text - a diagram operation must NEVER touch this;
 *                walked precisely to prove the negative.
 *   attic        page revisions - same reasoning as pages/.
 *   meta         per-page metadata, including .indexed (the reindex marker
 *                this plugin's own _reindex_diagram_pages()/_reindexConverted()
 *                exist to update - see action.php/admin.php).
 *   index        the fulltext/metadata search index - what the reindex
 *                above actually writes into.
 *   cache        cached rendered pages (CacheRenderer output) - what
 *                helper::purgeDiagramPageCache() exists to purge.
 *
 * Excluded - engine bookkeeping that is not wiki content, is not something
 * this plugin's own writes are supposed to leave a *specific* trace in, and
 * would only add noise a real allow-list would have to grow to swallow:
 *
 *   locks  advisory lock files. draft_save renews one on every autosave (see
 *          action.php's _lock_id()) - its *existence* is an intended side
 *          effect, but the lock file's name and bytes (a username/timestamp
 *          under a hash the plugin does not control the shape of) are core's
 *          own lock() implementation detail, not "what did this operation
 *          write to the wiki". A filter this wide would be exactly the
 *          "too wide" trap the task warns about if it swallowed anything
 *          else - it doesn't: nothing except lock()/checklock() ever writes
 *          under data/locks, so excluding it only ever hides lock files.
 *   tmp    DokuWiki's own documented scratch directory - never wiki content,
 *          and this plugin never writes there (its own temp files, from
 *          _write_file(), live *inside* data/media next to their target,
 *          which the media/ subtree above already covers).
 *   log    diagnostic/deprecation logging, not wiki content. This suite runs
 *          against a deprecated idx_addPage()/idx_lookup() on purpose (see
 *          action.test.php's docblocks), which logs on every single run -
 *          including it would make every delta assertion in this file list
 *          a growing, timestamped deprecation log that has nothing to do
 *          with what the plugin wrote.
 */
const DRAWIO_TEST_DATA_SUBTREES = ['media', 'media_attic', 'media_meta', 'pages', 'attic', 'meta', 'index', 'cache'];

/**
 * Snapshot every file under the included subtrees of DOKU_INC.'data/'.
 *
 * @return array relative path (e.g. 'media/test/plan.png') => ['size' => int, 'hash' => string]
 */
function drawio_test_snapshot_data_dir()
{
    // Not DOKU_INC.'data/' - the test bootstrap (_test/bootstrap.php) points
    // every data_* path at a throwaway copy under sys_get_temp_dir()
    // (DOKU_TMP_DATA) instead, precisely so a real checkout's own data/ is
    // never at risk from a test run. $conf['datadir'] already resolves to
    // "<that tmp dir>/pages" via init_paths(), so its dirname is the data
    // root every other data_* config (mediadir, metadir, ...) shares.
    global $conf;
    $root = rtrim(dirname($conf['datadir']), '/') . '/';
    $out = [];
    foreach (DRAWIO_TEST_DATA_SUBTREES as $sub) {
        $dir = $root . $sub;
        if (!is_dir($dir)) continue;
        $it = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS)
        );
        foreach ($it as $file) {
            /** @var SplFileInfo $file */
            if (!$file->isFile()) continue;
            $rel = $sub . '/' . substr($file->getPathname(), strlen($dir) + 1);
            $out[$rel] = ['size' => $file->getSize(), 'hash' => md5_file($file->getPathname())];
        }
    }
    return $out;
}

/**
 * Compare two snapshots from drawio_test_snapshot_data_dir().
 *
 * @return array ['created' => string[], 'modified' => string[], 'deleted' => string[]], each sorted
 */
function drawio_test_diff_snapshots(array $before, array $after)
{
    $created = [];
    $modified = [];
    $deleted = [];
    foreach ($after as $path => $info) {
        if (!isset($before[$path])) {
            $created[] = $path;
        } elseif ($before[$path] !== $info) {
            $modified[] = $path;
        }
    }
    foreach ($before as $path => $info) {
        if (!isset($after[$path])) $deleted[] = $path;
    }
    sort($created);
    sort($modified);
    sort($deleted);
    return ['created' => $created, 'modified' => $modified, 'deleted' => $deleted];
}
