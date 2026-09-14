<?php

/**
 * Tests for the drawio action plugin's ajax save handler.
 *
 * Saving a diagram writes the media file directly instead of going through
 * DokuWiki's normal upload path, so other plugins (e.g. gitbacked) never
 * learn about the new/changed file unless we fire the same event core does.
 * See https://github.com/lejmr/dokuwiki-plugin-drawio/issues/36
 *
 * @group plugin_drawio
 * @group plugins
 */
class action_plugin_drawio_test extends DokuWikiTest
{
    protected $pluginsEnabled = ['drawio'];

    /** @var array captured MEDIA_UPLOAD_FINISH event data, one entry per fired event */
    protected $firedEvents = [];

    public function setUp(): void
    {
        parent::setUp();
        $this->firedEvents = [];

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
    }

    public function captureMediaUploadFinish(Doku_Event $event, $param)
    {
        $this->firedEvents[] = $event->data;
    }

    /**
     * Drive the plugin's ajax handler the same way script.js does for a diagram save.
     */
    protected function saveViaAjax($mediaId, $content = 'not-really-a-png')
    {
        $request = new TestRequest();
        // Deprecation notices already printed to stdout during bootstrap leave PHP
        // thinking headers were sent, so TestRequest's header_remove() warns here on
        // every run - suppress that unrelated noise instead of failing the test on it.
        return @$request->post(
            [
                'call' => 'plugin_drawio',
                'action' => 'save',
                'imageName' => $mediaId,
                'content' => 'data:image/png;base64,' . base64_encode($content),
            ],
            '/lib/exe/ajax.php'
        );
    }

    /**
     * A failed drawio export, or jQuery serialising an undefined msg.data as the
     * literal string "undefined" (script.js sends content: msg.data), used to
     * truncate the live diagram to garbage/zero bytes before anything noticed -
     * see action.php's 'save' handler.
     */
    public function testSaveWithGarbageContentLeavesTheDiagramAlone()
    {
        $file = mediaFN('test:keep.png');
        io_makeFileDir($file); file_put_contents($file, 'good-content');
        $r = new TestRequest();
        @$r->post(['call'=>'plugin_drawio','action'=>'save',
                   'imageName'=>'test:keep.png','content'=>'undefined'], '/lib/exe/ajax.php');
        $this->assertSame('good-content', file_get_contents($file));
    }

    /**
     * $USERINFO is null (not an array) for a visitor who isn't logged in, and
     * the image's onclick='edit(this)' is rendered regardless of ACL - so an
     * anonymous reader clicking a diagram on a publicly readable wiki hit
     * "Trying to access array offset on value of type null" in action.php
     * before the ACL check even ran.
     */
    public function testGetAuthWithAnonymousUserEmitsNoWarning()
    {
        global $USERINFO;
        $USERINFO = null;

        // convertNoticesToExceptions is off for this suite (see _test/phpunit.xml
        // upstream), so a warning would otherwise just print and the test would
        // still pass - make the specific warning this bug caused fail instead.
        // Local to this test only; other warnings pass through to PHP as usual.
        set_error_handler(function ($errno, $errstr) {
            if (stripos($errstr, 'array offset') !== false || stripos($errstr, 'null') !== false) {
                throw new \PHPUnit\Framework\Exception($errstr, $errno);
            }
            return false;
        }, E_WARNING);

        try {
            $request = new TestRequest();
            $request->post(
                ['call' => 'plugin_drawio', 'action' => 'get_auth', 'imageName' => 'test:anon.png'],
                '/lib/exe/ajax.php'
            );
        } finally {
            restore_error_handler();
        }

        // reaching here without the error handler throwing is the assertion
        $this->addToAssertionCount(1);
    }

    /**
     * script.js calls draft_rm unconditionally after both 'save' and 'exit', so a
     * normal open/draw/save cycle where autosave never fired (no draft was ever
     * written to disk) hit unlink(): No such file or directory.
     */
    public function testDraftRmOfNonexistentDraftEmitsNoWarning()
    {
        $file = mediaFN('test:nodraft.png.draft');
        $this->assertFileDoesNotExist($file);

        set_error_handler(function ($errno, $errstr) {
            if (stripos($errstr, 'No such file or directory') !== false) {
                throw new \PHPUnit\Framework\Exception($errstr, $errno);
            }
            return false;
        }, E_WARNING);

        try {
            $request = new TestRequest();
            $request->post(
                ['call' => 'plugin_drawio', 'action' => 'draft_rm', 'imageName' => 'test:nodraft.png'],
                '/lib/exe/ajax.php'
            );
        } finally {
            restore_error_handler();
        }

        $this->addToAssertionCount(1);
    }

    /**
     * cleanID('') is '', and mediaFN('') resolves to the media root directory
     * rather than a file - every action below assumed $fl is a file. Confirmed
     * without the guard this fix adds: rather than a clean no-op, the handler
     * silently wrote a bogus '<mediaroot>.<uniqid>.tmp' file as a *sibling* of
     * the media directory and fired MEDIA_UPLOAD_FINISH for it.
     */
    public function testSaveWithNoImageNameDoesNotCrash()
    {
        $request = new TestRequest();
        $response = @$request->post(
            [
                'call' => 'plugin_drawio',
                'action' => 'save',
                'content' => 'data:image/png;base64,' . base64_encode('content'),
            ],
            '/lib/exe/ajax.php'
        );

        $this->assertCount(0, $this->firedEvents, 'nothing should be written/logged for a missing imageName');
        $this->addToAssertionCount(1); // reaching here without a thrown Error/Exception is the point
    }

    /**
     * $auth_ow ("AUTH_UPLOAD if mediarevisions is on, else AUTH_DELETE") used to
     * gate every action, including creating a brand new diagram and the
     * read-only get_png/get_svg/draft_get - not just overwriting an existing
     * file, unlike core's own media_save() (inc/media.php). Verified live with
     * `* @ALL 8` (AUTH_UPLOAD) and mediarevisions off: get_auth returned false
     * and the diagram was not even clickable for anyone below admin.
     *
     * ACL is off in this test environment, so auth_aclcheck() always returns
     * exactly AUTH_UPLOAD (8) - never higher. That is deliberately below
     * AUTH_DELETE (16), so it stands in for "an uploader, not an admin" and
     * exercises the mediarevisions-off overwrite bar without needing a real
     * ACL/auth plugin setup.
     */
    public function testCreatingNewDiagramWorksWithoutMediarevisions()
    {
        global $conf;
        $conf['mediarevisions'] = 0;

        $mediaId = 'test:brandnew.png';
        $file = mediaFN($mediaId);
        $this->assertFileDoesNotExist($file);

        $this->saveViaAjax($mediaId, 'new-content');

        $this->assertFileExists($file, 'AUTH_UPLOAD alone must be enough to create a new diagram');
        $this->assertSame('new-content', file_get_contents($file));
    }

    public function testOverwritingRequiresMoreThanUploadWithoutMediarevisions()
    {
        global $conf;
        $conf['mediarevisions'] = 0;

        $mediaId = 'test:existing2.png';
        $file = mediaFN($mediaId);
        io_makeFileDir($file);
        file_put_contents($file, 'original-content');

        $this->saveViaAjax($mediaId, 'attempted-overwrite');

        $this->assertSame(
            'original-content',
            file_get_contents($file),
            'overwriting with mediarevisions off needs AUTH_DELETE, not just AUTH_UPLOAD'
        );
        $this->assertCount(0, $this->firedEvents);
    }

    /**
     * Read-only actions must only need the AUTH_UPLOAD baseline, never the
     * higher overwrite bar - a diagram that already exists must stay viewable
     * (and editable-and-reopenable) even when mediarevisions is off.
     */
    public function testGetPngStillWorksWithoutMediarevisions()
    {
        global $conf;
        $conf['mediarevisions'] = 0;

        $mediaId = 'test:readable.png';
        $file = mediaFN($mediaId);
        io_makeFileDir($file);
        file_put_contents($file, 'png-bytes');

        $request = new TestRequest();
        $response = @$request->post(
            ['call' => 'plugin_drawio', 'action' => 'get_png', 'imageName' => $mediaId],
            '/lib/exe/ajax.php'
        );

        $data = json_decode($response->getContent(), true);
        $this->assertSame('data:image/png;base64,' . base64_encode('png-bytes'), $data['content']);
    }

    public function testSaveOfNewFileFiresMediaUploadFinish()
    {
        $mediaId = 'test:new.png';
        $file = mediaFN($mediaId);
        $this->assertFileDoesNotExist($file);

        $this->saveViaAjax($mediaId, 'new-content');

        $this->assertFileExists($file);
        $this->assertSame('new-content', file_get_contents($file));

        $this->assertCount(1, $this->firedEvents, 'MEDIA_UPLOAD_FINISH must fire exactly once');
        $data = $this->firedEvents[0];

        // shape must match what core's media_save()/media_upload_finish() produce, see
        // inc/media.php - consumers such as gitbacked read data[1] and data[2].
        $this->assertSame($file, $data[1], 'data[1] must be the full path on disk');
        $this->assertSame($mediaId, $data[2], 'data[2] must be the media id');
        $this->assertSame('image/png', $data[3], 'data[3] must be the mimetype');
        $this->assertFalse($data[4], 'a brand new file must not be reported as an overwrite');
    }

    public function testSaveOfExistingFileFiresMediaUploadFinishAsOverwrite()
    {
        $mediaId = 'test:existing.png';
        $file = mediaFN($mediaId);
        io_makeFileDir($file);
        file_put_contents($file, 'old-content');

        $this->saveViaAjax($mediaId, 'new-content');

        $this->assertSame('new-content', file_get_contents($file));

        $this->assertCount(1, $this->firedEvents, 'MEDIA_UPLOAD_FINISH must fire exactly once');
        $data = $this->firedEvents[0];
        $this->assertSame($file, $data[1]);
        $this->assertSame($mediaId, $data[2]);
        $this->assertTrue($data[4], 'overwriting an existing file must be reported as an overwrite');
    }

    /**
     * lib/exe/mediamanager.php never fires DOKUWIKI_STARTED (only doku.php does), so
     * without also hooking MEDIAMANAGER_STARTED, JSINFO['plugin_drawio'] is missing
     * on the media manager. script.js used to dereference it at the top level, and
     * since DokuWiki concatenates every plugin's script.js into one response
     * (js_pluginscripts() in lib/exe/js.php), that silently broke every plugin
     * script sorting after "drawio" whenever the media manager was open.
     * See https://github.com/lejmr/dokuwiki-plugin-drawio/issues/16
     */
    public function testMediaManagerStartedPopulatesJsinfo()
    {
        global $JSINFO;
        $JSINFO = [];

        $data = [];
        \dokuwiki\Extension\Event::createAndTrigger('MEDIAMANAGER_STARTED', $data);

        $this->assertArrayHasKey('plugin_drawio', $JSINFO);
        $this->assertArrayHasKey('url', $JSINFO['plugin_drawio']);
        $this->assertArrayHasKey('toolbar_possible_extension', $JSINFO['plugin_drawio']);
    }

    public function testDraftSaveDoesNotFireMediaUploadFinish()
    {
        // drafts are internal scratch files, not media the user owns - see action.php
        $request = new TestRequest();
        @$request->post(
            [
                'call' => 'plugin_drawio',
                'action' => 'draft_save',
                'imageName' => 'test:draftonly',
                'content' => 'draft content',
            ],
            '/lib/exe/ajax.php'
        );

        $this->assertCount(0, $this->firedEvents, 'drafts must not fire a media event');
    }
}
