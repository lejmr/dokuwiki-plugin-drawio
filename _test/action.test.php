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
require_once __DIR__ . '/acl.inc.php';

class action_plugin_drawio_test extends DokuWikiTest
{
    use drawio_acl_test_helper;

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
    protected function saveViaAjax($mediaId, $content = null)
    {
        if ($content === null) $content = $this->pngBytes();
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

        $request = new TestRequest();
        $response = @$request->post(
            ['call' => 'plugin_drawio', 'action' => 'get_png', 'imageName' => $mediaId],
            '/lib/exe/ajax.php'
        );

        $data = json_decode($response->getContent(), true);
        $this->assertSame('data:image/png;base64,' . base64_encode('png-bytes'), $data['content']);
    }

    /**
     * The 'save' handler writes $fl directly, bypassing core's own media
     * extension whitelist entirely. Verified live before this fix:
     * imageName=test:pwn.php with a base64 payload wrote a working file into
     * data/media - the media manager itself refuses a .php upload, this
     * handler did not.
     */
    public function testSaveRejectsNonDrawioExtensions()
    {
        $mediaId = 'test:pwn.php';
        $file = mediaFN($mediaId);
        $this->assertFileDoesNotExist($file);

        $this->saveViaAjax($mediaId, '<?php echo "pwned"; ?>');

        $this->assertFileDoesNotExist($file);
        $this->assertCount(0, $this->firedEvents);
    }

    public function testSaveOfNewFileFiresMediaUploadFinish()
    {
        $mediaId = 'test:new.png';
        $file = mediaFN($mediaId);
        $this->assertFileDoesNotExist($file);

        $this->saveViaAjax($mediaId, $this->pngBytes());

        $this->assertFileExists($file);
        $this->assertSame($this->pngBytes(), file_get_contents($file));

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

        $this->saveViaAjax($mediaId, $this->pngBytes());

        $this->assertSame($this->pngBytes(), file_get_contents($file));

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
                'imageName' => 'test:draftonly.png',
                'content' => $this->draftJson(),
            ],
            '/lib/exe/ajax.php'
        );

        $this->assertCount(0, $this->firedEvents, 'drafts must not fire a media event');
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

    /**
     * The handler used to read its parameters from $INPUT (i.e. $_REQUEST), so
     * every action worked as a plain GET - and a GET is what turns a link the
     * victim clicks into a write on their behalf. The session cookie's
     * SameSite=Lax stops a cross site POST but not a top level GET navigation.
     */
    public function testSaveIsRejectedOverGet()
    {
        $file = mediaFN('test:getcsrf.png');
        io_makeFileDir($file);
        file_put_contents($file, 'good-content');

        $request = new TestRequest();
        @$request->get(
            [
                'call' => 'plugin_drawio',
                'action' => 'save',
                'imageName' => 'test:getcsrf.png',
                'content' => 'data:image/png;base64,' . base64_encode('overwritten'),
            ],
            '/lib/exe/ajax.php'
        );

        $this->assertSame('good-content', file_get_contents($file), 'a GET must not write anything');
        $this->assertCount(0, $this->firedEvents);
    }

    /**
     * No action ever validated DokuWiki's CSRF token, so any cross site request
     * that reached the browser with the victim's session cookie was executed.
     */
    public function testSaveIsRejectedWithoutSecurityToken()
    {
        $file = mediaFN('test:postcsrf.png');
        io_makeFileDir($file);
        file_put_contents($file, 'good-content');

        $request = new TestRequest();
        $request->setServer('REMOTE_USER', 'testuser');
        @$request->post(
            [
                'call' => 'plugin_drawio',
                'action' => 'save',
                'imageName' => 'test:postcsrf.png',
                'content' => 'data:image/png;base64,' . base64_encode('overwritten'),
                'sectok' => 'not-the-real-token',
            ],
            '/lib/exe/ajax.php'
        );

        $this->assertSame('good-content', file_get_contents($file), 'a bad token must not write anything');
        $this->assertCount(0, $this->firedEvents);
    }

    /**
     * The other half of the same gate: a logged in user posting the token
     * script.js reads out of JSINFO must still get through.
     */
    public function testSaveIsAcceptedWithValidSecurityToken()
    {
        $mediaId = 'test:tokenok.png';
        $file = mediaFN($mediaId);

        $request = new TestRequest();
        $request->setServer('REMOTE_USER', 'testuser');
        @$request->post(
            [
                'call' => 'plugin_drawio',
                'action' => 'save',
                'imageName' => $mediaId,
                'content' => 'data:image/png;base64,' . base64_encode($this->pngBytes()),
                'sectok' => $this->validTokenFor('testuser'),
            ],
            '/lib/exe/ajax.php'
        );

        $this->assertFileExists($file, 'a valid token must be accepted');
    }

    /**
     * script.js has to get the token from somewhere - JSINFO is how a plugin
     * hands values to its script, and core publishes the very same token in
     * every page's HTML itself (formSecurityToken()).
     */
    public function testJsinfoCarriesTheSecurityToken()
    {
        global $JSINFO;
        $JSINFO = [];

        $data = [];
        \dokuwiki\Extension\Event::createAndTrigger('DOKUWIKI_STARTED', $data);

        $this->assertArrayHasKey('sectok', $JSINFO['plugin_drawio']);
        $this->assertSame(getSecurityToken(), $JSINFO['plugin_drawio']['sectok']);
    }

    /**
     * Turn ACL on for a single test: a permissive parent namespace with two
     * locked children under it - the shape every real wiki with a private area
     * has. Two of them, so the write-side and the read-side test below never
     * name the same file and cannot depend on which of them runs first
     * (DokuWikiTest wipes the data directory once per class, not per test).
     */
    protected function enableSecretAcl()
    {
        $this->enableAcl([
            'public:*           @ALL    8',
            'public:secret:*    @ALL    0',
            'public:locked:*    @ALL    0',
        ]);
    }

    /**
     * The permission subject and the file path used to be derived from two
     * different strings: the ACL was checked on cleanID($name) while the file
     * was cleanID($name . '.draft'). A name crafted so that cleaning it with
     * the suffix lands one namespace deeper than cleaning it without had its
     * permission decided in the permissive parent and its bytes written in the
     * locked child.
     */
    public function testDraftSaveCannotEscapeIntoADeniedNamespace()
    {
        $this->enableSecretAcl();

        $request = new TestRequest();
        @$request->post(
            [
                'call' => 'plugin_drawio',
                'action' => 'draft_save',
                'imageName' => 'public:secret:',
                'content' => 'smuggled',
            ],
            '/lib/exe/ajax.php'
        );

        $this->assertFileDoesNotExist(
            mediaFN('public:secret:draft'),
            'a denied namespace must stay unwritable however the id is spelled'
        );
    }

    /**
     * Same seam on the read side: draft_get must not hand back a file from a
     * namespace the caller may not read.
     */
    public function testDraftGetCannotReadFromADeniedNamespace()
    {
        $this->enableSecretAcl();

        // a namespace of its own, and removed again: this is the one test that
        // plants a file in a denied namespace, and it must not be there for
        // any other test in the class to trip over.
        $file = mediaFN('public:locked:draft');
        io_makeFileDir($file);
        file_put_contents($file, 'secret-draft');

        try {
            $request = new TestRequest();
            $response = @$request->post(
                ['call' => 'plugin_drawio', 'action' => 'draft_get', 'imageName' => 'public:locked:'],
                '/lib/exe/ajax.php'
            );

            $this->assertStringNotContainsString('secret-draft', $response->getContent());
        } finally {
            unlink($file);
        }
    }

    /**
     * A page-style ACL check also asked the wrong question: core decides a
     * media file's permission on its *namespace* (mediaAclPath()), so an
     * exact-id rule meant for a page must not govern the media file, and the
     * namespace rule must. This is the "still allowed" half - a user who may
     * upload into public: must keep being able to save there.
     */
    public function testSaveIntoAPermittedNamespaceStillWorks()
    {
        $this->enableSecretAcl();

        $mediaId = 'public:allowed.png';
        $file = mediaFN($mediaId);
        $this->assertFileDoesNotExist($file);

        $this->saveViaAjax($mediaId, $this->pngBytes());

        $this->assertFileExists($file, 'an uploader must still be able to save in a permitted namespace');
    }


    /** What the editor actually posts as a draft: the JSON envelope from script.js. */
    protected function draftJson($xml = '<mxfile><diagram>x</diagram></mxfile>')
    {
        return json_encode(['lastModified' => '2026-01-01T00:00:00.000Z', 'xml' => $xml]);
    }

    protected function draftSaveViaAjax($imageName, $content)
    {
        $request = new TestRequest();
        return @$request->post(
            [
                'call' => 'plugin_drawio',
                'action' => 'draft_save',
                'imageName' => $imageName,
                'content' => $content,
            ],
            '/lib/exe/ajax.php'
        );
    }

    /**
     * draft_save wrote the raw posted bytes with no validation at all: no size
     * limit, no check on the base name, no check that the content is even the
     * draft envelope the client sends. Anyone who may upload anywhere had an
     * arbitrary write under an arbitrary base name.
     */
    public function testDraftSaveRejectsANonDiagramBaseName()
    {
        $this->draftSaveViaAjax('test:pwn.php', $this->draftJson());
        $this->assertFileDoesNotExist(mediaFN('test:pwn.php.draft'));
    }

    public function testDraftSaveRejectsAnOversizedDraft()
    {
        $this->draftSaveViaAjax('test:huge.png', $this->draftJson(str_repeat('A', 3 * 1024 * 1024)));
        $this->assertFileDoesNotExist(mediaFN('test:huge.png.draft'));
    }

    public function testDraftSaveRejectsContentThatIsNotADraft()
    {
        $this->draftSaveViaAjax('test:notadraft.png', 'just some bytes');
        $this->assertFileDoesNotExist(mediaFN('test:notadraft.png.draft'));
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

        $request = new TestRequest();
        $response = @$request->post(
            ['call' => 'plugin_drawio', 'action' => 'draft_get', 'imageName' => 'test:round.png'],
            '/lib/exe/ajax.php'
        );
        $this->assertSame($draft, $response->getContent());
    }

    /** A minimal SVG document of the shape draw.io exports. */
    protected function svgBytes($body = '<rect width="1" height="1"/>')
    {
        return '<?xml version="1.0" encoding="UTF-8"?>'
            . '<svg xmlns="http://www.w3.org/2000/svg" width="1" height="1">' . $body . '</svg>';
    }

    /**
     * The data: URL regex only ever checked the shape of the URL - the
     * mediatype in it is supplied by the caller and was never compared against
     * the decoded bytes. HTML carrying a script could therefore be stored under
     * a .png name, and DokuWiki then served it back as image/png.
     */
    public function testSaveRejectsPngContentThatIsNotAPng()
    {
        $mediaId = 'test:notapng.png';
        $this->saveViaAjax($mediaId, '<html><body><script>alert(1)</script></body></html>');
        $this->assertFileDoesNotExist(mediaFN($mediaId));
        $this->assertCount(0, $this->firedEvents);
    }

    public function testSaveRejectsSvgContentThatIsNotAnSvg()
    {
        $mediaId = 'test:notansvg.svg';
        $this->saveViaAjax($mediaId, '<html><body><script>alert(1)</script></body></html>');
        $this->assertFileDoesNotExist(mediaFN($mediaId));
    }

    public function testSaveRejectsSvgCarryingAScriptElement()
    {
        $mediaId = 'test:scripted.svg';
        $this->saveViaAjax($mediaId, $this->svgBytes('<script>alert(1)</script>'));
        $this->assertFileDoesNotExist(mediaFN($mediaId));
    }

    /** The other half: a real diagram export must still be saved. */
    public function testSaveAcceptsARealSvg()
    {
        $mediaId = 'test:real.svg';
        $this->saveViaAjax($mediaId, $this->svgBytes());
        $this->assertSame($this->svgBytes(), file_get_contents(mediaFN($mediaId)));
    }

    /** draw.io puts real links in its exports - those must not be mistaken for an attack. */
    public function testSaveAcceptsAnSvgContainingALink()
    {
        $mediaId = 'test:linked.svg';
        $svg = $this->svgBytes('<a href="https://example.com/"><rect width="1" height="1"/></a>');
        $this->saveViaAjax($mediaId, $svg);
        $this->assertSame($svg, file_get_contents(mediaFN($mediaId)));
    }

    /**
     * io_makeFileDir() used to run before the action was matched, so any
     * request - including one naming an action that does not exist - created a
     * namespace directory on disk as a side effect. Nothing needs it there:
     * both writing actions call io_createNamespace(), which makes the same
     * directory itself.
     */
    public function testAnUnknownActionCreatesNoDirectory()
    {
        $dir = dirname(mediaFN('freshns:whatever.png'));
        $this->assertDirectoryDoesNotExist($dir);

        $request = new TestRequest();
        @$request->post(
            ['call' => 'plugin_drawio', 'action' => 'no_such_action', 'imageName' => 'freshns:whatever.png'],
            '/lib/exe/ajax.php'
        );

        $this->assertDirectoryDoesNotExist($dir, 'an unrecognised action must not touch the disk');
    }

    /**
     * A draft action appends '.draft' to whatever it is given, so an imageName
     * that already ends in .draft used to address 'x.draft.draft' - a second,
     * shadow draft nobody can reach through the editor.
     */
    public function testADraftActionRejectsAnImageNameThatIsAlreadyADraft()
    {
        $shadow = mediaFN('test:stale.png.draft.draft');
        io_makeFileDir($shadow);
        file_put_contents($shadow, 'shadow');

        $request = new TestRequest();
        @$request->post(
            ['call' => 'plugin_drawio', 'action' => 'draft_rm', 'imageName' => 'test:stale.png.draft'],
            '/lib/exe/ajax.php'
        );

        $this->assertFileExists($shadow, 'a .draft imageName must be rejected, not suffixed again');
    }

    /**
     * Both writing actions go through one method now, so both must show the
     * same properties. This is the 'save' half of the file-mode check below:
     * the pair is the net that catches the two write paths drifting apart
     * again, which is how this plugin got here in the first place.
     */
    public function testSaveAppliesTheConfiguredFileMode()
    {
        global $conf;
        $conf['fmode'] = 0600;

        $this->saveViaAjax('test:fmodesave.png', $this->pngBytes());

        $file = mediaFN('test:fmodesave.png');
        $this->assertFileExists($file);
        clearstatcache(true, $file);
        $this->assertSame('0600', substr(sprintf('%o', fileperms($file)), -4));
    }

    /**
     * draft_save used to write with a bare fopen()/fwrite() while 'save' wrote
     * through a temp file and applied $conf['fmode'] - same plugin, two ways of
     * writing a file, one of them leaving the mode to the umask.
     */
    public function testDraftSaveAppliesTheConfiguredFileMode()
    {
        global $conf;
        $conf['fmode'] = 0600;

        $this->draftSaveViaAjax('test:fmode.png', $this->draftJson());

        $file = mediaFN('test:fmode.png.draft');
        $this->assertFileExists($file);
        clearstatcache(true, $file);
        $this->assertSame('0600', substr(sprintf('%o', fileperms($file)), -4));
    }

    /**
     * Defence in depth for the obvious script vectors in an svg. None of these
     * can execute as things stand - DokuWiki serves media under
     * default-src 'none' and the plugin does not inline svg into the page -
     * but that defence lives in a header this plugin does not control.
     *
     * @dataProvider provideSvgScriptVectors
     */
    public function testSaveRejectsSvgCarryingAScriptVector($label, $body)
    {
        $mediaId = 'test:vector' . $label . '.svg';
        $this->saveViaAjax($mediaId, $this->svgBytes($body));
        $this->assertFileDoesNotExist(mediaFN($mediaId), "$label must be rejected");
    }

    public function provideSvgScriptVectors()
    {
        return [
            ['onload', '<rect width="1" height="1" onload="alert(1)"/>'],
            ['onclick', "<rect width='1' height='1' onclick='alert(1)'/>"],
            ['jshref', '<a xlink:href="javascript:alert(1)"><rect width="1" height="1"/></a>'],
            ['use', '<use href="https://example.com/evil.svg#x"/>'],
        ];
    }

    /**
     * The check must not reject the plugin's own output. This fixture is a
     * genuine draw.io export (app.diagrams.net, embedded mxfile, 14
     * <foreignObject> labels and an <a xlink:href> link), taken verbatim from
     * cpq/embedded-network-programming-guide (MIT) - not hand written, because
     * a hand-written svg is exactly the thing that would not have caught
     * <foreignObject> being in every real export.
     */
    public function testSaveAcceptsARealDrawioExport()
    {
        $svg = file_get_contents(__DIR__ . '/real-drawio-export.svg');
        $this->assertStringContainsString('<foreignObject', $svg, 'fixture must be a real export');

        $mediaId = 'test:realexport.svg';
        $this->saveViaAjax($mediaId, $svg);

        $this->assertSame($svg, file_get_contents(mediaFN($mediaId)));
    }
}
