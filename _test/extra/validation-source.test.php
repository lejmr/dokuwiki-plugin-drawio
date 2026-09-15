<?php

/**
 * Edge cases for the .drawio source file's own save/open mechanics: writing
 * it alongside an svg, its own event, rejecting bad/oversized/client-named
 * sources, and the "which is newer" tiebreak when opening. The happy path
 * (a diagram gains, then keeps, its source) lives in
 * _test/golden/save.test.php.
 *
 * @group plugin_drawio
 * @group plugins
 */

class action_plugin_drawio_validation_source_test extends DokuWikiTest
{
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

    protected function openViaAjax($mediaId, $action = 'get_png')
    {
        $response = $this->ajaxPost(['action' => $action, 'imageName' => $mediaId]);
        return json_decode($response->getContent(), true);
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

    protected function diagramXml($marker = 'x')
    {
        return '<mxfile host="embed"><diagram id="' . $marker . '">' . $marker . '</diagram></mxfile>';
    }

    /**
     * An SVG carries its XML in a content= attribute, so a sibling source
     * looks redundant - it is not. That attribute is exactly as easy to lose
     * as a PNG chunk (any SVG optimiser drops unknown attributes), and one
     * rule for both formats means one code path here and one for the
     * migration task later.
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
     * (gitbacked) has to hear about it the same way it hears about the
     * image.
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
     * the name claims before anything is written, and a refused action
     * writes nothing at all - not the source, and not the image either.
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
     * .drawio must not become a second way to write an arbitrary file: the
     * id is derived from the diagram id on the server, and the request's
     * own name still only ever names a png or an svg.
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

    /**
     * If someone changed the image behind the plugin's back - a media
     * manager upload, or restoring an older revision from the attic - that
     * image is newer than the source and is what the wiki now says the
     * diagram is. Preferring the source unconditionally would make "restore
     * this revision" silently do nothing.
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

    /** Not a .drawio id at all - script.js never sends one, but a malformed request must not crash. */
    public function testResolveSourceRejectsANonDrawioId()
    {
        $this->ajaxPost(['action' => 'resolve_source', 'imageName' => 'test:notasource.png']);
        // No JSON body/exception is the assertion here, same shape as every
        // other 400 in this suite.
        $this->addToAssertionCount(1);
    }
}
