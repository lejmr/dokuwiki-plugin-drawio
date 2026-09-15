// Shared sandbox helpers for script.test.js's golden/extra tiers. Plain
// node:vm, no framework, no dependencies - see DEVELOPMENT.md.
//
// Loads script.js into a stubbed sandbox (no real browser/DOM) so
// _test/golden/script.test.js and _test/extra/script.test.js can drive
// edit_cb() and the postMessage 'receive' handler directly, since drawio's
// own iframe can't be exercised headlessly.

const fs = require('fs');
const path = require('path');
const vm = require('vm');
const assert = require('assert');

const SCRIPT_JS = path.join(__dirname, '..', 'script.js');

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

// --- minimal fake DOM + jQuery ---------------------------------------------
//
// Just enough to drive drawioAddMediaManagerButton() and inspect exactly
// what it builds - not a general DOM/jQuery reimplementation. In
// particular querySelectorAll() below only understands the shape of
// selector script.js actually uses ("tag.class tag2.class2 ..."
// descendant combinators), not full CSS.
function makeNode(tag, attrs) {
    return {
        tagName: tag.toUpperCase(),
        attrs: Object.assign({}, attrs),
        children: [],
        parent: null,
        textContent: '',
        getAttribute(name) {
            return Object.prototype.hasOwnProperty.call(this.attrs, name) ? this.attrs[name] : null;
        },
        setAttribute(name, value) { this.attrs[name] = value; },
        hasClass(cls) { return (this.attrs['class'] || '').split(/\s+/).indexOf(cls) !== -1; },
    };
}

function appendChild(parent, child) {
    child.parent = parent;
    parent.children.push(child);
    return child;
}

function removeChild(el) {
    if (!el.parent) return;
    const idx = el.parent.children.indexOf(el);
    if (idx !== -1) el.parent.children.splice(idx, 1);
    el.parent = null;
}

function querySelectorAll(root, selector) {
    const parts = selector.trim().split(/\s+/).map((part) => {
        const m = part.match(/^([a-zA-Z0-9]*)((?:\.[\w-]+)*)$/);
        return {
            tag: m[1] ? m[1].toUpperCase() : null,
            classes: (m[2].match(/\.[\w-]+/g) || []).map((c) => c.slice(1)),
        };
    });
    const matches = (el, part) => (!part.tag || el.tagName === part.tag)
        && part.classes.every((c) => el.hasClass(c));

    function allDescendants(el, acc) {
        el.children.forEach((c) => { acc.push(c); allDescendants(c, acc); });
        return acc;
    }

    const lastPart = parts[parts.length - 1];
    return allDescendants(root, []).filter((el) => {
        if (!matches(el, lastPart)) return false;
        const remaining = parts.slice(0, -1);
        let cursor = el.parent;
        let wanted = remaining.length - 1;
        while (wanted >= 0 && cursor) {
            if (matches(cursor, remaining[wanted])) wanted--;
            cursor = cursor.parent;
        }
        return wanted < 0;
    });
}

function MiniJQ(elements) {
    this.elements = elements || [];
    // real jQuery objects are array-like ($el[0]), and script.js relies on
    // that (`target = $img[0]`) - mirror it here.
    this.elements.forEach((el, i) => { this[i] = el; });
}
Object.defineProperty(MiniJQ.prototype, 'length', { get() { return this.elements.length; } });
MiniJQ.prototype.first = function () { return new MiniJQ(this.elements.slice(0, 1)); };
MiniJQ.prototype.get = function (i) { return this.elements[i === undefined ? 0 : i]; };
MiniJQ.prototype.text = function (str) {
    if (str === undefined) return this.elements[0] ? this.elements[0].textContent : undefined;
    this.elements.forEach((el) => { el.textContent = str; });
    return this;
};
MiniJQ.prototype.attr = function (name, value) {
    if (value === undefined) return this.elements[0] ? this.elements[0].getAttribute(name) : undefined;
    this.elements.forEach((el) => el.setAttribute(name, value));
    return this;
};
MiniJQ.prototype.append = function (child) {
    const childEl = child instanceof MiniJQ ? child.elements[0] : child;
    this.elements.forEach((el) => appendChild(el, childEl));
    return this;
};
MiniJQ.prototype.remove = function () { this.elements.forEach(removeChild); return this; };
MiniJQ.prototype.on = function (event, handler) {
    this.elements.forEach((el) => {
        el._handlers = el._handlers || {};
        el._handlers[event] = el._handlers[event] || [];
        el._handlers[event].push(handler);
    });
    return this;
};
MiniJQ.prototype.trigger = function (event) {
    this.elements.forEach((el) => {
        ((el._handlers && el._handlers[event]) || []).forEach((h) => h({ preventDefault: () => {} }));
    });
    return this;
};
// no-op: script.js registers through this once, at load; tests that want
// to observe a re-render call drawioAddMediaManagerButton() directly
// rather than replaying a real ajaxComplete event.
MiniJQ.prototype.ajaxComplete = function () { return this; };

function parseHtmlTag(html) {
    const m = html.match(/^<(\w+)((?:\s+[\w-]+="[^"]*")*)\s*\/?>(?:<\/\w+>)?$/);
    const attrs = {};
    const attrRe = /([\w-]+)="([^"]*)"/g;
    let am;
    while ((am = attrRe.exec(m[2]))) attrs[am[1]] = am[2];
    return makeNode(m[1], attrs);
}

// One shared `postCalls`-recording jQuery.post, wired to a fake DOM tree
// (`root`) that a test builds by hand to mimic core's real media manager
// markup (see the "media manager button" tests below).
function makeJQuery(root, postCalls) {
    function jQuery(arg) {
        if (typeof arg === 'function') { arg(); return undefined; }
        if (typeof arg === 'string') {
            return arg[0] === '<' ? new MiniJQ([parseHtmlTag(arg)]) : new MiniJQ(querySelectorAll(root, arg));
        }
        return arg instanceof MiniJQ ? arg : new MiniJQ([arg]);
    }
    jQuery.trim = (s) => String(s).trim();
    jQuery.post = (url, data, success) => {
        const call = { url, data, failCb: null, doneCb: null, successCb: success || null };
        postCalls.push(call);
        const chain = {
            fail: (cb) => { call.failCb = cb; return chain; },
            done: (cb) => { call.doneCb = cb; return chain; },
        };
        return chain;
    };
    jQuery.get = () => {};
    return jQuery;
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
    // fake DOM tree the media-manager tests build by hand (mimicking core's
    // real panel markup) and query through sandbox.jQuery.
    const mmRoot = makeNode('root');

    const sandbox = {
        JSINFO: {
            plugin_drawio: {
                url: 'https://embed.diagrams.net/',
                toolbar_possible_extension: ['png'],
                zIndex: 999,
                sectok: 'sek-test-default',
                locktime: 900,
                editbutton: 'Edit with draw.io',
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
        // A real (small) callable jQuery, not just a post()/get() stub: the
        // media-manager button tests need jQuery('<li>...') and
        // jQuery('div.file ul.actions') to behave like the browser does.
        // .post()/.get() work exactly as the old stub did.
        jQuery: makeJQuery(mmRoot, postCalls),
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
        timers, clearedTimers, mmRoot,
        wasReloaded: () => reloaded,
    };
}

// Builds the part of the real media manager panel drawioAddMediaManagerButton()
// reads from and appends to - div.file's panelHeader/image/actions, as
// tpl_mediaFileDetails() and media_preview_buttons() actually render it
// (inc/template.php, inc/media.php; verified against the live panel with
// headless Chrome). `ext` is the file extension under test; pass
// `withImage: false` for a .drawio source, which core never renders an
// <img> for.
function buildMediaPanelDom(mmRoot, mediaId, { withImage = true } = {}) {
    const file = appendChild(mmRoot, makeNode('div', { class: 'file' }));
    const header = appendChild(file, makeNode('div', { class: 'panelHeader' }));
    const link = appendChild(header, makeNode('a', { class: 'select mediafile', href: '#' }));
    link.textContent = mediaId;
    if (withImage) {
        const imageDiv = appendChild(file, makeNode('div', { class: 'image' }));
        appendChild(imageDiv, makeNode('img', {}));
    }
    appendChild(file, makeNode('ul', { class: 'actions' }));
    return file;
}

function loadScript(sandbox) {
    const code = fs.readFileSync(SCRIPT_JS, 'utf8');
    vm.runInContext(code, sandbox);
}

function autosave(sandbox, messageListeners, xml, iframes) {
    const receive = messageListeners[messageListeners.length - 1];
    assert(typeof receive === 'function', 'no message listener registered by edit_cb');
    const source = iframes[iframes.length - 1].contentWindow;
    receive({ source, data: JSON.stringify({ event: 'autosave', xml }) });
}

// A realistic plugin_drawio config, as actually published through JSINFO
// (conf/metadata.php / action.php's addjsinfo()) - used by the
// hostile-environment matrix so every scenario reflects a real page, not an
// idealised one.
const REALISTIC_CONF = {
    url: 'https://embed.diagrams.net/',
    toolbar_possible_extension: ['png', 'svg'],
    ui: 'atlas',
    editbutton: 'Edit with draw.io',
    lockwarning: 'This diagram was opened by %USER% %MINUTES% minute(s) ago and may still be open there. Continue anyway?',
    locktime: 900,
    sectok: '',
    zIndex: 999,
    topOffset: 0,
};

// Enough of jQuery for script.js's module-level jQuery(function(){...})
// registration and drawioAddMediaManagerButton() to run without throwing -
// not a DOM/rendering stub (buildSandbox()'s makeJQuery() above is that,
// for the tests that need it). Every selector here legitimately finds
// nothing on these pages (no media file detail panel on an article/admin
// page), so an empty, chainable no-op result is exactly what real jQuery
// would hand back too.
function noopJQuery() {
    function jQuery(arg) {
        if (typeof arg === 'function') { arg(); return undefined; }
        return {
            length: 0,
            first() { return this; },
            remove() { return this; },
            text() { return undefined; },
            ajaxComplete() { return this; },
        };
    }
    jQuery.trim = (s) => String(s).trim();
    return jQuery;
}

// Drives vm.runInContext() twice back to back, exactly like js.php
// concatenates two plugins' scripts into one <script> tag: script.js first,
// then a second, unrelated script. Both must run - proves script.js neither
// throws itself nor, by throwing, aborts everything DokuWiki concatenated
// after it (js_pluginscripts() in lib/exe/js.php - see issue #16).
function assertEnvironmentSurvives(name, jsinfo, extra) {
    const envSandbox = Object.assign({ JSINFO: jsinfo, window: {}, document: {}, console }, extra);
    envSandbox.global = envSandbox;
    vm.createContext(envSandbox);

    const scriptJs = fs.readFileSync(SCRIPT_JS, 'utf8');
    let threw = null;
    try {
        vm.runInContext(scriptJs, envSandbox);
        // simulate the next plugin's script, concatenated right after ours
        vm.runInContext('window.markerFromNextPlugin = true;', envSandbox);
    } catch (e) {
        threw = e;
    }
    assert.strictEqual(threw, null,
        'script.js must not throw on ' + name + ': ' + (threw && threw.stack));
    assert.strictEqual(envSandbox.window.markerFromNextPlugin, true,
        'a plugin script concatenated after script.js must still run on ' + name);
    return envSandbox;
}

// --- toolbar entry: generateToolBar() / picker-vs-single-format branching -
//
// buildSandbox()'s default window has no `toolbar` array at all, so the
// whole registration block (`typeof window.toolbar !== 'undefined'`) never
// runs unless a test opts in. Reproduce the real article-page shape:
// `window.toolbar`/bare `toolbar` aliased to the same array (in a real
// browser window IS the global object, and script.js's registration block
// uses the bare `toolbar` reference - they must be object-identical here or
// this sandbox would not reproduce the real page).
function withToolbar(sandbox) {
    sandbox.toolbar = [];
    sandbox.window.toolbar = sandbox.toolbar;
    return sandbox.toolbar;
}

module.exports = {
    fs, path, vm, assert,
    makeLocalStorage, makeImage, makeNode, appendChild, removeChild,
    querySelectorAll, MiniJQ, parseHtmlTag, makeJQuery, buildSandbox,
    buildMediaPanelDom, loadScript, autosave, REALISTIC_CONF, noopJQuery,
    assertEnvironmentSurvives, withToolbar,
};
