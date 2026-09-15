<?php

/**
 * Edge cases of the ajax 'lock' action: expiry, holder reporting, and that
 * a diagram's lock never collides with an unrelated page's lock or an
 * ACL-denied namespace's.
 *
 * @group plugin_drawio
 * @group plugins
 */
require_once __DIR__ . '/../acl.inc.php';

class action_plugin_drawio_lifecycle_edges_locks_test extends DokuWikiTest
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
     * Compute a security token for the given user the same way core's own ajax
     * CSRF test does (_test/tests/lib/exe/ajax_requests.test.php), without
     * leaking the temporary REMOTE_USER into the global state.
     */
    protected function validTokenFor($user)
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

    /**
     * The same id action.php's _lock_id() derives, computed independently
     * here (not by calling _lock_id() itself - that would just be asserting
     * the code agrees with itself) so a test can point wikiLockFN() at the
     * right file.
     */
    protected function lockFileFor($mediaId)
    {
        $ext = strtolower(pathinfo($mediaId, PATHINFO_EXTENSION));
        $srcId = in_array($ext, ['png', 'svg'], true)
            ? substr($mediaId, 0, -strlen($ext)) . 'drawio'
            : $mediaId;
        return wikiLockFN('drawio:' . $srcId);
    }

    protected function lockRequest($mediaId, $user)
    {
        return $this->ajaxPost(
            [
                'action' => 'lock',
                'imageName' => $mediaId,
                'sectok' => $this->validTokenFor($user),
            ],
            ['REMOTE_USER' => $user]
        );
    }

    public function testLockOfAFreshDiagramReportsNoHolder()
    {
        $response = $this->lockRequest('test:fresh.png', 'alice');
        $data = json_decode($response->getContent(), true);
        $this->assertNull($data['locked_by'], 'nobody held the lock before this call');
        $this->assertFileExists($this->lockFileFor('test:fresh.png'), 'the call must take the lock for next time');
    }

    /**
     * "Nothing proves a lock actually goes stale." checklock() (inc/common.php)
     * is core's own call and already refuses to report a holder once the lock
     * file is older than $conf['locktime'] - but nothing here exercised that,
     * only the scheduled-renewal side (testDraftSaveRenewsAnExistingLock
     * above). If a refactor ever stopped honouring locktime, a lock would
     * report a holder forever and the silent-overwrite problem this feature
     * exists to prevent would come straight back with no test catching it.
     *
     * Same back-dating trick as the renewal test: touch() the lock file to
     * just past locktime old, then ask 'lock' again and expect no holder.
     */
    public function testLockExpiresAfterLocktime()
    {
        global $conf;
        $this->lockRequest('test:stale.png', 'alice');
        $lockFile = $this->lockFileFor('test:stale.png');
        $this->assertFileExists($lockFile);

        $staleAge = $conf['locktime'] + 10;
        touch($lockFile, time() - $staleAge);
        clearstatcache();
        $this->assertLessThanOrEqual(time() - $staleAge + 1, filemtime($lockFile), 'sanity: touch() must have backdated the lock file past locktime');

        $response = $this->lockRequest('test:stale.png', 'bob');
        $data = json_decode($response->getContent(), true);

        $this->assertNull($data['locked_by'], 'a lock older than $conf[locktime] must no longer be reported as held');
    }

    public function testLockIsSharedBetweenPngAndSvgOfTheSameDiagram()
    {
        $this->lockRequest('test:shared.png', 'alice');
        $response = $this->lockRequest('test:shared.svg', 'bob');

        $data = json_decode($response->getContent(), true);
        $this->assertSame('alice', $data['locked_by'],
            'the .png and the .svg are the same diagram (see helper.php sourceID()) and must share one lock');
    }

    /**
     * The shallow collision the maintainer's brief called out by name: a
     * page 'ns:plan' and a diagram 'ns:plan.png' must not share a lock.
     * Simulated with core's own lock() (the same call DokuWiki's edit form
     * makes), not a fake - if the keys ever collided this would see
     * 'pageeditor' come back as the diagram's holder.
     */
    public function testDiagramLockDoesNotCollideWithAPageLockOfTheSameBaseName()
    {
        $_SERVER['REMOTE_USER'] = 'pageeditor';
        global $INPUT;
        $INPUT = new \dokuwiki\Input\Input();
        lock('test:collide');
        unset($_SERVER['REMOTE_USER']);
        $INPUT = new \dokuwiki\Input\Input();

        $this->assertNotSame(
            wikiLockFN('test:collide'),
            $this->lockFileFor('test:collide.png'),
            'sanity: the two ids must not hash to the same lock file'
        );

        $response = $this->lockRequest('test:collide.png', 'diagramuser');
        $data = json_decode($response->getContent(), true);
        $this->assertNull($data['locked_by'], 'the page lock must not leak into the diagram lock');
    }

    /**
     * The deeper collision an arbiter review found: unlike the shallow case
     * above, a page id CAN legally be spelled exactly like this diagram's
     * unprefixed lock key ('test:collide2.drawio' is as valid a page id as
     * any other - dots are only special at an id's boundaries). Without the
     * 'drawio:' prefix in _lock_id() this page's lock and the diagram
     * 'test:collide2.png''s lock would be the very same file.
     */
    public function testDiagramLockDoesNotCollideWithAPageLockSpelledLikeItsOwnUnprefixedKey()
    {
        $_SERVER['REMOTE_USER'] = 'pageeditor';
        global $INPUT;
        $INPUT = new \dokuwiki\Input\Input();
        lock('test:collide2.drawio'); // a legal page id, not a diagram action
        unset($_SERVER['REMOTE_USER']);
        $INPUT = new \dokuwiki\Input\Input();

        $this->assertNotSame(
            wikiLockFN('test:collide2.drawio'),
            $this->lockFileFor('test:collide2.png'),
            'the prefix must separate these even though the page id equals the diagram\'s unprefixed key'
        );

        $response = $this->lockRequest('test:collide2.png', 'diagramuser');
        $data = json_decode($response->getContent(), true);
        $this->assertNull($data['locked_by'], "a page named exactly like the diagram's own lock key must not leak into it");
    }

    /**
     * Same permission model as every other action, checked the same way: a
     * caller without AUTH_UPLOAD on the resolved id's namespace must be
     * refused before any lock code runs at all - not told "denied" (that
     * would itself be a tell), just refused, and refused with no side
     * effect. An existence/permission oracle through a new endpoint is
     * exactly the class of bug this development cycle already fixed once
     * for rendering (see SECURITY.md, "Existence of protected media was
     * observable") - this must not reopen it through the lock action.
     */
    public function testLockIsDeniedForANamespaceWithoutAccess()
    {
        $this->enableAcl(['secret:* @ALL 0'], 'mallory');

        $response = $this->lockRequest('secret:diagram.png', 'mallory');

        $this->assertFileDoesNotExist(
            $this->lockFileFor('secret:diagram.png'),
            'a denied caller must not be able to take a lock on something they cannot edit'
        );
        // No message body distinguishes "denied" from "does not exist" - see
        // action.php's _ajax_call() doc comment; there is nothing else to
        // assert on here without a live HTTP status (see the other 403 tests
        // in this suite for why that can't be checked under the CLI SAPI).
        $this->addToAssertionCount(1);
    }

}
