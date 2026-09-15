<?php

/**
 * Whole-data-directory delta tests for deleting a diagram and for the admin
 * bulk-conversion task - the delete/bulk-conversion half of
 * _test/data-delta.test.php's original scope (see extra/data-delta.test.php
 * for the save/draft half; split for the ~400 line cap). The PNG round-trip
 * case (testDeletingTheLastRenderingRoundTripsThroughARealDrawioPngExport)
 * moved to golden/lifecycle.test.php as the happy-path representative of
 * this whole "delete folds the source into the attic copy" feature.
 *
 * @group plugin_drawio
 * @group plugins
 */

require_once __DIR__ . '/../acl.inc.php';
require_once __DIR__ . '/../data-snapshot.inc.php';

class action_plugin_drawio_data_delta_deletes_test extends DokuWikiTest
{
    use drawio_acl_test_helper;

    protected $pluginsEnabled = ['drawio'];

    public function setUp(): void
    {
        parent::setUp();
        global $USERINFO;
        $USERINFO = ['grps' => []];
    }

    // --- shared plumbing, deliberately duplicated rather than shared - see
    // extra/data-delta.test.php's own comment on this ---

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

    protected function pngBytes()
    {
        return base64_decode(
            'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk'
            . 'YPhfDwAChwGA60e6kgAAAABJRU5ErkJggg=='
        );
    }

    protected function diagramWordXml($word)
    {
        return '<mxfile><diagram><mxGraphModel><root><mxCell value="' . $word . '" vertex="1"/></root></mxGraphModel></diagram></mxfile>';
    }

    protected function put($id, $content)
    {
        $file = mediaFN($id);
        io_makeFileDir($file);
        file_put_contents($file, $content);
        return $file;
    }

    // --- the delta assertion itself - see extra/data-delta.test.php's own docblock ---

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

    // --- delete a diagram ---

    /**
     * Deleting one rendering while the other still exists must not cascade
     * to the shared source - see action.php's _media_delete_sibling()'s own
     * docblock. This also doubles as the "two diagrams share a namespace,
     * only one is touched" case the delete direction needs: a second,
     * unrelated diagram (delta:sibling.png) lives in the very same
     * namespace and must not appear anywhere in the delta.
     */
    public function testDeletingOneRenderingLeavesTheSharedSourceAndTheSiblingDiagramAlone()
    {
        $this->enableAcl(['delta:* @ALL 16'], 'root', ['admin']);
        $this->saveViaAjax('delta:pair.png', $this->pngBytes(), $this->diagramWordXml('pairword'), ['REMOTE_USER' => 'root']);
        $this->ajaxPost([
            'action' => 'save',
            'imageName' => 'delta:pair.svg',
            'content' => 'data:image/svg+xml;base64,' . base64_encode('<svg xmlns="http://www.w3.org/2000/svg"/>'),
            'xml' => $this->diagramWordXml('pairword'),
            'sectok' => $this->validTokenFor('root'),
        ], ['REMOTE_USER' => 'root']);
        // an entirely unrelated diagram, same namespace
        $this->saveViaAjax('delta:sibling.png', $this->pngBytes(), $this->diagramWordXml('siblingword'), ['REMOTE_USER' => 'root']);

        $before = drawio_test_snapshot_data_dir();
        media_delete('delta:pair.png', AUTH_DELETE);
        $after = drawio_test_snapshot_data_dir();

        $this->assertFileDoesNotExist(mediaFN('delta:pair.png'));
        $this->assertFileExists(mediaFN('delta:pair.svg'), 'the other rendering must survive');
        $this->assertFileExists(mediaFN('delta:pair.drawio'), 'the shared source must survive while the .svg still needs it');
        $this->assertFileExists(mediaFN('delta:sibling.png'), 'an unrelated diagram in the same namespace must never be touched');

        $this->assertDelta(
            $before,
            $after,
            ['media_attic/delta/pair.*.png', 'meta/_media.changes'], // created
            ['meta/_media.changes', 'media_meta/delta/pair.png.changes'], // modified - core's own delete-changelog entry
            ['media/delta/pair.png']          // deleted
        );
    }

    /**
     * FIXED (was a skipped FINDING - see git history for the original
     * failing repro): deleting a diagram's *last* rendering used to cascade
     * into archiving its .drawio source separately, which corrupted the
     * attic filename - mediaFN()'s mimetype()-based naming has no case for
     * an extension core's mime.conf does not know, which '.drawio' never
     * is, so the archive landed under a truncated, extension-less name
     * DokuWiki's own revision UI could never see.
     *
     * The maintainer's decision (see action.php's _media_delete_sibling()/
     * _embed_source_into_attic() for the full reasoning): don't archive the
     * .drawio at all. Fold its XML into the attic copy of the image core
     * already archives correctly - a .png/.svg attic name was never the
     * defective part - so the one revision left behind is self-contained,
     * exactly the way every diagram was before this plugin gained a
     * separate source file.
     */
    public function testDeletingTheLastRenderingArchivesTheSourceEmbeddedInTheImage()
    {
        $this->enableAcl(['delta:* @ALL 16'], 'root', ['admin']);
        $xml = $this->diagramWordXml('findingword');
        $this->saveViaAjax('delta:finding.png', $this->pngBytes(), $xml, ['REMOTE_USER' => 'root']);

        $before = drawio_test_snapshot_data_dir();
        media_delete('delta:finding.png', AUTH_DELETE);
        $after = drawio_test_snapshot_data_dir();

        // What a clean delete looks like now: the image gone but archived
        // (with the source folded into it), the source gone and NOT
        // archived separately, nothing malformed left behind.
        $this->assertDelta(
            $before,
            $after,
            ['media_attic/delta/finding.*.png', 'meta/_media.changes'],
            ['meta/_media.changes', 'media_meta/delta/finding.png.changes'],
            ['media/delta/finding.png', 'media/delta/finding.drawio']
        );

        $atticFiles = glob(dirname(mediaFN('delta:finding.png', 1)) . '/finding.*.png');
        $this->assertCount(1, $atticFiles, 'exactly one attic entry, for the image - none for the source');

        $helper = plugin_load('helper', 'drawio');
        $this->assertSame(
            $xml,
            $helper->extractPngXml(file_get_contents($atticFiles[0])),
            'the archived revision must carry the deleted source, embedded, byte for byte'
        );
    }

    /**
     * Same round trip as golden/lifecycle.test.php's PNG case, for the SVG
     * rendering - embedSvgXml() writes a content= attribute on the root
     * <svg>, matching this branch's own real-drawio-export.svg fixture's
     * shape (see that method's own docblock), so this is the SVG half of
     * the decision that PNG isn't the only rendering that needs to survive
     * a delete self-contained.
     */
    public function testDeletingTheLastRenderingRoundTripsThroughARealDrawioSvgExport()
    {
        $this->enableAcl(['delta:* @ALL 16'], 'root', ['admin']);
        $realSvg = file_get_contents(__DIR__ . '/../real-drawio-export.svg');
        $xml = $this->diagramWordXml('svgroundtripword');
        $this->ajaxPost([
            'action' => 'save',
            'imageName' => 'delta:svground.svg',
            'content' => 'data:image/svg+xml;base64,' . base64_encode($realSvg),
            'xml' => $xml,
            'sectok' => $this->validTokenFor('root'),
        ], ['REMOTE_USER' => 'root']);

        media_delete('delta:svground.svg', AUTH_DELETE);

        $atticFiles = glob(dirname(mediaFN('delta:svground.svg', 1)) . '/svground.*.svg');
        $this->assertCount(1, $atticFiles);

        $helper = plugin_load('helper', 'drawio');
        $extracted = $helper->extractSvgXml(file_get_contents($atticFiles[0]));
        $this->assertSame($xml, $extracted, 'round trip through the plugin\'s own extractor must return exactly the deleted source');
    }

    /**
     * An old diagram that was never re-saved through this plugin has no
     * separate .drawio at all - its image already carries whatever XML it
     * was exported with, and nothing in the new embed-on-delete path has
     * anything to do once _media_delete_sibling() finds no source file.
     * Deleting it is untouched by this whole feature: a plain core delete,
     * one attic copy of the image, nothing folded in because there was
     * nothing beside it to fold.
     */
    /**
     * Found on the maintainer's own wiki: deleting the .drawio directly in
     * the media manager made core archive it as "name.drawi.<rev>." (no
     * mimetype for .drawio, so mediaFN() has no extension to split on) -
     * an attic entry nothing can list or restore. The image stays, its
     * own attic keeps the XML; the broken file must not appear.
     */
    public function testDeletingTheSourceDirectlyLeavesNoBrokenAtticEntry()
    {
        $this->enableAcl(['delta:* @ALL 16'], 'root', ['admin']);
        $this->saveViaAjax('delta:direct.png', $this->pngBytes(), $this->diagramWordXml('directword'), ['REMOTE_USER' => 'root']);
        $this->assertFileExists(mediaFN('delta:direct.drawio'));

        $before = drawio_test_snapshot_data_dir();
        media_delete('delta:direct.drawio', AUTH_DELETE);
        $after = drawio_test_snapshot_data_dir();

        $this->assertFileDoesNotExist(mediaFN('delta:direct.drawio'));
        $this->assertFileExists(mediaFN('delta:direct.png'));
        $this->assertSame([], glob(dirname(mediaFN('delta:direct.png', 1)) . '/direct.drawi.*'), 'core\'s mimetype-less attic name must be cleaned up');
        $this->assertDelta(
            $before,
            $after,
            ['media_meta/delta/direct.drawio.changes'],
            ['meta/_media.changes'],
            ['media/delta/direct.drawio']
        );
    }

    public function testDeletingADiagramWithNoSeparateSourceArchivesOnlyTheImage()
    {
        $this->enableAcl(['delta:* @ALL 16'], 'root', ['admin']);
        // Written directly (bypassing this plugin's save action entirely) -
        // exactly what a diagram saved before this plugin existed, and
        // never opened through it since, looks like on disk.
        $this->put('delta:nosource.png', file_get_contents(__DIR__ . '/../real-drawio-export.png'));

        $before = drawio_test_snapshot_data_dir();
        media_delete('delta:nosource.png', AUTH_DELETE);
        $after = drawio_test_snapshot_data_dir();

        $this->assertFileDoesNotExist(mediaFN('delta:nosource.drawio'));
        $this->assertDelta(
            $before,
            $after,
            ['media_attic/delta/nosource.*.png', 'media_meta/delta/nosource.png.changes', 'meta/_media.changes'],
            ['meta/_media.changes'],
            ['media/delta/nosource.png']
        );
    }

    /**
     * mediarevisions off: core never archives the deleted image at all (see
     * media_saveOldRevision()'s own early return), so there is no attic
     * copy of it for _embed_source_into_attic() to fold the source into -
     * and, per the maintainer's decision, no separate archive is
     * manufactured for the source either. The source is simply deleted,
     * unarchived, exactly as its image is - "no attic copy" stays true for
     * both halves of one diagram, not just the image half.
     */
    public function testDeletingTheLastRenderingWithMediarevisionsOffArchivesNothing()
    {
        global $conf;
        $this->enableAcl(['delta:* @ALL 16'], 'root', ['admin']);
        $this->saveViaAjax('delta:norevfinding.png', $this->pngBytes(), $this->diagramWordXml('norevfindingword'), ['REMOTE_USER' => 'root']);

        $conf['mediarevisions'] = 0;
        $before = drawio_test_snapshot_data_dir();
        media_delete('delta:norevfinding.png', AUTH_DELETE);
        $after = drawio_test_snapshot_data_dir();

        $this->assertDelta(
            $before,
            $after,
            [],
            ['meta/_media.changes', 'media_meta/delta/norevfinding.png.changes'],
            ['media/delta/norevfinding.png', 'media/delta/norevfinding.drawio']
        );
    }

    // --- bulk conversion (admin.php) ---

    /**
     * The admin task's own delta, end to end: a diagram whose bytes carry
     * recoverable XML gets exactly one new file (its .drawio, written
     * nowhere else); a diagram with no recoverable XML gets nothing at all;
     * and neither touches the other's image - the namespace-sharing case
     * this bullet asks for, right alongside "what can/can't be recovered".
     */
    public function testBulkConversionWritesSourcesOnlyForWhatCanBeRecoveredAndScopesToEachDiagram()
    {
        $recoverableBytes = file_get_contents(__DIR__ . '/../real-drawio-export.png');
        $this->put('deltabulk:recoverable.png', $recoverableBytes);
        $this->put('deltabulk:unrecoverable.png', $this->pngBytes()); // no embedded xmlpng chunk

        $before = drawio_test_snapshot_data_dir();

        $admin = new admin_plugin_drawio();
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_POST = ['convert' => '1', 'sectok' => getSecurityToken()];
        $_GET = [];
        $_REQUEST = $_POST;
        global $INPUT;
        $INPUT = new \dokuwiki\Input\Input();
        $admin->handle();
        ob_start();
        $admin->html();
        ob_end_clean();

        $after = drawio_test_snapshot_data_dir();

        $this->assertFileExists(mediaFN('deltabulk:recoverable.drawio'));
        $this->assertFileDoesNotExist(mediaFN('deltabulk:unrecoverable.drawio'));
        // the images themselves are never touched by this task
        $this->assertSame($recoverableBytes, file_get_contents(mediaFN('deltabulk:recoverable.png')));
        $this->assertSame($this->pngBytes(), file_get_contents(mediaFN('deltabulk:unrecoverable.png')));

        $this->assertDelta(
            $before,
            $after,
            ['media/deltabulk/recoverable.drawio'] // created - nothing for unrecoverable.png, no attic, no changelog (see admin.php's _process())
        );
    }
}
