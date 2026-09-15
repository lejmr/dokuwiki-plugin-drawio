// Extra tier: everything else that earns its place - edge cases, rejected
// input, security regressions, and implementation details the golden tier
// (one check per feature) has no room for. See DEVELOPMENT.md.
//
// Run with: node _test/extra/script.test.js

const {
    fs, vm, path,
    assert, makeImage, buildSandbox, buildMediaPanelDom, loadScript, autosave,
    REALISTIC_CONF, noopJQuery, assertEnvironmentSurvives, withToolbar,
    querySelectorAll,
} = require('../script-sandbox.inc.js');

const SCRIPT_JS = path.join(__dirname, '..', '..', 'script.js');
const SCRIPT_JS_SOURCE = fs.readFileSync(SCRIPT_JS, 'utf8');

// --- issue #37 (Snippets plugin conflict): script.js must not throw when
// JSINFO itself is missing, or shaped like a page addjsinfo() never ran
// against --------------------------------------------------------------
//
// splitbrain's diagnosis of #37: the plugin's JS assumed values in JSINFO
// that action.php's addjsinfo() only ever sets on DOKUWIKI_STARTED/
// MEDIAMANAGER_STARTED - the Snippets plugin's popup fires neither, so it
// can present a page where JSINFO was never declared at all (not merely
// missing plugin_drawio - genuinely absent, because whatever built that
// popup's <head> never called core's own jsinfo()/tpl_metaheaders() either).
// That is a stricter case than #16's fix (JSINFO present, JSINFO.id null):
// a *bare* `JSINFO` reference (not `typeof JSINFO`) on an undeclared global
// is a ReferenceError, not a friendly `undefined` - and DokuWiki
// concatenates every plugin's script.js into one response (js_pluginscripts()
// in lib/exe/js.php), so that throw kills every plugin script after ours in
// the bundle, exactly like #16 did.
//
// buildSandbox()/assertEnvironmentSurvives() (script-sandbox.inc.js) always
// give the vm context a `JSINFO` property, even when its value is undefined -
// which is not the same thing: a property that exists (however its value)
// never triggers a ReferenceError on a bare reference, only a truly absent
// global does. So this case is built by hand, with no JSINFO key at all.
function runWithoutJsinfoGlobal(name, extra) {
    const sandbox = Object.assign({ window: {}, document: {}, console }, extra);
    // sanity: this sandbox really has no JSINFO global, matching the popup
    assert.ok(!Object.prototype.hasOwnProperty.call(sandbox, 'JSINFO'),
        'test bug: sandbox must not declare JSINFO at all for this case');
    sandbox.global = sandbox;
    vm.createContext(sandbox);
    let threw = null;
    try {
        vm.runInContext(SCRIPT_JS_SOURCE, sandbox);
        vm.runInContext('window.markerFromNextPlugin = true;', sandbox);
    } catch (e) {
        threw = e;
    }
    assert.strictEqual(threw, null,
        'script.js must not throw on ' + name + ': ' + (threw && threw.stack));
    assert.strictEqual(sandbox.window.markerFromNextPlugin, true,
        'a plugin script concatenated after script.js must still run on ' + name);
    return sandbox;
}

// (a) no JSINFO global at all, on a page whose edit toolbar *is* present -
// the exact combination that reaches script.js's toolbar-registration guard
// (`typeof window.toolbar !== 'undefined' && ...`) and, before this fix,
// went on to read a bare `JSINFO` straight into a ReferenceError.
{
    const sharedToolbar = [];
    const env = runWithoutJsinfoGlobal(
        'a page with no JSINFO global at all and window.toolbar defined (the Snippets-popup shape)',
        { jQuery: noopJQuery(), toolbar: sharedToolbar, window: { toolbar: sharedToolbar } },
    );
    assert.strictEqual(env.toolbar.length, 0,
        'with no JSINFO there is no page id to build {{drawio>...}} against - no toolbar item should be registered');
}

// also drive the module-level jQuery(document ready) registration path
// (drawioAddMediaManagerButton()) in the same no-JSINFO environment -
// buildSandbox() builds a full fake DOM/jQuery already; just strip JSINFO
// from it entirely to close the gap between "JSINFO missing a key" (already
// covered above) and "JSINFO absent".
{
    const built = buildSandbox();
    delete built.sandbox.JSINFO;
    let threw = null;
    try {
        loadScript(built.sandbox);
    } catch (e) {
        threw = e;
    }
    assert.strictEqual(threw, null,
        'script.js must not throw at load with no JSINFO at all, jQuery present: ' + (threw && threw.stack));
}

console.log('OK: script.js survives having no JSINFO global at all (issue #37)');

// (b) JSINFO declared but plugin_drawio entirely missing, window.toolbar
// defined and JSINFO.id a normal string - drawioConf() must return null
// (not throw) and the toolbar registration must still work off JSINFO.id
// alone, independently of plugin_drawio.
{
    const sharedToolbar = [];
    const env = assertEnvironmentSurvives(
        'JSINFO without plugin_drawio, toolbar defined, JSINFO.id a string',
        { id: 'test:page', namespace: '', ACT: 'show' },
        { jQuery: noopJQuery(), toolbar: sharedToolbar, window: { toolbar: sharedToolbar } },
    );
    assert.strictEqual(env.toolbar.length, 0,
        'with no plugin_drawio config, drawioConf() is null and edit_cb() bails - but the toolbar block itself ' +
        'must not throw just because plugin_drawio is missing (toolbarPossibleExtension falls back to [])');
}

// (c) JSINFO.id undefined (not null, not a string) - a shape neither the
// golden matrix's null case nor the extra tier's '' case above covers, but
// `typeof JSINFO.id === 'string'` treats it identically: false, no throw.
{
    assertEnvironmentSurvives(
        'JSINFO.id undefined, plugin_drawio present, toolbar defined',
        { plugin_drawio: REALISTIC_CONF }, // no `id` key at all -> JSINFO.id is undefined
        { jQuery: noopJQuery(), toolbar: [], window: { toolbar: [] } },
    );
}

console.log('OK: script.js survives JSINFO without plugin_drawio, and JSINFO.id undefined (issue #37 matrix)');

// --- hostile-environment variant beyond the golden matrix: JSINFO.id === ''
//
// mediamanager.php itself sets $JSINFO['id'] = '' (verified in .cache/
// dokuwiki-{stable,oldstable,master}, all three identical) - but jsinfo()
// (inc/common.php, called later by every version's tpl_metaheaders() to
// build the JSINFO the browser gets) unconditionally overwrites
// JSINFO['id'] with the global $ID, which mediamanager.php never sets, so a
// real browser always receives null there (confirmed by curl against the
// running dev wiki: "id":null, not "id":""), which is what the golden
// matrix covers. script.js's own guard (`typeof JSINFO.id === 'string' &&
// JSINFO.id`) treats null and '' identically, so this is tested here too,
// as defence in depth, in case a future DokuWiki release or a different
// fullscreen template ever hands the intermediate '' value to the browser
// directly instead of routing it through jsinfo().
{
    const sharedToolbar = [];
    const env = assertEnvironmentSurvives(
        "the media manager, JSINFO.id === '' (mediamanager.php's own value, " +
        'before jsinfo() overwrites it with null for the browser - see above)',
        {
            id: '', namespace: 'test', ACT: 'show', plugin_drawio: REALISTIC_CONF,
            useHeadingNavigation: 0, useHeadingContent: 0,
        },
        { jQuery: noopJQuery(), toolbar: sharedToolbar, window: { toolbar: sharedToolbar } },
    );
    assert.strictEqual(env.toolbar.length, 0,
        "with JSINFO.id === '' there is nothing to insert {{drawio>...}} into, same as null - no toolbar item should be registered");
}

console.log("OK: script.js also survives JSINFO.id === '' (defence in depth beyond the null case the golden matrix covers)");

// --- regression test: double-click must not stack a second editor ---------
//
// edit_cb() used to append a new window 'message' listener every call with no
// check for one already being attached. Two clicks on a diagram meant two
// iframes and two listeners, so every subsequent autosave fired duplicate
// draft_save ajax posts for the rest of the session.
{
    const { sandbox, messageListeners } = buildSandbox();
    loadScript(sandbox);

    sandbox.edit_cb(makeImage('double.png'));
    assert.strictEqual(messageListeners.length, 1, 'first click must register a listener');

    sandbox.edit_cb(makeImage('double.png'));
    assert.strictEqual(messageListeners.length, 1,
        'a second click while the editor is still open must not add a second listener');
}

console.log('OK: a second click while the editor is open does not stack a second listener (#4)');

// --- regression test: postMessage must be checked against the real iframe ---
//
// The listener is on window, not the iframe, so without a source check
// receive() would parse and act on a message posted by anything on the page,
// not just the real draw.io iframe. Checked via evt.source object identity,
// not evt.origin (a string check breaks the moment a redirect/reverse
// proxy/SSO gateway sits in front of a self-hosted draw.io - see script.js).
{
    const { sandbox, localStorage, messageListeners, iframes } = buildSandbox();
    loadScript(sandbox);

    sandbox.edit_cb(makeImage('spoofed.png'));
    const receive = messageListeners[messageListeners.length - 1];
    assert.strictEqual(iframes.length, 1);

    const fakeSource = { postMessage() {} };
    assert.notStrictEqual(fakeSource, iframes[0].contentWindow, 'sanity: must be a different object');
    receive({
        source: fakeSource,
        data: JSON.stringify({ event: 'autosave', xml: '<mxGraphModel>spoofed</mxGraphModel>' }),
    });

    assert.strictEqual(localStorage.getItem('.draft-spoofed.png'), null,
        'a message whose source is not the real draw.io iframe must be ignored');
}

console.log('OK: postMessage not sourced from the real draw.io iframe is ignored (#5)');

// --- regression test: a non-JSON postMessage must not wedge the editor ---
//
// JSON.parse(evt.data) was unguarded. A throw there aborted the listener
// before close() could run, leaving editorOpen stuck true and every diagram
// on the page uneditable until a reload.
{
    const { sandbox, messageListeners, iframes } = buildSandbox();
    loadScript(sandbox);

    sandbox.edit_cb(makeImage('malformed.png'));
    const receive = messageListeners[messageListeners.length - 1];
    const source = iframes[iframes.length - 1].contentWindow;

    const logged = [];
    const realConsole = sandbox.console;
    sandbox.console = { ...realConsole, log: (...args) => logged.push(args) };
    try {
        assert.doesNotThrow(() => receive({ source, data: 'not json at all' }),
            'a non-JSON postMessage must not throw out of the listener');
    } finally {
        sandbox.console = realConsole;
    }
    assert.ok(logged.some((args) => String(args[0]).includes('non-JSON')),
        'the malformed message should still be logged for debugging');

    assert.strictEqual(sandbox.editorOpen, true,
        'editorOpen must survive a malformed message - the editor is still open, just got noise');

    autosave(sandbox, messageListeners, '<mxGraphModel>ok</mxGraphModel>', iframes);
    assert.ok(sandbox.localStorage.getItem('.draft-malformed.png'),
        'a later, well-formed message must still work after a malformed one');
}

console.log('OK: a non-JSON postMessage does not wedge the editor open (#5 JSON.parse guard)');

// --- regression test: a rejected save must not lose the diagram ----------
{
    const { sandbox, messageListeners, iframes, postCalls, alerts, wasReloaded } = buildSandbox();
    loadScript(sandbox);

    sandbox.edit_cb(makeImage('rejected.png'));
    autosave(sandbox, messageListeners, '<mxGraphModel>keep me</mxGraphModel>', iframes);
    const draftKey = '.draft-rejected.png';
    assert.ok(sandbox.localStorage.getItem(draftKey), 'sanity: draft must exist before the export');

    const receive = messageListeners[messageListeners.length - 1];
    const source = iframes[iframes.length - 1].contentWindow;

    receive({
        source,
        data: JSON.stringify({ event: 'export', format: 'xmlpng', data: 'data:image/png;base64,Zm9v' }),
    });

    const saveCall = postCalls.find((c) => c.data && c.data.action === 'save');
    assert.ok(saveCall, "the 'export' handler must post action:'save'");
    assert.ok(typeof saveCall.failCb === 'function',
        "the 'save' post must register a .fail() handler so a server rejection is not silent");

    saveCall.failCb();

    assert.strictEqual(alerts.length, 1, 'a rejected save must alert the user, not pretend to have worked');
    assert.ok(wasReloaded(), 'the page must reload so the lying "saved" image does not stick around');
    assert.ok(sandbox.localStorage.getItem(draftKey),
        'a rejected save must NOT clear the draft - it is the only copy of the change left');
    assert.ok(!postCalls.some((c) => c.data && c.data.action === 'draft_rm'),
        'a rejected save must NOT clean up the on-disk draft either');
}

console.log('OK: a rejected save keeps the draft instead of losing the diagram');

// --- get_auth: denial and request-failure must both stay quiet -----------
//
// A denial (a successful response carrying `false`) already shows the user
// nothing, so a failed request (e.g. an expired sectok) must not show the
// noisy generic "request failed" alert either.
{
    const { sandbox, postCalls, alerts } = buildSandbox();
    loadScript(sandbox);

    sandbox.edit(makeImage('perm.png'));
    const authCall = postCalls.find((c) => c.data && c.data.action === 'get_auth');
    assert.ok(authCall, 'test setup: get_auth must be posted');

    authCall.successCb(false);
    assert.strictEqual(sandbox.editorOpen, false, 'a false response must not open the editor');
    assert.strictEqual(alerts.length, 0, 'a permission denial must not alert');

    assert.strictEqual(authCall.failCb, null,
        'get_auth must be posted silently: true, so a failed request does not alert');
}

console.log('OK: get_auth denial and request failure both stay silent');

// --- regression test for S6: SVG export must not innerHTML raw markup ----
//
// edit_cb()'s 'export' handler used to decode the data URI drawio's iframe
// posts back and assign it straight into the page with element.innerHTML =
// imgData - a handler embedded in a diagram's content would then execute.
// It also pins the fix for the bug this same branch used to carry: the old
// replaceChild() swapped in a brand new <img>, discarding the
// width/height/title/alt syntax.php had computed for this diagram.
{
    const { sandbox, messageListeners, iframes } = buildSandbox();
    loadScript(sandbox);

    const image = makeImage('malicious.svg');
    image.setAttribute('title', 'My title');
    image.setAttribute('style', 'max-width:100%;cursor:pointer;width:200px;height:100px;');
    sandbox.edit_cb(image);
    const receive = messageListeners[messageListeners.length - 1];
    const source = iframes[iframes.length - 1].contentWindow;

    const payload = '<svg onload="window.pwned = true"><a href="x">y</a></svg>';
    const svgDataUri = 'data:image/svg+xml;base64,' + Buffer.from(payload).toString('base64');
    receive({ source, data: JSON.stringify({ event: 'export', format: 'svg', data: svgDataUri }) });

    assert.strictEqual(image.innerHTML, undefined, 'raw SVG markup must never be assigned via innerHTML');
    assert.strictEqual(image.getAttribute('src'), svgDataUri,
        "the freshly saved SVG must be loaded via the existing image's src, not inlined as markup");
    assert.strictEqual(sandbox.window.pwned, undefined,
        'a handler embedded in the SVG payload must never execute');
    assert.strictEqual(image.getAttribute('title'), 'My title', 'title must survive an svg export');
    assert.strictEqual(image.getAttribute('style'), 'max-width:100%;cursor:pointer;width:200px;height:100px;',
        'width/height (via style) must survive an svg export');
}

console.log('OK: SVG export does not inject raw markup into the page (S6)');

// --- draft decline paths: local and server-fetched -------------------------

// decline a localStorage draft: falls back to the stored image - and, per the
// BUG note below, actually does clean it up (see the fix already in this
// branch: declining a local draft now removes it and posts draft_rm, the
// same as the server-fetched branch does).
{
    const { sandbox, messageListeners, iframes, postCalls } = buildSandbox();
    loadScript(sandbox);

    const draftKey = '.draft-declineme.png';
    const draftXml = '<mxGraphModel>local draft, please DO NOT restore me</mxGraphModel>';
    sandbox.localStorage.setItem(draftKey, JSON.stringify({ lastModified: new Date(), xml: draftXml }));
    sandbox.confirm = () => false;

    sandbox.edit_cb(makeImage('declineme.png'));
    const receive = messageListeners[messageListeners.length - 1];
    const frame = iframes[iframes.length - 1];
    const posted = [];
    frame.contentWindow.postMessage = (m) => posted.push(JSON.parse(m));

    receive({ source: frame.contentWindow, data: JSON.stringify({ event: 'init' }) });
    const getPngCall = postCalls.find((c) => c.data && c.data.action === 'get_png');
    assert.ok(getPngCall, 'declining the local draft must fall back to fetching the stored image');
    getPngCall.successCb({ content: 'data:image/png;base64,Zm9v' });

    const loadMsg = posted.find((m) => m.action === 'load');
    assert.ok(loadMsg, "declining must still post the editor's 'load' action");
    assert.notStrictEqual(loadMsg.xml, draftXml, 'a declined draft must never be loaded into the editor');

    assert.strictEqual(sandbox.localStorage.getItem(draftKey), null,
        'declining a local draft must remove it from localStorage, or it is offered again on every open');
    assert.ok(postCalls.some((c) => c.data && c.data.action === 'draft_rm'),
        'declining a local draft must also ask the server to discard its copy');
}

console.log('OK: declining a local draft discards it for good and falls back to the image');

// accept a server-fetched draft (no local one - a different browser/tab)
{
    const { sandbox, messageListeners, iframes, postCalls } = buildSandbox();
    loadScript(sandbox);
    sandbox.confirm = () => true;

    sandbox.edit_cb(makeImage('serverrestoreme.png'));
    const draftGetCall = postCalls.find((c) => c.data && c.data.action === 'draft_get');
    assert.ok(draftGetCall && draftGetCall.successCb, 'test setup: draft_get must be postable');

    const draftXml = '<mxGraphModel>server draft, please restore me</mxGraphModel>';
    draftGetCall.successCb({ content: 'data:image/png;base64,Zm9v', lastModified: Date.now(), xml: draftXml });

    const receive = messageListeners[messageListeners.length - 1];
    const frame = iframes[iframes.length - 1];
    const posted = [];
    frame.contentWindow.postMessage = (m) => posted.push(JSON.parse(m));
    receive({ source: frame.contentWindow, data: JSON.stringify({ event: 'init' }) });

    const loadMsg = posted.find((m) => m.action === 'load');
    assert.ok(loadMsg, "accepting a server draft must still post the editor's 'load' action");
    assert.strictEqual(loadMsg.xml, draftXml, 'accepting a server-fetched draft must load its xml');
}

console.log('OK: accepting a server-fetched draft loads it');

// decline a server-fetched draft: draft_rm must be posted, falls back to image
{
    const { sandbox, messageListeners, iframes, postCalls } = buildSandbox();
    loadScript(sandbox);
    sandbox.confirm = () => false;

    sandbox.edit_cb(makeImage('serverdeclineme.png'));
    const draftGetCall = postCalls.find((c) => c.data && c.data.action === 'draft_get');
    const draftXml = '<mxGraphModel>server draft, please DO NOT restore me</mxGraphModel>';
    draftGetCall.successCb({ content: 'data:image/png;base64,Zm9v', lastModified: Date.now(), xml: draftXml });

    assert.ok(postCalls.some((c) => c.data && c.data.action === 'draft_rm' && c.data.imageName === 'serverdeclineme.png'),
        'declining a server-fetched draft must clean it up on the server (draft_rm)');

    const receive = messageListeners[messageListeners.length - 1];
    const frame = iframes[iframes.length - 1];
    const posted = [];
    frame.contentWindow.postMessage = (m) => posted.push(JSON.parse(m));
    receive({ source: frame.contentWindow, data: JSON.stringify({ event: 'init' }) });

    const getPngCall = postCalls.find((c) => c.data && c.data.action === 'get_png');
    assert.ok(getPngCall, 'declining the server draft must fall back to fetching the stored image');
    getPngCall.successCb({ content: 'data:image/png;base64,Zm9v' });
    const loadMsg = posted.find((m) => m.action === 'load');
    assert.notStrictEqual(loadMsg.xml, draftXml, 'a declined server draft must never be loaded into the editor');
}

console.log('OK: declining a server-fetched draft cleans it up (draft_rm) and falls back to the image');

// a dot inside the diagram name (ns:release-v1.2.png) must open like any other
// diagram: the extension is what follows the last dot, nothing else
{
    const { sandbox, iframes, postCalls } = buildSandbox();
    loadScript(sandbox);
    let alerted = false;
    sandbox.alert = () => { alerted = true; };
    sandbox.edit_cb(makeImage('ns:release-v1.2.png'));
    assert.strictEqual(alerted, false, 'a dot in the diagram name must not be refused with an alert');
    assert.strictEqual(iframes.length, 1, 'the editor must open for ns:release-v1.2.png');
    assert.ok(postCalls.some((c) => c.data && c.data.imageName === 'ns:release-v1.2.png'),
        'the diagram must be addressed under its full id, dots included');
}

console.log('OK: a dot inside the diagram name does not block editing');

// --- advisory lock: it is never released on close, by construction -------
//
// The lock file is one shared resource per diagram (not one per tab), so
// closing either of two tabs on the same diagram must not release the
// other tab's still-active protection. There used to be an 'unlock' call
// in close() for both exit and save; it was removed, so both paths are
// asserted here to post no 'unlock' at all (there is no such action left
// server-side either).
{
    const { sandbox, messageListeners, iframes, postCalls } = buildSandbox();
    loadScript(sandbox);

    sandbox.edit_cb(makeImage('viaexit.png'));
    const receive = messageListeners[messageListeners.length - 1];
    const source = iframes[iframes.length - 1].contentWindow;

    receive({ source, data: JSON.stringify({ event: 'exit' }) });

    assert.ok(!postCalls.some((c) => c.data && c.data.action === 'unlock'),
        "exiting must not release the lock - it could be someone else's still-open tab");
}

console.log('OK: exiting the editor does not release the (shared) lock');

{
    const { sandbox, messageListeners, iframes, postCalls } = buildSandbox();
    loadScript(sandbox);

    sandbox.edit_cb(makeImage('viasave.png'));
    const receive = messageListeners[messageListeners.length - 1];
    const source = iframes[iframes.length - 1].contentWindow;

    receive({
        source,
        data: JSON.stringify({ event: 'export', format: 'xmlpng', data: 'data:image/png;base64,Zm9v' }),
    });

    assert.ok(!postCalls.some((c) => c.data && c.data.action === 'unlock'),
        'saving and closing must not release the lock either, for the same reason');
}

console.log('OK: saving and closing the editor does not release the (shared) lock');

// --- lock renewal timer details --------------------------------------------
//
// autosave/'save' only fire on a model *change* (per draw.io's own embed
// docs), not on a timer, so they cannot by themselves keep the lock alive
// for someone who opens a diagram and reads it a while before editing.
// edit_cb() schedules its own renewal interval for exactly that.
{
    const { sandbox, messageListeners, iframes, timers, clearedTimers } = buildSandbox();
    sandbox.JSINFO.plugin_drawio.locktime = 900; // core's default, seconds
    loadScript(sandbox);

    sandbox.edit_cb(makeImage('renewtimer.png'));

    assert.strictEqual(timers.length, 1, 'opening the editor must schedule exactly one renewal timer');
    assert.ok(timers[0].ms > 0 && timers[0].ms < 900 * 1000,
        'the renewal interval must be comfortably under locktime (900s): got ' + timers[0].ms + 'ms');

    const receive = messageListeners[messageListeners.length - 1];
    const source = iframes[iframes.length - 1].contentWindow;
    receive({ source, data: JSON.stringify({ event: 'exit' }) });

    assert.deepStrictEqual(clearedTimers, [timers[0].id], 'closing must clear the renewal timer');
}

console.log('OK: opening schedules a lock-renewal timer under locktime, and closing clears it');

{
    const { sandbox, timers, postCalls } = buildSandbox();
    loadScript(sandbox);

    sandbox.edit_cb(makeImage('renewfire.png'));
    const before = postCalls.filter((c) => c.data && c.data.action === 'lock').length;

    assert.strictEqual(timers.length, 1, 'test setup: a timer must have been scheduled');
    timers[0].fn(); // simulate the interval firing - this sandbox has no real event loop

    const after = postCalls.filter((c) => c.data && c.data.action === 'lock').length;
    assert.strictEqual(after, before + 1, 'the renewal timer must re-post the lock action');
}

console.log('OK: the renewal timer re-posts the lock action while the editor stays open');

{
    const { sandbox, timers } = buildSandbox();
    sandbox.JSINFO.plugin_drawio.locktime = 0;
    loadScript(sandbox);

    sandbox.edit_cb(makeImage('nolocking.png'));

    assert.strictEqual(timers.length, 0, 'locktime <= 0 must not schedule a renewal timer');
}

console.log('OK: no renewal timer is scheduled when locking is disabled site-wide (locktime <= 0)');

// --- ui config edge cases ---------------------------------------------------

// no ui configured (e.g. cached JSINFO from before this setting existed) ->
// falls back to the plugin's long-standing default, atlas
{
    const { sandbox, iframes } = buildSandbox();
    delete sandbox.JSINFO.plugin_drawio.ui;
    loadScript(sandbox);

    sandbox.edit_cb(makeImage('uifallback.png'));

    const src = iframes[iframes.length - 1].getAttribute('src');
    assert.ok(src.includes('ui=atlas'), 'missing ui config must fall back to atlas: ' + src);
}

console.log('OK: a missing ui config falls back to atlas');

// dark=auto is always appended, so a dark-capable theme (min/sketch/simple)
// follows the browser/OS preference for free
{
    const { sandbox, iframes } = buildSandbox();
    loadScript(sandbox);

    sandbox.edit_cb(makeImage('darkauto.png'));

    const src = iframes[iframes.length - 1].getAttribute('src');
    assert.ok(src.includes('dark=auto'), 'iframe src must ask for dark=auto: ' + src);
}

console.log('OK: dark=auto is always requested so dark-capable themes follow the OS preference');

// --- media manager: clicking the diagram's source (.drawio) opens it too ---
//
// edit() recognises a .drawio id and asks the server ('resolve_source')
// which rendering it belongs to before doing anything else; every action
// after that point (lock/get_png/get_svg/save/draft_*) still only ever
// sees a png/svg id, exactly as before.
{
    const { sandbox, postCalls, messageListeners, iframes } = buildSandbox();
    loadScript(sandbox);

    sandbox.edit(makeImage('probe:plan.drawio'));

    const resolveCall = postCalls.find((c) => c.data && c.data.action === 'resolve_source');
    assert.ok(resolveCall, 'a .drawio click must ask the server which rendering it belongs to');
    assert.strictEqual(resolveCall.data.imageName, 'probe:plan.drawio');
    assert.strictEqual(
        postCalls.some((c) => c.data && c.data.action === 'get_auth'),
        false,
        'resolve_source already answers what get_auth would - a .drawio click must not also call get_auth'
    );

    resolveCall.successCb({ granted: true, id: 'probe:plan.png' });

    assert.strictEqual(sandbox.currentDiagramId, 'probe:plan.png',
        'the editor must open on the resolved rendering id, not the .drawio id');

    const receive = messageListeners[messageListeners.length - 1];
    const source = iframes[iframes.length - 1].contentWindow;
    receive({ source, data: JSON.stringify({ event: 'init' }) });

    const getPngCall = postCalls.find((c) => c.data && c.data.action === 'get_png');
    assert.ok(getPngCall, 'the resolved id must drive the ordinary png open path');
    assert.strictEqual(getPngCall.data.imageName, 'probe:plan.png');
}

console.log("OK: clicking a diagram's source (.drawio) resolves to its rendering before opening");

// A denied resolve_source (same shape a denied get_auth already used) must not open the editor.
{
    const { sandbox, postCalls } = buildSandbox();
    loadScript(sandbox);

    sandbox.edit(makeImage('secret:hidden.drawio'));
    const resolveCall = postCalls.find((c) => c.data && c.data.action === 'resolve_source');

    resolveCall.successCb({ granted: false, id: 'secret:hidden.png' });
    assert.strictEqual(sandbox.editorOpen, false, 'a denied resolve_source must not open the editor');
    assert.strictEqual(resolveCall.failCb, null, 'resolve_source must be posted silently: true');
}

console.log('OK: a denied (or failed) resolve_source does not open the editor, silently, like get_auth');

// --- media manager button: markup/idempotency details -----------------------

// Must not appear for a file type that is neither a configured diagram
// rendering nor a .drawio source.
{
    const { sandbox, mmRoot } = buildSandbox();
    loadScript(sandbox);
    buildMediaPanelDom(mmRoot, 'test:readme.txt');

    sandbox.drawioAddMediaManagerButton();

    assert.strictEqual(querySelectorAll(mmRoot, 'li.drawio__mmbtn').length, 0, 'a non-diagram file must not get the button');
}

console.log('OK: the button does not appear for a non-diagram file type');

// core wraps buttons with no <form> and type="button" - pin those details,
// and that no unrelated <form> got added anywhere in the panel.
{
    const { sandbox, mmRoot } = buildSandbox();
    loadScript(sandbox);
    buildMediaPanelDom(mmRoot, 'test:plan.png');

    sandbox.drawioAddMediaManagerButton();

    const button = querySelectorAll(mmRoot, 'li.drawio__mmbtn button')[0];
    assert.strictEqual(button.getAttribute('type'), 'button',
        'type must be "button", not "submit" - there is no <form> here to (accidentally) submit');
    assert.strictEqual(querySelectorAll(mmRoot, 'form').length, 0,
        "unlike core's Delete/Upload, this control must not be wrapped in a <form>");
}

console.log('OK: the button markup has no <form> and type="button"');

// Idempotent: re-running after every ajax panel refresh must not stack a
// second button (script.js removes '.drawio__mmbtn' at the top of the
// function for exactly this reason).
{
    const { sandbox, mmRoot } = buildSandbox();
    loadScript(sandbox);
    buildMediaPanelDom(mmRoot, 'test:plan.png');

    sandbox.drawioAddMediaManagerButton();
    sandbox.drawioAddMediaManagerButton();
    sandbox.drawioAddMediaManagerButton();

    assert.strictEqual(querySelectorAll(mmRoot, 'li.drawio__mmbtn').length, 1, 'refreshing the panel must not stack a second button');
}

console.log('OK: re-running after a panel refresh stays idempotent');

// Clicking the button opens the editor (calls edit(), get_auth-gated exactly
// as before) rather than doing anything else - no navigation, no form
// submit. Covers the .png rendering and the .drawio source, since the two
// build different `target` elements internally.
{
    const { sandbox, mmRoot, postCalls } = buildSandbox();
    loadScript(sandbox);
    buildMediaPanelDom(mmRoot, 'test:plan.png');

    sandbox.drawioAddMediaManagerButton();
    const button = querySelectorAll(mmRoot, 'li.drawio__mmbtn button')[0];
    let prevented = false;
    button._handlers.click[0]({ preventDefault: () => { prevented = true; } });

    assert.ok(prevented, 'the click handler must call preventDefault()');
    const authCall = postCalls.find((c) => c.data && c.data.action === 'get_auth');
    assert.ok(authCall, 'clicking must open the editor through edit()');
    assert.strictEqual(authCall.data.imageName, 'test:plan.png');
}

console.log('OK: clicking the button opens the editor via edit(), same as before, and never navigates');

{
    const { sandbox, mmRoot, postCalls } = buildSandbox();
    loadScript(sandbox);
    buildMediaPanelDom(mmRoot, 'test:plan.drawio', { withImage: false });

    sandbox.drawioAddMediaManagerButton();
    const button = querySelectorAll(mmRoot, 'li.drawio__mmbtn button')[0];
    button._handlers.click[0]({ preventDefault: () => {} });

    const resolveCall = postCalls.find((c) => c.data && c.data.action === 'resolve_source');
    assert.ok(resolveCall, "clicking the .drawio source's button must go through edit()'s resolve_source path");
    assert.strictEqual(resolveCall.data.imageName, 'test:plan.drawio');
}

console.log("OK: clicking the button on a .drawio source resolves to its rendering, same as before");

// --- regression test for #32/#57: a full localStorage must not break save -
//
// A large diagram makes localStorage.setItem() throw QuotaExceededError.
// Before drawioLocalStorageSet()'s try/catch, that throw escaped the
// postMessage handler mid-way through the 'save' event, so the export
// message never reached the iframe and the server-side 'save' post (the
// durable copy - draft_save's ajax call) never happened either: "Updating
// page..." then nothing.
{
    const { sandbox, messageListeners, iframes, postCalls } = buildSandbox();
    loadScript(sandbox);

    const quotaError = new Error('QuotaExceededError');
    quotaError.name = 'QuotaExceededError';
    sandbox.localStorage.setItem = () => { throw quotaError; };

    sandbox.edit_cb(makeImage('full.png'));
    const receive = messageListeners[messageListeners.length - 1];
    const source = iframes[iframes.length - 1].contentWindow;

    assert.doesNotThrow(
        () => receive({ source, data: JSON.stringify({ event: 'save', xml: '<mxGraphModel>a</mxGraphModel>' }) }),
        'a full localStorage must not throw out of the save handler',
    );

    const draftSaveCall = postCalls.find((c) => c.data && c.data.action === 'draft_save');
    assert.ok(draftSaveCall,
        'the server-side draft_save must still be posted even though the local write failed');

    assert.doesNotThrow(
        () => receive({
            source,
            data: JSON.stringify({ event: 'export', format: 'xmlpng', data: 'data:image/png;base64,Zm9v' }),
        }),
        'a later export/save must still work after a local write failure',
    );
    const saveCall = postCalls.find((c) => c.data && c.data.action === 'save');
    assert.ok(saveCall, "the diagram must still be saved to the server ('save' action) despite the quota error");
}

console.log('OK: a full localStorage (QuotaExceededError) does not break save/autosave (#32/#57)');

// --- issue #62: drawioEditButtonClick() edge cases -------------------------

// A stale button (its image removed from the DOM between render and click,
// or a broken data-image-id) must not throw - just do nothing, same as a
// core control clicking on something already gone.
{
    const { sandbox, postCalls } = buildSandbox();
    loadScript(sandbox);

    const button = makeImage('nonexistent.png');
    button.setAttribute('data-image-id', 'nonexistent.png');
    sandbox.document.getElementById = () => null; // nothing found

    assert.doesNotThrow(() => sandbox.drawioEditButtonClick(button));
    assert.strictEqual(postCalls.length, 0, 'no image found must mean no ajax call at all');
}

console.log('OK: drawioEditButtonClick() on a button with no matching image does nothing (#62)');

// --- issue #50: top_offset edge cases --------------------------------------

// The default (0, or the key entirely missing on a page whose cached JSINFO
// predates this setting) must leave the iframe exactly as it always was -
// no top/height override at all, not just one computed to be a no-op.
{
    const { sandbox, iframes } = buildSandbox(); // topOffset absent from JSINFO
    loadScript(sandbox);

    sandbox.edit_cb(makeImage('nooffset.png'));

    const style = iframes[iframes.length - 1].getAttribute('style');
    assert.ok(!style.includes('top:'), 'no configured top_offset must mean no top override: ' + style);
    assert.ok(!style.includes('height:'), 'no configured top_offset must mean no height override: ' + style);
}

console.log('OK: a missing/zero top_offset leaves the iframe style unchanged (#50)');

{
    const { sandbox, iframes } = buildSandbox();
    sandbox.JSINFO.plugin_drawio.topOffset = 0; // explicit zero, not just absent
    loadScript(sandbox);

    sandbox.edit_cb(makeImage('zerooffset.png'));

    const style = iframes[iframes.length - 1].getAttribute('style');
    assert.ok(!style.includes('top:'), 'an explicit zero must mean no top override: ' + style);
}

console.log('OK: an explicit top_offset of 0 also leaves the iframe style unchanged (#50)');

// --- issue #30: drawioInitInteractive() edge cases -------------------------

// No configured viewer_url at all (a page whose cached JSINFO predates this
// setting) must still fall back to draw.io's public viewer script, the same
// fallback shape every other optional JSINFO key in this file already has.
{
    const { sandbox, iframes } = buildSandbox(); // viewer_url absent from JSINFO
    sandbox.document.querySelectorAll = () => [{ children: [], nextElementSibling: null }];
    loadScript(sandbox);

    sandbox.drawioInitInteractive();

    assert.strictEqual(iframes[0].getAttribute('src'), 'https://viewer.diagrams.net/js/viewer-static.min.js');
}

console.log('OK: a missing viewer_url config falls back to draw.io\'s public viewer script (#30)');

// A container without a '.drawio-interactive-fallback' sibling (or none at
// all) must not throw - only syntax.php's own markup ever has one, but a
// hostile/edited DOM must not crash this the same way every other DOM
// access in this file is defended (#16).
{
    const { sandbox, iframes } = buildSandbox();
    sandbox.MutationObserver = function () { this.observe = () => {}; this.disconnect = () => {}; };
    sandbox.document.querySelectorAll = () => [{ children: [], nextElementSibling: null }];
    loadScript(sandbox);

    assert.doesNotThrow(() => sandbox.drawioInitInteractive());
    assert.strictEqual(iframes.length, 1, 'the viewer script must still be injected');
}

console.log('OK: drawioInitInteractive() tolerates a container with no fallback sibling (#30)');

// A container that already has a rendered child (the viewer having run
// synchronously, e.g. from GraphViewer.cachedUrls) hides the fallback
// immediately, without waiting on a MutationObserver callback.
{
    const { sandbox, iframes } = buildSandbox();
    sandbox.MutationObserver = function () { this.observe = () => {}; this.disconnect = () => {}; };
    const fallback = { classList: { contains: (c) => c === 'drawio-interactive-fallback' }, hidden: false };
    const container = { children: [{}], nextElementSibling: fallback };
    sandbox.document.querySelectorAll = () => [container];
    loadScript(sandbox);

    sandbox.drawioInitInteractive();

    assert.strictEqual(fallback.hidden, true, 'a container already carrying a rendered child must hide its fallback right away');
    assert.strictEqual(iframes.length, 1, 'the viewer script is still injected for an already-rendered container');
}

console.log('OK: an already-rendered container hides its fallback sibling immediately (#30)');

// Acceptance row 9, for real: GraphViewer inserts its own (empty) SVG
// canvas into a container the instant it starts working on it - well
// before it has fetched/parsed that container's XML - so a 404 (or
// anything else it can't parse) makes it throw *after* the container
// already has a child. Checking container.children.length alone (as the
// code used to) would hide the fallback and leave an uncaught console
// error for exactly the row-9 case. drawioInitInteractive() now runs its
// own existence check (a HEAD against the same fetch.php URL) before ever
// letting GraphViewer see the container, and must not request the viewer
// script at all until every such check has settled - proven here with a
// controllable fetch() so the ordering, not just the end state, is
// checked.
(async () => {
    // document.querySelectorAll deliberately left at buildSandbox()'s
    // default (returns []) until after loadScript(): loadScript() itself
    // triggers one drawioInitInteractive() run via the jQuery-ready
    // callback at the bottom of script.js (this sandbox's jQuery(fn) calls
    // fn() synchronously) - if the container were already wired up by
    // then, that run would fire too, doubling every count this test
    // asserts on. One explicit, single call below is what is under test.
    const { sandbox, iframes } = buildSandbox();
    sandbox.MutationObserver = function () { this.observe = () => {}; this.disconnect = () => {}; };
    loadScript(sandbox);

    let resolveFetch;
    const fetchCalls = [];
    sandbox.fetch = (url, opts) => {
        fetchCalls.push({ url, opts });
        return new Promise((resolve) => { resolveFetch = resolve; });
    };

    const fallback = { classList: { contains: (c) => c === 'drawio-interactive-fallback' }, hidden: false };
    let removedClass = null;
    const container = {
        children: [],
        nextElementSibling: fallback,
        getAttribute: (name) => (name === 'data-mxgraph'
            ? JSON.stringify({ url: '/lib/exe/fetch.php?media=test:missing.drawio' })
            : null),
        classList: { remove: (cls) => { removedClass = cls; } },
    };
    sandbox.document.querySelectorAll = () => [container];

    sandbox.drawioInitInteractive();

    assert.strictEqual(fetchCalls.length, 1, 'a container with a data-mxgraph url must be existence-checked');
    assert.strictEqual(fetchCalls[0].url, '/lib/exe/fetch.php?media=test:missing.drawio',
        'the check must hit the exact same fetch.php url the viewer itself would use');
    assert.strictEqual(fetchCalls[0].opts.method, 'HEAD', 'the existence check must be a HEAD, not a full GET');
    assert.strictEqual(iframes.length, 0,
        'the viewer script must not be requested before the existence check settles');

    resolveFetch({ ok: false }); // the .drawio source 404s
    // A real setTimeout (this test's own Node realm), not another
    // Promise.resolve() tick: script.js's Promise.all runs inside the vm
    // sandbox's own separate realm, so settling the cross-realm fetch()
    // promise above takes a few microtask hops to propagate - a macrotask
    // boundary is what reliably waits out all of them, in any V8 version.
    await new Promise((resolve) => setTimeout(resolve, 0));

    assert.strictEqual(removedClass, 'mxgraph',
        "a 404'd source must be pulled out of GraphViewer's own scan (its 'mxgraph' class)");
    assert.strictEqual(iframes.length, 1, 'the viewer script is still requested once checks settle (other diagrams may need it)');
    assert.strictEqual(fallback.hidden, false,
        'a container GraphViewer never touched must never have its fallback hidden');

    console.log("OK: a 404'd .drawio source is kept out of GraphViewer's scan, and its fallback is never hidden (#30 row 9)");
})().catch((e) => {
    console.error(e);
    process.exitCode = 1;
});
