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
