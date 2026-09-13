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
}
