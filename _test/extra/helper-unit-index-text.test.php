<?php

/**
 * Unit tests for helper_plugin_drawio::diagramIndexText() - the text the
 * search index gets out of a diagram's stored XML. draw.io stores each
 * <diagram> element's content either as plain XML or as
 * base64(deflate_raw(encodeURIComponent(xml))) - both forms appear in real
 * exports - and a label's text arrives HTML-escaped and, for anything past a
 * single line, carrying real markup.
 *
 * @group plugin_drawio
 * @group plugins
 */
class helper_plugin_drawio_index_text_test extends DokuWikiTest
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

    /** Build a one-page .drawio file with a single labelled cell, uncompressed. */
    protected function plainDrawio($label = 'Firewall')
    {
        return '<mxfile host="embed"><diagram id="p1" name="Page-1">'
            . '<mxGraphModel><root><mxCell id="0"/><mxCell id="1" parent="0"/>'
            . '<mxCell id="2" value="' . htmlspecialchars($label, ENT_QUOTES) . '" vertex="1" parent="1">'
            . '<mxGeometry/></mxCell></root></mxGraphModel>'
            . '</diagram></mxfile>';
    }

    /** The same, but with the <diagram> content compressed the way a real export does it. */
    protected function compressedDrawio($label = 'Firewall')
    {
        $inner = '<mxGraphModel><root><mxCell id="0"/><mxCell id="1" parent="0"/>'
            . '<mxCell id="2" value="' . htmlspecialchars($label, ENT_QUOTES) . '" vertex="1" parent="1">'
            . '<mxGeometry/></mxCell></root></mxGraphModel>';
        $payload = base64_encode(gzdeflate(rawurlencode($inner), 9));
        return '<mxfile host="embed"><diagram id="p1" name="Page-1">' . $payload . '</diagram></mxfile>';
    }

    public function testDiagramIndexTextExtractsAPlainLabel()
    {
        $this->assertSame('Firewall', $this->helper->diagramIndexText($this->plainDrawio('Firewall')));
    }

    /**
     * The compressed form is the common one in the wild (see this file's own
     * real-drawio-export.png fixture) - base64, then raw deflate (gzinflate(),
     * not gzuncompress() - no zlib header), then URL-decoded.
     */
    public function testDiagramIndexTextExtractsACompressedLabel()
    {
        $this->assertSame('Firewall', $this->helper->diagramIndexText($this->compressedDrawio('Firewall')));
    }

    /**
     * A multi-line label is HTML-escaped markup, not plain text - draw.io
     * itself produces "&lt;div&gt;JavaScript&lt;/div&gt;&lt;div&gt;Source
     * Code&lt;br&gt;&lt;/div&gt;" for a two-line label (verified against this
     * branch's real-drawio-export.png fixture). The tags must be stripped and
     * the entities decoded, so what lands in the index is words, not markup.
     */
    public function testDiagramIndexTextStripsMarkupAndDecodesEntities()
    {
        $xml = '<mxfile><diagram>'
            . '<mxGraphModel><root><mxCell id="0"/><mxCell id="1" parent="0"/>'
            . '<mxCell id="2" value="&lt;div&gt;JavaScript&lt;/div&gt;&lt;div&gt;Source Code&lt;br&gt;&lt;/div&gt;" vertex="1" parent="1"/>'
            . '</root></mxGraphModel></diagram></mxfile>';
        $text = $this->helper->diagramIndexText($xml);
        $this->assertStringNotContainsString('<div>', $text);
        $this->assertStringContainsString('JavaScript', $text);
        $this->assertStringContainsString('Source Code', $text);
    }

    /** A diagram can have more than one page; every page's labels count. */
    public function testDiagramIndexTextCollectsEveryPage()
    {
        $xml = '<mxfile>'
            . '<diagram id="p1"><mxGraphModel><root><mxCell value="Alpha" vertex="1"/></root></mxGraphModel></diagram>'
            . '<diagram id="p2"><mxGraphModel><root><mxCell value="Bravo" vertex="1"/></root></mxGraphModel></diagram>'
            . '</mxfile>';
        $text = $this->helper->diagramIndexText($xml);
        $this->assertStringContainsString('Alpha', $text);
        $this->assertStringContainsString('Bravo', $text);
    }

    public function testDiagramIndexTextReturnsEmptyForNonDiagramXml()
    {
        $this->assertSame('', $this->helper->diagramIndexText('<html><body>nope</body></html>'));
        $this->assertSame('', $this->helper->diagramIndexText(''));
    }

    /**
     * The bound: a huge diagram must not make one page's indexed text
     * unbounded. Verified with a genuinely huge label (well past the cap),
     * not just an assertion on a made-up small number.
     */
    public function testDiagramIndexTextIsCapped()
    {
        $huge = str_repeat('word ', 10000); // 50000 chars of real words
        $text = $this->helper->diagramIndexText($this->plainDrawio($huge));
        $this->assertLessThanOrEqual(20000, strlen($text));
    }

    /**
     * A crafted deflate stream that expands to far more than the cap must
     * not be inflated in full before being discarded - that is a memory
     * exhaustion vector for a .drawio placed directly in data/media (the
     * 2 MiB save-time cap in action.php only bounds what this plugin itself
     * writes). A few KB of repeated bytes compress to a tiny stream but
     * inflate to many megabytes; this must return quickly and safely rather
     * than pull all of it into memory.
     */
    public function testDiagramIndexTextSurvivesADecompressionBomb()
    {
        $bomb = base64_encode(gzdeflate(str_repeat('A', 6 * 1024 * 1024), 9));
        $xml = '<mxfile><diagram id="p1">' . $bomb . '</diagram></mxfile>';
        $text = @$this->helper->diagramIndexText($xml);
        $this->assertSame('', $text, 'an oversized decompression must be discarded, not indexed');
    }

    /** Genuine draw.io output, not a hand-built fixture - proves the real shape parses. */
    public function testDiagramIndexTextExtractsFromARealExport()
    {
        $png = file_get_contents(__DIR__ . '/../real-drawio-export.png');
        $xml = $this->helper->extractPngXml($png);
        $text = $this->helper->diagramIndexText($xml);
        $this->assertNotSame('', $text, 'a real export must yield indexable text');
    }

    /**
     * The defect this class of test is guarding against: a diagram whose
     * shapes carry separate one-word labels was indexed as a single
     * concatenated token ("kozakmilos"), so searching for either real word
     * found nothing. Two independent mxCell values, each their own <div>
     * line, exactly like the two-line labels in real-drawio-export.png (see
     * the test below) - only shorter, so the individual words are asserted
     * directly rather than needing a substring search through prose.
     *
     * Every assertion here is "is this exact word present", never "does the
     * whole blob equal this string" - a test that pins the full concatenated
     * output would have passed with the concatenation bug still in place.
     */
    public function testDiagramIndexTextKeepsSeparateLabelsAsSeparateWords()
    {
        $xml = '<mxfile host="embed"><diagram id="p1" name="Page-1">'
            . '<mxGraphModel><root><mxCell id="0"/><mxCell id="1" parent="0"/>'
            . '<mxCell id="2" value="kozak" style="text;html=1;" vertex="1" parent="1"><mxGeometry/></mxCell>'
            . '<mxCell id="3" value="milos" style="text;html=1;" vertex="1" parent="1"><mxGeometry/></mxCell>'
            . '</root></mxGraphModel></diagram></mxfile>';
        $text = $this->helper->diagramIndexText($xml);

        $words = preg_split('/\s+/', trim($text));
        $this->assertContains('kozak', $words, 'each shape\'s label must be its own findable word');
        $this->assertContains('milos', $words, 'each shape\'s label must be its own findable word');
        $this->assertStringNotContainsString('kozakmilos', $text);
        $this->assertStringNotContainsString('miloskozak', $text);
    }

    /**
     * A real draw.io export's actual shape for a multi-line HTML label,
     * taken straight from this branch's real-drawio-export.png fixture (see
     * this class's own extractPngXml() tests for how the source is pulled
     * out of the PNG): "<div>Bytecode</div><div>Compiler<br></div>" for one
     * shape's two-line label. Stripping the markup without turning the
     * line-break tags into a separator collapses the two lines into one
     * unsearchable token, "BytecodeCompiler" - the exact shape of the
     * concatenation defect, just produced by one shape's own rich text
     * rather than by two neighbouring shapes.
     */
    public function testDiagramIndexTextSeparatesDivAndBrLinesWithinOneLabel()
    {
        $xml = '<mxfile><diagram>'
            . '<mxGraphModel><root><mxCell id="0"/><mxCell id="1" parent="0"/>'
            . '<mxCell id="2" value="&lt;div&gt;Bytecode&lt;/div&gt;&lt;div&gt;Compiler&lt;br&gt;&lt;/div&gt;" vertex="1" parent="1"/>'
            . '</root></mxGraphModel></diagram></mxfile>';
        $text = $this->helper->diagramIndexText($xml);

        $words = preg_split('/\s+/', trim($text));
        $this->assertContains('Bytecode', $words);
        $this->assertContains('Compiler', $words);
        $this->assertStringNotContainsString('BytecodeCompiler', $text);
    }

    /**
     * The neighbouring risk of the fix above: an *inline* tag styling part
     * of a word ("<b>mc</b>.your.domain", verified against this branch's own
     * real-drawio-export-ztxt.png fixture) must not be treated the same way
     * as a line break - doing so would split one real word into two
     * ("mc" / ".your.domain"), so a search for the whole address would miss
     * the page that a search for "mc.your.domain" was supposed to find.
     */
    public function testDiagramIndexTextDoesNotSplitAWordOverAnInlineTag()
    {
        $xml = '<mxfile><diagram>'
            . '<mxGraphModel><root><mxCell id="0"/><mxCell id="1" parent="0"/>'
            . '<mxCell id="2" value="&lt;b&gt;mc&lt;/b&gt;.your.domain" vertex="1" parent="1"/>'
            . '</root></mxGraphModel></diagram></mxfile>';
        $text = $this->helper->diagramIndexText($xml);

        $this->assertStringContainsString('mc.your.domain', $text);
    }

    /**
     * A shape styled html=1 has its value interpreted as HTML by draw.io
     * itself, so its stored XML attribute carries an entity that itself
     * belongs to that inner HTML - value="API:&amp;nbsp;socket()" for a
     * label whose actual text is "API:" followed by a non-breaking space
     * (this is exactly what real-drawio-export.svg's own value attribute
     * looks like once extractSvgXml() has already undone that fixture's own
     * outer, SVG-attribute level of escaping - see
     * testDiagramIndexTextDecodesEntitiesFromARealHtmlLabel() below for the
     * full real fixture, escaping and all). A single decode pass here would
     * leave a literal, unreadable "&nbsp;" in the index instead of the
     * character a reader would expect.
     */
    public function testDiagramIndexTextDecodesEntitiesFromWithinAnHtmlLabel()
    {
        $xml = '<mxfile><diagram>'
            . '<mxGraphModel><root><mxCell id="0"/><mxCell id="1" parent="0"/>'
            . '<mxCell id="2" value="API:&amp;nbsp;socket()" style="html=1;" vertex="1" parent="1"/>'
            . '</root></mxGraphModel></diagram></mxfile>';
        $text = $this->helper->diagramIndexText($xml);

        $this->assertStringNotContainsString('nbsp', $text);
        $this->assertStringContainsString('socket()', $text);
    }

    /**
     * Genuine draw.io output, not a hand-built fixture: real-drawio-export.svg's
     * content= attribute doubly escapes "&nbsp;" - once for XML-attribute
     * storage, on top of the diagram's own HTML escaping of the shape's rich
     * text - "API:&amp;amp;nbsp;socket()" in the raw SVG file. Proves the
     * whole real pipeline (extractSvgXml() then diagramIndexText()) leaves
     * no entity behind, not just the synthetic case above.
     */
    public function testDiagramIndexTextDecodesEntitiesFromARealHtmlLabel()
    {
        $svg = file_get_contents(__DIR__ . '/../real-drawio-export.svg');
        $xml = $this->helper->extractSvgXml($svg);
        $text = $this->helper->diagramIndexText($xml);

        $this->assertStringNotContainsString('nbsp', $text);
        $this->assertStringContainsString('socket()', $text);
    }

    /**
     * Genuine draw.io output with more than one shape, not a hand-built
     * fixture: real-drawio-export.png's diagram carries several two-line
     * labels ("Bytecode" / "Compiler", "Bytecode" / "Interpreter",
     * "Resulting" / "JsValue" among them - see extractPngXml()'s own test
     * for how the source is pulled out of the image). Every one of those
     * words must be findable on its own; none of the neighbouring pairs may
     * have collapsed into a single concatenated token.
     */
    public function testDiagramIndexTextFromARealExportKeepsEachLabelSeparate()
    {
        $png = file_get_contents(__DIR__ . '/../real-drawio-export.png');
        $xml = $this->helper->extractPngXml($png);
        $text = $this->helper->diagramIndexText($xml);

        $words = preg_split('/\s+/', trim($text));
        foreach (['Bytecode', 'Compiler', 'Interpreter', 'Resulting', 'JsValue'] as $word) {
            $this->assertContains($word, $words, "'$word' must be its own indexed word");
        }
        foreach (['BytecodeCompiler', 'BytecodeInterpreter', 'ResultingJsValue'] as $collapsed) {
            $this->assertStringNotContainsString($collapsed, $text);
        }
    }
}
