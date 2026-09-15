<?php

/**
 * One regression test per hardening claim in SECURITY.md's "Hardening in
 * this release" table. Where the original suite had more than one test for
 * the same claim, they are merged here into one - see each test's docblock
 * for which claim it proves and which older tests it folds together.
 *
 * @group plugin_drawio
 * @group plugins
 */
require_once __DIR__ . '/../acl.inc.php';
require_once __DIR__ . '/../odt-fake-renderer.inc.php';

class drawio_security_test extends DokuWikiTest
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

    protected function ajaxPost(array $post, array $server = [])
    {
        $request = new TestRequest();
        foreach ($server as $key => $value) {
            $request->setServer($key, $value);
        }
        return @$request->post(array_merge(['call' => 'plugin_drawio'], $post), '/lib/exe/ajax.php');
    }

    protected function saveViaAjax($mediaId, $content = null, $xml = null)
    {
        if ($content === null) $content = $this->pngBytes();
        $post = [
            'action' => 'save',
            'imageName' => $mediaId,
            'content' => 'data:image/png;base64,' . base64_encode($content),
        ];
        if ($xml !== null) $post['xml'] = $xml;
        return $this->ajaxPost($post);
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

    protected function put($mediaId, $content = 'bytes')
    {
        $file = mediaFN($mediaId);
        io_makeFileDir($file);
        file_put_contents($file, $content);
        return $file;
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

    protected function lockRequest($mediaId, $user)
    {
        return $this->ajaxPost(
            ['action' => 'lock', 'imageName' => $mediaId, 'sectok' => $this->validTokenFor($user)],
            ['REMOTE_USER' => $user]
        );
    }

    protected function resolveSourceViaAjax($mediaId, $server = [])
    {
        $post = ['action' => 'resolve_source', 'imageName' => $mediaId];
        if ($server) $post['sectok'] = $this->validTokenFor(reset($server));
        return $this->ajaxPost($post, $server);
    }

    /**
     * Turn ACL on for a single test: a permissive parent namespace with two
     * locked children under it - the shape every real wiki with a private
     * area has. Two of them, so the write-side and the read-side assertion
     * below never name the same file.
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
     * SECURITY.md, High: "Cross-site request forgery". The ajax endpoint
     * used to read its parameters from $INPUT (i.e. $_REQUEST), so every
     * action worked as a plain GET - and answered without checking
     * DokuWiki's own CSRF token - which is exactly what turns a link a
     * victim clicks, or a cross-site POST with their session cookie, into a
     * write on their behalf. Merges the old testSaveIsRejectedOverGet and
     * testSaveIsRejectedWithoutSecurityToken: both angles of the same fix,
     * proven against the same file so neither can pass by accident.
     */
    public function testAjaxSaveRequiresPostAndAValidSecurityToken()
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

        $this->ajaxPost(
            [
                'action' => 'save',
                'imageName' => 'test:getcsrf.png',
                'content' => 'data:image/png;base64,' . base64_encode('overwritten'),
                'sectok' => 'not-the-real-token',
            ],
            ['REMOTE_USER' => 'testuser']
        );
        $this->assertSame('good-content', file_get_contents($file), 'a bad token must not write anything either');

        $this->assertCount(0, $this->firedEvents);
    }

    /**
     * SECURITY.md, High: "Write into an ACL-protected namespace". The
     * permission subject and the file path used to be derived from two
     * different strings: the ACL was checked on cleanID($name) while the
     * file was cleanID($name . '.draft'). A name crafted so that cleaning it
     * with the suffix lands one namespace deeper than cleaning it without
     * had its permission decided in the permissive parent and its bytes
     * written (or read back) in the locked child. Merges the old
     * testDraftSaveCannotEscapeIntoADeniedNamespace (write side) and
     * testDraftGetCannotReadFromADeniedNamespace (read side).
     */
    public function testWriteIntoAnAclProtectedNamespaceIsDenied()
    {
        $this->enableSecretAcl();

        $this->ajaxPost(['action' => 'draft_save', 'imageName' => 'public:secret:', 'content' => 'smuggled']);
        $this->assertFileDoesNotExist(
            mediaFN('public:secret:draft'),
            'a denied namespace must stay unwritable however the id is spelled'
        );

        // a namespace of its own, and removed again: it must not be there
        // for any other test in this class to trip over.
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
     * SECURITY.md, Medium: "Unvalidated draft writes". draft_save wrote the
     * raw posted bytes with no validation at all: no size limit, no check on
     * the base name, no check that the content is even the draft envelope
     * the client sends. Anyone who may upload anywhere had an arbitrary
     * write under an arbitrary base name. Merges the old
     * testDraftSaveRejectsANonDiagramBaseName, testDraftSaveRejectsAnOversizedDraft
     * and testDraftSaveRejectsContentThatIsNotADraft - three angles on one
     * claim, proven independently so none of them can pass by another one's
     * side effect.
     */
    public function testDraftWritesAreValidated()
    {
        $this->draftSaveViaAjax('test:pwn.php', $this->draftJson());
        $this->assertFileDoesNotExist(mediaFN('test:pwn.php.draft'), 'a non-diagram base name must be rejected');

        $this->draftSaveViaAjax('test:huge.png', $this->draftJson(str_repeat('A', 3 * 1024 * 1024)));
        $this->assertFileDoesNotExist(mediaFN('test:huge.png.draft'), 'an oversized draft must be rejected');

        $this->draftSaveViaAjax('test:notadraft.png', 'just some bytes');
        $this->assertFileDoesNotExist(mediaFN('test:notadraft.png.draft'), 'content that is not the draft envelope must be rejected');
    }

    /**
     * SECURITY.md, Medium: "Stored bytes did not have to match the file
     * type". The data: URL regex only ever checked the shape of the URL -
     * the mediatype in it is supplied by the caller and was never compared
     * against the decoded bytes. HTML carrying a script could therefore be
     * stored under a .png or .svg name, and DokuWiki then served it back as
     * that mimetype. Merges the old testSaveRejectsPngContentThatIsNotAPng
     * and testSaveRejectsSvgContentThatIsNotAnSvg.
     */
    public function testStoredBytesMustMatchTheClaimedFileType()
    {
        $pngId = 'test:notapng.png';
        $this->saveViaAjax($pngId, '<html><body><script>alert(1)</script></body></html>');
        $this->assertFileDoesNotExist(mediaFN($pngId));
        $this->assertCount(0, $this->firedEvents);

        $svgId = 'test:notansvg.svg';
        $this->saveViaAjax($svgId, '<html><body><script>alert(1)</script></body></html>');
        $this->assertFileDoesNotExist(mediaFN($svgId));
    }

    public function provideSvgScriptVectors()
    {
        return [
            ['scriptelement', '<script>alert(1)</script>'],
            ['onload', '<rect width="1" height="1" onload="alert(1)"/>'],
            ['onclick', "<rect width='1' height='1' onclick='alert(1)'/>"],
            ['jshref', '<a xlink:href="javascript:alert(1)"><rect width="1" height="1"/></a>'],
            ['use', '<use href="https://example.com/evil.svg#x"/>'],
        ];
    }

    /**
     * SECURITY.md, Medium: "Unsanitised markup inserted into the page".
     * Saving an SVG diagram injected its markup directly into the wiki
     * page's DOM, bypassing the content security policy that protects media
     * served normally - so every obvious script vector in an SVG must be
     * rejected at save time. Merges the old testSaveRejectsSvgCarryingAScriptElement
     * (folded in below as the "scriptelement" vector) into the stronger,
     * already-parametrized testSaveRejectsSvgCarryingAScriptVector.
     *
     * @dataProvider provideSvgScriptVectors
     */
    public function testSvgContentWithAScriptVectorIsRejected($label, $body)
    {
        $mediaId = 'test:vector' . $label . '.svg';
        $this->saveViaAjax($mediaId, $this->svgBytes($body));
        $this->assertFileDoesNotExist(mediaFN($mediaId), "$label must be rejected");
    }

    /**
     * SECURITY.md, High: "ODT export ignored read permissions". The ODT
     * export embedded a diagram's bytes without checking whether the person
     * exporting may read it. Proven non-vacuous both ways: a denied viewer
     * must get nothing embedded, and - so this can't pass by a broken gate
     * refusing everyone - a permitted viewer must still get the image.
     */
    public function testOdtExportHonoursReadAcl()
    {
        $this->createMedia('restricted:present.png');

        $this->enableAcl(
            ['*             @ALL   8', 'restricted:*  @ALL   0'],
            'john',
            ['user']
        );
        $deniedRenderer = new drawio_test_fake_odt_renderer();
        $this->renderOdt('{{drawio>restricted:present}}', $deniedRenderer);
        $this->assertCount(0, $deniedRenderer->addImageCalls, 'a denied user must not get the diagram embedded');

        $this->enableAcl(
            ['*             @ALL   8', 'restricted:*  @ALL   0', 'restricted:*  @boss  8'],
            'admin',
            ['boss']
        );
        $allowedRenderer = new drawio_test_fake_odt_renderer();
        $this->renderOdt('{{drawio>restricted:present}}', $allowedRenderer);
        $this->assertCount(1, $allowedRenderer->addImageCalls, 'a permitted user must still get the diagram embedded');
        $this->assertSame(mediaFN('restricted:present.png'), $allowedRenderer->addImageCalls[0]['src']);

        // The two results above differ per viewer, but the odt plugin caches
        // a page's render per page: without nocache() whoever exports first
        // decides what everyone after gets.
        $this->assertFalse($deniedRenderer->info['cache'], 'an ODT render must never be cached');
        $this->assertFalse($allowedRenderer->info['cache'], 'an ODT render must never be cached');
    }

    protected function createMedia($mediaId, $content = 'not-really-a-png')
    {
        $file = mediaFN($mediaId);
        io_makeFileDir($file);
        file_put_contents($file, $content);
        return $file;
    }

    /**
     * Build the $data render() expects for a given drawio tag, exactly as
     * DokuWiki's parser would via handle(), then feed the result into
     * render('odt', ...) directly instead of p_render(), since p_render()
     * can't reach an 'odt' renderer that isn't installed.
     */
    protected function renderOdt($match, Doku_Renderer $renderer, $id = 'start')
    {
        global $ID, $INPUT;
        $ID = $id;
        $_REQUEST['id'] = $id;
        $INPUT = new \dokuwiki\Input\Input();

        /** @var syntax_plugin_drawio $plugin */
        $plugin = plugin_load('syntax', 'drawio');
        if (class_exists('\dokuwiki\Parsing\ModeRegistry')) {
            global $conf;
            $handler = new Doku_Handler(new \dokuwiki\Parsing\ModeRegistry($conf['syntax']));
        } else {
            $handler = new Doku_Handler();
        }
        $data = $plugin->handle($match, DOKU_LEXER_SPECIAL, 0, $handler);

        return $plugin->render('odt', $renderer, $data);
    }

    /**
     * SECURITY.md, Medium: "Existence of protected media was observable".
     * Rendering revealed whether a diagram existed in a namespace the
     * viewer had no access to; the same class of leak existed in two more
     * ajax responses - lock() and resolve_source() - so both are proven
     * here, merged from the old testLockResponseShapeDoesNotDependOnFileExistence
     * and testResolveSourceResponseIsIdenticalForMissingAndForbidden: a
     * response's shape (or, for resolve_source, its exact bytes) must be
     * identical whether the thing behind it is missing or merely hidden.
     */
    public function testExistenceOfProtectedMediaIsNeverObservable()
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

        $this->enableAcl(['secret:* @ALL 0'], 'mallory');
        $this->put('secret:real.png', $this->pngBytes());
        $this->put('secret:real.drawio', $this->diagramXml());
        $forbidden = $this->resolveSourceViaAjax('secret:real.drawio', ['REMOTE_USER' => 'mallory']);
        $missing = $this->resolveSourceViaAjax('secret:doesnotexist.drawio', ['REMOTE_USER' => 'mallory']);
        $this->assertSame($forbidden->getContent(), $missing->getContent());
    }
}
