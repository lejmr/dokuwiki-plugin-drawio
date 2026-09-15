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

    /**
     * Same as render(), but as if the page were viewed at $dateAt - core's
     * own $DATE_AT/"view page at date" ($conf['date_at_format'], inc/
     * actions.php's ACTION_SHOW) - which p_render() only sets Doku_Renderer::
     * $date_at from when its own 4th argument is given.
     */
    protected function renderAtDate($text, $dateAt, $id = 'start')
    {
        global $ID, $INPUT;
        $ID = $id;
        $_REQUEST['id'] = $id;
        $INPUT = new \dokuwiki\Input\Input();

        return p_render('xhtml', p_get_instructions($text), $info, $dateAt);
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

    /**
     * issue #62: a diagram saved empty by draw.io is a valid, invisible
     * 1x1px PNG - min-width/min-height give even that image a clickable
     * area, unconditionally (edit_button off, the default, is exercised
     * here since that is what every existing wiki already has).
     */
    public function testDiagramImageHasAMinimumClickableSize()
    {
        $this->createMedia('test:present.png');

        $html = $this->render('{{drawio>test:present}}');

        $this->assertStringContainsString('min-width:24px', $html);
        $this->assertStringContainsString('min-height:24px', $html);
    }

    /**
     * issue #62: with edit_button on, an explicit "Edit with draw.io" button
     * renders under the image - a click target that survives even a
     * diagram whose rendering is otherwise unclickable.
     */
    public function testEditButtonRendersUnderTheImageWhenEnabled()
    {
        $this->setConf('edit_button', 1);
        $this->createMedia('test:present.png');

        $html = $this->render('{{drawio>test:present}}');

        $this->assertStringContainsString('<button', $html);
        $this->assertStringContainsString("data-image-id='test:present.png'", $html);
        $this->assertStringContainsString('drawioEditButtonClick(this)', $html);
        $this->assertStringContainsString('Edit with draw.io', $html);
    }

    /**
     * Archives an old media revision the same two ways core itself keeps
     * one: a copy of its bytes under media_attic/, and a line in its own
     * .changes file (mediaMetaFN($id, '.changes')) - the one
     * MediaChangeLog::getLastRevisionAt() actually reads. Deliberately not
     * media_saveOldRevision(): its own getRevisionInfo() consistency check
     * (comparing the live file's mtime against an empty changelog) treats a
     * first-ever archive as "nothing to log" on some DokuWiki versions and
     * skips writing the changelog line entirely - real diagrams never hit
     * that gap because core's own upload path always logs *every* save, but
     * a test faking one old revision by hand does. Writing both pieces
     * directly is what a real second save actually leaves behind.
     */
    protected function archiveOldMediaRevision($mediaId, $bytes, $atTime)
    {
        global $INPUT;
        $INPUT = new \dokuwiki\Input\Input(); // addMediaLogEntry() reads it

        $atticFile = mediaFN($mediaId, $atTime);
        io_makeFileDir($atticFile);
        file_put_contents($atticFile, $bytes);
        addMediaLogEntry($atTime, $mediaId, DOKU_CHANGE_TYPE_CREATE, '', '', null, strlen($bytes));
    }

    /**
     * $DATE_AT ("view page at date"): a diagram embedded in a page viewed at
     * an older revision must point at the media revision that was current
     * at that date, exactly like core's own {{image.png}} already does -
     * only the URL changes, no server-side existence check is added.
     */
    public function testDiagramAtAnOlderPageRevisionPointsAtTheMatchingMediaRevision()
    {
        $mediaId = 'test:revved.png';
        $oldMtime = time() - 120;
        $this->archiveOldMediaRevision($mediaId, 'first-version', $oldMtime);

        // the "current" version, saved after the date we'll view at
        $this->createMedia($mediaId, 'second-version');

        $dateAt = time() - 60; // between the archived revision and now

        $html = $this->renderAtDate('{{drawio>test:revved}}', $dateAt);

        $this->assertStringContainsString('rev='.$oldMtime, $html);
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
