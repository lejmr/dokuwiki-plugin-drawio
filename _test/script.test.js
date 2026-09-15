// Regression test for #26: a draft for one diagram must not be offered (or
// overwrite) another diagram's draft. Loads script.js in a stubbed sandbox
// (no real browser/DOM) and drives edit_cb() + the postMessage 'receive'
// handler directly, since drawio's own iframe can't be exercised headlessly.
//
// Run with: node _test/script.test.js

const fs = require('fs');
const path = require('path');
const vm = require('vm');
const assert = require('assert');

// --- minimal localStorage stub -------------------------------------------
function makeLocalStorage() {
    const store = new Map();
    return {
        getItem: (k) => (store.has(k) ? store.get(k) : null),
        setItem: (k, v) => store.set(k, String(v)),
        removeItem: (k) => store.delete(k),
        _store: store,
    };
}

// --- minimal DOM/browser stubs ---------------------------------------------
function makeImage(id) {
    return {
        id,
        _attrs: { id },
        getAttribute(name) { return this._attrs[name]; },
        setAttribute(name, value) { this._attrs[name] = value; },
    };
}

function buildSandbox() {
    const localStorage = makeLocalStorage();
    const messageListeners = [];
    // one entry per document.createElement() call, in order - script.js checks
    // evt.source against the current iframe's contentWindow (object identity,
    // not a string), so tests need the real object, not a URL to compare.
    const iframes = [];
    // one entry per jQuery.post() call, in call order, so a test can find e.g.
    // the 'save' post and fire its .fail() callback to simulate the server
    // rejecting it (bad extension/payload/permission all surface the same way:
    // a non-2xx response, i.e. jQuery's fail()).
    const postCalls = [];
    const alerts = [];
    let reloaded = false;
    // one entry per setInterval() call - {id, fn, ms} - so a test can both
    // assert on the scheduled interval and fire it manually (this sandbox
    // has no real event loop). clearedTimers records every id passed to
    // clearInterval(), in call order.
    const timers = [];
    const clearedTimers = [];
    let nextTimerId = 1;

    const sandbox = {
        JSINFO: {
            plugin_drawio: {
                url: 'https://embed.diagrams.net/',
                toolbar_possible_extension: ['png'],
                zIndex: 999,
                sectok: 'sek-test-default',
                locktime: 900,
            },
            id: 'test:page',
        },
        DOKU_BASE: '/',
        localStorage,
        console,
        alert: (msg) => alerts.push(msg),
        setInterval: (fn, ms) => {
            const id = nextTimerId++;
            timers.push({ id, fn, ms });
            return id;
        },
        clearInterval: (id) => { clearedTimers.push(id); },
        jQuery: {
            // Stub network calls: most tests never need their callbacks to fire
            // (drafts are driven straight through localStorage) - but .done()/
            // .fail() must be chainable, since script.js's 'save' post relies on
            // both (the draft is only cleared once the save succeeds).
            post: (url, data, success) => {
                const call = { url, data, failCb: null, doneCb: null, successCb: success || null };
                postCalls.push(call);
                const chain = {
                    fail: (cb) => { call.failCb = cb; return chain; },
                    done: (cb) => { call.doneCb = cb; return chain; },
                };
                return chain;
            },
            get: () => {},
        },
        document: {
            createElement: () => {
                const attrs = {};
                const iframe = {
                    setAttribute(name, value) { attrs[name] = value; },
                    getAttribute(name) { return attrs[name]; },
                    contentWindow: { postMessage() {} },
                };
                iframes.push(iframe);
                return iframe;
            },
            body: { appendChild() {}, removeChild() {} },
            getElementById: () => null,
        },
        window: {
            addEventListener: (evt, fn) => { if (evt === 'message') messageListeners.push(fn); },
            removeEventListener: () => {},
            location: { href: 'http://localhost/doku.php?id=test', reload: () => { reloaded = true; } },
        },
        URL,
        atob: (s) => Buffer.from(s, 'base64').toString('binary'),
        // A stale/misattributed draft is exactly what #26 was about; always
        // accept it so the code path under test runs instead of blocking on
        // a native confirm() dialog that doesn't exist here.
        confirm: () => true,
    };
    sandbox.global = sandbox;
    vm.createContext(sandbox);
    return {
        sandbox, localStorage, messageListeners, iframes, postCalls, alerts,
        timers, clearedTimers,
        wasReloaded: () => reloaded,
    };
}

function loadScript(sandbox) {
    const code = fs.readFileSync(path.join(__dirname, '..', 'script.js'), 'utf8');
    vm.runInContext(code, sandbox);
}

function autosave(sandbox, messageListeners, xml, iframes) {
    const receive = messageListeners[messageListeners.length - 1];
    assert(typeof receive === 'function', 'no message listener registered by edit_cb');
    const source = iframes[iframes.length - 1].contentWindow;
    receive({ source, data: JSON.stringify({ event: 'autosave', xml }) });
}

// --- the actual test ---------------------------------------------------
const { sandbox, localStorage, messageListeners, iframes } = buildSandbox();
loadScript(sandbox);

// Editing diagramA and autosaving stores a draft under its own key.
sandbox.edit_cb(makeImage('diagramA.png'));
autosave(sandbox, messageListeners, '<mxGraphModel>A</mxGraphModel>', iframes);

// edit_cb() now refuses to open a second editor while one is open (#4/#5
// fix, tested on its own below) - reset the flag the way any of the real
// close paths (save/exit) do, without going through their side effects
// (which include clearing the draft this test is about to check for).
sandbox.editorOpen = false;

// Editing a different diagram, diagramB, and autosaving must not touch
// diagramA's draft, and must land under its own distinct key.
sandbox.edit_cb(makeImage('diagramB.png'));
autosave(sandbox, messageListeners, '<mxGraphModel>B</mxGraphModel>', iframes);

const keyA = '.draft-diagramA.png';
const keyB = '.draft-diagramB.png';

assert.notStrictEqual(keyA, keyB, 'sanity: keys must differ');
assert.strictEqual(localStorage.getItem('.draft-null'), null,
    'no draft should ever be stored under the old broken .draft-null key');

const draftA = localStorage.getItem(keyA);
const draftB = localStorage.getItem(keyB);
assert.ok(draftA, 'diagramA draft missing');
assert.ok(draftB, 'diagramB draft missing');
assert.notStrictEqual(draftA, draftB, 'both diagrams got the same draft content');
assert.ok(JSON.parse(draftA).xml.includes('A'), 'diagramA draft has wrong content');
assert.ok(JSON.parse(draftB).xml.includes('B'), 'diagramB draft has wrong content');

// The stale write to a bare `name` key (pre-fix line 184) must be gone: it
// was never read back by anything, so it should not reappear.
assert.strictEqual(localStorage.getItem('diagramA.png'), null,
    'no dead localStorage entry should be written under the bare diagram id');

console.log('OK: two diagrams get two independent draft keys (#26)');

// --- regression test for #16: media manager has no JSINFO.plugin_drawio ---
//
// lib/exe/js.php concatenates every enabled plugin's script.js into one
// response (js_pluginscripts()). Before the fix, script.js dereferenced
// JSINFO['plugin_drawio'] at the top level, so on any page where that key is
// missing - e.g. the fullscreen media manager, which never fires
// DOKUWIKI_STARTED - loading it threw a TypeError and aborted the *whole*
// concatenated response, silently breaking every plugin script that sorts
// after "drawio" in the bundle (that is what issue #16, blamed for years on
// the struct/CKGEdit plugins, actually was).
//
// This drives vm.runInContext() twice back to back, exactly like js.php
// concatenates two scripts into one <script> tag: first script.js with a
// media-manager-shaped JSINFO (no plugin_drawio key), then a second,
// unrelated script. Both must run.
{
    const mmSandbox = {
        // shape actually observed on lib/exe/mediamanager.php: no plugin_drawio
        JSINFO: { id: null, namespace: '', ACT: 'show' },
        window: {},
        console,
    };
    mmSandbox.global = mmSandbox;
    vm.createContext(mmSandbox);

    const scriptJs = fs.readFileSync(path.join(__dirname, '..', 'script.js'), 'utf8');
    let threw = null;
    try {
        vm.runInContext(scriptJs, mmSandbox);
        // simulate the next plugin's script, concatenated right after ours
        vm.runInContext('window.markerFromNextPlugin = true;', mmSandbox);
    } catch (e) {
        threw = e;
    }

    assert.strictEqual(threw, null,
        'script.js must not throw when JSINFO.plugin_drawio is absent (issue #16): ' +
        (threw && threw.stack));
    assert.strictEqual(mmSandbox.window.markerFromNextPlugin, true,
        'a plugin script concatenated after script.js must still run');
}

console.log('OK: script.js does not break other plugins when JSINFO.plugin_drawio is absent (#16)');

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

    // a synthesised autosave posted with some other window as evt.source
    // (e.g. an unrelated script/frame on the page) must be ignored
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
// on the page uneditable until a reload - worse than the double-click bug
// above. Reachable whenever the embed posts a non-JSON string (a partial
// message during load, a heartbeat, a protocol hiccup).
{
    const { sandbox, messageListeners, iframes } = buildSandbox();
    loadScript(sandbox);

    sandbox.edit_cb(makeImage('malformed.png'));
    const receive = messageListeners[messageListeners.length - 1];
    const source = iframes[iframes.length - 1].contentWindow;

    // script.js is right to console.log this in a real browser - swap in a
    // capturing stub for this one call so a passing test run stays quiet,
    // without touching the real (shared) console object, then assert on what
    // got logged rather than just swallowing it.
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
        'the malformed message should still be logged for debugging, just not to this test\'s stdout');

    assert.strictEqual(sandbox.editorOpen, true,
        'editorOpen must survive a malformed message - the editor is still open, just got noise');

    // and the editor must still be usable afterwards
    autosave(sandbox, messageListeners, '<mxGraphModel>ok</mxGraphModel>', iframes);
    assert.ok(sandbox.localStorage.getItem('.draft-malformed.png'),
        'a later, well-formed message must still work after a malformed one');
}

console.log('OK: a non-JSON postMessage does not wedge the editor open (#5 JSON.parse guard)');

// --- regression test: a rejected save must not lose the diagram ----------
//
// The 'export' handler updates the visible image and closes the editor
// immediately, then fires the 'save' ajax post. If the server rejects it (bad
// extension/payload/permission - all of which action.php now can) the user
// otherwise has no way to know the file on disk is unchanged - unless the
// draft (localStorage + on-disk) is still there to fall back on. That draft
// used to be wiped unconditionally *before* the save post even went out, so
// a rejected save meant the change existed nowhere at all: not on disk (the
// server rejected it), not in the draft either.
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

    // simulate the server rejecting it (bad extension/payload/permission -
    // action.php now returns a non-2xx status for all three)
    saveCall.failCb();

    assert.strictEqual(alerts.length, 1, 'a rejected save must alert the user, not pretend to have worked');
    assert.ok(wasReloaded(), 'the page must reload so the lying "saved" image does not stick around');

    assert.ok(sandbox.localStorage.getItem(draftKey),
        'a rejected save must NOT clear the draft - it is the only copy of the change left');
    assert.ok(!postCalls.some((c) => c.data && c.data.action === 'draft_rm'),
        'a rejected save must NOT clean up the on-disk draft either');
}

console.log('OK: a rejected save keeps the draft instead of losing the diagram');

// --- regression test for S1: every ajax call must carry the security token
//
// The server-side ajax handler (action.php) is being hardened in parallel to
// require DokuWiki's CSRF/security token as the `sectok` request param and
// reject anything else. This drives as many of script.js's ajax call sites
// as the harness can reach and asserts that *every* recorded post carries a
// `sectok` matching JSINFO['plugin_drawio']['sectok'] - the assertion walks
// every call generically rather than a hardcoded list of today's seven
// actions, so a future call that forgets the token fails this too.
{
    const SECTOK = 'sek-9f8e7d';
    const { sandbox, messageListeners, iframes, postCalls } = buildSandbox();
    sandbox.JSINFO.plugin_drawio.sectok = SECTOK;
    loadScript(sandbox);

    // get_auth
    sandbox.edit(makeImage('sectok.png'));

    // draft_get (no local draft -> asks the server for an on-disk one)
    sandbox.edit_cb(makeImage('sectok.png'));
    const receive = messageListeners[messageListeners.length - 1];
    const source = iframes[iframes.length - 1].contentWindow;

    // init -> get_png (draft is still null: the stub never auto-invokes
    // draft_get's callback, same as a request that hasn't come back yet)
    receive({ source, data: JSON.stringify({ event: 'init' }) });

    // autosave -> draft_save
    receive({ source, data: JSON.stringify({ event: 'autosave', xml: '<mxGraphModel/>' }) });

    // save (drawio's own pre-export event) -> draft_save again
    receive({ source, data: JSON.stringify({ event: 'save', xml: '<mxGraphModel/>' }) });

    // export -> save, then its .done() -> draft_rm cleanup
    receive({
        source,
        data: JSON.stringify({ event: 'export', format: 'xmlpng', data: 'data:image/png;base64,Zm9v' }),
    });
    const saveCall = postCalls.find((c) => c.data && c.data.action === 'save');
    assert.ok(saveCall && typeof saveCall.doneCb === 'function', 'test setup: save must be postable');
    saveCall.doneCb();

    // a second diagram, as .svg, to reach the get_svg action
    const svg = buildSandbox();
    svg.sandbox.JSINFO.plugin_drawio.sectok = SECTOK;
    svg.sandbox.JSINFO.plugin_drawio.toolbar_possible_extension = ['svg'];
    loadScript(svg.sandbox);
    svg.sandbox.edit_cb(makeImage('sectok.svg'));
    const svgReceive = svg.messageListeners[svg.messageListeners.length - 1];
    const svgSource = svg.iframes[svg.iframes.length - 1].contentWindow;
    svgReceive({ source: svgSource, data: JSON.stringify({ event: 'init' }) });

    const allCalls = postCalls.concat(svg.postCalls);
    const actionsSeen = allCalls.map((c) => c.data && c.data.action);
    ['get_auth', 'draft_get', 'get_png', 'draft_save', 'save', 'draft_rm', 'get_svg'].forEach((a) => {
        assert.ok(actionsSeen.includes(a), 'test setup: expected to exercise action ' + a);
    });

    allCalls.forEach((c) => {
        assert.strictEqual(c.data && c.data.sectok, SECTOK,
            "ajax call missing/wrong sectok (action: '" + (c.data && c.data.action) + "')");
    });
}

console.log('OK: every ajax call carries the DokuWiki security token (sectok)');

// --- get_auth now reads as a real boolean, and fails silently ------------
//
// action.php now sends application/json for get_auth like every other JSON
// action, so jQuery hands edit()'s callback a real boolean - the old
// `data != 'true'` string compare must be gone. And the call itself must be
// silent: a denial (a successful response carrying `false`) already shows
// the user nothing, so a failed request (e.g. an expired sectok) must not
// show the noisy generic "request failed" alert either - the two must not
// disagree about how loud they are.
{
    const { sandbox, postCalls, alerts } = buildSandbox();
    loadScript(sandbox);

    sandbox.edit(makeImage('perm.png'));
    const authCall = postCalls.find((c) => c.data && c.data.action === 'get_auth');
    assert.ok(authCall, 'test setup: get_auth must be posted');

    // permission granted: a real boolean true, not the string 'true'
    authCall.successCb(true);
    assert.strictEqual(sandbox.editorOpen, true, 'a true response must open the editor');
    sandbox.editorOpen = false;

    // permission denied: must not open the editor and must not alert
    authCall.successCb(false);
    assert.strictEqual(sandbox.editorOpen, false, 'a false response must not open the editor');
    assert.strictEqual(alerts.length, 0, 'a permission denial must not alert');

    // the request itself failing (e.g. an expired sectok, a 403) must be
    // just as quiet - drawioPost only attaches its generic alert when not
    // silent, so silent:true must mean no .fail() handler was ever wired
    assert.strictEqual(authCall.failCb, null,
        'get_auth must be posted silently: true, so a failed request does not alert');
}

console.log('OK: get_auth is read as a real boolean and fails silently, like a denial does');

// --- regression test for S6: SVG export must not innerHTML raw markup ----
//
// edit_cb()'s 'export' handler used to decode the data URI drawio's iframe
// posts back and assign it straight into the page with element.innerHTML =
// imgData. That content comes from the editor iframe (ultimately, from
// whatever diagram content someone fed into it) and inline event handlers
// (onload, onerror, ...) fire on markup inserted this way - lib/exe/fetch.php
// sends a strict CSP, but this path never goes through it, and doku.php
// sends no CSP at all. This drives the 'export'/'svg' branch directly and
// asserts it never touches innerHTML and that a handler embedded in the
// payload never runs.
//
// It also pins the fix for the bug this same branch used to carry: the old
// replaceChild() swapped in a brand new <img> with a hardcoded class/style,
// discarding the width/height/title/alt syntax.php had computed for this
// diagram until the next page load. Setting src on the existing node (same
// as the png branch) keeps them - asserted here by checking the existing
// image's attributes are untouched aside from src.
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

    assert.strictEqual(image.innerHTML, undefined,
        'raw SVG markup must never be assigned via innerHTML');
    assert.strictEqual(image.getAttribute('src'), svgDataUri,
        'the freshly saved SVG must be loaded via the existing image\'s src, not inlined as markup');
    assert.strictEqual(sandbox.window.pwned, undefined,
        'a handler embedded in the SVG payload must never execute');
    // the size/title syntax.php computed must survive the save - this is
    // exactly what the old replaceChild()-with-a-fresh-<img> approach lost
    assert.strictEqual(image.getAttribute('title'), 'My title',
        'title must survive an svg export');
    assert.strictEqual(image.getAttribute('style'), 'max-width:100%;cursor:pointer;width:200px;height:100px;',
        'width/height (via style) must survive an svg export');
}

console.log('OK: SVG export does not inject raw markup into the page (S6)');

// --- the diagram source must travel with the save, and be preferred on open
//
// The editor hands script.js the diagram XML on its 'save' event and the
// exported image on the following 'export' event. The XML used to be dropped
// on the floor, so the only copy of the source was whatever the exporter
// buried inside the image - lost the moment anything rewrote that file. The
// save post must carry it, and the open path must load the source the server
// returns rather than re-parsing the image.
{
    const { sandbox, messageListeners, iframes, postCalls } = buildSandbox();
    loadScript(sandbox);

    sandbox.edit_cb(makeImage('source.png'));
    const receive = messageListeners[messageListeners.length - 1];
    const source = iframes[iframes.length - 1].contentWindow;

    const xml = '<mxfile host="embed"><diagram>source of truth</diagram></mxfile>';
    receive({ source, data: JSON.stringify({ event: 'save', xml }) });
    receive({
        source,
        data: JSON.stringify({ event: 'export', format: 'xmlpng', data: 'data:image/png;base64,Zm9v' }),
    });

    const saveCall = postCalls.find((c) => c.data && c.data.action === 'save');
    assert.ok(saveCall, "the 'export' handler must post action:'save'");
    assert.strictEqual(saveCall.data.xml, xml,
        "the save post must carry the diagram XML, not just the exported image");
}

console.log('OK: saving a diagram sends its XML source alongside the image');

// the open path: when the server has a source, load from it; otherwise fall
// back to the old xmlpng/xmlsvg extraction so existing diagrams keep working
function loadMessageFor(serverReply, imageId, ext) {
    const { sandbox, messageListeners, iframes, postCalls } = buildSandbox();
    sandbox.JSINFO.plugin_drawio.toolbar_possible_extension = [ext];
    loadScript(sandbox);

    const posted = [];
    sandbox.edit_cb(makeImage(imageId));
    const receive = messageListeners[messageListeners.length - 1];
    const frame = iframes[iframes.length - 1];
    frame.contentWindow.postMessage = (m) => posted.push(JSON.parse(m));

    receive({ source: frame.contentWindow, data: JSON.stringify({ event: 'init' }) });
    const getCall = postCalls.find((c) => c.data && /^get_(png|svg)$/.test(c.data.action));
    assert.ok(getCall && getCall.successCb, 'init must ask the server for the diagram');
    getCall.successCb(serverReply);
    return posted[posted.length - 1];
}

{
    const xml = '<mxfile><diagram>from the source file</diagram></mxfile>';
    const msg = loadMessageFor({ content: 'data:image/png;base64,Zm9v', xml }, 'hassource.png', 'png');
    assert.strictEqual(msg.action, 'load');
    assert.strictEqual(msg.xml, xml, 'the editor must be loaded from the source XML');
    assert.strictEqual(msg.xmlpng, undefined, 'the image must not be re-parsed when a source exists');

    const legacy = loadMessageFor({ content: 'data:image/png;base64,Zm9v' }, 'nosource.png', 'png');
    assert.strictEqual(legacy.xmlpng, 'data:image/png;base64,Zm9v',
        'a diagram with no source must still open from its image');

    const svg = loadMessageFor({ content: 'data:image/svg+xml;base64,Zm9v', xml }, 'has.svg', 'svg');
    assert.strictEqual(svg.xml, xml, 'an svg diagram must prefer its source too');
}

console.log('OK: opening a diagram prefers the source file and falls back to the image');

// --- advisory diagram lock -------------------------------------------------
//
// Two tabs editing the same diagram silently overwriting each other, with no
// warning to either person, is the bug this whole change is for. The editor
// must take the lock on open, warn (without blocking) when someone else
// already holds it, and release it on every way the editor closes.

// opening a diagram must ask the server for the lock, and open regardless
{
    const { sandbox, iframes, postCalls } = buildSandbox();
    loadScript(sandbox);

    sandbox.edit_cb(makeImage('lockme.png'));

    const lockCall = postCalls.find((c) => c.data && c.data.action === 'lock');
    assert.ok(lockCall, 'opening a diagram must ask the server for its lock status');
    assert.strictEqual(lockCall.data.imageName, 'lockme.png');

    // the editor must not wait for the lock response before opening - same
    // as it does not wait for draft_get/get_png/get_svg today
    assert.strictEqual(iframes.length, 1, 'the editor must open without waiting for the lock response');
}

console.log('OK: opening a diagram asks the server for its lock and does not wait on the answer');

// nobody else holds the lock -> no warning
{
    const { sandbox, alerts, postCalls } = buildSandbox();
    loadScript(sandbox);

    sandbox.edit_cb(makeImage('free.png'));
    const lockCall = postCalls.find((c) => c.data && c.data.action === 'lock');
    assert.ok(lockCall && lockCall.successCb, 'test setup: lock must be postable');
    lockCall.successCb({ locked_by: null, since: null });

    assert.strictEqual(alerts.length, 0, 'no one else holds the lock - nothing to warn about');
}

console.log('OK: no warning is shown when nobody else holds the lock');

// someone else holds the lock -> named in a warning, but the editor still
// opens either way ("the warning is information, not a gate")
{
    const { sandbox, alerts, postCalls } = buildSandbox();
    loadScript(sandbox);

    sandbox.edit_cb(makeImage('contested.png'));
    const lockCall = postCalls.find((c) => c.data && c.data.action === 'lock');
    const since = Math.floor(Date.now() / 1000) - 120;
    lockCall.successCb({ locked_by: 'alice', since });

    assert.strictEqual(alerts.length, 1, 'someone else holding the lock must be warned about');
    assert.ok(alerts[0].includes('alice'), 'the warning must name who holds it: ' + alerts[0]);
    // the warning is informational only - nothing about it may have closed
    // the editor that already opened
    assert.strictEqual(sandbox.editorOpen, true, 'the editor must stay open after the warning');
}

console.log('OK: a warning names the existing holder and does not gate opening the editor (informational only)');

// Closing the editor must NOT release the lock any more - not via 'exit',
// not via a successful save (export). There used to be an 'unlock' call in
// close() for both; it was removed because the lock file is one shared
// resource per diagram (not one per tab), so closing either of two tabs on
// the same diagram deleted the *other* tab's still-active protection - see
// script.js's close() comment. Simply never unlocking fixes that by
// construction, so both paths are asserted here to post no 'unlock' at all
// (there is no such action left server-side either).
{
    const { sandbox, messageListeners, iframes, postCalls } = buildSandbox();
    loadScript(sandbox);

    sandbox.edit_cb(makeImage('viaexit.png'));
    const receive = messageListeners[messageListeners.length - 1];
    const source = iframes[iframes.length - 1].contentWindow;

    receive({ source, data: JSON.stringify({ event: 'exit' }) });

    assert.ok(!postCalls.some((c) => c.data && c.data.action === 'unlock'),
        "exiting must not release the lock - it could be someone else's still-open tab or rendering");
}

console.log("OK: exiting the editor does not release the (shared) lock");

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

// --- lock renewal timer ----------------------------------------------------
//
// autosave/'save' only fire on a model *change* (per draw.io's own embed
// docs), not on a timer, so they cannot by themselves keep the lock alive
// for someone who opens a diagram and reads it a while before editing -
// the ordinary prelude to editing, not a forgotten tab. edit_cb() schedules
// its own renewal interval for exactly that.

// opening schedules a renewal interval comfortably under locktime, and
// closing clears it
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

// the timer firing actually re-posts 'lock'
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

// locking disabled site-wide (locktime <= 0, core's own convention) -> no
// timer to schedule, nothing to renew
{
    const { sandbox, timers } = buildSandbox();
    sandbox.JSINFO.plugin_drawio.locktime = 0;
    loadScript(sandbox);

    sandbox.edit_cb(makeImage('nolocking.png'));

    assert.strictEqual(timers.length, 0, 'locktime <= 0 must not schedule a renewal timer');
}

console.log('OK: no renewal timer is scheduled when locking is disabled site-wide (locktime <= 0)');

// --- configurable editor interface (ui=atlas was hardcoded) -----------------
//
// The editor's URL used to always ask for ui=atlas, one of several
// interfaces draw.io offers (kennedy/min/atlas/dark/sketch/simple - see
// https://www.drawio.com/doc/faq/supported-url-parameters). It's now read
// from the plugin config, published through JSINFO like every other setting
// this plugin has (see conf/metadata.php, action.php's addjsinfo()).

// the admin's chosen interface reaches the iframe's src
{
    const { sandbox, iframes } = buildSandbox();
    sandbox.JSINFO.plugin_drawio.ui = 'min';
    loadScript(sandbox);

    sandbox.edit_cb(makeImage('uitest.png'));

    const src = iframes[iframes.length - 1].getAttribute('src');
    assert.ok(src.includes('ui=min'), 'iframe src must carry the configured ui: ' + src);
    assert.ok(!src.includes('ui=atlas'), 'iframe src must not still hardcode ui=atlas: ' + src);
}

console.log('OK: the configured ui reaches the editor iframe URL');

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
// follows the browser/OS preference for free - inert for kennedy/atlas/dark,
// see draw.io's own docs for the dark= parameter.
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
// The media manager button used to only ever appear for a rendering
// (ns:plan.png/.svg) - selecting the source itself, the more important half
// of the pair, offered nothing. edit() now recognises a .drawio id and asks
// the server ('resolve_source') which rendering it belongs to before doing
// anything else; every action after that point (lock/get_png/get_svg/save/
// draft_*) still only ever sees a png/svg id, exactly as before.
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

    assert.strictEqual(
        sandbox.currentDiagramId,
        'probe:plan.png',
        'the editor must open on the resolved rendering id, not the .drawio id'
    );

    // edit_cb() only asks for the diagram's content once the editor iframe
    // itself reports it is ready - drive that the same way every other test
    // in this file does.
    const receive = messageListeners[messageListeners.length - 1];
    const source = iframes[iframes.length - 1].contentWindow;
    receive({ source, data: JSON.stringify({ event: 'init' }) });

    const getPngCall = postCalls.find((c) => c.data && c.data.action === 'get_png');
    assert.ok(getPngCall, 'the resolved id must drive the ordinary png open path, exactly as a direct .png click would');
    assert.strictEqual(getPngCall.data.imageName, 'probe:plan.png');
}

console.log('OK: clicking a diagram\'s source (.drawio) resolves to its rendering before opening');

// A denied resolve_source (same shape a denied get_auth already used) must not open the editor.
{
    const { sandbox, postCalls } = buildSandbox();
    loadScript(sandbox);

    sandbox.edit(makeImage('secret:hidden.drawio'));
    const resolveCall = postCalls.find((c) => c.data && c.data.action === 'resolve_source');

    resolveCall.successCb({ granted: false, id: 'secret:hidden.png' });
    assert.strictEqual(sandbox.editorOpen, false, 'a denied resolve_source must not open the editor');

    // and a request that fails outright (expired sectok, network hiccup)
    // must stay just as quiet as get_auth's does - see that test above
    assert.strictEqual(resolveCall.failCb, null, 'resolve_source must be posted silently: true');
}

console.log('OK: a denied (or failed) resolve_source does not open the editor, silently, like get_auth');
