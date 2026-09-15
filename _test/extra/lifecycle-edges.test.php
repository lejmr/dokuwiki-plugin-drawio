<?php

/**
 * Behaviour that only shows up with $conf['mediarevisions'] off, plus a
 * couple of no-crash edge cases (GET as an anonymous user, removing a draft
 * that was never written) golden/lifecycle.test.php doesn't cover.
 *
 * @group plugin_drawio
 * @group plugins
 */
class action_plugin_drawio_lifecycle_edges_test extends DokuWikiTest
{
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

        $this->saveViaAjax($mediaId, $this->pngBytes());

        $this->assertFileExists($file, 'AUTH_UPLOAD alone must be enough to create a new diagram');
        $this->assertSame($this->pngBytes(), file_get_contents($file));
    }

    public function testOverwritingRequiresMoreThanUploadWithoutMediarevisions()
    {
        global $conf;
        $conf['mediarevisions'] = 0;

        $mediaId = 'test:existing2.png';
        $file = mediaFN($mediaId);
        io_makeFileDir($file);
        file_put_contents($file, 'original-content');

        $this->saveViaAjax($mediaId, $this->pngBytes());

        $this->assertSame(
            'original-content',
            file_get_contents($file),
            'overwriting with mediarevisions off needs AUTH_DELETE, not just AUTH_UPLOAD'
        );
        $this->assertCount(0, $this->firedEvents);
        // A denied save should also respond with a non-2xx status - that is
        // what script.js's ajax .fail() needs to tell the user it was rejected
        // (see action.php: http_status(403) on this path). Not asserted here:
        // TestRequest's header_remove()/headers_list() round trip is a no-op
        // under the CLI SAPI, where headers_sent() is true from process start
        // (confirmed with a bare `php -r` reproduction, nothing dokuwiki- or
        // plugin-specific) - every header()/http_status() call in ANY test in
        // this suite is silently dropped, so getStatusCode() would always
        // return null regardless of what the code under test does. Verified
        // live instead (curl) that this path actually sends HTTP 403.
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

        $response = $this->ajaxPost(['action' => 'get_png', 'imageName' => $mediaId]);

        $data = json_decode($response->getContent(), true);
        $this->assertSame('data:image/png;base64,' . base64_encode('png-bytes'), $data['content']);
    }

}
