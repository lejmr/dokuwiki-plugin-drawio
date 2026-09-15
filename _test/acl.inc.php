<?php

/**
 * Shared ACL setup for this plugin's tests.
 *
 * Several tests in both suites turn ACL on and pretend to be a particular
 * user. None of that is per-test state in DokuWiki: $AUTH_ACL, $USERINFO,
 * $auth and REMOTE_USER are process globals, and $conf only happens to come
 * back from disk because DokuWikiTest::setUp() reloads it - nothing promises
 * that. So the setup undoes itself here, once, for both suites, instead of
 * each of them relying on that from a different direction.
 */
trait drawio_acl_test_helper
{
    /**
     * @param array  $acl    ACL lines, "id<whitespace>user<whitespace>permission"
     * @param string $user   who the request comes from ('' = not logged in)
     * @param array  $groups that user's groups
     */
    protected function enableAcl(array $acl, $user = '', array $groups = [])
    {
        global $conf, $AUTH_ACL, $auth, $USERINFO;
        $conf['useacl'] = 1;
        $auth = new \dokuwiki\test\mock\AuthPlugin();
        $AUTH_ACL = $acl;
        $USERINFO = ['grps' => $groups];
        if ($user === '') {
            unset($_SERVER['REMOTE_USER']);
        } else {
            $_SERVER['REMOTE_USER'] = $user;
        }
    }

    protected function tearDown(): void
    {
        global $conf, $AUTH_ACL, $auth, $USERINFO;
        $conf['useacl'] = 0;
        $AUTH_ACL = [];
        $auth = null;
        $USERINFO = [];
        unset($_SERVER['REMOTE_USER']);
        parent::tearDown();
    }
}
