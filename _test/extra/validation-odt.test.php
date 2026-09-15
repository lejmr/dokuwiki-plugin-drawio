<?php

/**
 * ODT export edge cases for the drawio syntax plugin - the plugin gracefully
 * no-oping when the third-party odt plugin isn't installed, and every corner
 * case (sizing, real svg, missing/empty/linkonly diagrams) once it is. The
 * happy path (a present diagram embedded as an image) lives in
 * _test/golden/render.test.php; ODT's ACL enforcement is a SECURITY.md claim
 * and lives in _test/extra/security.test.php.
 *
 * @group plugin_drawio
 * @group plugins
 */
require_once __DIR__ . '/../odt-fake-renderer.inc.php';

class syntax_plugin_drawio_validation_odt_test extends DokuWikiTest
{
    protected $pluginsEnabled = ['drawio'];

    protected function createMedia($mediaId, $content = 'not-really-a-png')
    {
        $file = mediaFN($mediaId);
        io_makeFileDir($file);
        file_put_contents($file, $content);
        return $file;
    }

    protected function createRealSvgMedia($mediaId)
    {
        return $this->createMedia($mediaId, file_get_contents(__DIR__ . '/../real-drawio-export.svg'));
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
     * ODT export (issue #7) is provided by a third-party "odt" plugin that
     * most installs do not have. DokuWiki core (p_get_renderer()) simply
     * returns no renderer for a mode nobody provides, so p_render('odt', ...)
     * must come back null - not throw, not warn.
     */
    public function testOdtExportIsANoopWhenTheOdtPluginIsNotInstalled()
    {
        $this->createMedia('test:present.png');

        global $ID, $INPUT;
        $ID = 'start';
        $_REQUEST['id'] = 'start';
        $INPUT = new \dokuwiki\Input\Input();
        $info = null;

        $result = p_render('odt', p_get_instructions('{{drawio>test:present}}'), $info);

        $this->assertNull($result);
    }

    public function testOdtModePassesSizeAndTitleToOdtAddImage()
    {
        $this->createMedia('test:present.png');
        $renderer = new drawio_test_fake_odt_renderer();

        $this->renderOdt('{{drawio>test:present?200x100|My Title}}', $renderer);

        $call = $renderer->addImageCalls[0];
        $this->assertSame('200', $call['width']);
        $this->assertSame('100', $call['height']);
        $this->assertSame('My Title', $call['title']);
    }

    /**
     * The odt path was the thinnest spot for svg: it reads the media's bytes
     * off disk itself (_odtAddImage($path, ...) takes a real filesystem
     * path), so unlike xhtml a broken svg id here would silently embed the
     * wrong file into an exported document with no browser onerror handler
     * to ever surface it.
     */
    public function testOdtModeEmbedsARealSvgDiagram()
    {
        $this->createRealSvgMedia('test:present.svg');
        $renderer = new drawio_test_fake_odt_renderer();

        $ok = $this->renderOdt('{{drawio>test:present.svg|Network layers}}', $renderer);

        $this->assertTrue($ok);
        $this->assertCount(1, $renderer->addImageCalls);
        $call = $renderer->addImageCalls[0];
        $this->assertSame(mediaFN('test:present.svg'), $call['src']);
        $this->assertStringEndsWith('.svg', $call['src']);
        $this->assertSame('Network layers', $call['title']);
    }

    public function testOdtModeSkipsAMissingDiagramInsteadOfExportingThePlaceholder()
    {
        $renderer = new drawio_test_fake_odt_renderer();

        $ok = $this->renderOdt('{{drawio>test:missing}}', $renderer);

        $this->assertTrue($ok, 'a missing diagram must not fail the export');
        $this->assertCount(0, $renderer->addImageCalls);
    }

    public function testOdtModeSkipsAnEmptyZeroByteDiagram()
    {
        // issue #66, same rule as the xhtml placeholder fallback
        $this->createMedia('test:blank.png', '');
        $renderer = new drawio_test_fake_odt_renderer();

        $this->renderOdt('{{drawio>test:blank}}', $renderer);

        $this->assertCount(0, $renderer->addImageCalls);
    }

    public function testOdtModeStillEmbedsALinkonlyDiagram()
    {
        // linkonly exists so a click opens the drawio editor in xhtml - there is
        // no editor to open in a static export, so a linkonly diagram is
        // embedded as an image here too rather than becoming a dead link.
        $this->createMedia('test:present.png');
        $renderer = new drawio_test_fake_odt_renderer();

        $this->renderOdt('{{drawio>test:present?linkonly}}', $renderer);

        $this->assertCount(1, $renderer->addImageCalls);
    }
}
