<?php

/**
 * Pagination for the drawio admin bulk-conversion task, in a file of its own.
 *
 * DokuWikiTest wipes and reseeds the media tree once per *class*
 * (setUpBeforeClass), not once per test method, so a test that asserts
 * exactly which diagrams a batch window contains needs a media tree nothing
 * else has written to yet - hence a separate test class (and, since PHPUnit
 * warns that multiple test classes per file is deprecated, a separate file)
 * rather than another method on admin_plugin_drawio_test in admin.test.php,
 * whose other tests leave their own media files lying around for the rest of
 * that class's run.
 *
 * @group plugin_drawio
 * @group plugins
 */
class admin_plugin_drawio_batching_test extends DokuWikiTest
{
    protected $pluginsEnabled = ['drawio'];

    protected function writeMedia($id, $content)
    {
        $file = mediaFN($id);
        io_makeFileDir($file);
        file_put_contents($file, $content);
        return $file;
    }

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
     * $batchSize is a protected property (not a hard constant) precisely so
     * pagination can be proven without needing hundreds of real media files -
     * see admin.php's docblock for why a fixed per-request ceiling with a
     * manual offset, rather than a background job, is how this scales to a
     * big wiki.
     */
    public function testBatchingOnlyProcessesOneWindowPerRequestAndOffsetResumes()
    {
        $this->writeMedia('batch:a.png', 'garbage-a');
        $this->writeMedia('batch:b.png', 'garbage-b');
        $this->writeMedia('batch:c.png', 'garbage-c');

        $admin = new admin_plugin_drawio_test_smallbatch();

        $this->simulateRequest('GET', ['offset' => 0]);
        $admin->handle();
        ob_start();
        $admin->html();
        $first = ob_get_clean();
        $this->assertStringContainsString('batch:a.png', $first);
        $this->assertStringNotContainsString('batch:b.png', $first);
        $this->assertStringNotContainsString('batch:c.png', $first);

        $this->simulateRequest('GET', ['offset' => 1]);
        $admin->handle();
        ob_start();
        $admin->html();
        $second = ob_get_clean();
        $this->assertStringNotContainsString('batch:a.png', $second);
        $this->assertStringContainsString('batch:b.png', $second);
        $this->assertStringNotContainsString('batch:c.png', $second);
    }
}

/**
 * A test-only subclass rather than an anonymous class: PHPUnit's legacy
 * file-based test loader mis-parses `new class extends ... { ... }` inside a
 * file that (like this one, deliberately - see the class docblock above)
 * already defines its own top-level test class, and fails the whole file
 * with "Unclosed '{'" - a real PHP parser (php -l) accepts the exact same
 * file without complaint, so this is a PHPUnit discovery quirk, not a syntax
 * error. A named subclass sidesteps it.
 */
class admin_plugin_drawio_test_smallbatch extends admin_plugin_drawio
{
    protected $batchSize = 1;
}
