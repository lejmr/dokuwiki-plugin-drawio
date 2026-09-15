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

    const sandbox = {
        JSINFO: {
            plugin_drawio: {
                url: 'https://embed.diagrams.net/',
                toolbar_possible_extension: ['png'],
                zIndex: 999,
                sectok: 'sek-test-default',
            },
            id: 'test:page',
        },
        DOKU_BASE: '/',
        localStorage,
        console,
        alert: (msg) => alerts.push(msg),
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
                const iframe = { setAttribute() {}, contentWindow: { postMessage() {} } };
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

// --- regression test for S6: SVG export must not innerHTML raw markup ----
//
// edit_cb()'s 'export' handler used to decode the data URI drawio's iframe
// posts back and assign it straight into the page with element.innerHTML =
// imgData. That content comes from the editor iframe (ultimately, from
// whatever diagram content someone fed into it) and inline event handlers
// (onload, onerror, ...) fire on markup inserted this way - lib/exe/fetch.php
// sends a strict CSP, but this path never goes through it, and doku.php
// sends no CSP at all. This drives the 'export'/'svg' branch directly and
// asserts the DOM node it produces was never handed raw markup via
// innerHTML, and that a handler embedded in the payload never runs.
{
    const { sandbox, messageListeners, iframes } = buildSandbox();
    loadScript(sandbox);

    sandbox.edit_cb(makeImage('malicious.svg'));
    const receive = messageListeners[messageListeners.length - 1];
    const source = iframes[iframes.length - 1].contentWindow;

    // the DOM node the export handler looks up (via document.getElementById)
    // and replaces - a plain stub, not a real DOM, since this harness has no
    // browser
    let replaced = null;
    const tdElement = { id: 'malicious.svg', parentNode: null };
    const trElement = {
        style: {},
        replaceChild: (newNode) => { replaced = newNode; },
    };
    tdElement.parentNode = trElement;
    sandbox.document.getElementById = (id) => (id === 'malicious.svg' ? tdElement : null);
    // createElement('img') needs its own stub distinct from the generic
    // iframe stub above (which lacks a settable .src)
    sandbox.document.createElement = (tag) => (
        tag === 'img' ? { setAttribute() {} } : { setAttribute() {}, contentWindow: { postMessage() {} } }
    );

    const payload = '<svg onload="window.pwned = true"><a href="x">y</a></svg>';
    const svgDataUri = 'data:image/svg+xml;base64,' + Buffer.from(payload).toString('base64');
    receive({ source, data: JSON.stringify({ event: 'export', format: 'svg', data: svgDataUri }) });

    assert.ok(replaced, 'the export handler must still replace the diagram DOM node');
    assert.strictEqual(replaced.innerHTML, undefined,
        'raw SVG markup must never be assigned via innerHTML');
    assert.strictEqual(replaced.src, svgDataUri,
        'the freshly saved SVG must be loaded as an <img> src, not inlined as markup');
    assert.strictEqual(sandbox.window.pwned, undefined,
        'a handler embedded in the SVG payload must never execute');
}

console.log('OK: SVG export does not inject raw markup into the page (S6)');
