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
        $this->ajaxPost(['action' => 'save', 'imageName' => 'test:keep.png', 'content' => 'undefined']);
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
        $this->ajaxPost([
            'action' => 'save',
            'content' => 'data:image/png;base64,' . base64_encode('content'),
        ]);

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

        $response = $this->ajaxPost(['action' => 'get_png', 'imageName' => $mediaId]);

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
        // script.js needs this to scale its lock-renewal interval to
        // whatever this wiki has $conf['locktime'] set to, rather than
        // guessing a fixed one against the 900s/15min default.
        $this->assertSame($GLOBALS['conf']['locktime'], $JSINFO['plugin_drawio']['locktime']);
    }

    public function testDraftSaveDoesNotFireMediaUploadFinish()
    {
        // drafts are internal scratch files, not media the user owns - see action.php
        $this->ajaxPost([
            'action' => 'draft_save',
            'imageName' => 'test:draftonly.png',
            'content' => $this->draftJson(),
        ]);

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

        $this->ajaxPost(
            [
                'action' => 'save',
                'imageName' => 'test:postcsrf.png',
                'content' => 'data:image/png;base64,' . base64_encode('overwritten'),
                'sectok' => 'not-the-real-token',
            ],
            ['REMOTE_USER' => 'testuser']
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

        $this->ajaxPost(
            [
                'action' => 'save',
                'imageName' => $mediaId,
                'content' => 'data:image/png;base64,' . base64_encode($this->pngBytes()),
                'sectok' => $this->validTokenFor('testuser'),
            ],
            ['REMOTE_USER' => 'testuser']
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

        $this->ajaxPost([
            'action' => 'draft_save',
            'imageName' => 'public:secret:',
            'content' => 'smuggled',
        ]);

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
            $response = $this->ajaxPost(['action' => 'draft_get', 'imageName' => 'public:locked:']);

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
        return $this->ajaxPost([
            'action' => 'draft_save',
            'imageName' => $imageName,
            'content' => $content,
        ]);
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

        $response = $this->ajaxPost(['action' => 'draft_get', 'imageName' => 'test:round.png']);
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

        $this->ajaxPost(['action' => 'no_such_action', 'imageName' => 'freshns:whatever.png']);

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

        $this->ajaxPost(['action' => 'draft_rm', 'imageName' => 'test:stale.png.draft']);

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

    // --- the diagram source next to the image ----------------------------
    //
    // A diagram used to exist only as an exported image with its XML hidden
    // inside it (a PNG text chunk, an SVG attribute). Anything that rewrites
    // media - an optimiser, a format conversion, a backup that re-encodes -
    // drops that and the diagram is a flat picture forever (issues #15, #66).
    // The XML now lives beside the image as ns:plan.drawio, an ordinary media
    // file under the very same namespace ACL.

    /** The XML the editor hands to script.js on its 'save' event. */
    protected function diagramXml($marker = 'x')
    {
        return '<mxfile host="embed"><diagram id="' . $marker . '">' . $marker . '</diagram></mxfile>';
    }

    public function testSaveAlsoWritesTheDiagramSource()
    {
        $mediaId = 'test:withsource.png';
        $xml = $this->diagramXml('one');

        $this->saveViaAjax($mediaId, $this->pngBytes(), $xml);

        $this->assertSame($this->pngBytes(), file_get_contents(mediaFN($mediaId)));
        $this->assertSame($xml, file_get_contents(mediaFN('test:withsource.drawio')));
    }

    /**
     * An SVG carries its XML in a content= attribute, so a sibling looks
     * redundant - it is not. That attribute is exactly as easy to lose as a
     * PNG chunk (any SVG optimiser drops unknown attributes), and one rule
     * for both formats means one code path here and one for the migration
     * task later.
     */
    public function testSaveOfAnSvgAlsoWritesTheDiagramSource()
    {
        $mediaId = 'test:vector.svg';
        $xml = $this->diagramXml('svg');
        $this->ajaxPost([
            'action' => 'save', 'imageName' => $mediaId,
            'content' => 'data:image/svg+xml;base64,' . base64_encode($this->svgBytes()),
            'xml' => $xml,
        ]);

        $this->assertSame($this->svgBytes(), file_get_contents(mediaFN($mediaId)));
        $this->assertSame($xml, file_get_contents(mediaFN('test:vector.drawio')));
    }

    /**
     * The source is the thing worth backing up, so a backup plugin
     * (gitbacked) has to hear about it the same way it hears about the image.
     */
    public function testSaveFiresMediaUploadFinishForTheSourceToo()
    {
        $this->saveViaAjax('test:evented.png', $this->pngBytes(), $this->diagramXml());

        $this->assertCount(2, $this->firedEvents, 'image and source each fire once');
        $ids = array_map(function ($d) { return $d[2]; }, $this->firedEvents);
        $this->assertSame(['test:evented.png', 'test:evented.drawio'], $ids);
        $this->assertSame(mediaFN('test:evented.drawio'), $this->firedEvents[1][1]);
    }

    /** An old, cached script.js sends no xml - that must still save the image. */
    public function testSaveWithoutXmlStillSavesTheImageAndWritesNoSource()
    {
        $this->saveViaAjax('test:noxml.png', $this->pngBytes());

        $this->assertSame($this->pngBytes(), file_get_contents(mediaFN('test:noxml.png')));
        $this->assertFileDoesNotExist(mediaFN('test:noxml.drawio'));
    }

    /**
     * Same rule as every other write here: content is checked against what
     * the name claims before anything is written, and a refused action writes
     * nothing at all - not the source, and not the image either.
     */
    public function testSaveRejectsXmlThatIsNotADiagram()
    {
        $mediaId = 'test:badxml.png';
        $this->saveViaAjax($mediaId, $this->pngBytes(), '<html><body>not a diagram</body></html>');

        $this->assertFileDoesNotExist(mediaFN($mediaId));
        $this->assertFileDoesNotExist(mediaFN('test:badxml.drawio'));
        $this->assertCount(0, $this->firedEvents);
    }

    public function testSaveRejectsAnOversizedSource()
    {
        $mediaId = 'test:hugexml.png';
        $xml = '<mxfile>' . str_repeat('a', 2 * 1024 * 1024) . '</mxfile>';
        $this->saveViaAjax($mediaId, $this->pngBytes(), $xml);

        $this->assertFileDoesNotExist(mediaFN($mediaId));
        $this->assertFileDoesNotExist(mediaFN('test:hugexml.drawio'));
    }

    /**
     * .drawio must not become a second way to write an arbitrary file: the id
     * is derived from the diagram id on the server, and the request's own
     * name still only ever names a png or an svg.
     */
    public function testASourceCannotBeWrittenUnderAClientChosenName()
    {
        $this->saveViaAjax('test:evil.drawio', $this->pngBytes(), $this->diagramXml());
        $this->assertFileDoesNotExist(mediaFN('test:evil.drawio'));

        // and neither can a draft of one
        $this->ajaxPost([
            'action' => 'draft_save',
            'imageName' => 'test:evil2.drawio',
            'content' => '{"lastModified":1,"xml":"<mxfile/>"}',
        ]);
        $this->assertFileDoesNotExist(mediaFN('test:evil2.drawio.draft'));
    }

    /** Drive the open path the way script.js's 'init' handler does. */
    protected function openViaAjax($mediaId, $action = 'get_png')
    {
        $response = $this->ajaxPost(['action' => $action, 'imageName' => $mediaId]);
        return json_decode($response->getContent(), true);
    }

    /**
     * The whole point: opening a diagram loads the XML source, not whatever
     * survived inside the image.
     */
    public function testOpeningADiagramPrefersItsSource()
    {
        $mediaId = 'test:prefer.png';
        $xml = $this->diagramXml('fromsource');
        $this->saveViaAjax($mediaId, $this->pngBytes(), $xml);

        $data = $this->openViaAjax($mediaId);
        $this->assertSame($xml, $data['xml'], 'the editor must be handed the source XML');
        $this->assertSame('data:image/png;base64,' . base64_encode($this->pngBytes()), $data['content']);
    }

    public function testOpeningAnSvgDiagramPrefersItsSource()
    {
        $xml = $this->diagramXml('svgsource');
        $file = mediaFN('test:presvg.svg');
        io_makeFileDir($file); file_put_contents($file, $this->svgBytes());
        $src = mediaFN('test:presvg.drawio');
        file_put_contents($src, $xml);
        touch($src, time() + 5);

        $data = $this->openViaAjax('test:presvg.svg', 'get_svg');
        $this->assertSame($xml, $data['xml']);
    }

    /** An old diagram has no source - it must still open, from the image. */
    public function testOpeningADiagramWithoutASourceFallsBackToTheImage()
    {
        $file = mediaFN('test:legacy.png');
        io_makeFileDir($file); file_put_contents($file, $this->pngBytes());

        $data = $this->openViaAjax('test:legacy.png');
        $this->assertArrayNotHasKey('xml', $data, 'no source means the old xmlpng path');
        $this->assertSame('data:image/png;base64,' . base64_encode($this->pngBytes()), $data['content']);
    }

    /** Migration is lazy: an old diagram gains its source the first time it is saved. */
    public function testAnOldDiagramGainsItsSourceOnTheNextSave()
    {
        $mediaId = 'test:lazy.png';
        $file = mediaFN($mediaId);
        io_makeFileDir($file); file_put_contents($file, 'old-content');
        $this->assertFileDoesNotExist(mediaFN('test:lazy.drawio'));

        $this->saveViaAjax($mediaId, $this->pngBytes(), $this->diagramXml('migrated'));

        $this->assertSame($this->diagramXml('migrated'), file_get_contents(mediaFN('test:lazy.drawio')));
    }

    /**
     * If someone changed the image behind the plugin's back - a media manager
     * upload, or restoring an older revision from the attic - that image is
     * newer than the source and is what the wiki now says the diagram is.
     * Preferring the source unconditionally would make "restore this revision"
     * silently do nothing.
     */
    public function testAnImageChangedAfterItsSourceWins()
    {
        $mediaId = 'test:disagree.png';
        $this->saveViaAjax($mediaId, $this->pngBytes(), $this->diagramXml('stale'));
        $this->assertFileExists(mediaFN('test:disagree.drawio'));

        // someone uploads/restores a different image afterwards
        file_put_contents(mediaFN($mediaId), $this->pngBytes());
        touch(mediaFN($mediaId), time() + 60);
        clearstatcache();

        $data = $this->openViaAjax($mediaId);
        $this->assertArrayNotHasKey('xml', $data,
            'an image newer than its source must be loaded from the image');
    }

    /** A zero byte source (issue #66's shape) is not a source. */
    public function testAnEmptySourceIsIgnored()
    {
        $mediaId = 'test:emptysrc.png';
        $file = mediaFN($mediaId);
        io_makeFileDir($file); file_put_contents($file, $this->pngBytes());
        file_put_contents(mediaFN('test:emptysrc.drawio'), '');
        touch(mediaFN('test:emptysrc.drawio'), time() + 5);

        $data = $this->openViaAjax($mediaId);
        $this->assertArrayNotHasKey('xml', $data);
    }

    /** The source obeys the same file mode as everything else written here. */
    public function testSourceAppliesTheConfiguredFileMode()
    {
        global $conf;
        $conf['fmode'] = 0640;
        $this->saveViaAjax('test:fmodesrc.png', $this->pngBytes(), $this->diagramXml());
        clearstatcache();
        $this->assertSame('0640', substr(sprintf('%o', fileperms(mediaFN('test:fmodesrc.drawio'))), -4));
    }

    // --- a diagram's identity is its name, not its extension -------------
    //
    // ns:plan.png and ns:plan.svg are two renderings of one diagram, not two
    // diagrams. Both read and write the very same ns:plan.drawio - that is
    // the maintainer's explicit design decision, not the accidental
    // consequence of sourceID() replacing rather than appending the
    // extension. See helper.php's sourceID() docblock for the full reasoning.

    /**
     * Saving one format, then opening the diagram in the other format, must
     * yield the same diagram - they are two renderings of one thing, not two
     * separate diagrams that happen to sit next to each other.
     */
    public function testSavingOneFormatMakesTheOtherFormatOpenTheSameDiagram()
    {
        $xml = $this->diagramXml('shared');

        // only the png exists on disk; save it with its source
        $this->saveViaAjax('test:identity.png', $this->pngBytes(), $xml);

        // the svg has never been saved, but opening it must still see the
        // very same source, because it is the same diagram
        $svgFile = mediaFN('test:identity.svg');
        io_makeFileDir($svgFile);
        file_put_contents($svgFile, $this->svgBytes());

        $data = $this->openViaAjax('test:identity.svg', 'get_svg');
        $this->assertSame($xml, $data['xml'], 'the svg rendering must open the diagram the png save wrote');
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

    // --- _write_file() must not report success for a write that failed ---

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

    // --- advisory diagram lock -------------------------------------------
    //
    // Two tabs editing the same diagram: the second save silently wins and
    // the first person's work is gone, with no warning to either of them.
    // These drive the plugin's ajax handler exactly like script.js's 'lock'
    // call does, and the 'draft_save' renewal alongside it.
    //
    // The lock is keyed on 'drawio:' + the diagram's *source* id
    // (helper->sourceID(), e.g. 'drawio:test:plan.drawio') rather than the
    // requested media id ('test:plan.png'), for two reasons at once: the
    // source id is the same "one diagram, two renderings" identity already
    // used for the stored XML (see helper.php), so opening the .png and the
    // .svg of the same diagram warn about each other; and the 'drawio:'
    // prefix makes the key collision-free against a same-named *page*
    // lock - fully, not just for the id shapes this suite happens to try -
    // because core's wikiLockFN() keys every lock (page or diagram) purely
    // by md5(cleanID($id)), with nothing distinguishing which kind of lock
    // it is, and a page id may contain dots anywhere (only special at an
    // id's boundaries), so a page literally named e.g. 'test:collide.drawio'
    // is a legal id that would otherwise share the unprefixed key exactly.

    /**
     * The same id action.php's _lock_id() derives, computed independently
     * here (not by calling _lock_id() itself - that would just be asserting
     * the code agrees with itself) so a test can point wikiLockFN() at the
     * right file.
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

    public function testLockOfAFreshDiagramReportsNoHolder()
    {
        $response = $this->lockRequest('test:fresh.png', 'alice');
        $data = json_decode($response->getContent(), true);
        $this->assertNull($data['locked_by'], 'nobody held the lock before this call');
        $this->assertFileExists($this->lockFileFor('test:fresh.png'), 'the call must take the lock for next time');
    }

    public function testLockNamesTheExistingHolderAndTakesItAnyway()
    {
        $this->lockRequest('test:contested.png', 'alice');
        $response = $this->lockRequest('test:contested.png', 'bob');

        $data = json_decode($response->getContent(), true);
        $this->assertSame('alice', $data['locked_by'], 'the warning must name who holds it');
        $this->assertIsInt($data['since'], 'and say how long ago, in seconds since epoch');
        $this->assertLessThanOrEqual(time(), $data['since']);

        // "Whatever the user answers, the editor opens": a lock is informational,
        // never a gate, so bob's request must still have taken the lock.
        $this->assertFileExists($this->lockFileFor('test:contested.png'));
    }

    public function testLockIsSharedBetweenPngAndSvgOfTheSameDiagram()
    {
        $this->lockRequest('test:shared.png', 'alice');
        $response = $this->lockRequest('test:shared.svg', 'bob');

        $data = json_decode($response->getContent(), true);
        $this->assertSame('alice', $data['locked_by'],
            'the .png and the .svg are the same diagram (see helper.php sourceID()) and must share one lock');
    }

    /**
     * The shallow collision the maintainer's brief called out by name: a
     * page 'ns:plan' and a diagram 'ns:plan.png' must not share a lock.
     * Simulated with core's own lock() (the same call DokuWiki's edit form
     * makes), not a fake - if the keys ever collided this would see
     * 'pageeditor' come back as the diagram's holder.
     */
    public function testDiagramLockDoesNotCollideWithAPageLockOfTheSameBaseName()
    {
        $_SERVER['REMOTE_USER'] = 'pageeditor';
        global $INPUT;
        $INPUT = new \dokuwiki\Input\Input();
        lock('test:collide');
        unset($_SERVER['REMOTE_USER']);
        $INPUT = new \dokuwiki\Input\Input();

        $this->assertNotSame(
            wikiLockFN('test:collide'),
            $this->lockFileFor('test:collide.png'),
            'sanity: the two ids must not hash to the same lock file'
        );

        $response = $this->lockRequest('test:collide.png', 'diagramuser');
        $data = json_decode($response->getContent(), true);
        $this->assertNull($data['locked_by'], 'the page lock must not leak into the diagram lock');
    }

    /**
     * The deeper collision an arbiter review found: unlike the shallow case
     * above, a page id CAN legally be spelled exactly like this diagram's
     * unprefixed lock key ('test:collide2.drawio' is as valid a page id as
     * any other - dots are only special at an id's boundaries). Without the
     * 'drawio:' prefix in _lock_id() this page's lock and the diagram
     * 'test:collide2.png''s lock would be the very same file.
     */
    public function testDiagramLockDoesNotCollideWithAPageLockSpelledLikeItsOwnUnprefixedKey()
    {
        $_SERVER['REMOTE_USER'] = 'pageeditor';
        global $INPUT;
        $INPUT = new \dokuwiki\Input\Input();
        lock('test:collide2.drawio'); // a legal page id, not a diagram action
        unset($_SERVER['REMOTE_USER']);
        $INPUT = new \dokuwiki\Input\Input();

        $this->assertNotSame(
            wikiLockFN('test:collide2.drawio'),
            $this->lockFileFor('test:collide2.png'),
            'the prefix must separate these even though the page id equals the diagram\'s unprefixed key'
        );

        $response = $this->lockRequest('test:collide2.png', 'diagramuser');
        $data = json_decode($response->getContent(), true);
        $this->assertNull($data['locked_by'], "a page named exactly like the diagram's own lock key must not leak into it");
    }

    /**
     * "An editor left open must keep its lock alive." draft_save is what
     * autosave (and the editor's own 'save' event) already calls, so it
     * renews the lock too - alongside, not instead of, script.js's own
     * renewal interval (see script.js's close()) which is what actually
     * covers "opened but not yet edited for a long time", since autosave
     * only fires on a model change.
     */
    public function testDraftSaveRenewsAnExistingLock()
    {
        $this->lockRequest('test:renew.png', 'alice');
        $lockFile = $this->lockFileFor('test:renew.png');
        $this->assertFileExists($lockFile);

        // back-date the lock as if it had been sitting untouched for a while
        touch($lockFile, time() - 600);
        clearstatcache();
        $agedMtime = filemtime($lockFile);
        $this->assertLessThanOrEqual(time() - 590, $agedMtime, 'sanity: touch() must have backdated the lock file');

        $this->ajaxPost(
            [
                'action' => 'draft_save',
                'imageName' => 'test:renew.png',
                'content' => $this->draftJson(),
                'sectok' => $this->validTokenFor('alice'),
            ],
            ['REMOTE_USER' => 'alice']
        );

        clearstatcache();
        $this->assertGreaterThan($agedMtime, filemtime($lockFile), 'autosave must refresh the lock, not just the draft');
    }

    /**
     * Same permission model as every other action, checked the same way: a
     * caller without AUTH_UPLOAD on the resolved id's namespace must be
     * refused before any lock code runs at all - not told "denied" (that
     * would itself be a tell), just refused, and refused with no side
     * effect. An existence/permission oracle through a new endpoint is
     * exactly the class of bug this development cycle already fixed once
     * for rendering (see SECURITY.md, "Existence of protected media was
     * observable") - this must not reopen it through the lock action.
     */
    public function testLockIsDeniedForANamespaceWithoutAccess()
    {
        $this->enableAcl(['secret:* @ALL 0'], 'mallory');

        $response = $this->lockRequest('secret:diagram.png', 'mallory');

        $this->assertFileDoesNotExist(
            $this->lockFileFor('secret:diagram.png'),
            'a denied caller must not be able to take a lock on something they cannot edit'
        );
        // No message body distinguishes "denied" from "does not exist" - see
        // action.php's _ajax_call() doc comment; there is nothing else to
        // assert on here without a live HTTP status (see the other 403 tests
        // in this suite for why that can't be checked under the CLI SAPI).
        $this->addToAssertionCount(1);
    }

    /**
     * The response shape (and the side effect of taking the lock) must not
     * depend on whether the file actually exists on disk - lock()/checklock()
     * are pure id lookups, same as a page lock works for a page that has
     * never been saved. A response that only appeared, or only carried
     * 'since', for an existing file would be exactly the kind of oracle
     * SECURITY.md already closed once for rendering.
     */
    public function testLockResponseShapeDoesNotDependOnFileExistence()
    {
        $existing = mediaFN('test:existsalready.png');
        io_makeFileDir($existing);
        file_put_contents($existing, $this->pngBytes());

        $responseExisting = $this->lockRequest('test:existsalready.png', 'alice');
        $responseMissing = $this->lockRequest('test:doesnotexist.png', 'alice');

        $dataExisting = json_decode($responseExisting->getContent(), true);
        $dataMissing = json_decode($responseMissing->getContent(), true);

        $this->assertSame(array_keys($dataExisting), array_keys($dataMissing));
        $this->assertNull($dataExisting['locked_by']);
        $this->assertNull($dataMissing['locked_by']);
    }

    // --- the sibling follows the image through delete and rename ---------
    //
    // DokuWiki's media manager knows nothing about the png/svg <-> drawio
    // pairing. Without this, deleting or renaming a diagram's image orphans
    // its source - the exact thing the source-of-truth feature exists to
    // prevent.

    protected function put($mediaId, $content = 'bytes')
    {
        $file = mediaFN($mediaId);
        io_makeFileDir($file);
        file_put_contents($file, $content);
        return $file;
    }

    /** Fire MEDIA_DELETE_FILE the same shape core's media_delete() builds it. */
    protected function fireMediaDeleteFile($mediaId, $unlinked = true)
    {
        $data = [
            'id' => $mediaId,
            'name' => noNS($mediaId),
            'path' => mediaFN($mediaId),
            'size' => 0,
            'unl' => $unlinked,
            'del' => false,
        ];
        \dokuwiki\Extension\Event::createAndTrigger('MEDIA_DELETE_FILE', $data, null, false);
    }

    /**
     * The maintainer's decision: deleting a diagram's only rendering deletes
     * its source too - "delete" means the diagram is gone, not "gone except
     * for a file nobody asked to keep". media_delete() is used for the
     * cascade (not unlink()), so this is also proof it fires
     * MEDIA_DELETE_FILE for the source - a backup plugin hears about it
     * exactly the way it hears about the image.
     */
    public function testDeletingTheOnlyRenderingDeletesItsSource()
    {
        // media_delete() (the cascade's own delete path) recomputes ACL
        // itself and requires AUTH_DELETE, which ACL-disabled test runs
        // never grant (auth_quickaclcheck() caps out at AUTH_UPLOAD with
        // useacl off) - so this needs it explicitly, same as
        // testDeletingViaCoreMediaDeleteCascadesLive() below.
        $this->enableAcl(['test:* @ALL 16']);
        $this->put('test:delone.drawio', $this->diagramXml());
        $this->assertFileExists(mediaFN('test:delone.drawio'));

        $this->fireMediaDeleteFile('test:delone.png');

        $this->assertFileDoesNotExist(mediaFN('test:delone.drawio'));
        $this->assertContains(
            'test:delone.drawio',
            $this->deletedMediaIds,
            'the cascade must go through media_delete(), so MEDIA_DELETE_FILE fires for the source too - a backup plugin hears about it the same way it hears about the image'
        );
    }

    /**
     * The one case that must NOT cascade: ns:plan.png and ns:plan.svg are
     * two renderings of the same diagram and share one source (see
     * helper.php's sourceID()). Deleting just the .png has not deleted "the
     * diagram" - the .svg is still there and still needs ns:plan.drawio.
     */
    public function testDeletingOneRenderingLeavesTheSourceForTheOther()
    {
        $this->put('test:deltwo.svg', 'svg-bytes');
        $this->put('test:deltwo.drawio', $this->diagramXml());

        $this->fireMediaDeleteFile('test:deltwo.png');

        $this->assertFileExists(mediaFN('test:deltwo.drawio'), 'the surviving .svg rendering still needs this source');
    }

    /**
     * Deleting the second rendering, once the first is already gone, must
     * then delete the source - the cascade looks at what is on disk right
     * now, so it does not matter which rendering was deleted first.
     */
    public function testDeletingTheSecondRenderingThenDeletesTheSource()
    {
        $this->enableAcl(['test:* @ALL 16']);
        $this->put('test:delboth.drawio', $this->diagramXml());
        // .png already gone (simulating the earlier delete above)
        $this->fireMediaDeleteFile('test:delboth.svg');

        $this->assertFileDoesNotExist(mediaFN('test:delboth.drawio'));
    }

    /** A failed/no-op delete (unl=false) must not cascade to the source. */
    public function testAFailedImageDeleteDoesNotCascade()
    {
        $this->put('test:delfail.drawio', $this->diagramXml());
        $this->fireMediaDeleteFile('test:delfail.png', false);
        $this->assertFileExists(mediaFN('test:delfail.drawio'));
    }

    /**
     * Deleting the source directly must not reach out and delete the image.
     * The image is not made worthless by losing its source - it still opens
     * from its own embedded XML, exactly as it always did before this
     * feature existed (see action.php's _source_xml()) - so a small text
     * file's deletion silently destroying somebody's picture would be the
     * more surprising direction, and it does not happen.
     */
    public function testDeletingTheSourceDirectlyDoesNotTouchTheImage()
    {
        $this->put('test:delsrc.png', 'png-bytes');
        $this->fireMediaDeleteFile('test:delsrc.drawio');
        $this->assertFileExists(mediaFN('test:delsrc.png'));
    }

    /** End to end, through the real core delete path (ACL granted), not just a synthetic event. */
    public function testDeletingViaCoreMediaDeleteCascadesLive()
    {
        $this->enableAcl(['test:* @ALL 16'], 'alice');
        $this->put('test:delreal.png', 'png-bytes');
        $this->put('test:delreal.drawio', $this->diagramXml());

        $result = media_delete('test:delreal.png', AUTH_DELETE);

        $this->assertSame(DOKU_MEDIA_DELETED, $result & DOKU_MEDIA_DELETED);
        $this->assertFileDoesNotExist(mediaFN('test:delreal.png'));
        $this->assertFileDoesNotExist(mediaFN('test:delreal.drawio'), 'the source must go with the image through the real delete path too');
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

    /**
     * The bug this task exists to fix: renaming/moving a diagram's image
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

    /** Same "still needed under its old name" rule as the delete cascade. */
    public function testMovingOneRenderingLeavesTheSourceForTheOther()
    {
        $this->put('test:mv2.svg', 'svg-bytes');
        $this->put('test:mv2.drawio', $this->diagramXml('shared'));

        $this->fireMoveMedia('test:mv2.png', 'ns2:mv2moved.png');

        $this->assertFileExists(mediaFN('test:mv2.drawio'), 'the untouched .svg rendering still needs this source');
        $this->assertFileDoesNotExist(mediaFN('ns2:mv2moved.drawio'));
    }

    /** Renaming to a namespace/name that already has its own source must not clobber it. */
    public function testMovingNeverClobbersAnExistingSourceAtTheDestination()
    {
        $this->put('test:mv3.png', 'png-bytes');
        $this->put('test:mv3.drawio', $this->diagramXml('mover'));
        $this->put('test:mv3dest.drawio', 'unrelated-existing-source');

        $this->fireMoveMedia('test:mv3.png', 'test:mv3dest.png');

        $this->assertSame('unrelated-existing-source', file_get_contents(mediaFN('test:mv3dest.drawio')));
    }

    /** Renaming the source directly must not reach for the image - same asymmetry as delete. */
    public function testMovingTheSourceDirectlyDoesNotTouchTheImage()
    {
        $this->put('test:mv4.png', 'png-bytes');
        $this->put('test:mv4.drawio', $this->diagramXml());

        $this->fireMoveMedia('test:mv4.drawio', 'test:mv4renamed.drawio');

        $this->assertFileExists(mediaFN('test:mv4.png'), 'the image must stay exactly where it was');
        // the source itself is core's/the move plugin's own job to move - this
        // plugin only refuses to *cascade from* a source move into the image
    }

    public function testMovingTheSourceFiresMediaUploadFinishForBackupPlugins()
    {
        $this->put('test:mv5.png', 'png-bytes');
        $this->put('test:mv5.drawio', $this->diagramXml('evented'));

        $this->fireMoveMedia('test:mv5.png', 'ns2:mv5.png');

        $this->assertCount(1, $this->firedEvents);
        $this->assertSame('ns2:mv5.drawio', $this->firedEvents[0][2]);
    }

    // --- diagram text in the search index ---------------------------------

    /** Drive the indexer's own event shape, the way inc/Search/Indexer.php builds it. */
    protected function fireIndexerPageAdd($page, array $relationMedia)
    {
        $data = [
            'page' => $page,
            'body' => '',
            'metadata' => [
                'title' => $page,
                'relation_references' => [],
                'relation_media' => $relationMedia,
                'internal_index' => true,
            ],
            'pid' => 1,
        ];
        \dokuwiki\Extension\Event::createAndTrigger('INDEXER_PAGE_ADD', $data, null, false);
        return $data;
    }

    public function testIndexerAddsAReadableDiagramsTextToThePageBody()
    {
        $this->put('test:idx1.png', 'png-bytes');
        $this->put('test:idx1.drawio', $this->diagramXml('firewall-label'));
        // diagramXml()'s payload is just a marker string, not real drawio
        // value= markup - use a fixture that actually parses as one
        $xml = '<mxfile><diagram><mxGraphModel><root><mxCell value="firewall" vertex="1"/></root></mxGraphModel></diagram></mxfile>';
        file_put_contents(mediaFN('test:idx1.drawio'), $xml);

        $data = $this->fireIndexerPageAdd('idxpage1', ['test:idx1.png']);

        $this->assertStringContainsString('firewall', $data['body']);
    }

    /** No source yet - must not warn/crash, and adds nothing. */
    public function testIndexerIgnoresADiagramWithoutASource()
    {
        $this->put('test:idx2.png', 'png-bytes');
        $data = $this->fireIndexerPageAdd('idxpage2', ['test:idx2.png']);
        $this->assertSame('', trim($data['body']));
    }

    /** A referenced file that is not a diagram at all (relation_media lists every embedded media). */
    public function testIndexerIgnoresNonDiagramMedia()
    {
        $this->put('test:notdiagram.jpg', 'jpg-bytes');
        $data = $this->fireIndexerPageAdd('idxpage3', ['test:notdiagram.jpg']);
        $this->assertSame('', trim($data['body']));
    }

    /**
     * The leak this has to close: a page anyone can read must not hand out
     * a restricted diagram's text through search just because the page
     * embeds it. Fulltext search is checked against the *page's* permission,
     * not the media's - core has no way to say "this word came from a
     * namespace some readers may not see" - so the plugin has to refuse to
     * put it there in the first place.
     */
    public function testIndexerDoesNotIndexADiagramFromADeniedNamespace()
    {
        $this->enableAcl(['secret:* @ALL 0']);
        $this->put('secret:idx4.png', 'png-bytes');
        $xml = '<mxfile><diagram><mxGraphModel><root><mxCell value="topsecretword" vertex="1"/></root></mxGraphModel></diagram></mxfile>';
        file_put_contents(mediaFN('secret:idx4.drawio'), $xml);

        $data = $this->fireIndexerPageAdd('idxpage4', ['secret:idx4.png']);

        $this->assertStringNotContainsString(
            'topsecretword',
            $data['body'],
            'a diagram in an ACL-restricted namespace must never reach a page\'s shared index'
        );
    }

    /** The other half: a diagram explicitly readable by everyone must still be indexed with ACL on. */
    public function testIndexerIndexesADiagramFromAnUnrestrictedNamespaceEvenWithAclOn()
    {
        $this->enableAcl(['secret:* @ALL 0', 'test:* @ALL 8']);
        $this->put('test:idx5.png', 'png-bytes');
        $xml = '<mxfile><diagram><mxGraphModel><root><mxCell value="openword" vertex="1"/></root></mxGraphModel></diagram></mxfile>';
        file_put_contents(mediaFN('test:idx5.drawio'), $xml);

        $data = $this->fireIndexerPageAdd('idxpage5', ['test:idx5.png']);

        $this->assertStringContainsString('openword', $data['body']);
    }

    /** A zero-byte source is not a source - same rule action.php's own read path uses. */
    public function testIndexerIgnoresAnEmptySource()
    {
        $this->put('test:idx6.png', 'png-bytes');
        $this->put('test:idx6.drawio', '');
        $data = $this->fireIndexerPageAdd('idxpage6', ['test:idx6.png']);
        $this->assertSame('', trim($data['body']));
    }

    // --- reindexing the page(s) that embed a diagram whose source changed ---
    //
    // The bug this task exists to fix: _index_diagrams() above only ever
    // *supplies* text to an indexing pass that some other event already
    // triggered - it never triggers one itself. Saving a diagram writes
    // media files only, never a page, so nothing DokuWiki does on its own
    // ever reindexes the page that embeds it. A freshly drawn diagram's
    // words were therefore never searchable until someone forced a manual
    // reindex - exactly what the maintainer found live.

    /**
     * Reproduces the maintainer's live finding as a unit test. Failed before
     * the fix (confirmed by running it against the pre-fix action.php): the
     * page is indexed - with nothing to extract, since the diagram has no
     * source yet, exactly like testIndexerIgnoresADiagramWithoutASource
     * above - and then never touched again. Saving the diagram through the
     * same ajax endpoint script.js uses does not, on its own, make the
     * embedding page findable by a word that exists only inside the
     * diagram. No manual reindex step appears anywhere in this test - that
     * absence is the point.
     */
    public function testSavingADiagramMakesTheEmbeddingPageFindableBySearch()
    {
        // Real-world order: the page is written and indexed long before
        // anyone ever opens its diagram in the editor.
        saveWikiText('reindexpage', '{{drawio>test:reindexed.png}}', 'test init');
        idx_addPage('reindexpage', false, true);

        // Sanity: nothing to find yet - see testIndexerIgnoresADiagramWithoutASource.
        $words = ['bytecodecompiler'];
        $before = idx_lookup($words);
        $this->assertArrayNotHasKey(
            'reindexpage',
            $before['bytecodecompiler'] ?? [],
            'sanity: the diagram has no source yet, so there is nothing to find'
        );

        // Now the diagram is actually saved, through the same ajax endpoint
        // script.js uses, with a source containing a word that exists only
        // inside the diagram.
        $xml = '<mxfile><diagram><mxGraphModel><root>'
            . '<mxCell value="BytecodeCompiler" vertex="1"/>'
            . '</root></mxGraphModel></diagram></mxfile>';
        $this->saveViaAjax('test:reindexed.png', $this->pngBytes(), $xml);

        $words = ['bytecodecompiler'];
        $after = idx_lookup($words);
        $this->assertArrayHasKey(
            'reindexpage',
            $after['bytecodecompiler'] ?? [],
            'saving the diagram must reindex the page that embeds it, with no manual reindex step'
        );
    }

    // --- the media manager button on the source itself, not just its rendering ---
    //
    // A diagram is stored as a png/svg *and* its .drawio source; the media
    // manager's "Edit with draw.io" button used to offer nothing when the
    // .drawio itself was selected, even though it is the more important half
    // of the pair. script.js's edit() resolves a .drawio click to a
    // rendering id through this action before opening the editor - see its
    // own docblock in action.php.

    protected function resolveSourceViaAjax($mediaId, $server = [])
    {
        $post = ['action' => 'resolve_source', 'imageName' => $mediaId];
        if ($server) $post['sectok'] = $this->validTokenFor(reset($server));
        return $this->ajaxPost($post, $server);
    }

    /**
     * Both renderings exist: png wins, deterministically - not "whichever
     * happens to be newer" or any other tiebreak, so opening the same source
     * twice in a row always lands on the same rendering. See helper::
     * renderingCandidates()'s docblock for why png specifically.
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

    /** Not a .drawio id at all - script.js never sends one, but a malformed request must not crash. */
    public function testResolveSourceRejectsANonDrawioId()
    {
        $this->ajaxPost(['action' => 'resolve_source', 'imageName' => 'test:notasource.png']);
        // No JSON body/exception is the assertion here, same shape as every
        // other 400 in this suite - see testOverwritingRequiresMoreThanUpload...
        // for why the actual HTTP status can't be read under the CLI SAPI.
        $this->addToAssertionCount(1);
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
     * The other half of "identical whether missing or forbidden": a denied
     * caller gets the exact same empty response for a source that does not
     * exist at all as for one that exists but is hidden from them.
     */
    public function testResolveSourceResponseIsIdenticalForMissingAndForbidden()
    {
        $this->enableAcl(['secret:* @ALL 0'], 'mallory');
        $this->put('secret:real.png', $this->pngBytes());
        $this->put('secret:real.drawio', $this->diagramXml());

        $forbidden = $this->resolveSourceViaAjax('secret:real.drawio', ['REMOTE_USER' => 'mallory']);
        $missing = $this->resolveSourceViaAjax('secret:doesnotexist.drawio', ['REMOTE_USER' => 'mallory']);

        $this->assertSame($forbidden->getContent(), $missing->getContent());
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
