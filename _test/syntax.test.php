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

    public function testEmptyMediaFileFallsBackToPlaceholder()
    {
        // issue #66: an empty diagram was saved, leaving a zero-byte file
        $this->createMedia('test:blank.png', '');

        $html = $this->render('{{drawio>test:blank}}');

        $this->assertStringContainsString('blank-image.png', $html);
        $this->assertStringContainsString('onclick', $html);
    }
}
