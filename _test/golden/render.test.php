<?php

/**
 * Golden-path rendering tests for the drawio syntax plugin.
 *
 * One test per rendering feature, through the real parser API
 * (p_get_instructions/p_render) with realistic data, asserting what a user
 * actually sees. Everything else (edge cases, rejected input, ACL variants,
 * odt corner cases) lives in _test/extra/validation-render.test.php - see
 * DEVELOPMENT.md's "Run the tests" section for the golden/extra split.
 *
 * @group plugin_drawio
 * @group plugins
 */
require_once __DIR__ . '/../acl.inc.php';
require_once __DIR__ . '/../odt-fake-renderer.inc.php';

class syntax_plugin_drawio_golden_test extends DokuWikiTest
{
    use drawio_acl_test_helper;

    protected $pluginsEnabled = ['drawio'];

    /**
     * Render wiki text as if it were on page $id.
     */
    protected function render($text, $id = 'start')
    {
        global $ID, $INPUT;
        // The plugin resolves relative media ids against getID(), which reads
        // the *requested* page id, not $ID - so both have to be set.
        $ID = $id;
        $_REQUEST['id'] = $id;
        $INPUT = new \dokuwiki\Input\Input();

        return p_render('xhtml', p_get_instructions($text), $info);
    }

    protected function setConf($setting, $value)
    {
        global $conf;
        $conf['plugin']['drawio'][$setting] = $value;
    }

    protected function createMedia($mediaId, $content = 'not-really-a-png')
    {
        $file = mediaFN($mediaId);
        io_makeFileDir($file);
        file_put_contents($file, $content);
        return $file;
    }

    /**
     * Seed media with a genuine draw.io SVG export instead of a synthetic
     * string - the repository carries a real one specifically so tests don't
     * have to invent what a draw.io SVG export looks like (several shapes,
     * real mxfile/mxGraphModel payload embedded in the SVG's own content=
     * attribute, real multi-word labels).
     */
    protected function createRealSvgMedia($mediaId)
    {
        return $this->createMedia($mediaId, file_get_contents(__DIR__ . '/../real-drawio-export.svg'));
    }

    public function testExistingDiagramIsFetched()
    {
        $this->createMedia('test:present.png');

        $html = $this->render('{{drawio>test:present}}');

        $this->assertStringContainsString("src='".DOKU_BASE."lib/exe/fetch.php?media=test:present.png'", $html);
    }

    public function testMissingDiagramFallsBackToPlaceholderOnErrorInTheBrowser()
    {
        // The plugin no longer checks existence before choosing what to
        // render: the <img> always points at fetch.php (which will answer
        // 404 for this one), and an onerror handler swaps it to the on-wiki
        // placeholder client-side.
        $html = $this->render('{{drawio>test:missing}}');

        $this->assertStringContainsString('fetch.php?media=test:missing.png', $html);
        $this->assertStringContainsString('onerror', $html);
        $this->assertStringContainsString('blank-image.png', $html);
        $this->assertStringContainsString("id='test:missing.png'", $html);
    }

    public function testEmptyMediaFileFallsBackToPlaceholderOnErrorInTheBrowser()
    {
        // issue #66: an empty diagram was saved, leaving a zero-byte file -
        // fetch.php serves it (200, zero bytes), the browser can't decode
        // that as an image, onerror fires the same as for a 404.
        $this->createMedia('test:blank.png', '');

        $html = $this->render('{{drawio>test:blank}}');

        $this->assertStringContainsString('fetch.php?media=test:blank.png', $html);
        $this->assertStringContainsString('onerror', $html);
        $this->assertStringContainsString('blank-image.png', $html);
        $this->assertStringContainsString('onclick', $html);
    }

    public function testLinkonlyRendersATextLinkInsteadOfAnImage()
    {
        $this->createMedia('test:present.png');

        $html = $this->render('{{drawio>test:present?linkonly|edit graph}}');

        $this->assertStringNotContainsString('<img', $html);
        $this->assertStringContainsString('<a ', $html);
        $this->assertStringContainsString('edit graph', $html);
        $this->assertStringContainsString('fetch.php?media=test:present.png', $html);
    }

    /**
     * Merges the old testSizeAndTitleParametersAreApplied and
     * testSizedDiagramRequestsAResizedCopyFromFetchPhp: one test, one
     * feature - sizing a diagram both applies CSS sizing/title/alt AND asks
     * fetch.php for a server-resized copy (core's MEDIA_RESIZE mechanism,
     * not just a CSS shrink of the full-size image - otherwise a page with
     * many thumbnailed diagrams downloads full-size images for all of them).
     */
    public function testSizeAndTitleParametersAreApplied()
    {
        $this->createMedia('test:present.png');

        $html = $this->render('{{drawio>test:present?200x100|mouse-over text}}');

        $this->assertStringContainsString('max-width:100%', $html);
        $this->assertStringContainsString('width:200px', $html);
        $this->assertStringContainsString('height:100px', $html);
        $this->assertStringContainsString("alt='mouse-over text'", $html);
        $this->assertStringContainsString("title='mouse-over text'", $html);
        $this->assertStringContainsString('w=200', $html);
        $this->assertStringContainsString('h=100', $html);
        $this->assertStringContainsString(
            'tok='.media_get_token('test:present.png', 200, 100),
            $html
        );
    }

    /**
     * Merges the old testNamespaceAndPageNamePlaceholders and
     * testFilePlaceholderIsAliasForPage: @FILE@ is documented as a plain
     * alias for @PAGE@, so both resolve the same name from the same page.
     */
    public function testNamespaceAndPageNamePlaceholders()
    {
        $html = $this->render('{{drawio>@NS@:@PAGE@_diagram}}', 'wiki:some:page');
        $this->assertStringContainsString("id='wiki:some:page_diagram.png'", $html);

        $htmlViaFile = $this->render('{{drawio>@NS@:@FILE@_diagram}}', 'wiki:some:page');
        $this->assertStringContainsString("id='wiki:some:page_diagram.png'", $htmlViaFile);
    }

    /**
     * The end-to-end svg counterpart to testExistingDiagramIsFetched() above,
     * using a genuine draw.io SVG export rather than a synthetic string, to
     * prove the id/extension handling is exercised with a real svg on disk,
     * not just a name that happens to end in .svg.
     */
    public function testRealSvgDiagramRendersEndToEnd()
    {
        $this->createRealSvgMedia('test:present.svg');

        $html = $this->render('{{drawio>test:present.svg|Network layers}}');

        $this->assertStringContainsString("id='test:present.svg'", $html);
        $this->assertStringContainsString("src='".DOKU_BASE."lib/exe/fetch.php?media=test:present.svg'", $html);
        $this->assertStringContainsString("alt='Network layers'", $html);
        $this->assertStringContainsString('onerror', $html);
        $this->assertStringContainsString('onclick', $html);
        $this->assertStringNotContainsString('.svg.png', $html);
    }

    /**
     * The media manager lists where a file is used by reading the page's
     * metadata. Without this the diagram looks unused (#10).
     */
    protected function mediaUsedOn($id, $text)
    {
        saveWikiText($id, $text, 'drawio test');

        return (array) p_get_metadata($id, 'relation media', METADATA_RENDER_UNLIMITED);
    }

    public function testDiagramIsRecordedAsMediaUsedOnThePage()
    {
        $media = $this->mediaUsedOn('start', '{{drawio>test:tracked}}');

        $this->assertArrayHasKey('test:tracked.png', $media);
    }

    /**
     * Build the $data render() expects for a given drawio tag, exactly as
     * DokuWiki's parser would via handle() - so this exercises the same
     * parsing the xhtml test above goes through, just feeding the result
     * into render('odt', ...) directly instead of p_render(), since
     * p_render() can't reach an 'odt' renderer that isn't installed.
     */
    protected function renderOdt($match, Doku_Renderer $renderer, $id = 'start')
    {
        global $ID, $INPUT;
        $ID = $id;
        $_REQUEST['id'] = $id;
        $INPUT = new \dokuwiki\Input\Input();

        /** @var syntax_plugin_drawio $plugin */
        $plugin = plugin_load('syntax', 'drawio');
        // same construction p_get_instructions() uses on releases that have
        // ModeRegistry - a bare `new Doku_Handler()` triggers its own deprecation
        // warning there. oldstable predates ModeRegistry entirely.
        if (class_exists('\dokuwiki\Parsing\ModeRegistry')) {
            global $conf;
            $handler = new Doku_Handler(new \dokuwiki\Parsing\ModeRegistry($conf['syntax']));
        } else {
            $handler = new Doku_Handler();
        }
        $data = $plugin->handle($match, DOKU_LEXER_SPECIAL, 0, $handler);

        return $plugin->render('odt', $renderer, $data);
    }

    public function testOdtModeEmbedsTheDiagramAsAnImage()
    {
        $this->createMedia('test:present.png', 'not-really-a-png');
        $renderer = new drawio_test_fake_odt_renderer();

        $ok = $this->renderOdt('{{drawio>test:present}}', $renderer);

        $this->assertTrue($ok);
        $this->assertCount(1, $renderer->addImageCalls);
        $this->assertSame(mediaFN('test:present.png'), $renderer->addImageCalls[0]['src']);
    }
}
