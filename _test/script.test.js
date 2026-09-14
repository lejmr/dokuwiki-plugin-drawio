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

    const sandbox = {
        JSINFO: {
            plugin_drawio: {
                url: 'https://embed.diagrams.net/',
                toolbar_possible_extension: ['png'],
                zIndex: 999,
            },
            id: 'test:page',
        },
        DOKU_BASE: '/',
        localStorage,
        console,
        jQuery: {
            // Stub network calls: the test never needs their callbacks to
            // fire (drafts are driven straight through localStorage).
            post: () => {},
            get: () => {},
        },
        document: {
            createElement: () => ({
                setAttribute() {},
                contentWindow: { postMessage() {} },
            }),
            body: { appendChild() {}, removeChild() {} },
            getElementById: () => null,
        },
        window: {
            addEventListener: (evt, fn) => { if (evt === 'message') messageListeners.push(fn); },
            removeEventListener: () => {},
            location: { href: 'http://localhost/doku.php?id=test' },
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
    return { sandbox, localStorage, messageListeners };
}

function loadScript(sandbox) {
    const code = fs.readFileSync(path.join(__dirname, '..', 'script.js'), 'utf8');
    vm.runInContext(code, sandbox);
}

function autosave(sandbox, messageListeners, xml) {
    const receive = messageListeners[messageListeners.length - 1];
    assert(typeof receive === 'function', 'no message listener registered by edit_cb');
    receive({ data: JSON.stringify({ event: 'autosave', xml }) });
}

// --- the actual test ---------------------------------------------------
const { sandbox, localStorage, messageListeners } = buildSandbox();
loadScript(sandbox);

// Editing diagramA and autosaving stores a draft under its own key.
sandbox.edit_cb(makeImage('diagramA.png'));
autosave(sandbox, messageListeners, '<mxGraphModel>A</mxGraphModel>');

// Editing a different diagram, diagramB, and autosaving must not touch
// diagramA's draft, and must land under its own distinct key.
sandbox.edit_cb(makeImage('diagramB.png'));
autosave(sandbox, messageListeners, '<mxGraphModel>B</mxGraphModel>');

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
