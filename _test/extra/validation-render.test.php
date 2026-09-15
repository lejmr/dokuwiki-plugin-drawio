<?php

/**
 * Rendering edge cases for the drawio syntax plugin that aren't the happy
 * path: extension handling, empty/malformed names, output escaping, sizing
 * variants, media-manager usage recording, ACL/cache interaction. The happy
 * path itself lives in _test/golden/render.test.php.
 *
 * @group plugin_drawio
 * @group plugins
 */
require_once __DIR__ . '/../acl.inc.php';

class syntax_plugin_drawio_validation_render_test extends DokuWikiTest
{
    use drawio_acl_test_helper;

    protected $pluginsEnabled = ['drawio'];

    protected function render($text, $id = 'start')
    {
        global $ID, $INPUT;
        $ID = $id;
        $_REQUEST['id'] = $id;
        $INPUT = new \dokuwiki\Input\Input();

        return p_render('xhtml', p_get_instructions($text), $info);
    }

    /**
     * Whether p_render() came back saying its output may be cached - the
     * $renderer->info['cache'] flag nocache() clears, surfaced by p_render()
     * through its by-reference $info argument.
     */
    protected function renderIsCacheable($text, $id = 'start')
    {
        global $ID, $INPUT;
        $ID = $id;
        $_REQUEST['id'] = $id;
        $INPUT = new \dokuwiki\Input\Input();

        p_render('xhtml', p_get_instructions($text), $info);

        return (bool) $info['cache'];
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
     * The media manager lists where a file is used by reading the page's
     * metadata. Without this the diagram looks unused (#10).
     */
    protected function mediaUsedOn($id, $text)
    {
        saveWikiText($id, $text, 'drawio test');

        return (array) p_get_metadata($id, 'relation media', METADATA_RENDER_UNLIMITED);
    }

    public function testExplicitExtensionIsNotDuplicated()
    {
        $html = $this->render('{{drawio>test:explicit.png}}');

        $this->assertStringContainsString("id='test:explicit.png'", $html);
        $this->assertStringNotContainsString('.png.png', $html);
    }

    /**
     * Whether a name already carries an extension is decided against the
     * fixed png/svg pair every other write/read path uses - not the admin's
     * toolbar_possible_extension, which only names what the *toolbar* offers
     * for a new diagram. Run at both the shipped default (png only) and at
     * png,svg, since the point is that the answer does not depend on it.
     */
    public function testExplicitSvgExtensionIsNotDuplicatedAtShippedDefaultConfig()
    {
        $this->setConf('toolbar_possible_extension', 'png');

        $html = $this->render('{{drawio>test:plan.svg}}');

        $this->assertStringContainsString("id='test:plan.svg'", $html);
        $this->assertStringNotContainsString('.svg.png', $html);
    }

    public function testExplicitSvgExtensionIsNotDuplicatedWithBothExtensionsConfigured()
    {
        $this->setConf('toolbar_possible_extension', 'png,svg');

        $html = $this->render('{{drawio>test:plan.svg}}');

        $this->assertStringContainsString("id='test:plan.svg'", $html);
        $this->assertStringNotContainsString('.svg.png', $html);
    }

    public function testRelativeNameResolvesAgainstCurrentNamespace()
    {
        $html = $this->render('{{drawio>diagram}}', 'wiki:some:page');

        $this->assertStringContainsString("id='wiki:some:diagram.png'", $html);
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

    /**
     * Merges the old testWidthOnlyParameterIsApplied and
     * testWidthOnlySizeOmitsTheHeightParameter: width-only sizing applies
     * the width CSS/resize params and omits the height ones, in one test.
     */
    public function testWidthOnlyParameterIsApplied()
    {
        $this->createMedia('test:present.png');

        $html = $this->render('{{drawio>test:present?200}}');

        $this->assertStringContainsString('width:200px', $html);
        $this->assertStringNotContainsString('height:', $html);
        $this->assertStringContainsString('w=200', $html);
        $this->assertStringNotContainsString('h=', $html);
        $this->assertStringContainsString(
            'tok='.media_get_token('test:present.png', 200, 0),
            $html
        );
    }

    /**
     * Server-side resizing a vector is meaningless - fetch.php's own
     * MEDIA_RESIZE handler already skips it for image/svg+xml - so an SVG
     * still gets the size params (matching what core's own {{image.svg?200}}
     * sends via ml()), it just has no effect on what's served.
     */
    public function testSizedSvgDiagramStillCarriesSizeParamsLikeCoreDoes()
    {
        $this->createMedia('test:present.svg');

        $html = $this->render('{{drawio>test:present.svg?200}}');

        $this->assertStringContainsString('w=200', $html);
    }

    public function testUnsizedDiagramCarriesNoResizeParams()
    {
        $this->createMedia('test:present.png');

        $html = $this->render('{{drawio>test:present}}');

        $this->assertStringNotContainsString('w=', $html);
        $this->assertStringNotContainsString('tok=', $html);
    }

    public function testEmptyTitleFallsBackToMediaIdAsAlt()
    {
        $this->createMedia('test:present.png');

        $html = $this->render('{{drawio>test:present|}}');

        $this->assertStringContainsString("alt='test:present.png'", $html);
        $this->assertStringNotContainsString("alt=''", $html);
    }

    public function testRelativeDiagramIsRecordedWithItsResolvedId()
    {
        $media = $this->mediaUsedOn('wiki:some:page', '{{drawio>tracked}}');

        $this->assertArrayHasKey('wiki:some:tracked.png', $media);
    }

    public function testSvgDiagramIsRecordedAsMediaUsedOnThePage()
    {
        // metadata mode goes through the exact same extension resolution as
        // xhtml above (render() branches on $mode after the id is built), so
        // an svg name must show up in the media manager's usage list under
        // its real, un-mangled .svg id, not silently as .svg.png.
        $media = $this->mediaUsedOn('start', '{{drawio>test:tracked.svg}}');

        $this->assertArrayHasKey('test:tracked.svg', $media);
        $this->assertArrayNotHasKey('test:tracked.svg.png', $media);
    }

    /**
     * S5 / the arbiter's cache-staleness finding: this plugin must never
     * render different HTML for an allowed vs a denied viewer - render()
     * never makes that decision at all any more, fetch.php enforces the ACL
     * itself at request time.
     */
    public function testAclDoesNotChangeTheRenderedHtmlAtAll()
    {
        $this->createMedia('restricted:present.png');

        $this->enableAcl(
            ['*             @ALL   8', 'restricted:*  @ALL   0', 'restricted:*  @boss  8'],
            'admin',
            ['boss']
        );
        $allowed = $this->render('{{drawio>restricted:present}}');

        $this->enableAcl(
            ['*             @ALL   8', 'restricted:*  @ALL   0'],
            'john',
            ['user']
        );
        $denied = $this->render('{{drawio>restricted:present}}');

        $this->assertSame($allowed, $denied);
        $this->assertStringContainsString('fetch.php?media=restricted:present.png', $denied);
    }

    /**
     * nocache() is gone entirely: the <img> it emits is the same fetch.php
     * URL for every diagram and every visitor, so there is nothing
     * render-time-decided left that a shared page cache could serve stale -
     * not "the diagram was just created", and not "an ACL was just
     * tightened".
     */
    public function testRenderNeverDisablesCacheAnyMore()
    {
        $this->assertTrue($this->renderIsCacheable('{{drawio>test:missing}}'));

        $this->createMedia('test:blank.png', '');
        $this->assertTrue($this->renderIsCacheable('{{drawio>test:blank}}'));

        $this->createMedia('restricted:present.png');
        $this->enableAcl(
            ['*             @ALL   8', 'restricted:*  @ALL   0', 'restricted:*  @boss  8'],
            'admin',
            ['boss']
        );
        $this->assertTrue($this->renderIsCacheable('{{drawio>restricted:present}}'));
    }

    /**
     * The placeholder shipped with the plugin used to carry a draw.io tEXt
     * chunk from whatever diagram it was once exported from: the admin
     * conversion then offered to "recover" a source from every placeholder
     * on the wiki, and that source was a foreign diagram.
     */
    public function testPlaceholderImageCarriesNoDiagramXml()
    {
        $bytes = file_get_contents(__DIR__ . '/../../blank-image.png');
        /** @var helper_plugin_drawio $helper */
        $helper = plugin_load('helper', 'drawio');

        $this->assertSame('', $helper->extractPngXml($bytes));
        $this->assertStringNotContainsString('tEXt', $bytes);
        $this->assertStringNotContainsString('zTXt', $bytes);
        $this->assertStringNotContainsString('iTXt', $bytes);
    }
}
