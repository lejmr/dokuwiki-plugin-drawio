<?php

/**
 * Tests for the drawio admin plugin's bulk-conversion task, beyond the
 * golden happy path (golden/lifecycle.test.php's
 * testConvertsARealPngExportAndFiresTheUploadEvent): admin-only access,
 * dry-run reporting, CSRF/GET rejection, and the never-overwrite /
 * never-recover-garbage guarantees.
 *
 * @group plugin_drawio
 * @group plugins
 */
class admin_plugin_drawio_test extends DokuWikiTest
{
    protected $pluginsEnabled = ['drawio'];

    /** @var array captured MEDIA_UPLOAD_FINISH event data, one entry per fired event */
    protected $firedEvents = [];

    public function setUp(): void
    {
        parent::setUp();
        $this->firedEvents = [];

        global $USERINFO;
        $USERINFO = ['grps' => []];

        global $EVENT_HANDLER;
        $EVENT_HANDLER->register_hook('MEDIA_UPLOAD_FINISH', 'AFTER', $this, 'captureMediaUploadFinish');
    }

    public function captureMediaUploadFinish(Doku_Event $event, $param)
    {
        $this->firedEvents[] = $event->data;
    }

    /**
     * DokuWikiTest only wipes the media tree once per *class*
     * (setUpBeforeClass), not once per test method - and a real convert POST
     * (batchSize defaults to 200, comfortably the whole tree here) sweeps up
     * every still-unconverted diagram any earlier test in this class left
     * behind, not just the one this test created. That is correct product
     * behaviour (one convert pass gets everything it can), but it means a
     * test asserting "no event fired for *my* diagram" has to filter by id
     * rather than assume it was the only thing in the batch.
     */
    protected function eventsFor($mediaId)
    {
        return array_values(array_filter($this->firedEvents, function ($e) use ($mediaId) {
            return $e[2] === $mediaId;
        }));
    }

    /**
     * Rebuild $_SERVER/$_GET/$_POST/$_REQUEST/$INPUT the way the real request
     * would look, without going through a full doku.php dispatch - handle()
     * and html() are the plugin's own public interface and are exercised
     * directly, the same way syntax.test.php drives syntax_plugin_drawio
     * through the parser API rather than a full page render.
     */
    protected function simulateRequest($method, array $params = [])
    {
        global $INPUT;
        $_SERVER['REQUEST_METHOD'] = $method;
        if ($method === 'POST') {
            $_POST = $params;
            $_GET = [];
        } else {
            $_GET = $params;
            $_POST = [];
        }
        $_REQUEST = $params;
        $INPUT = new \dokuwiki\Input\Input();
    }

    /**
     * A security token for $user, computed the same way action.test.php's
     * validTokenFor() does for the ajax suite - getSecurityToken() reads
     * REMOTE_USER and the current session id, so the token has to be built
     * under the same REMOTE_USER the request itself will use.
     */
    protected function tokenFor($user)
    {
        global $INPUT;
        $oldServer = $_SERVER;
        $oldInput = $INPUT;
        $_SERVER['REMOTE_USER'] = $user;
        $INPUT = new \dokuwiki\Input\Input();
        $token = getSecurityToken();
        $_SERVER = $oldServer;
        $INPUT = $oldInput;
        return $token;
    }

    protected function runAdmin()
    {
        $admin = new admin_plugin_drawio();
        $admin->handle();
        ob_start();
        $admin->html();
        return ob_get_clean();
    }

    /** Build a PNG carrying a draw.io tEXt chunk - same shape helper.test.php uses. */
    protected function pngWithChunk($keyword, $value)
    {
        $png = base64_decode(
            'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk'
            . 'YPhfDwAChwGA60e6kgAAAABJRU5ErkJggg=='
        );
        $data = $keyword . "\0" . $value;
        $chunk = pack('N', strlen($data)) . 'tEXt' . $data . pack('N', crc32('tEXt' . $data));
        $ihdrLen = unpack('N', substr($png, 8, 4))[1];
        $at = 8 + 12 + $ihdrLen;
        return substr($png, 0, $at) . $chunk . substr($png, $at);
    }

    protected function writeMedia($id, $content)
    {
        $file = mediaFN($id);
        io_makeFileDir($file);
        file_put_contents($file, $content);
        return $file;
    }

    public function testIsRestrictedToRealAdmins()
    {
        $admin = new admin_plugin_drawio();
        $this->assertTrue($admin->forAdminOnly());
    }

    public function testDryRunClassifiesAllThreeCases()
    {
        $xml = '<mxfile host="embed"><diagram id="x">stuff</diagram></mxfile>';
        $this->writeMedia('admintest1:already.png', $this->pngWithChunk('mxfile', rawurlencode($xml)));
        $this->writeMedia('admintest1:already.drawio', '<mxfile><diagram>existing</diagram></mxfile>');
        $this->writeMedia('admintest1:recoverable.png', $this->pngWithChunk('mxfile', rawurlencode($xml)));
        $this->writeMedia('admintest1:garbage.png', 'not a png at all');

        $this->simulateRequest('GET');
        $html = $this->runAdmin();

        $this->assertStringContainsString('admintest1:already.png', $html);
        $this->assertStringContainsString('admintest1:recoverable.png', $html);
        $this->assertStringContainsString('admintest1:garbage.png', $html);

        // Nothing was written or overwritten by a dry run.
        $this->assertSame(
            '<mxfile><diagram>existing</diagram></mxfile>',
            file_get_contents(mediaFN('admintest1:already.drawio')),
            'a dry run must never touch an existing source'
        );
        $this->assertFileDoesNotExist(mediaFN('admintest1:recoverable.drawio'));
        $this->assertFileDoesNotExist(mediaFN('admintest1:garbage.drawio'));
        $this->assertCount(0, $this->firedEvents, 'a dry run must fire no media event');
    }

    /**
     * A GET must never convert anything, even if it carries every parameter a
     * real convert POST would - the same rule SECURITY.md documents for the
     * ajax endpoint ("Every action is now POST-only").
     */
    public function testGetNeverConvertsEvenWithConvertAndTokenSet()
    {
        $xml = '<mxfile host="embed"><diagram id="x">stuff</diagram></mxfile>';
        $this->writeMedia('admintest2:plan.png', $this->pngWithChunk('mxfile', rawurlencode($xml)));

        $token = $this->tokenFor('testadmin');
        $_SERVER['REMOTE_USER'] = 'testadmin';
        $this->simulateRequest('GET', ['convert' => '1', 'sectok' => $token]);
        $this->runAdmin();

        $this->assertFileDoesNotExist(mediaFN('admintest2:plan.drawio'));
        $this->assertCount(0, $this->firedEvents);
    }

    /**
     * A deliberately wrong, non-empty token rather than an omitted one: the
     * comparison below is hash_equals(getSecurityToken(), posted sectok), and
     * this proves it rejects a mismatch regardless of what the real token
     * happens to be in whatever environment the test runs in (an omitted
     * sectok defaults to '', which would only prove the rejection in an
     * environment where the real token is coincidentally non-empty -
     * action.test.php's own equivalent CSRF test avoids the same pitfall by
     * posting an explicit wrong value rather than omitting the field).
     */
    public function testConvertWithAWrongSecurityTokenWritesNothing()
    {
        $xml = '<mxfile host="embed"><diagram id="x">stuff</diagram></mxfile>';
        $this->writeMedia('admintest4:plan.png', $this->pngWithChunk('mxfile', rawurlencode($xml)));

        $token = $this->tokenFor('testadmin');
        $_SERVER['REMOTE_USER'] = 'testadmin';
        $this->simulateRequest('POST', ['convert' => '1', 'sectok' => $token . 'x']);
        $this->runAdmin();

        $this->assertFileDoesNotExist(mediaFN('admintest4:plan.drawio'));
        $this->assertCount(0, $this->firedEvents);
    }

    /** The non-compliant raw-deflate zTXt export - same fixture helper.test.php covers. */
    public function testConvertsARealPngExportWithNonCompliantZtxt()
    {
        $png = file_get_contents(__DIR__ . '/../real-drawio-export-ztxt.png');
        $this->writeMedia('admintest6:plan.png', $png);

        $helper = plugin_load('helper', 'drawio');
        $expectedXml = $helper->extractPngXml($png);
        $this->assertNotSame('', $expectedXml);

        $token = $this->tokenFor('testadmin');
        $_SERVER['REMOTE_USER'] = 'testadmin';
        $this->simulateRequest('POST', ['convert' => '1', 'sectok' => $token]);
        $this->runAdmin();

        $this->assertSame($expectedXml, file_get_contents(mediaFN('admintest6:plan.drawio')));
    }

    /** The real SVG export fixture - proves the svg extraction path, not just png. */
    public function testConvertsARealSvgExport()
    {
        $svg = file_get_contents(__DIR__ . '/../real-drawio-export.svg');
        $this->writeMedia('admintest7:plan.svg', $svg);

        $helper = plugin_load('helper', 'drawio');
        $expectedXml = $helper->extractSvgXml($svg);
        $this->assertNotSame('', $expectedXml);

        $token = $this->tokenFor('testadmin');
        $_SERVER['REMOTE_USER'] = 'testadmin';
        $this->simulateRequest('POST', ['convert' => '1', 'sectok' => $token]);
        $this->runAdmin();

        $this->assertSame($expectedXml, file_get_contents(mediaFN('admintest7:plan.drawio')));
    }

    public function testConvertNeverOverwritesAnExistingSource()
    {
        $xml = '<mxfile host="embed"><diagram id="x">new</diagram></mxfile>';
        $this->writeMedia('admintest8:plan.png', $this->pngWithChunk('mxfile', rawurlencode($xml)));
        $this->writeMedia('admintest8:plan.drawio', '<mxfile><diagram>kept</diagram></mxfile>');

        $token = $this->tokenFor('testadmin');
        $_SERVER['REMOTE_USER'] = 'testadmin';
        $this->simulateRequest('POST', ['convert' => '1', 'sectok' => $token]);
        $this->runAdmin();

        $this->assertSame(
            '<mxfile><diagram>kept</diagram></mxfile>',
            file_get_contents(mediaFN('admintest8:plan.drawio'))
        );
        $this->assertCount(0, $this->eventsFor('admintest8:plan.drawio'), 'no write means no event for this diagram');
    }

    public function testConvertLeavesAnUnrecoverableDiagramAloneAndReportsIt()
    {
        $this->writeMedia('admintest9:plan.png', 'this is not a valid png at all');

        $token = $this->tokenFor('testadmin');
        $_SERVER['REMOTE_USER'] = 'testadmin';
        $this->simulateRequest('POST', ['convert' => '1', 'sectok' => $token]);
        $html = $this->runAdmin();

        $this->assertFileDoesNotExist(mediaFN('admintest9:plan.drawio'));
        $this->assertCount(0, $this->eventsFor('admintest9:plan.drawio'));
        $this->assertStringContainsString('admintest9:plan.png', $html);
    }
}
