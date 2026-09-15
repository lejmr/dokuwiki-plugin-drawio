<?php

/**
 * Tests for helper::embedPngXml()/embedSvgXml() - the write half of what
 * helper::extractPngXml()/extractSvgXml() already read (extra/helper-unit-
 * extract.test.php). "attic" because that is the one place these two
 * methods are used from: action.php's _embed_source_into_attic() folds a
 * diagram's .drawio source into the attic copy of its image when the last
 * rendering is deleted - see extra/data-delta.test.php's
 * testDeletingTheLastRenderingArchivesTheSourceEmbeddedInTheImage() and its
 * neighbours for the end-to-end behaviour this only unit-tests in isolation.
 *
 * Every round trip here goes back through the plugin's own
 * extractPngXml()/extractSvgXml() - the same bar action.php's real callers
 * have to clear - and, wherever this branch has a genuine draw.io export to
 * test against (real-drawio-export.png, real-drawio-export-ztxt.png,
 * real-drawio-export.svg), the assertions run against that fixture, not a
 * hand-built one: a hand-built PNG/SVG proves the round trip works, not
 * that the written shape is anything draw.io's own editor would recognise.
 *
 * @group plugin_drawio
 * @group plugins
 */
class helper_plugin_drawio_attic_format_test extends DokuWikiTest
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

    protected function pngBytes()
    {
        return base64_decode(
            'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk'
            . 'YPhfDwAChwGA60e6kgAAAABJRU5ErkJggg=='
        );
    }

    // --- embedPngXml() -----------------------------------------------

    /**
     * The minimal, no-text-chunk-yet case: a fresh chunk keyed 'mxfile' is
     * inserted, and the plugin's own extractor reads exactly what was
     * embedded straight back out.
     */
    public function testEmbedPngXmlRoundTripsThroughTheOwnExtractorOnAPlainPng()
    {
        $xml = '<mxfile><diagram><mxGraphModel><root/></mxGraphModel></diagram></mxfile>';
        $embedded = $this->helper->embedPngXml($this->pngBytes(), $xml);

        $this->assertNotSame('', $embedded, 'a well-formed PNG must always embed');
        $this->assertSame($xml, $this->helper->extractPngXml($embedded));
    }

    /**
     * The chunk this writes has to be one a strict PNG reader (draw.io
     * itself included) accepts, not just one this plugin's own lenient
     * extractor happens to tolerate - so the CRC is checked independently
     * here with PHP's own crc32(), the same algorithm the PNG spec itself
     * specifies (ISO 3309 / ITU-T V.42, identical to zip/gzip's), rather
     * than trusting extractPngXml() (which never validates a chunk's CRC at
     * all) to catch a wrong one.
     */
    public function testEmbedPngXmlWritesAChunkWithACorrectCrc()
    {
        $embedded = $this->helper->embedPngXml($this->pngBytes(), '<mxfile><diagram/></mxfile>');

        // Walk to the tEXt chunk by hand (IHDR is always chunk #1, 25 bytes:
        // 8 sig + 4 len + 4 type + 13 data, so the next chunk starts at 33).
        $pos = 8 + 4 + 4 + 13 + 4;
        $this->assertSame('tEXt', substr($embedded, $pos + 4, 4), 'must sit directly after IHDR, matching a real export');
        $size = unpack('N', substr($embedded, $pos, 4))[1];
        $data = substr($embedded, $pos + 8, $size);
        $storedCrc = unpack('N', substr($embedded, $pos + 8 + $size, 4))[1];
        $this->assertSame(crc32('tEXt' . $data), $storedCrc);
    }

    /**
     * A real draw.io export, its own tEXt chunk replaced with a new one -
     * the round trip proves this plugin's writer and its own reader agree,
     * against the exact chunk layout (IHDR, tEXt, IDAT..., IEND) a genuine
     * export has, not a synthetic stand-in for it.
     */
    public function testEmbedPngXmlReplacesAnExistingTextChunkOnARealExport()
    {
        $original = file_get_contents(__DIR__ . '/../real-drawio-export.png');
        $this->assertNotSame('', $this->helper->extractPngXml($original), 'sanity: the fixture must already carry embedded XML');

        $newXml = '<mxfile><diagram>replaced</diagram></mxfile>';
        $embedded = $this->helper->embedPngXml($original, $newXml);

        $this->assertNotSame('', $embedded);
        $this->assertSame($newXml, $this->helper->extractPngXml($embedded), 'must read back the NEW xml, not the fixture\'s original');
        // exactly one xml-bearing chunk left - no stale copy kept alongside the new one
        $this->assertSame(1, preg_match_all('/mxfile\x00|mxGraphModel\x00/', $embedded));
    }

    /**
     * Same replacement, against the zTXt (compressed) fixture - the stale
     * chunk being compressed rather than plain must not stop it from being
     * recognised and dropped.
     */
    public function testEmbedPngXmlReplacesAnExistingCompressedTextChunkOnARealExport()
    {
        $original = file_get_contents(__DIR__ . '/../real-drawio-export-ztxt.png');
        $this->assertNotSame('', $this->helper->extractPngXml($original), 'sanity: the fixture must already carry embedded (compressed) XML');

        $newXml = '<mxfile><diagram>replaced-ztxt</diagram></mxfile>';
        $embedded = $this->helper->embedPngXml($original, $newXml);

        $this->assertNotSame('', $embedded);
        $this->assertSame($newXml, $this->helper->extractPngXml($embedded));
        $this->assertSame(1, preg_match_all('/mxfile\x00|mxGraphModel\x00/', $embedded));
    }

    public function testEmbedPngXmlRefusesAMalformedPng()
    {
        $this->assertSame('', $this->helper->embedPngXml('not a png at all', '<mxfile/>'));
        $this->assertSame('', $this->helper->embedPngXml("\x89PNG\r\n\x1a\n", '<mxfile/>'), 'truncated right after the signature');
        $this->assertSame('', $this->helper->embedPngXml("\x89PNG\r\n\x1a\n" . str_repeat('x', 20), '<mxfile/>'), 'no real IHDR chunk');
    }

    /**
     * Size bound: matches the 2 MiB cap action.php's 'save' handler already
     * enforces on the way in (see helper::MAX_EMBED_XML_BYTES's own
     * docblock) - a diagram that could never have been saved through this
     * plugin in the first place must not grow an attic entry without
     * bound. The caller (action.php's _embed_source_into_attic()) treats
     * '' as "archive the plain image, unembedded" rather than failing the
     * delete - see its own docblock.
     */
    public function testEmbedPngXmlRefusesXmlOverTheSizeCap()
    {
        $tooBig = '<mxfile>' . str_repeat('x', 2 * 1024 * 1024) . '</mxfile>';
        $this->assertGreaterThan(helper_plugin_drawio::MAX_EMBED_XML_BYTES, strlen($tooBig));
        $this->assertSame('', $this->helper->embedPngXml($this->pngBytes(), $tooBig));
    }

    // --- embedSvgXml() -----------------------------------------------

    /**
     * A real draw.io SVG export, its own content= attribute replaced - same
     * reasoning as the PNG replacement test above: proves the writer and
     * the reader agree on a genuine export's exact shape, not a stand-in.
     */
    public function testEmbedSvgXmlReplacesTheExistingContentAttributeOnARealExport()
    {
        $original = file_get_contents(__DIR__ . '/../real-drawio-export.svg');
        $this->assertNotSame('', $this->helper->extractSvgXml($original), 'sanity: the fixture must already carry embedded XML');

        $newXml = '<mxfile><diagram>replaced</diagram></mxfile>';
        $embedded = $this->helper->embedSvgXml($original, $newXml);

        $this->assertNotSame('', $embedded);
        $this->assertSame($newXml, $this->helper->extractSvgXml($embedded));
        // exactly one content= attribute on the root <svg> tag - not two.
        // The fixture's DOCTYPE (before <svg> itself) contains its own '>',
        // so the root tag has to be matched properly rather than cut at the
        // first '>' in the file.
        $this->assertSame(1, preg_match('/<svg\b[^>]*>/i', $embedded, $rootTag));
        $this->assertSame(1, preg_match_all('/\scontent\s*=/i', $rootTag[0]));
    }

    /**
     * A diagram with no content= attribute at all yet (embedPngXml()'s SVG
     * counterpart of "no text chunk yet") - the attribute is inserted, both
     * on an ordinary and on a self-closing root <svg> tag.
     */
    public function testEmbedSvgXmlInsertsTheAttributeWhenNoneExists()
    {
        $xml = '<mxfile><diagram/></mxfile>';

        $embedded = $this->helper->embedSvgXml('<svg xmlns="http://www.w3.org/2000/svg"><rect/></svg>', $xml);
        $this->assertSame($xml, $this->helper->extractSvgXml($embedded));

        $selfClosing = $this->helper->embedSvgXml('<svg xmlns="http://www.w3.org/2000/svg"/>', $xml);
        $this->assertSame($xml, $this->helper->extractSvgXml($selfClosing));
        $this->assertStringContainsString('/>', $selfClosing, 'a self-closing root tag must stay self-closing');
    }

    /**
     * A literal newline inside an XML attribute value is legal XML but gets
     * normalised to a space by any conformant parser - silently corrupting
     * a multi-line diagram's source. A real draw.io export avoids this by
     * writing '&#10;' instead (see embedSvgXml()'s own docblock, verified
     * against real-drawio-export.svg); this is the same round trip, with a
     * source deliberately built to contain real newlines, proving they
     * survive being written and read back through this plugin's own pair.
     */
    public function testEmbedSvgXmlPreservesNewlinesAsCharacterReferences()
    {
        $xml = "<mxfile>\n  <diagram>\r\n    multi-line\n  </diagram>\n</mxfile>";
        $embedded = $this->helper->embedSvgXml('<svg xmlns="http://www.w3.org/2000/svg"/>', $xml);

        $this->assertStringNotContainsString("\n", $embedded, 'no literal newline may land inside the attribute');
        $this->assertSame($xml, $this->helper->extractSvgXml($embedded));
    }

    public function testEmbedSvgXmlRefusesContentWithNoRootSvgElement()
    {
        $this->assertSame('', $this->helper->embedSvgXml('<not-an-svg/>', '<mxfile/>'));
        $this->assertSame('', $this->helper->embedSvgXml('', '<mxfile/>'));
    }

    public function testEmbedSvgXmlRefusesXmlOverTheSizeCap()
    {
        $tooBig = '<mxfile>' . str_repeat('x', 2 * 1024 * 1024) . '</mxfile>';
        $this->assertSame('', $this->helper->embedSvgXml('<svg xmlns="http://www.w3.org/2000/svg"/>', $tooBig));
    }
}
