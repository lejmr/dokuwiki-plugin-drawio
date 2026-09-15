<?php

/**
 * Rendering tests for the drawio syntax plugin.
 *
 * Everything goes through the public parser API (p_get_instructions/p_render)
 * so the tests keep working across DokuWiki releases.
 *
 * @group plugin_drawio
 * @group plugins
 */
class syntax_plugin_drawio_test extends DokuWikiTest
{
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

    protected function createMedia($mediaId, $content = 'not-really-a-png')
    {
        $file = mediaFN($mediaId);
        io_makeFileDir($file);
        file_put_contents($file, $content);
        return $file;
    }

    public function testMissingDiagramRendersPlaceholder()
    {
        $html = $this->render('{{drawio>test:missing}}');

        $this->assertStringContainsString('blank-image.png', $html);
        $this->assertStringContainsString("id='test:missing.png'", $html);
    }

    public function testExistingDiagramIsFetched()
    {
        $this->createMedia('test:present.png');

        $html = $this->render('{{drawio>test:present}}');

        $this->assertStringContainsString('fetch.php?media=test:present.png', $html);
        $this->assertStringNotContainsString('blank-image.png', $html);
    }

    public function testExplicitExtensionIsNotDuplicated()
    {
        $html = $this->render('{{drawio>test:explicit.png}}');

        $this->assertStringContainsString("id='test:explicit.png'", $html);
        $this->assertStringNotContainsString('.png.png', $html);
    }

    public function testRelativeNameResolvesAgainstCurrentNamespace()
    {
        $html = $this->render('{{drawio>diagram}}', 'wiki:some:page');

        $this->assertStringContainsString("id='wiki:some:diagram.png'", $html);
    }

    public function testClickOpensTheEditor()
    {
        $html = $this->render('{{drawio>test:missing}}');

        $this->assertStringContainsString('onclick', $html);
    }

    public function testEmptyDiagramNameRendersError()
    {
        $html = $this->render('{{drawio>test:}}');

        $this->assertStringContainsString('drawio-error', $html);
        $this->assertStringNotContainsString('onclick', $html);
        $this->assertStringNotContainsString('blank-image.png', $html);
    }

    public function testSingleCharacterNameWithoutNamespaceIsNotTreatedAsEmpty()
    {
        // regression: strrpos() returns false with no ':', and false + 1 === 1,
        // which used to chop the leading character off a namespace-less name
        $html = $this->render('{{drawio>a}}');

        $this->assertStringNotContainsString('drawio-error', $html);
        $this->assertStringContainsString("id='a.png'", $html);
    }

    public function testNamespaceAndPageNamePlaceholders()
    {
        $html = $this->render('{{drawio>@NS@:@PAGE@_diagram}}', 'wiki:some:page');

        $this->assertStringContainsString("id='wiki:some:page_diagram.png'", $html);
    }

    public function testFilePlaceholderIsAliasForPage()
    {
        $html = $this->render('{{drawio>@NS@:@FILE@_diagram}}', 'wiki:some:page');

        $this->assertStringContainsString("id='wiki:some:page_diagram.png'", $html);
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

    public function testLinkonlyIdMatchesTheImageIdForTheSameName()
    {
        // regression: linkonly used to render before resolve_mediaid() ran,
        // so it produced a *different*, unresolved id than {{drawio>diagram}}
        // would for the exact same name on the exact same page
        $imgHtml = $this->render('{{drawio>diagram}}', 'ns:page');
        $linkHtml = $this->render('{{drawio>diagram?linkonly|edit}}', 'ns:page');

        $this->assertStringContainsString("id='ns:diagram.png'", $imgHtml);
        $this->assertStringContainsString("id='ns:diagram.png'", $linkHtml);
    }

    public function testCraftedNameCannotBreakOutOfTheAttribute()
    {
        // a wiki editor fully controls this name - resolve_mediaid()'s cleanID
        // must run (and hsc() must escape) before any of it reaches an
        // attribute, on the linkonly path just like on the image path
        $html = $this->render('{{drawio>a"onmouseover="alert(1)"x?linkonly|click}}');

        $this->assertStringNotContainsString('"onmouseover="', $html);
        $this->assertStringNotContainsString("'onmouseover='", $html);
    }

    public function testEmptyTitleFallsBackToMediaIdInLinkonlyText()
    {
        // {{drawio>x?linkonly|}} - an empty title is "no title", so the link
        // text falls back to the media id, same as when no title is given at all
        $html = $this->render('{{drawio>test:missing?linkonly|}}');

        $this->assertStringContainsString('>test:missing.png</a>', $html);
    }

    public function testSizeAndTitleParametersAreApplied()
    {
        $this->createMedia('test:present.png');

        $html = $this->render('{{drawio>test:present?200x100|mouse-over text}}');

        $this->assertStringContainsString('max-width:100%', $html);
        $this->assertStringContainsString('width:200px', $html);
        $this->assertStringContainsString('height:100px', $html);
        $this->assertStringContainsString("alt='mouse-over text'", $html);
        $this->assertStringContainsString("title='mouse-over text'", $html);
    }

    public function testWidthOnlyParameterIsApplied()
    {
        $this->createMedia('test:present.png');

        $html = $this->render('{{drawio>test:present?200}}');

        $this->assertStringContainsString('width:200px', $html);
        $this->assertStringNotContainsString('height:', $html);
    }

    public function testEmptyTitleFallsBackToMediaIdAsAlt()
    {
        $this->createMedia('test:present.png');

        $html = $this->render('{{drawio>test:present|}}');

        $this->assertStringContainsString("alt='test:present.png'", $html);
        $this->assertStringNotContainsString("alt=''", $html);
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

    public function testRelativeDiagramIsRecordedWithItsResolvedId()
    {
        $media = $this->mediaUsedOn('wiki:some:page', '{{drawio>tracked}}');

        $this->assertArrayHasKey('wiki:some:tracked.png', $media);
    }

    public function testEmptyMediaFileFallsBackToPlaceholder()
    {
        // issue #66: an empty diagram was saved, leaving a zero-byte file
        $this->createMedia('test:blank.png', '');

        $html = $this->render('{{drawio>test:blank}}');

        $this->assertStringContainsString('blank-image.png', $html);
        $this->assertStringContainsString('onclick', $html);
    }

    /**
     * ODT export (issue #7) is provided by a third-party "odt" plugin
     * (https://www.dokuwiki.org/plugin:odt) that most installs do not have.
     * DokuWiki core (p_get_renderer()) simply returns no renderer for a mode
     * nobody provides, so p_render('odt', ...) must come back null - not throw,
     * not warn - proving the feature is a no-op, not a crash, when the odt
     * plugin isn't installed. This is the one part of "odt support" that is
     * honestly testable without actually having that plugin in the test image.
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

    /**
     * Build the $data render() expects for a given drawio tag, exactly as
     * DokuWiki's parser would via handle() - so these tests exercise the same
     * parsing the xhtml tests above go through, just feeding the result into
     * render('odt', ...) directly instead of p_render(), since p_render() can't
     * reach an 'odt' renderer that isn't installed (see the noop test above).
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

    public function testOdtModeSkipsAMissingDiagramInsteadOfExportingThePlaceholder()
    {
        $renderer = new drawio_test_fake_odt_renderer();

        $ok = $this->renderOdt('{{drawio>test:missing}}', $renderer);

        $this->assertTrue($ok, 'a missing diagram must not fail the export');
        $this->assertCount(0, $renderer->addImageCalls);
    }

    public function testOdtModeSkipsAnEmptyZeroByteDiagram()
    {
        // issue #66, same rule as the xhtml placeholder fallback above
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

/**
 * Stands in for the third-party odt plugin's renderer_plugin_odt_page, whose
 * _odtAddImage($src, $width, $height, $align, $title, $style, $returnonly)
 * takes $src as a filesystem path (mediaFN(), not a media id or URL) - see
 * https://github.com/LarsGit223/dokuwiki-plugin-odt ODT/ODTImage.php. Records
 * calls instead of building real ODT XML so these tests don't depend on that
 * plugin being installed.
 */
class drawio_test_fake_odt_renderer extends Doku_Renderer
{
    public $addImageCalls = [];

    public function getFormat()
    {
        return 'odt';
    }

    public function _odtAddImage($src, $width = null, $height = null, $align = null, $title = null, $style = null, $returnonly = false)
    {
        $this->addImageCalls[] = compact('src', 'width', 'height', 'align', 'title');
    }
}
