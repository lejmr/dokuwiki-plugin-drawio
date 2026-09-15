<?php

/**
 * Edge cases around indexing a diagram's words and reindexing the pages
 * that embed it: empty/missing source, non-diagram media, ACL-restricted
 * namespaces, and reuse of the same rendering across several pages.
 *
 * @group plugin_drawio
 * @group plugins
 */
require_once __DIR__ . '/../acl.inc.php';

class action_plugin_drawio_lifecycle_edges_indexer_test extends DokuWikiTest
{
    use drawio_acl_test_helper;

    protected $pluginsEnabled = ['drawio'];

    /** @var array captured MEDIA_UPLOAD_FINISH event data, one entry per fired event */
    protected $firedEvents = [];

    /** @var array captured MEDIA_DELETE_FILE ids, one entry per fired event */
    protected $deletedMediaIds = [];

    public function setUp(): void
    {
        parent::setUp();
        $this->firedEvents = [];
        $this->deletedMediaIds = [];

        // auth_aclcheck()/auth_quickaclcheck() read this even with ACL disabled
        global $USERINFO;
        $USERINFO = ['grps' => []];

        // TestRequest::execute() reads/restores $_SESSION; nothing else starts a
        // session in this standalone test run
        if (!isset($_SESSION)) {
            $_SESSION = [];
        }

        global $EVENT_HANDLER;
        $EVENT_HANDLER->register_hook('MEDIA_UPLOAD_FINISH', 'AFTER', $this, 'captureMediaUploadFinish');
        // AFTER the plugin's own _media_delete_sibling(), which is also
        // registered AFTER - so this always sees whatever it cascaded too.
        $EVENT_HANDLER->register_hook('MEDIA_DELETE_FILE', 'AFTER', $this, 'captureMediaDeleteFile');
    }

    public function captureMediaUploadFinish(Doku_Event $event, $param)
    {
        $this->firedEvents[] = $event->data;
    }

    public function captureMediaDeleteFile(Doku_Event $event, $param)
    {
        $this->deletedMediaIds[] = $event->data['id'];
    }

    /**
     * Post one ajax request the way script.js does. The one place that builds
     * a TestRequest and posts to the plugin's endpoint - every test in this
     * suite (directly, or through the four named helpers below) goes through
     * this instead of repeating the same TestRequest()+post([...]) pair by
     * hand, which is what this file used to do at every single call site.
     *
     * $server sets request server vars (e.g. REMOTE_USER) before posting -
     * needed by the token/lock tests, which post as a particular user.
     *
     * Deprecation notices already printed to stdout during bootstrap leave PHP
     * thinking headers were sent, so TestRequest's header_remove() warns here on
     * every run - suppressed here, once, instead of at every call site, since
     * it is unrelated to whatever the test using this is actually checking.
     *
     * @param array $post   everything except 'call', which is always 'plugin_drawio'
     * @param array $server e.g. ['REMOTE_USER' => 'alice']
     * @return \TestResponse
     */
    protected function ajaxPost(array $post, array $server = [])
    {
        $request = new TestRequest();
        foreach ($server as $key => $value) {
            $request->setServer($key, $value);
        }
        return @$request->post(array_merge(['call' => 'plugin_drawio'], $post), '/lib/exe/ajax.php');
    }

    /**
     * Drive the plugin's ajax handler the same way script.js does for a diagram save.
     */
    protected function saveViaAjax($mediaId, $content = null, $xml = null)
    {
        if ($content === null) $content = $this->pngBytes();
        $post = [
            'action' => 'save',
            'imageName' => $mediaId,
            'content' => 'data:image/png;base64,' . base64_encode($content),
        ];
        // script.js only sends this once the editor has handed it the xml it
        // exported from; a save without it is what an old cached script.js
        // (or a pre-source diagram) looks like.
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

    protected function put($mediaId, $content = 'bytes')
    {
        $file = mediaFN($mediaId);
        io_makeFileDir($file);
        file_put_contents($file, $content);
        return $file;
    }

    /** Drive the indexer's own event shape, the way inc/Search/Indexer.php builds it. */
    protected function fireIndexerPageAdd($page, array $relationMedia)
    {
        $data = [
            'page' => $page,
            'body' => '',
            'metadata' => [
                'title' => $page,
                'relation_references' => [],
                'relation_media' => $relationMedia,
                'internal_index' => true,
            ],
            'pid' => 1,
        ];
        \dokuwiki\Extension\Event::createAndTrigger('INDEXER_PAGE_ADD', $data, null, false);
        return $data;
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
     * A single long, unique label cannot reproduce a join-without-a-separator
     * defect - concatenating one string with nothing still produces exactly
     * that string. This is deliberately two separate one-word labels ("auth"
     * and "server"), and it deliberately also embeds a second diagram in the
     * same namespace (relation_media lists two media ids, both diagrams'
     * text landing in one page body) - the shape that actually broke: joining
     * "auth" and "server" without a space between them produces the single
     * token "authserver", which is not the word either search term is.
     */
    public function testIndexerAddsEachDiagramsWordsAsIndependentlyFindableWords()
    {
        $this->put('test:idx1.png', 'png-bytes');
        $this->put('test:idx1.drawio', $this->multiShapeDrawio(['auth', 'gateway']));
        $this->put('test:idx1b.png', 'png-bytes');
        $this->put('test:idx1b.drawio', $this->multiShapeDrawio(['server', 'cache']));

        $data = $this->fireIndexerPageAdd('idxpage1', ['test:idx1.png', 'test:idx1b.png']);

        foreach (['auth', 'gateway', 'server', 'cache'] as $word) {
            $this->assertMatchesRegularExpression(
                '/\b' . $word . '\b/',
                $data['body'],
                "'$word' must appear as its own word, not merged into a neighbour"
            );
        }
        $this->assertStringNotContainsString(
            'authserver',
            $data['body'],
            'two labels joined without a separator would corrupt both into one unsearchable token'
        );
    }

    /** No source yet - must not warn/crash, and adds nothing. */
    public function testIndexerIgnoresADiagramWithoutASource()
    {
        $this->put('test:idx2.png', 'png-bytes');
        $data = $this->fireIndexerPageAdd('idxpage2', ['test:idx2.png']);
        $this->assertSame('', trim($data['body']));
    }

    /** A referenced file that is not a diagram at all (relation_media lists every embedded media). */
    public function testIndexerIgnoresNonDiagramMedia()
    {
        $this->put('test:notdiagram.jpg', 'jpg-bytes');
        $data = $this->fireIndexerPageAdd('idxpage3', ['test:notdiagram.jpg']);
        $this->assertSame('', trim($data['body']));
    }

    /**
     * The leak this has to close: a page anyone can read must not hand out
     * a restricted diagram's text through search just because the page
     * embeds it. Fulltext search is checked against the *page's* permission,
     * not the media's - core has no way to say "this word came from a
     * namespace some readers may not see" - so the plugin has to refuse to
     * put it there in the first place.
     */
    public function testIndexerDoesNotIndexADiagramFromADeniedNamespace()
    {
        $this->enableAcl(['secret:* @ALL 0']);
        $this->put('secret:idx4.png', 'png-bytes');
        $xml = '<mxfile><diagram><mxGraphModel><root><mxCell value="topsecretword" vertex="1"/></root></mxGraphModel></diagram></mxfile>';
        file_put_contents(mediaFN('secret:idx4.drawio'), $xml);

        $data = $this->fireIndexerPageAdd('idxpage4', ['secret:idx4.png']);

        $this->assertStringNotContainsString(
            'topsecretword',
            $data['body'],
            'a diagram in an ACL-restricted namespace must never reach a page\'s shared index'
        );
    }

    /** The other half: a diagram explicitly readable by everyone must still be indexed with ACL on. */
    public function testIndexerIndexesADiagramFromAnUnrestrictedNamespaceEvenWithAclOn()
    {
        $this->enableAcl(['secret:* @ALL 0', 'test:* @ALL 8']);
        $this->put('test:idx5.png', 'png-bytes');
        $xml = '<mxfile><diagram><mxGraphModel><root><mxCell value="openword" vertex="1"/></root></mxGraphModel></diagram></mxfile>';
        file_put_contents(mediaFN('test:idx5.drawio'), $xml);

        $data = $this->fireIndexerPageAdd('idxpage5', ['test:idx5.png']);

        $this->assertStringContainsString('openword', $data['body']);
    }

    /** A zero-byte source is not a source - same rule action.php's own read path uses. */
    public function testIndexerIgnoresAnEmptySource()
    {
        $this->put('test:idx6.png', 'png-bytes');
        $this->put('test:idx6.drawio', '');
        $data = $this->fireIndexerPageAdd('idxpage6', ['test:idx6.png']);
        $this->assertSame('', trim($data['body']));
    }

    /**
     * Reproduces the maintainer's live finding as a unit test. Failed before
     * the fix (confirmed by running it against the pre-fix action.php): the
     * page is indexed - with nothing to extract, since the diagram has no
     * source yet, exactly like testIndexerIgnoresADiagramWithoutASource
     * above - and then never touched again. Saving the diagram through the
     * same ajax endpoint script.js uses does not, on its own, make the
     * embedding page findable by a word that exists only inside the
     * diagram. No manual reindex step appears anywhere in this test - that
     * absence is the point.
     */
    public function testSavingADiagramMakesTheEmbeddingPageFindableBySearch()
    {
        // Real-world order: the page is written and indexed long before
        // anyone ever opens its diagram in the editor.
        saveWikiText('reindexpage', '{{drawio>test:reindexed.png}}', 'test init');
        plugin_load('helper', 'drawio')->reindexPage('reindexpage');

        // Sanity: nothing to find yet - see testIndexerIgnoresADiagramWithoutASource.
        $words = ['bytecodecompiler'];
        $before = idx_lookup($words);
        $this->assertArrayNotHasKey(
            'reindexpage',
            $before['bytecodecompiler'] ?? [],
            'sanity: the diagram has no source yet, so there is nothing to find'
        );

        // Now the diagram is actually saved, through the same ajax endpoint
        // script.js uses, with a source containing a word that exists only
        // inside the diagram.
        $xml = '<mxfile><diagram><mxGraphModel><root>'
            . '<mxCell value="BytecodeCompiler" vertex="1"/>'
            . '</root></mxGraphModel></diagram></mxfile>';
        $this->saveViaAjax('test:reindexed.png', $this->pngBytes(), $xml);

        $words = ['bytecodecompiler'];
        $after = idx_lookup($words);
        $this->assertArrayHasKey(
            'reindexpage',
            $after['bytecodecompiler'] ?? [],
            'saving the diagram must reindex the page that embeds it, with no manual reindex step'
        );
    }

    /**
     * A diagram is very often reused: the same rendering embedded on more
     * than one page. Saving it must reindex every page that embeds it, not
     * just the first one helper::pagesUsing() happens to return.
     */
    public function testSavingADiagramSharedByTwoPagesMakesBothFindable()
    {
        saveWikiText('reindexsharedpageone', '{{drawio>test:reindexshared.png}}', 'test init');
        saveWikiText('reindexsharedpagetwo', '{{drawio>test:reindexshared.png}}', 'test init');
        plugin_load('helper', 'drawio')->reindexPage('reindexsharedpageone');
        plugin_load('helper', 'drawio')->reindexPage('reindexsharedpagetwo');

        $xml = '<mxfile><diagram><mxGraphModel><root>'
            . '<mxCell value="topologyword" vertex="1"/>'
            . '</root></mxGraphModel></diagram></mxfile>';
        $this->saveViaAjax('test:reindexshared.png', $this->pngBytes(), $xml);

        $words = ['topologyword'];
        $after = idx_lookup($words);
        $this->assertArrayHasKey('reindexsharedpageone', $after['topologyword'] ?? [], 'the first embedding page must be reindexed');
        $this->assertArrayHasKey('reindexsharedpagetwo', $after['topologyword'] ?? [], 'the second embedding page must be reindexed too');
    }

    /** A diagram with no embedding page has nothing to purge, and must not error. */
    public function testSavingAnUnembeddedDiagramPurgesNothing()
    {
        $this->saveViaAjax('test:orphan.png', $this->pngBytes(), $this->diagramXml());
        $this->assertTrue(true, 'no exception means the empty-pages case is handled');
    }

}
