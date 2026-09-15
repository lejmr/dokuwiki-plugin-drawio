<?php

/**
 * Unit tests for helper_plugin_drawio's id mapping and its extractPngXml()/
 * extractSvgXml()/isDiagramXml() readers - the read half of what
 * extra/attic-format.test.php's embedPngXml()/embedSvgXml() write.
 *
 * @group plugin_drawio
 * @group plugins
 */
class helper_plugin_drawio_extract_test extends DokuWikiTest
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

    /**
     * The source id is derived from the diagram id on the server, never taken
     * from the request - so this mapping is the only place a .drawio name is
     * ever produced, and it has to be total: every id it is given either maps
     * to exactly one sibling source, or to nothing at all.
     */
    public function testSourceIdIsTheSiblingOfTheDiagram()
    {
        $this->assertSame('ns:plan.drawio', $this->helper->sourceID('ns:plan.png'));
        $this->assertSame('ns:plan.drawio', $this->helper->sourceID('ns:plan.svg'));
        $this->assertSame('plan.drawio', $this->helper->sourceID('plan.png'));
        $this->assertSame('a:b:c.drawio', $this->helper->sourceID('a:b:c.PNG'));
    }

    /** Anything that is not a diagram has no source - including a source itself. */
    public function testSourceIdRefusesEverythingElse()
    {
        $this->assertSame('', $this->helper->sourceID('ns:plan.php'));
        $this->assertSame('', $this->helper->sourceID('ns:plan.drawio'));
        $this->assertSame('', $this->helper->sourceID('ns:plan'));
        $this->assertSame('', $this->helper->sourceID(''));
    }

    /**
     * The single home for the ACL path governing a diagram's media file -
     * syntax.php and action.php both call this now instead of each
     * inlining ltrim(getNS($media_id) . ':*', ':') themselves.
     */
    public function testMediaAclPathIsTheNamespaceWildcard()
    {
        $this->assertSame('ns:*', $this->helper->mediaAclPath('ns:plan.png'));
        $this->assertSame('a:b:*', $this->helper->mediaAclPath('a:b:c.svg'));
        $this->assertSame('*', $this->helper->mediaAclPath('plan.png'));
    }

    /**
     * This is the single home for "is this a diagram" - action.php's save
     * gate now asks it directly instead of re-answering the question itself.
     */
    public function testIsDiagramExtension()
    {
        $this->assertTrue($this->helper->isDiagramExtension('ns:plan.png'));
        $this->assertTrue($this->helper->isDiagramExtension('ns:plan.svg'));
        $this->assertTrue($this->helper->isDiagramExtension('ns:plan.PNG'));
        $this->assertTrue($this->helper->isDiagramExtension('ns:plan.SVG'));
        $this->assertFalse($this->helper->isDiagramExtension('ns:plan.php'));
        $this->assertFalse($this->helper->isDiagramExtension('ns:plan.drawio'));
        $this->assertFalse($this->helper->isDiagramExtension('ns:plan'));
        $this->assertFalse($this->helper->isDiagramExtension(''));
    }

    /**
     * Build a PNG carrying a draw.io tEXt chunk, the way the editor's xmlpng
     * export does: keyword "mxfile", value URL-encoded.
     */
    protected function pngWithChunk($keyword, $value, $type = 'tEXt')
    {
        $png = base64_decode(
            'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk'
            . 'YPhfDwAChwGA60e6kgAAAABJRU5ErkJggg=='
        );
        $data = $keyword . "\0" . $value;
        $chunk = pack('N', strlen($data)) . $type . $data . pack('N', crc32($type . $data));
        // insert right after the 8 byte signature + IHDR chunk
        $ihdrLen = unpack('N', substr($png, 8, 4))[1];
        $at = 8 + 12 + $ihdrLen;
        return substr($png, 0, $at) . $chunk . substr($png, $at);
    }

    public function testExtractsTheXmlFromAPngTextChunk()
    {
        $xml = '<mxfile host="embed"><diagram id="x">zipped</diagram></mxfile>';
        $png = $this->pngWithChunk('mxfile', rawurlencode($xml));
        $this->assertSame($xml, $this->helper->extractPngXml($png));
    }

    /** Older exports store the XML unencoded - both shapes are in the wild. */
    public function testExtractsAnUnencodedPngTextChunk()
    {
        $xml = '<mxGraphModel dx="1"><root/></mxGraphModel>';
        $png = $this->pngWithChunk('mxGraphModel', $xml);
        $this->assertSame($xml, $this->helper->extractPngXml($png));
    }

    public function testExtractReturnsNothingForAPngWithoutADiagram()
    {
        $png = $this->pngWithChunk('Comment', 'nothing to see here');
        $this->assertSame('', $this->helper->extractPngXml($png));
    }

    /** Untrusted bytes: a truncated/garbage file must return nothing, not warn or loop. */
    public function testExtractSurvivesGarbage()
    {
        $this->assertSame('', $this->helper->extractPngXml(''));
        $this->assertSame('', $this->helper->extractPngXml('not a png at all'));
        $png = $this->pngWithChunk('mxfile', rawurlencode('<mxfile/>'));
        $this->assertSame('', $this->helper->extractPngXml(substr($png, 0, 30)));
        // a chunk claiming to be longer than the file is
        $bogus = substr($png, 0, 8) . pack('N', 0x7ffffff0) . 'tEXt' . 'xx';
        $this->assertSame('', $this->helper->extractPngXml($bogus));
    }

    public function testExtractsTheXmlFromAnSvgContentAttribute()
    {
        $xml = '<mxfile host="embed"><diagram>a &amp; b</diagram></mxfile>';
        $svg = '<?xml version="1.0"?>' . "\n"
            . '<svg xmlns="http://www.w3.org/2000/svg" width="1" height="1" '
            . 'content="' . htmlspecialchars($xml, ENT_QUOTES) . '"><rect/></svg>';
        $this->assertSame($xml, $this->helper->extractSvgXml($svg));
    }

    public function testExtractSvgReturnsNothingWithoutAContentAttribute()
    {
        $this->assertSame('', $this->helper->extractSvgXml(
            '<svg xmlns="http://www.w3.org/2000/svg"><rect/></svg>'
        ));
        $this->assertSame('', $this->helper->extractSvgXml('not xml'));
    }

    /**
     * A "content" attribute somewhere deep inside the drawing is not the
     * document's source - only the one on the root <svg> element is.
     */
    public function testExtractSvgIgnoresAContentAttributeOnAChildElement()
    {
        $svg = '<svg xmlns="http://www.w3.org/2000/svg"><foreignObject content="&lt;mxfile/&gt;"/></svg>';
        $this->assertSame('', $this->helper->extractSvgXml($svg));
    }

    /**
     * The same check action.php applies before it writes a .drawio file, kept
     * in one place so the write path and the migration path cannot disagree
     * about what a diagram source is.
     */
    public function testDiagramXmlRecognisesWhatTheEditorProduces()
    {
        $this->assertTrue($this->helper->isDiagramXml('<mxfile><diagram/></mxfile>'));
        $this->assertTrue($this->helper->isDiagramXml("\n  <mxGraphModel dx=\"1\"/>"));
        $this->assertFalse($this->helper->isDiagramXml(''));
        $this->assertFalse($this->helper->isDiagramXml('<html><body>nope</body></html>'));
        $this->assertFalse($this->helper->isDiagramXml('<?php echo 1;'));
    }

    /**
     * A payload starting with an XML declaration, a UTF-8 BOM, or a leading
     * comment must not be refused - a refused save is a user losing work, not
     * a caught attack. The old regex anchored straight on
     * '<(mxfile|mxGraphModel)' and rejected all three, even though the SVG
     * check three lines away in action.php already tolerated a prolog.
     *
     * Verified against real, unmodified files: ngxs/store's
     * docs/assets/actions-fsm.drawio (MIT, "<?xml version=\"1.0\"?>" then
     * <mxfile ...>) and valueflows/valueflows's
     * mkdocs/docs/assets/process-layer.xml (CC-BY-SA, "<?xml version=\"1.0\"
     * encoding=\"UTF-8\"?>" then <mxfile ...>) - a standalone .drawio/.xml
     * export routinely carries this prolog, this is not a hand-picked edge
     * case.
     */
    public function testDiagramXmlToleratesAProlog()
    {
        $this->assertTrue($this->helper->isDiagramXml(
            "<?xml version=\"1.0\"?>\n<mxfile host=\"www.draw.io\"><diagram/></mxfile>"
        ), 'a bare XML declaration must not sink a real export');
        $this->assertTrue($this->helper->isDiagramXml(
            "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n<mxfile compressed=\"false\"><diagram/></mxfile>"
        ));
    }

    public function testDiagramXmlToleratesABom()
    {
        $this->assertTrue($this->helper->isDiagramXml("\xEF\xBB\xBF<mxfile><diagram/></mxfile>"));
    }

    public function testDiagramXmlToleratesALeadingComment()
    {
        $this->assertTrue($this->helper->isDiagramXml(
            "<!-- Do not edit this file with editors other than draw.io -->\n<mxfile><diagram/></mxfile>"
        ));
        // the shape a real draw.io export actually uses: declaration, then comment
        $this->assertTrue($this->helper->isDiagramXml(
            "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n"
            . "<!-- Do not edit this file with editors other than draw.io -->\n"
            . "<mxfile><diagram/></mxfile>"
        ));
    }

    /** The looser check must still reject what is genuinely not a diagram. */
    public function testDiagramXmlWithAPrologStillRejectsNonDiagrams()
    {
        $this->assertFalse($this->helper->isDiagramXml('<?xml version="1.0"?><html><body>nope</body></html>'));
        $this->assertFalse($this->helper->isDiagramXml("\xEF\xBB\xBF<html></html>"));
    }

    // --- extraction against genuine draw.io output, not hand-built fixtures ----
    //
    // extractPngXml()/extractSvgXml() exist for the bulk-migration task that
    // comes next, so a synthetic chunk built by pngWithChunk() above proves
    // the parser logic but nothing about whether real exports actually look
    // like that. These run the same code against unmodified bytes taken from
    // real repositories.

    /**
     * A genuine PNG export with an uncompressed tEXt chunk, taken verbatim
     * from boa-dev/boa's docs/img/boa_architecture.drawio.png (MIT).
     */
    public function testExtractsTheXmlFromARealPngExport()
    {
        $png = file_get_contents(__DIR__ . '/../real-drawio-export.png');
        $xml = $this->helper->extractPngXml($png);
        $this->assertNotSame('', $xml, 'a genuine export must yield its xml');
        $this->assertStringStartsWith('<mxfile', $xml);
        $this->assertTrue($this->helper->isDiagramXml($xml));
    }

    /**
     * A genuine PNG export whose zTXt chunk is NOT spec-compliant: the PNG
     * spec says zTXt's value is zlib-wrapped deflate (what gzuncompress()
     * expects), but this real file - itzg/mc-router's
     * docs/example-deployment.drawio.png (MIT) - contains raw deflate with no
     * zlib header/checksum. Before this fix extractPngXml() silently returned
     * '' for it: gzuncompress() failed, nothing else was tried, and a real
     * exported diagram was simply unrecoverable.
     */
    public function testExtractsTheXmlFromARealPngExportWithNonCompliantZtxt()
    {
        $png = file_get_contents(__DIR__ . '/../real-drawio-export-ztxt.png');
        $xml = $this->helper->extractPngXml($png);
        $this->assertNotSame('', $xml, 'a real, if non-compliant, zTXt chunk must still yield its xml');
        $this->assertStringStartsWith('<mxfile', $xml);
        $this->assertTrue($this->helper->isDiagramXml($xml));
    }

    /**
     * The same real SVG export action.test.php uses to prove the save-path
     * validator accepts real output - here proving the extractor reads its
     * own content= attribute back out correctly.
     */
    public function testExtractsTheXmlFromARealSvgExport()
    {
        $svg = file_get_contents(__DIR__ . '/../real-drawio-export.svg');
        $xml = $this->helper->extractSvgXml($svg);
        $this->assertNotSame('', $xml);
        $this->assertStringStartsWith('<mxfile', $xml);
        $this->assertTrue($this->helper->isDiagramXml($xml));
    }
}
