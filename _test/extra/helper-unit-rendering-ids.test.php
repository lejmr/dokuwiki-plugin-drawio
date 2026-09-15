<?php

/**
 * Unit tests for helper_plugin_drawio's otherRenderingID()/renderingCandidates()
 * - the "one diagram, two renderings" identity used by action.php's
 * delete/rename cascade and by the 'resolve_source' ajax action.
 *
 * @group plugin_drawio
 * @group plugins
 */
class helper_plugin_drawio_rendering_ids_test extends DokuWikiTest
{
    protected $pluginsEnabled = ['drawio'];

    /** @var helper_plugin_drawio */
    protected $helper;

    public function setUp(): void
    {
        parent::setUp();
        $this->helper = plugin_load('helper', 'drawio');
        $this->assertInstanceOf('helper_plugin_drawio', $this->helper);
    }

    public function testOtherRenderingIdSwapsPngAndSvg()
    {
        $this->assertSame('ns:plan.svg', $this->helper->otherRenderingID('ns:plan.png'));
        $this->assertSame('ns:plan.png', $this->helper->otherRenderingID('ns:plan.svg'));
        $this->assertSame('a:b:c.svg', $this->helper->otherRenderingID('a:b:c.PNG'));
    }

    public function testOtherRenderingIdRefusesNonDiagrams()
    {
        $this->assertSame('', $this->helper->otherRenderingID('ns:plan.drawio'));
        $this->assertSame('', $this->helper->otherRenderingID('ns:plan.php'));
        $this->assertSame('', $this->helper->otherRenderingID(''));
    }

    // --- renderingCandidates() -------------------------------------------
    //
    // The reverse of sourceID(): given a .drawio source, which rendering
    // ids it could belong to - used by action.php's 'resolve_source' action
    // to open the right diagram when the media manager's "Edit with
    // draw.io" button is clicked on the source itself, not a rendering.

    public function testRenderingCandidatesListsPngBeforeSvg()
    {
        $this->assertSame(
            ['ns:plan.png', 'ns:plan.svg'],
            $this->helper->renderingCandidates('ns:plan.drawio')
        );
        $this->assertSame(
            ['a:b:c.png', 'a:b:c.svg'],
            $this->helper->renderingCandidates('a:b:c.DRAWIO')
        );
    }

    public function testRenderingCandidatesRefusesEverythingElse()
    {
        $this->assertSame([], $this->helper->renderingCandidates('ns:plan.png'));
        $this->assertSame([], $this->helper->renderingCandidates('ns:plan.svg'));
        $this->assertSame([], $this->helper->renderingCandidates('ns:plan'));
        $this->assertSame([], $this->helper->renderingCandidates(''));
    }
}
