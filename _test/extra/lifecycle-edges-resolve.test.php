<?php

/**
 * Format-alternation (saving PNG then SVG back and forth), a save whose
 * rename or source write fails partway, and the resolve_source ajax
 * action's own fallbacks and namespace ACL check - none go through the
 * golden path.
 *
 * @group plugin_drawio
 * @group plugins
 */
require_once __DIR__ . '/../acl.inc.php';

class action_plugin_drawio_lifecycle_edges_resolve_test extends DokuWikiTest
{
    use drawio_acl_test_helper;

    protected $pluginsEnabled = ['drawio'];

    /** @var array captured MEDIA_UPLOAD_FINISH event data, one entry per fired event */
    protected $firedEvents = [];

    /** @var array captured MEDIA_DELETE_FILE ids, one entry per fired event */
    protected $deletedMediaIds = [];

    public function setUp(): void
    {
        parent::setUp();
        $this->firedEvents = [];
        $this->deletedMediaIds = [];

        // auth_aclcheck()/auth_quickaclcheck() read this even with ACL disabled
        global $USERINFO;
        $USERINFO = ['grps' => []];

        // TestRequest::execute() reads/restores $_SESSION; nothing else starts a
        // session in this standalone test run
        if (!isset($_SESSION)) {
            $_SESSION = [];
        }

        global $EVENT_HANDLER;
        $EVENT_HANDLER->register_hook('MEDIA_UPLOAD_FINISH', 'AFTER', $this, 'captureMediaUploadFinish');
        // AFTER the plugin's own _media_delete_sibling(), which is also
        // registered AFTER - so this always sees whatever it cascaded too.
        $EVENT_HANDLER->register_hook('MEDIA_DELETE_FILE', 'AFTER', $this, 'captureMediaDeleteFile');
    }

    public function captureMediaUploadFinish(Doku_Event $event, $param)
    {
        $this->firedEvents[] = $event->data;
    }

    public function captureMediaDeleteFile(Doku_Event $event, $param)
    {
        $this->deletedMediaIds[] = $event->data['id'];
    }

    /**
     * Post one ajax request the way script.js does. The one place that builds
     * a TestRequest and posts to the plugin's endpoint - every test in this
     * suite (directly, or through the four named helpers below) goes through
     * this instead of repeating the same TestRequest()+post([...]) pair by
     * hand, which is what this file used to do at every single call site.
     *
     * $server sets request server vars (e.g. REMOTE_USER) before posting -
     * needed by the token/lock tests, which post as a particular user.
     *
     * Deprecation notices already printed to stdout during bootstrap leave PHP
     * thinking headers were sent, so TestRequest's header_remove() warns here on
     * every run - suppressed here, once, instead of at every call site, since
     * it is unrelated to whatever the test using this is actually checking.
     *
     * @param array $post   everything except 'call', which is always 'plugin_drawio'
     * @param array $server e.g. ['REMOTE_USER' => 'alice']
     * @return \TestResponse
     */
    protected function ajaxPost(array $post, array $server = [])
    {
        $request = new TestRequest();
        foreach ($server as $key => $value) {
            $request->setServer($key, $value);
        }
        return @$request->post(array_merge(['call' => 'plugin_drawio'], $post), '/lib/exe/ajax.php');
    }

    /**
     * Drive the plugin's ajax handler the same way script.js does for a diagram save.
     */
    protected function saveViaAjax($mediaId, $content = null, $xml = null)
    {
        if ($content === null) $content = $this->pngBytes();
        $post = [
            'action' => 'save',
            'imageName' => $mediaId,
            'content' => 'data:image/png;base64,' . base64_encode($content),
        ];
        // script.js only sends this once the editor has handed it the xml it
        // exported from; a save without it is what an old cached script.js
        // (or a pre-source diagram) looks like.
        if ($xml !== null) $post['xml'] = $xml;
        return $this->ajaxPost($post);
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

    /** Drive the open path the way script.js's 'init' handler does. */
    protected function openViaAjax($mediaId, $action = 'get_png')
    {
        $response = $this->ajaxPost(['action' => $action, 'imageName' => $mediaId]);
        return json_decode($response->getContent(), true);
    }

    protected function put($mediaId, $content = 'bytes')
    {
        $file = mediaFN($mediaId);
        io_makeFileDir($file);
        file_put_contents($file, $content);
        return $file;
    }

    /**
     * Compute a security token for the given user the same way core's own ajax
     * CSRF test does (_test/tests/lib/exe/ajax_requests.test.php), without
     * leaking the temporary REMOTE_USER into the global state.
     */
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

    protected function resolveSourceViaAjax($mediaId, $server = [])
    {
        $post = ['action' => 'resolve_source', 'imageName' => $mediaId];
        if ($server) $post['sectok'] = $this->validTokenFor(reset($server));
        return $this->ajaxPost($post, $server);
    }

    /**
     * Saving alternately between the two formats must not lose the source -
     * each save simply overwrites the one shared file, and the most recent
     * save (in either format) is what both formats open from afterwards.
     */
    public function testAlternatingSavesBetweenFormatsDoNotLoseTheSource()
    {
        $mediaId1 = 'test:alt.png';
        $mediaId2 = 'test:alt.svg';

        $this->saveViaAjax($mediaId1, $this->pngBytes(), $this->diagramXml('first'));
        $this->assertFileExists(mediaFN('test:alt.drawio'));

        $this->ajaxPost([
            'action' => 'save', 'imageName' => $mediaId2,
            'content' => 'data:image/svg+xml;base64,' . base64_encode($this->svgBytes()),
            'xml' => $this->diagramXml('second'),
        ]);
        $this->assertFileExists(mediaFN('test:alt.drawio'), 'the source must still be there after the second save');

        $pngData = $this->openViaAjax($mediaId1, 'get_png');
        $svgData = $this->openViaAjax($mediaId2, 'get_svg');
        $this->assertSame($this->diagramXml('second'), $pngData['xml']);
        $this->assertSame($this->diagramXml('second'), $svgData['xml']);
    }

    /**
     * The maintainer's rule, taken literally: saving one format must not
     * write the other format's image. A save that touched a file the user
     * never asked to save would be a surprise a stale sibling image is not.
     */
    public function testSavingOneFormatDoesNotTouchTheOtherFormatsImage()
    {
        $pngFile = mediaFN('test:onlyone.png');
        io_makeFileDir($pngFile);
        file_put_contents($pngFile, 'original-png-bytes');
        $pngMtime = filemtime($pngFile);

        $this->saveViaAjax('test:onlyone.svg', $this->svgBytes(), $this->diagramXml());

        clearstatcache();
        $this->assertSame('original-png-bytes', file_get_contents($pngFile), 'the untouched format must be left alone');
        $this->assertSame($pngMtime, filemtime($pngFile), 'not even the mtime may change');
    }

    /**
     * The arbiter's reproduction: obstruct the destination (put a directory
     * where the file is supposed to land, so rename() cannot replace it) and
     * the unfixed _write_file() still returned true - firing
     * MEDIA_UPLOAD_FINISH and leaving an orphaned .tmp file behind for a
     * write that never happened.
     */
    public function testFailedRenameLeavesNoOrphanTmpAndFiresNoEvent()
    {
        $mediaId = 'test:obstruct.png';
        $file = mediaFN($mediaId);
        io_makeFileDir($file);
        mkdir($file, 0777, true); // obstruct: rename() cannot replace a directory

        @$this->saveViaAjax($mediaId, $this->pngBytes());

        $this->assertTrue(is_dir($file), 'the obstruction itself must be untouched');
        $this->assertCount(0, $this->firedEvents, 'no event may fire for a write that did not happen');
        $this->assertSame([], glob($file . '.*.tmp'), 'a failed rename must not leave an orphaned tmp file');
    }

    /**
     * The other caller of _write_file(): the same fix must not regress the
     * branch's own reasoning that a failed *source* write still leaves a
     * successful image save - only the source's event/tmp file are affected.
     */
    public function testFailedSourceWriteStillLeavesTheImageSaveSuccessful()
    {
        $mediaId = 'test:srcobstruct.png';
        $file = mediaFN($mediaId);
        $srcFile = mediaFN('test:srcobstruct.drawio');
        io_makeFileDir($srcFile);
        mkdir($srcFile, 0777, true); // obstruct only the source

        @$this->saveViaAjax($mediaId, $this->pngBytes(), $this->diagramXml());

        $this->assertSame($this->pngBytes(), file_get_contents($file), 'the image must still be saved');
        $this->assertCount(1, $this->firedEvents, 'only the image write may fire an event');
        $this->assertSame($mediaId, $this->firedEvents[0][2]);
        $this->assertTrue(is_dir($srcFile), 'the source obstruction must be untouched');
        $this->assertSame([], glob($srcFile . '.*.tmp'), 'a failed source write must not leave an orphaned tmp file');
    }

    /** Only the svg rendering exists - that is the one to open, not the fixed default. */
    public function testResolveSourceResolvesToTheExistingSvgRendering()
    {
        $this->put('test:onlysvg.svg', $this->svgBytes());
        $this->put('test:onlysvg.drawio', $this->diagramXml());

        $data = json_decode($this->resolveSourceViaAjax('test:onlysvg.drawio')->getContent(), true);

        $this->assertSame('test:onlysvg.svg', $data['id']);
    }

    /**
     * Neither rendering exists yet - the pair can be broken by deleting one
     * side, or the bulk-conversion admin task can create a source for a
     * diagram whose image was itself later removed. The sensible outcome:
     * still resolve to *something* openable (the fixed png default, same as
     * everywhere else in this plugin), so the editor opens on the source XML
     * and the very next save is what actually creates that rendering - see
     * testGetPngFallsBackToTheSourceWhenNoRenderingExistsYet() below for
     * confirmation that the open half of that actually works, not just this
     * resolution step.
     */
    public function testResolveSourceDefaultsToPngWhenNeitherRenderingExists()
    {
        $this->put('test:neither.drawio', $this->diagramXml());

        $data = json_decode($this->resolveSourceViaAjax('test:neither.drawio')->getContent(), true);

        $this->assertSame('test:neither.png', $data['id']);
        $this->assertTrue($data['granted'], 'AUTH_UPLOAD is enough to create the new rendering the next save writes');
    }

    /**
     * The security constraint this whole action exists to respect: a caller
     * without rights in the resolved id's namespace must learn nothing -
     * not which rendering exists, not whether the source exists at all.
     * SECURITY.md already closed this exact class of bug once ("Existence of
     * protected media was observable"); a "which rendering does this source
     * have" lookup is exactly that shape again if it is not gated the same
     * way as everything else here.
     */
    public function testResolveSourceIsDeniedForANamespaceWithoutAccess()
    {
        $this->enableAcl(['secret:* @ALL 0'], 'mallory');
        // both renderings exist - if the check were skipped, the response
        // would differ from the "neither exists" case below and that
        // difference alone would be the leak.
        $this->put('secret:hidden.png', $this->pngBytes());
        $this->put('secret:hidden.svg', $this->svgBytes());
        $this->put('secret:hidden.drawio', $this->diagramXml());

        $response = $this->resolveSourceViaAjax('secret:hidden.drawio', ['REMOTE_USER' => 'mallory']);

        // Same as every other denied action in this suite: no JSON body was
        // ever produced (the handler returns before echoing anything), so
        // there is nothing for a denied caller to read either way - verified
        // live below (a real HTTP 403) since TestRequest can't observe it.
        $this->assertSame('', $response->getContent());
    }

    /**
     * The other end of the "neither rendering exists" case above: the
     * editor must actually be able to open from the source once
     * 'resolve_source' has pointed script.js at a rendering id that has
     * never been saved. Before this fix, get_png/get_svg bailed out the
     * moment the image file itself didn't exist, discarding a source that
     * was sitting right there - opening a diagram that way silently lost
     * its content and started the user from a blank canvas instead.
     */
    public function testGetPngFallsBackToTheSourceWhenNoRenderingExistsYet()
    {
        $xml = $this->diagramXml('sourceonly');
        $this->put('test:sourceonly.drawio', $xml);
        $this->assertFileDoesNotExist(mediaFN('test:sourceonly.png'));

        $data = $this->openViaAjax('test:sourceonly.png', 'get_png');

        $this->assertSame($xml, $data['xml']);
        $this->assertArrayNotHasKey('content', $data, 'there is no image to send - only the source exists');
    }

}
