<?php

/**
 * Whole-data-directory delta tests for saving/drafting a diagram - the
 * save/overwrite/reject/draft half of _test/data-delta.test.php's original
 * scope (see extra/data-delta-deletes.test.php for the delete/bulk-conversion
 * half; split for the ~400 line cap).
 *
 * Every other test file in this suite asserts that an *expected* file
 * appeared with the *expected* content. None of them ever asserted that
 * nothing *else* changed - and that blind spot is exactly where two real
 * defects lived the night before this file was written: a stray .tmp file
 * left behind by a failed rename, and a .drawio source that quietly
 * survived its image's delete. Neither would fail a single existing
 * assertion here, because neither test asserts "and nothing else".
 *
 * This file snapshots DOKU_INC's (test-copy) data directory before an
 * operation and diffs it after (see _test/data-snapshot.inc.php), then
 * checks every changed path against a per-operation allow-list. A file
 * outside the allow-list fails the test, whether it is an unexpected extra
 * write or - just as much a bug - a write in the wrong subtree.
 *
 * @group plugin_drawio
 * @group plugins
 */

require_once __DIR__ . '/../acl.inc.php';
require_once __DIR__ . '/../data-snapshot.inc.php';

class action_plugin_drawio_data_delta_test extends DokuWikiTest
{
    use drawio_acl_test_helper;

    protected $pluginsEnabled = ['drawio'];

    public function setUp(): void
    {
        parent::setUp();
        global $USERINFO;
        $USERINFO = ['grps' => []];
    }

    // --- shared plumbing, deliberately duplicated from action.test.php/
    // admin.test.php rather than shared: every existing test file in this
    // suite already duplicates this same handful of helpers instead of
    // factoring them out (see admin-batching.test.php's own docblock for
    // why - PHPUnit's file-based discovery makes a shared base class more
    // trouble than the ~10 lines it would save here) ---

    protected function ajaxPost(array $post, array $server = [])
    {
        $request = new TestRequest();
        foreach ($server as $key => $value) {
            $request->setServer($key, $value);
        }
        return @$request->post(array_merge(['call' => 'plugin_drawio'], $post), '/lib/exe/ajax.php');
    }

    protected function validTokenFor($user)
    {
        global $INPUT;
        $oldServer = $_SERVER;
        $oldInput = $INPUT;
        $_SERVER['REMOTE_USER'] = $user;
        $INPUT = new \dokuwiki\Input\Input();
        $token = getSecurityToken();
        $_SERVER = $oldServer;
        $INPUT = $oldInput;
        return $token;
    }

    protected function saveViaAjax($mediaId, $content = null, $xml = null, array $server = [])
    {
        if ($content === null) $content = $this->pngBytes();
        $post = [
            'action' => 'save',
            'imageName' => $mediaId,
            'content' => 'data:image/png;base64,' . base64_encode($content),
        ];
        if ($xml !== null) $post['xml'] = $xml;
        if ($server) $post['sectok'] = $this->validTokenFor(reset($server));
        return $this->ajaxPost($post, $server);
    }

    protected function draftJson($xml = '<mxfile><diagram>x</diagram></mxfile>')
    {
        return json_encode(['lastModified' => '2026-01-01T00:00:00.000Z', 'xml' => $xml]);
    }

    protected function draftSaveViaAjax($imageName, $content)
    {
        return $this->ajaxPost(['action' => 'draft_save', 'imageName' => $imageName, 'content' => $content]);
    }

    protected function pngBytes()
    {
        return base64_decode(
            'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk'
            . 'YPhfDwAChwGA60e6kgAAAABJRU5ErkJggg=='
        );
    }

    /** A second, distinct PNG (still a real signature, different bytes) - for an overwrite that must be observable as a real change. */
    protected function otherPngBytes()
    {
        return $this->pngBytes() . str_repeat('Z', 20);
    }

    protected function diagramWordXml($word)
    {
        return '<mxfile><diagram><mxGraphModel><root><mxCell value="' . $word . '" vertex="1"/></root></mxGraphModel></diagram></mxfile>';
    }

    // --- the delta assertion itself ---

    /**
     * Assert that every path the operation between $before and $after
     * touched is on the given allow-list for its kind (created/modified/
     * deleted). An allow-list entry is matched with fnmatch(), so '*' can
     * stand in for a core-owned, unpredictable filename (see this file's
     * own docblock for which paths get that treatment and why).
     *
     * Deliberately does NOT require every allow-listed pattern to actually
     * appear - each test already asserts the headline files' presence and
     * content directly (assertSame/assertFileExists, the same way every
     * other file in this suite does); this only ever asserts the negative
     * space: nothing outside the list.
     */
    protected function assertDelta(array $before, array $after, array $allowedCreated, array $allowedModified = [], array $allowedDeleted = [], $context = '')
    {
        $diff = drawio_test_diff_snapshots($before, $after);
        $this->assertAllowed($diff['created'], $allowedCreated, 'created', $context);
        $this->assertAllowed($diff['modified'], $allowedModified, 'modified', $context);
        $this->assertAllowed($diff['deleted'], $allowedDeleted, 'deleted', $context);
    }

    private function assertAllowed(array $paths, array $allowed, $kind, $context)
    {
        foreach ($paths as $path) {
            $matched = false;
            foreach ($allowed as $pattern) {
                if (fnmatch($pattern, $path)) {
                    $matched = true;
                    break;
                }
            }
            $this->assertTrue($matched, ($context !== '' ? "$context: " : '') . "unexpected $kind path outside the allow-list: $path");
        }
    }

    // --- save a brand new diagram ---

    /**
     * The complete footprint of a first save with no embedding page yet:
     * the image, its source, that media's own changelog entry, and core's
     * site-wide media changelog. Nothing else - specifically no .tmp file
     * (see action.php's _write_file()'s docblock for the defect that left
     * one behind on a failed rename) and no touch to data/pages, data/attic,
     * data/index, data/meta or data/cache, since nothing here embeds this
     * diagram yet.
     */
    public function testSavingANewDiagramWritesExactlyImageSourceAndChangelogs()
    {
        $before = drawio_test_snapshot_data_dir();
        $this->saveViaAjax('delta:new.png', $this->pngBytes(), $this->diagramWordXml('freshdiagram'));
        $after = drawio_test_snapshot_data_dir();

        $this->assertSame($this->pngBytes(), file_get_contents(mediaFN('delta:new.png')));
        $this->assertSame($this->diagramWordXml('freshdiagram'), file_get_contents(mediaFN('delta:new.drawio')));

        $this->assertDelta(
            $before,
            $after,
            [
                'media/delta/new.png',
                'media/delta/new.drawio',
                'media_meta/delta/new.png.changes',
                // meta/_media.changes (core's site-wide media changelog) is
                // CREATED the first time anything in this class run writes
                // media, MODIFIED on every write after that - which bucket
                // depends on what ran before this test, since DokuWikiTest
                // only wipes the data dir once per class, not per method
                // (see admin.test.php's own docblock for the same fact
                // biting a different test). --order-by=random surfaced
                // exactly this: allow it in both buckets rather than pin
                // an order this suite must not depend on.
                'meta/_media.changes',
            ],
            ['meta/_media.changes']
        );
    }

    /**
     * The other half of "save a new diagram": one that already has a
     * reader, in the form of a page that embeds it and has been indexed at
     * least once (the real-world order - see action.test.php's
     * testSavingADiagramMakesTheEmbeddingPageFindableBySearch()). Saving
     * must reindex that page and expire its non-xhtml cached renders (see
     * action.php's _reindex_diagram_pages()) - core names the index shard
     * files and the page's metadata blob itself, so those are allowed by
     * directory rather than by exact name (see this file's own docblock).
     * The purged cache entries are pinned to this one page's own cache
     * prefix (computed the same way helper::purgeDiagramPageCache() does),
     * not to "anything under data/cache" - so this still catches a purge
     * that reaches too far, just not the exact extension purged, which
     * differs across DokuWiki versions this file must pass on (oldstable
     * also caches a '.i' entry for a rendered page that stable/master do
     * not).
     */
    public function testSavingANewDiagramWithAnEmbeddingPageAlsoReindexesAndPurgesItsCache()
    {
        saveWikiText('deltapage', '{{drawio>delta:withpage.png}}', 'test init');
        plugin_load('helper', 'drawio')->reindexPage('deltapage');

        $file = wikiFN('deltapage');
        $odtCache = new \dokuwiki\Cache\CacheRenderer('deltapage', $file, 'odt');
        $odtCache->storeCache('stale-odt-bytes-from-before-the-save');
        $xhtmlCache = new \dokuwiki\Cache\CacheRenderer('deltapage', $file, 'xhtml');
        $xhtmlCache->storeCache('<p>stale-xhtml-does-not-matter</p>');

        $before = drawio_test_snapshot_data_dir();
        $this->saveViaAjax('delta:withpage.png', $this->pngBytes(), $this->diagramWordXml('embeddedword'));
        $after = drawio_test_snapshot_data_dir();

        // Same data root drawio_test_snapshot_data_dir() itself resolves
        // (see data-snapshot.inc.php) - relative-ize the cache file's real
        // path against it rather than guessing cachedir's own layout.
        global $conf;
        $dataRoot = rtrim(dirname($conf['datadir']), '/') . '/';
        $odtCacheRel = substr($odtCache->cache, strlen($dataRoot));
        $cachePrefixRel = substr($odtCache->cache, strlen($dataRoot), -strlen('.odt'));
        // The metadata cache is keyed differently from every other renderer
        // cache (CacheRenderer::getEnvironmentKey() adds DOKU_BASE for all
        // modes but 'metadata'), so its prefix is not $cachePrefixRel.
        // Indexer::addPage() has p_get_metadata() write time() into it -
        // which only shows up as a change when a second boundary falls
        // between the snapshot and the save, hence a flaky CI failure
        // before this was pinned by its own name.
        $metaCache = new \dokuwiki\Cache\CacheRenderer('deltapage', $file, 'metadata');
        $metaCacheRel = substr($metaCache->cache, strlen($dataRoot));

        $this->assertDelta(
            $before,
            $after,
            // created
            [
                'media/delta/withpage.png',
                'media/delta/withpage.drawio',
                'media_meta/delta/withpage.png.changes',
                'meta/_media.changes', // see testSavingANewDiagramWritesExactlyImageSourceAndChangelogs()'s comment on this same path
                'index/*',
                $metaCacheRel,
            ],
            // modified
            [
                'meta/_media.changes',
                'meta/deltapage.meta',
                'index/*',
                // Indexer::addPage() (helper::reindexPage()) has this page's
                // metadata re-rendered, which touches its own metadata cache
                // entry - pinned to that one page's own cache name, not "any
                // cache file", so a purge that reaches too far still fails.
                $metaCacheRel,
            ],
            // deleted - only this page's own cached, non-xhtml renders
            // (odt here; some DokuWiki versions cache more than one format
            // per page - see this test's own docblock). The xhtml cache
            // must survive (it only ever holds a fetch.php URL, never the
            // diagram's bytes - see purgeDiagramPageCache()'s docblock),
            // which the assertion right below this one checks directly.
            [$cachePrefixRel . '.*']
        );

        $this->assertFileDoesNotExist($odtCache->cache, 'the stale odt render must be purged');
        $this->assertFileExists($xhtmlCache->cache, 'the xhtml cache must survive untouched');
    }

    // --- save over an existing diagram ---

    /**
     * Overwriting adds exactly one thing on top of a fresh save: the attic
     * copy of the image that was just replaced. Same allow-list as a new
     * save, plus one created media_attic entry.
     */
    public function testSavingOverAnExistingDiagramAddsExactlyOneAtticCopy()
    {
        $this->saveViaAjax('delta:over.png', $this->pngBytes(), $this->diagramWordXml('first'));

        $before = drawio_test_snapshot_data_dir();
        $this->saveViaAjax('delta:over.png', $this->otherPngBytes(), $this->diagramWordXml('second'));
        $after = drawio_test_snapshot_data_dir();

        $this->assertSame($this->otherPngBytes(), file_get_contents(mediaFN('delta:over.png')));

        $this->assertDelta(
            $before,
            $after,
            ['media_attic/delta/over.*.png', 'meta/_media.changes'], // created - the one archived revision, timestamp-named (plus meta/_media.changes, see the note above)
            [
                'media/delta/over.png',
                'media/delta/over.drawio',
                'media_meta/delta/over.png.changes',
                'meta/_media.changes',
            ]
        );
    }

    /**
     * mediarevisions off is a supported configuration (conf/metadata.php
     * imposes no dependency on it), and action.php explicitly mirrors
     * core's own $auth_ow formula for it (see the 'save' handler's
     * comment) - so it needs its own test, not just an assumption that
     * "overwrite" behaves the same with revisions off.
     *
     * With ACL disabled, auth_aclcheck() always answers AUTH_UPLOAD (core's
     * own auth_aclcheck_cb() - "if no ACL is used always return upload
     * rights") - never AUTH_DELETE, which $auth_ow becomes when revisions
     * are off. That is not a plugin bug: core's own media_save() has the
     * identical formula (inc/media.php line ~483) and would refuse the
     * exact same overwrite the exact same way. So this needs a user who
     * genuinely holds AUTH_DELETE, via ACL - the same way a real wiki with
     * both ACL and mediarevisions-off enabled would have to be configured
     * for anyone but an admin to overwrite media at all.
     */
    public function testSavingOverAnExistingDiagramWithMediarevisionsOffOverwritesWithNoAtticCopy()
    {
        global $conf;
        $conf['mediarevisions'] = 0;
        $this->enableAcl(['delta:* @ALL 16'], 'root', ['admin']);

        $this->saveViaAjax('delta:norev.png', $this->pngBytes(), $this->diagramWordXml('first'), ['REMOTE_USER' => 'root']);

        $before = drawio_test_snapshot_data_dir();
        $this->saveViaAjax('delta:norev.png', $this->otherPngBytes(), $this->diagramWordXml('second'), ['REMOTE_USER' => 'root']);
        $after = drawio_test_snapshot_data_dir();

        $this->assertSame($this->otherPngBytes(), file_get_contents(mediaFN('delta:norev.png')));

        $this->assertDelta(
            $before,
            $after,
            ['meta/_media.changes'], // created - no attic copy without mediarevisions; see the note above for why meta/_media.changes is allowed here too
            [
                'media/delta/norev.png',
                'media/delta/norev.drawio',
                'media_meta/delta/norev.png.changes',
                'meta/_media.changes',
            ]
        );
    }

    // --- a save that must be rejected writes nothing at all ---

    /**
     * @dataProvider rejectedSaveProvider
     */
    public function testARejectedSaveWritesNothingAtAll($label, $mediaId, $content, $server, $overrideSectok)
    {
        $before = drawio_test_snapshot_data_dir();

        $post = ['action' => 'save', 'imageName' => $mediaId, 'content' => $content];
        if ($overrideSectok !== null) $post['sectok'] = $overrideSectok;
        elseif ($server) $post['sectok'] = $this->validTokenFor(reset($server));
        $this->ajaxPost($post, $server);

        $after = drawio_test_snapshot_data_dir();

        $this->assertDelta($before, $after, [], [], [], $label);
        $this->assertFileDoesNotExist(mediaFN($mediaId), $label);
    }

    public function rejectedSaveProvider()
    {
        return [
            'bad extension' => ['bad extension', 'delta:pwn.php', 'data:image/png;base64,' . base64_encode('whatever'), [], null],
            'malformed payload (no data: URL)' => ['malformed payload', 'delta:garbage.png', 'undefined', [], null],
            'malformed payload (bad base64 after the prefix)' => ['malformed base64', 'delta:garbage2.png', 'data:image/png;base64,***not-base64***', [], null],
            'missing token' => ['missing token', 'delta:notoken.png', 'data:image/png;base64,' . base64_encode('whatever'), ['REMOTE_USER' => 'someone'], 'not-the-real-token'],
        ];
    }

    // --- draft save / get / remove ---

    /**
     * A draft touches exactly the draft file, at every step of its
     * lifecycle - and never the id a real save would use (script.js relies
     * on that: an autosaved draft must never be mistaken for the diagram
     * itself by anything that reads media normally).
     */
    public function testDraftSaveGetAndRemoveTouchOnlyTheDraftFile()
    {
        $draftId = 'delta:draft.png';
        $draft = $this->draftJson($this->diagramWordXml('draftword'));

        $before = drawio_test_snapshot_data_dir();
        $this->draftSaveViaAjax($draftId, $draft);
        $afterSave = drawio_test_snapshot_data_dir();
        $this->assertDelta($before, $afterSave, ['media/delta/draft.png.draft'], [], [], 'draft_save');
        $this->assertFileDoesNotExist(mediaFN($draftId), 'a draft must never land where the diagram itself would');
        $this->assertFileDoesNotExist(mediaFN('delta:draft.drawio'), 'a draft must never create a source either');

        $this->ajaxPost(['action' => 'draft_get', 'imageName' => $draftId]);
        $afterGet = drawio_test_snapshot_data_dir();
        $this->assertDelta($afterSave, $afterGet, [], [], [], 'draft_get');

        $this->ajaxPost(['action' => 'draft_rm', 'imageName' => $draftId]);
        $afterRm = drawio_test_snapshot_data_dir();
        $this->assertDelta($afterGet, $afterRm, [], [], ['media/delta/draft.png.draft'], 'draft_rm');
    }
}
