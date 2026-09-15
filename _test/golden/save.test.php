<?php

/**
 * Golden-path save/open tests for the drawio action plugin's ajax handler.
 *
 * One test per feature, through the real ajax.php entry point (TestRequest +
 * sectok), with realistic data - the fixtures under _test/, not hand-rolled
 * bytes, wherever a real export exists. Everything else (rejected input,
 * mediarevisions variants, lock/delete/move edge cases, security
 * regressions) lives under _test/extra/ - see DEVELOPMENT.md.
 *
 * @group plugin_drawio
 * @group plugins
 */

class action_plugin_drawio_golden_save_test extends DokuWikiTest
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
     * Post one ajax request the way script.js does.
     *
     * @param array $post everything except 'call', which is always 'plugin_drawio'
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
        if ($xml !== null) $post['xml'] = $xml;
        return $this->ajaxPost($post);
    }

    /** Drive the open path the way script.js's 'init' handler does. */
    protected function openViaAjax($mediaId, $action = 'get_png')
    {
        $response = $this->ajaxPost(['action' => $action, 'imageName' => $mediaId]);
        return json_decode($response->getContent(), true);
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

    /**
     * The check must not reject the plugin's own output. This fixture is a
     * genuine draw.io export (app.diagrams.net, embedded mxfile, 14
     * <foreignObject> labels and an <a xlink:href> link), taken verbatim
     * from cpq/embedded-network-programming-guide (MIT).
     *
     * Merges the old testSaveAlsoWritesTheDiagramSource: one real save,
     * checked for both effects it must have - the image lands with the
     * exact bytes sent, and its .drawio source lands alongside it.
     */
    public function testSaveAcceptsARealDrawioExport()
    {
        $svg = file_get_contents(__DIR__ . '/../real-drawio-export.svg');
        $this->assertStringContainsString('<foreignObject', $svg, 'fixture must be a real export');

        $mediaId = 'test:realexport.svg';
        $this->saveViaAjax($mediaId, $svg);
        $this->assertSame($svg, file_get_contents(mediaFN($mediaId)));

        $pngId = 'test:withsource.png';
        $xml = $this->diagramXml('one');
        $this->saveViaAjax($pngId, $this->pngBytes(), $xml);
        $this->assertSame($this->pngBytes(), file_get_contents(mediaFN($pngId)));
        $this->assertSame($xml, file_get_contents(mediaFN('test:withsource.drawio')));
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

    /**
     * Migration is lazy: an old diagram gains its source the first time it
     * is saved. Merges the old testOpeningADiagramWithoutASourceFallsBackToTheImage:
     * before that save, the same old diagram must still open from the image alone.
     */
    public function testAnOldDiagramGainsItsSourceOnTheNextSave()
    {
        $mediaId = 'test:lazy.png';
        $file = mediaFN($mediaId);
        io_makeFileDir($file); file_put_contents($file, $this->pngBytes());
        $this->assertFileDoesNotExist(mediaFN('test:lazy.drawio'));

        $openedBeforeMigration = $this->openViaAjax($mediaId);
        $this->assertArrayNotHasKey('xml', $openedBeforeMigration, 'no source means the old xmlpng path');
        $this->assertSame('data:image/png;base64,' . base64_encode($this->pngBytes()), $openedBeforeMigration['content']);

        $this->saveViaAjax($mediaId, $this->pngBytes(), $this->diagramXml('migrated'));

        $this->assertSame($this->diagramXml('migrated'), file_get_contents(mediaFN('test:lazy.drawio')));
    }

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

        $this->assertSame($file, $data[1], 'data[1] must be the full path on disk');
        $this->assertSame($mediaId, $data[2], 'data[2] must be the media id');
        $this->assertSame('image/png', $data[3], 'data[3] must be the mimetype');
        $this->assertFalse($data[4], 'a brand new file must not be reported as an overwrite');
    }

    /**
     * lib/exe/mediamanager.php never fires DOKUWIKI_STARTED (only doku.php does), so
     * without also hooking MEDIAMANAGER_STARTED, JSINFO['plugin_drawio'] is missing
     * on the media manager. script.js used to dereference it at the top level, and
     * since DokuWiki concatenates every plugin's script.js into one response,
     * that silently broke every plugin script sorting after "drawio" whenever
     * the media manager was open.
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
        $this->assertSame($GLOBALS['conf']['locktime'], $JSINFO['plugin_drawio']['locktime']);
    }
}
