<?php

/**
 * Golden-path "what happens to the rest of the wiki when a diagram is
 * saved" tests: the embedding page's search index and its cached exports.
 * Saving a diagram touches no page directly, so these are the tests that
 * catch a save silently leaving the page it's used on stale - see
 * DEVELOPMENT.md's "Test the user's path, not the mechanism".
 *
 * @group plugin_drawio
 * @group plugins
 */

class action_plugin_drawio_golden_after_save_test extends DokuWikiTest
{
    protected $pluginsEnabled = ['drawio'];

    public function setUp(): void
    {
        parent::setUp();

        global $USERINFO;
        $USERINFO = ['grps' => []];

        if (!isset($_SESSION)) {
            $_SESSION = [];
        }
    }

    protected function ajaxPost(array $post, array $server = [])
    {
        $request = new TestRequest();
        foreach ($server as $key => $value) {
            $request->setServer($key, $value);
        }
        return @$request->post(array_merge(['call' => 'plugin_drawio'], $post), '/lib/exe/ajax.php');
    }

    protected function saveViaAjax($mediaId, $content = null, $xml = null)
    {
        if ($content === null) $content = $this->pngBytes();
        $post = [
            'action' => 'save',
            'imageName' => $mediaId,
            'content' => 'data:image/png;base64,' . base64_encode($content),
        ];
        if ($xml !== null) $post['xml'] = $xml;
        return $this->ajaxPost($post);
    }

    /** A real, minimal (1x1, transparent) PNG - the smallest thing that is actually a PNG. */
    protected function pngBytes()
    {
        return base64_decode(
            'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk'
            . 'YPhfDwAChwGA60e6kgAAAABJRU5ErkJggg=='
        );
    }

    /** The XML the editor hands to script.js on its 'save' event. */
    protected function diagramXml($marker = 'x')
    {
        return '<mxfile host="embed"><diagram id="' . $marker . '">' . $marker . '</diagram></mxfile>';
    }

    /** An mxGraphModel with several shapes, each a distinct one-word label - the shape a real diagram actually has. */
    protected function multiShapeDrawio(array $words)
    {
        $cells = '';
        foreach ($words as $i => $word) {
            $cells .= '<mxCell id="c' . $i . '" value="' . $word . '" vertex="1"/>';
        }
        return '<mxfile><diagram><mxGraphModel><root>' . $cells . '</root></mxGraphModel></diagram></mxfile>';
    }

    /**
     * The maintainer's own counter-example, end to end: a diagram with two
     * one-word labels, each searched *separately* through DokuWiki's real
     * fulltext lookup (idx_lookup(), the same call search.php makes) - not
     * just a substring check on an intermediate string. A diagram with a
     * single long, unique label cannot reproduce a labels-joined-without-a-
     * separator defect (there is nothing to join it to); two one-word labels
     * can, and do here: if "auth" and "gateway" were ever concatenated into
     * one token, neither word would be found on its own.
     */
    public function testSavingADiagramMakesEachOfItsSeveralLabelsSeparatelySearchable()
    {
        saveWikiText('multilabelpage', '{{drawio>test:multilabel.png}}', 'test init');
        plugin_load('helper', 'drawio')->reindexPage('multilabelpage');

        $xml = $this->multiShapeDrawio(['auth', 'gateway']);
        $this->saveViaAjax('test:multilabel.png', $this->pngBytes(), $xml);

        $words = ['auth', 'gateway', 'authgateway'];
        $after = idx_lookup($words);
        $this->assertArrayHasKey('multilabelpage', $after['auth'] ?? [], "'auth' must be independently searchable");
        $this->assertArrayHasKey('multilabelpage', $after['gateway'] ?? [], "'gateway' must be independently searchable");
        $this->assertArrayNotHasKey(
            'multilabelpage',
            $after['authgateway'] ?? [],
            'the two labels must never have been merged into one token'
        );
    }

    /**
     * The page's cached odt render must not survive a diagram save
     * untouched - an odt export driven from that cache would otherwise still
     * embed the picture that was cached before the save. No manual
     * cache-clearing step appears anywhere in this test - that absence is
     * the point.
     */
    public function testSavingADiagramPurgesTheEmbeddingPagesCachedOdtRender()
    {
        // The page has to actually be indexed at least once (a real wiki
        // does this the first time anyone views it) before pagesUsing() has
        // anything to answer with.
        saveWikiText('cachepage', '{{drawio>test:cached.png}}', 'test init');
        plugin_load('helper', 'drawio')->reindexPage('cachepage');

        $file = wikiFN('cachepage');
        $odtCache = new \dokuwiki\Cache\CacheRenderer('cachepage', $file, 'odt');
        $odtCache->storeCache('stale-odt-bytes-from-before-the-save');
        $xhtmlCache = new \dokuwiki\Cache\CacheRenderer('cachepage', $file, 'xhtml');
        $xhtmlCache->storeCache('<p>stale-xhtml-does-not-matter</p>');

        $this->assertFileExists($odtCache->cache, 'sanity: the odt cache exists before the save');
        $this->assertFileExists($xhtmlCache->cache, 'sanity: the xhtml cache exists before the save');

        $this->saveViaAjax('test:cached.png', $this->pngBytes(), $this->diagramXml());

        $this->assertFileDoesNotExist(
            $odtCache->cache,
            'saving the diagram must purge the odt cache - it embeds the diagram\'s bytes, so a stale copy is a stale export'
        );
        $this->assertFileExists(
            $xhtmlCache->cache,
            'the xhtml cache must survive untouched - it only ever contains a fetch.php URL, so it is still correct'
        );
    }
}
