<?php

/**
 * Golden-path lifecycle tests for the drawio plugin: draft autosave, lock
 * contention, choosing which rendering to open a diagram from, moving a
 * diagram, deleting the last rendering, and bulk-converting an old diagram
 * into one with a source - each through its real entry point (ajax.php,
 * admin.php, or the same core events op.php fires), with realistic data.
 *
 * Everything else (mediarevisions variants, lock edge cases, delete/move
 * cascades, indexer edge cases, admin negatives) lives under _test/extra/.
 *
 * @group plugin_drawio
 * @group plugins
 */
require_once __DIR__ . '/../acl.inc.php';

class action_plugin_drawio_golden_lifecycle_test extends DokuWikiTest
{
    use drawio_acl_test_helper;

    protected $pluginsEnabled = ['drawio'];

    /** @var array captured MEDIA_UPLOAD_FINISH event data, one entry per fired event */
    protected $firedEvents = [];

    public function setUp(): void
    {
        parent::setUp();
        $this->firedEvents = [];

        global $USERINFO;
        $USERINFO = ['grps' => []];

        if (!isset($_SESSION)) {
            $_SESSION = [];
        }

        global $EVENT_HANDLER;
        $EVENT_HANDLER->register_hook('MEDIA_UPLOAD_FINISH', 'AFTER', $this, 'captureMediaUploadFinish');
    }

    public function captureMediaUploadFinish(Doku_Event $event, $param)
    {
        $this->firedEvents[] = $event->data;
    }

    /**
     * DokuWikiTest only wipes the media tree once per *class*, not per test
     * method, and a real convert POST sweeps up every still-unconverted
     * diagram any earlier test in this class left behind - so a test
     * asserting "the event fired for *my* diagram" has to filter by id
     * rather than assume it was the only thing in the batch.
     */
    protected function eventsFor($mediaId)
    {
        return array_values(array_filter($this->firedEvents, function ($e) use ($mediaId) {
            return $e[2] === $mediaId;
        }));
    }

    protected function ajaxPost(array $post, array $server = [])
    {
        $request = new TestRequest();
        foreach ($server as $key => $value) {
            $request->setServer($key, $value);
        }
        return @$request->post(array_merge(['call' => 'plugin_drawio'], $post), '/lib/exe/ajax.php');
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

    protected function draftJson($xml = '<mxfile><diagram>x</diagram></mxfile>')
    {
        return json_encode(['lastModified' => '2026-01-01T00:00:00.000Z', 'xml' => $xml]);
    }

    protected function draftSaveViaAjax($imageName, $content)
    {
        return $this->ajaxPost(['action' => 'draft_save', 'imageName' => $imageName, 'content' => $content]);
    }

    /** A real, minimal (1x1, transparent) PNG - the smallest thing that is actually a PNG. */
    protected function pngBytes()
    {
        return base64_decode(
            'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk'
            . 'YPhfDwAChwGA60e6kgAAAABJRU5ErkJggg=='
        );
    }

    /** A minimal SVG document of the shape draw.io exports. */
    protected function svgBytes($body = '<rect width="1" height="1"/>')
    {
        return '<?xml version="1.0" encoding="UTF-8"?>'
            . '<svg xmlns="http://www.w3.org/2000/svg" width="1" height="1">' . $body . '</svg>';
    }

    /** The XML the editor hands to script.js on its 'save' event. */
    protected function diagramXml($marker = 'x')
    {
        return '<mxfile host="embed"><diagram id="' . $marker . '">' . $marker . '</diagram></mxfile>';
    }

    protected function diagramWordXml($word)
    {
        return '<mxfile><diagram><mxGraphModel><root><mxCell value="' . $word . '" vertex="1"/></root></mxGraphModel></diagram></mxfile>';
    }

    protected function put($mediaId, $content = 'bytes')
    {
        $file = mediaFN($mediaId);
        io_makeFileDir($file);
        file_put_contents($file, $content);
        return $file;
    }

    /**
     * The same id action.php's _lock_id() derives, computed independently
     * here so a test can point wikiLockFN() at the right file.
     */
    protected function lockFileFor($mediaId)
    {
        $ext = strtolower(pathinfo($mediaId, PATHINFO_EXTENSION));
        $srcId = in_array($ext, ['png', 'svg'], true)
            ? substr($mediaId, 0, -strlen($ext)) . 'drawio'
            : $mediaId;
        return wikiLockFN('drawio:' . $srcId);
    }

    protected function lockRequest($mediaId, $user)
    {
        return $this->ajaxPost(
            [
                'action' => 'lock',
                'imageName' => $mediaId,
                'sectok' => $this->validTokenFor($user),
            ],
            ['REMOTE_USER' => $user]
        );
    }

    /** Drive the move plugin's own event shape (helper/op.php's moveMedia()). */
    protected function fireMoveMedia($srcId, $dstId)
    {
        $data = [
            'opts' => ['ns' => getNS($srcId), 'name' => noNS($srcId), 'newns' => getNS($dstId), 'newname' => noNS($dstId)],
            'affected_pages' => [],
            'src_id' => $srcId,
            'dst_id' => $dstId,
        ];
        \dokuwiki\Extension\Event::createAndTrigger('PLUGIN_MOVE_MEDIA_RENAME', $data, null, false);
    }

    protected function resolveSourceViaAjax($mediaId, $server = [])
    {
        $post = ['action' => 'resolve_source', 'imageName' => $mediaId];
        if ($server) $post['sectok'] = $this->validTokenFor(reset($server));
        return $this->ajaxPost($post, $server);
    }

    protected function simulateRequest($method, array $params = [])
    {
        global $INPUT;
        $_SERVER['REQUEST_METHOD'] = $method;
        if ($method === 'POST') {
            $_POST = $params;
            $_GET = [];
        } else {
            $_GET = $params;
            $_POST = [];
        }
        $_REQUEST = $params;
        $INPUT = new \dokuwiki\Input\Input();
    }

    protected function runAdmin()
    {
        $admin = new admin_plugin_drawio();
        $admin->handle();
        ob_start();
        $admin->html();
        return ob_get_clean();
    }

    /**
     * The round trip the editor depends on must keep working: autosave posts
     * the envelope, reopening the diagram gets exactly it back.
     */
    public function testDraftRoundTripStillWorks()
    {
        $draft = $this->draftJson();
        $this->draftSaveViaAjax('test:round.png', $draft);
        $this->assertSame($draft, file_get_contents(mediaFN('test:round.png.draft')));

        $response = $this->ajaxPost(['action' => 'draft_get', 'imageName' => 'test:round.png']);
        $this->assertSame($draft, $response->getContent());
    }

    /**
     * A lock is informational, never a gate: contesting an existing lock
     * must name who holds it, and must still take it anyway. Merges the old
     * testDraftSaveRenewsAnExistingLock: the same draft_save autosave path
     * that takes/reports a lock must also renew one it already holds -
     * alongside, not instead of, script.js's own renewal interval, which is
     * what actually covers "opened but not yet edited for a long time".
     */
    public function testLockNamesTheExistingHolderAndTakesItAnyway()
    {
        $this->lockRequest('test:contested.png', 'alice');
        $response = $this->lockRequest('test:contested.png', 'bob');

        $data = json_decode($response->getContent(), true);
        $this->assertSame('alice', $data['locked_by'], 'the warning must name who holds it');
        $this->assertIsInt($data['since'], 'and say how long ago, in seconds since epoch');
        $this->assertLessThanOrEqual(time(), $data['since']);

        // "Whatever the user answers, the editor opens": bob's request must
        // still have taken the lock.
        $lockFile = $this->lockFileFor('test:contested.png');
        $this->assertFileExists($lockFile);

        // back-date the lock as if it had been sitting untouched for a while
        touch($lockFile, time() - 600);
        clearstatcache();
        $agedMtime = filemtime($lockFile);
        $this->assertLessThanOrEqual(time() - 590, $agedMtime, 'sanity: touch() must have backdated the lock file');

        $this->ajaxPost(
            [
                'action' => 'draft_save',
                'imageName' => 'test:contested.png',
                'content' => $this->draftJson(),
                'sectok' => $this->validTokenFor('bob'),
            ],
            ['REMOTE_USER' => 'bob']
        );

        clearstatcache();
        $this->assertGreaterThan($agedMtime, filemtime($lockFile), 'autosave must refresh the lock, not just the draft');
    }

    /**
     * Both renderings exist: png wins, deterministically - not "whichever
     * happens to be newer" or any other tiebreak, so opening the same source
     * twice in a row always lands on the same rendering.
     */
    public function testResolveSourcePrefersPngWhenBothRenderingsExist()
    {
        $this->put('test:both.png', $this->pngBytes());
        $this->put('test:both.svg', $this->svgBytes());
        $this->put('test:both.drawio', $this->diagramXml());

        $data = json_decode($this->resolveSourceViaAjax('test:both.drawio')->getContent(), true);

        $this->assertSame('test:both.png', $data['id']);
        $this->assertTrue($data['granted']);
    }

    /**
     * The same delete, using a genuine draw.io export (real-drawio-export.png)
     * rather than a hand-built PNG - a hand-built one proves nothing about
     * whether embedPngXml() matches draw.io's own chunk layout closely
     * enough for the plugin's own extractor (and, by the same shape, the
     * real editor) to read the result back. Round trip: save with a real
     * image and a chosen source, delete, extract the archived revision's
     * embedded XML, and assert it is exactly what was deleted.
     */
    public function testDeletingTheLastRenderingRoundTripsThroughARealDrawioPngExport()
    {
        $this->enableAcl(['delta:* @ALL 16'], 'root', ['admin']);
        $realPng = file_get_contents(__DIR__ . '/../real-drawio-export.png');
        $xml = $this->diagramWordXml('roundtripword');
        $this->saveViaAjax('delta:roundtrip.png', $realPng, $xml, ['REMOTE_USER' => 'root']);

        media_delete('delta:roundtrip.png', AUTH_DELETE);

        $atticFiles = glob(dirname(mediaFN('delta:roundtrip.png', 1)) . '/roundtrip.*.png');
        $this->assertCount(1, $atticFiles);

        $helper = plugin_load('helper', 'drawio');
        $extracted = $helper->extractPngXml(file_get_contents($atticFiles[0]));
        $this->assertSame($xml, $extracted, 'round trip through the plugin\'s own extractor must return exactly the deleted source');
    }

    /**
     * The bug this feature exists to fix: renaming/moving a diagram's image
     * used to strand its source under the old name, silently losing the
     * "editable diagram" half of what this feature is for.
     */
    public function testMovingTheOnlyRenderingMovesItsSource()
    {
        $this->put('test:mv1.png', 'png-bytes');
        $this->put('test:mv1.drawio', $this->diagramXml('moveme'));

        $this->fireMoveMedia('test:mv1.png', 'ns2:renamed.png');

        $this->assertFileDoesNotExist(mediaFN('test:mv1.drawio'), 'the old location must not keep the source behind');
        $this->assertSame($this->diagramXml('moveme'), file_get_contents(mediaFN('ns2:renamed.drawio')));
    }

    /**
     * A genuine draw.io PNG export (uncompressed tEXt chunk), taken verbatim
     * from the same fixture helper.test.php uses to prove extraction against
     * real output rather than a hand-built one. The admin bulk-conversion
     * task must recover its source and fire the same upload event core's own
     * media_save() would, so other plugins (e.g. gitbacked) learn about it.
     */
    public function testConvertsARealPngExportAndFiresTheUploadEvent()
    {
        $png = file_get_contents(__DIR__ . '/../real-drawio-export.png');
        $this->put('admintest5:plan.png', $png);

        $helper = plugin_load('helper', 'drawio');
        $expectedXml = $helper->extractPngXml($png);
        $this->assertNotSame('', $expectedXml);

        $token = $this->validTokenFor('testadmin');
        $_SERVER['REMOTE_USER'] = 'testadmin';
        $this->simulateRequest('POST', ['convert' => '1', 'sectok' => $token]);
        $html = $this->runAdmin();

        $srcFile = mediaFN('admintest5:plan.drawio');
        $this->assertFileExists($srcFile);
        $this->assertSame($expectedXml, file_get_contents($srcFile));
        $this->assertStringContainsString('admintest5:plan.png', $html);

        $events = $this->eventsFor('admintest5:plan.drawio');
        $this->assertCount(1, $events);
        [$name, $fl, $id, $mime, $overwrite, $move] = $events[0];
        $this->assertSame(basename($srcFile), $name);
        $this->assertSame($srcFile, $fl);
        $this->assertSame('admintest5:plan.drawio', $id);
        $this->assertSame('application/xml', $mime);
        $this->assertFalse($overwrite);
        $this->assertNull($move);
    }
}
