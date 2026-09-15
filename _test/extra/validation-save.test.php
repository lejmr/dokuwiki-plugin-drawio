<?php

/**
 * Save/draft ajax handler edge cases that aren't the happy path or a
 * SECURITY.md regression: garbage/missing input, non-diagram extensions,
 * ACL namespace interaction, unknown actions, file mode, event shape on
 * overwrite. The happy path lives in _test/golden/save.test.php; the
 * .drawio source file's own mechanics live in
 * _test/extra/validation-source.test.php.
 *
 * @group plugin_drawio
 * @group plugins
 */
require_once __DIR__ . '/../acl.inc.php';

class action_plugin_drawio_validation_save_test extends DokuWikiTest
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

    protected function draftSaveViaAjax($imageName, $content)
    {
        return $this->ajaxPost(['action' => 'draft_save', 'imageName' => $imageName, 'content' => $content]);
    }

    protected function draftJson($xml = '<mxfile><diagram>x</diagram></mxfile>')
    {
        return json_encode(['lastModified' => '2026-01-01T00:00:00.000Z', 'xml' => $xml]);
    }

    protected function pngBytes()
    {
        return base64_decode(
            'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk'
            . 'YPhfDwAChwGA60e6kgAAAABJRU5ErkJggg=='
        );
    }

    protected function svgBytes($body = '<rect width="1" height="1"/>')
    {
        return '<?xml version="1.0" encoding="UTF-8"?>'
            . '<svg xmlns="http://www.w3.org/2000/svg" width="1" height="1">' . $body . '</svg>';
    }

    protected function enableSecretAcl()
    {
        $this->enableAcl([
            'public:*           @ALL    8',
            'public:secret:*    @ALL    0',
            'public:locked:*    @ALL    0',
        ]);
    }

    /**
     * A failed drawio export, or jQuery serialising an undefined msg.data as
     * the literal string "undefined" (script.js sends content: msg.data),
     * used to truncate the live diagram to garbage/zero bytes before
     * anything noticed.
     */
    public function testSaveWithGarbageContentLeavesTheDiagramAlone()
    {
        $file = mediaFN('test:keep.png');
        io_makeFileDir($file); file_put_contents($file, 'good-content');
        $this->ajaxPost(['action' => 'save', 'imageName' => 'test:keep.png', 'content' => 'undefined']);
        $this->assertSame('good-content', file_get_contents($file));
    }

    /**
     * cleanID('') is '', and mediaFN('') resolves to the media root
     * directory rather than a file - every action assumes $fl is a file.
     * Without the guard, the handler silently wrote a bogus
     * '<mediaroot>.<uniqid>.tmp' file as a *sibling* of the media directory
     * and fired MEDIA_UPLOAD_FINISH for it.
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

    public function testDraftSaveDoesNotFireMediaUploadFinish()
    {
        // drafts are internal scratch files, not media the user owns
        $this->ajaxPost([
            'action' => 'draft_save',
            'imageName' => 'test:draftonly.png',
            'content' => $this->draftJson(),
        ]);

        $this->assertCount(0, $this->firedEvents, 'drafts must not fire a media event');
    }

    /**
     * script.js has to get the token from somewhere - JSINFO is how a
     * plugin hands values to its script, and core publishes the very same
     * token in every page's HTML itself (formSecurityToken()).
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
     * request - including one naming an action that does not exist -
     * created a namespace directory on disk as a side effect. Nothing needs
     * it there: both writing actions call io_createNamespace(), which makes
     * the same directory itself.
     */
    public function testAnUnknownActionCreatesNoDirectory()
    {
        $dir = dirname(mediaFN('freshns:whatever.png'));
        $this->assertDirectoryDoesNotExist($dir);

        $this->ajaxPost(['action' => 'no_such_action', 'imageName' => 'freshns:whatever.png']);

        $this->assertDirectoryDoesNotExist($dir, 'an unrecognised action must not touch the disk');
    }

    /**
     * A draft action appends '.draft' to whatever it is given, so an
     * imageName that already ends in .draft used to address
     * 'x.draft.draft' - a second, shadow draft nobody can reach through the
     * editor.
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
     * draft_save used to write with a bare fopen()/fwrite() while 'save'
     * wrote through a temp file and applied $conf['fmode'] - same plugin,
     * two ways of writing a file, one of them leaving the mode to the
     * umask.
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
}
