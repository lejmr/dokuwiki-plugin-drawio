// Golden tier: one check per feature, must always be green, runs in seconds.
// See DEVELOPMENT.md's tests section for what belongs here vs _test/extra/.
//
// Run with: node _test/golden/script.test.js

const {
    assert, makeImage, buildSandbox, buildMediaPanelDom, loadScript, autosave,
    REALISTIC_CONF, noopJQuery, assertEnvironmentSurvives, withToolbar,
    querySelectorAll,
} = require('../script-sandbox.inc.js');

// --- 1: loads in every context without throwing, and the next script still
// runs (js_pluginscripts() concatenates every enabled plugin's script.js
// into one response - see issue #16) -----------------------------------
//
// Each shape below was taken from a real page loaded against the dev wiki
// (see DEVELOPMENT.md "Verifying a change"), not invented - an article
// page, an admin page (no edit toolbar script there, so window.toolbar is
// simply undefined), the media manager (JSINFO.id is null there, and
// JSINFO.plugin_drawio can be entirely absent on some DokuWiki paths into
// it), and a page with no jQuery loaded at all.
{
    assertEnvironmentSurvives('an article page', {
        id: 'drawio', namespace: '', ACT: 'show', plugin_drawio: REALISTIC_CONF,
        useHeadingNavigation: 0, useHeadingContent: 0,
    }, { jQuery: noopJQuery() });

    assertEnvironmentSurvives('an admin page (window.toolbar is simply undefined there)', {
        id: 'start', namespace: '', ACT: 'admin', plugin_drawio: REALISTIC_CONF,
        useHeadingNavigation: 0, useHeadingContent: 0,
    }, { jQuery: noopJQuery() });

    assertEnvironmentSurvives('a page with no jQuery loaded at all', {
        id: 'drawio', namespace: '', ACT: 'show', plugin_drawio: REALISTIC_CONF,
        useHeadingNavigation: 0, useHeadingContent: 0,
    }, {}); // deliberately no `jQuery` key: typeof jQuery === 'undefined'

    // media manager, JSINFO.plugin_drawio entirely absent (issue #16)
    assertEnvironmentSurvives('the media manager with no JSINFO.plugin_drawio at all',
        { id: null, namespace: '', ACT: 'show' }, { window: {} });

    // media manager, plugin_drawio present but JSINFO.id null and
    // window.toolbar defined (what actually drove the #16-shaped crash)
    {
        const sharedToolbar = [];
        const env = assertEnvironmentSurvives(
            'the media manager, JSINFO.id null, window.toolbar defined',
            {
                id: null, namespace: '', ACT: 'show', plugin_drawio: REALISTIC_CONF,
                useHeadingNavigation: 0, useHeadingContent: 0,
            },
            { jQuery: noopJQuery(), toolbar: sharedToolbar, window: { toolbar: sharedToolbar } },
        );
        assert.strictEqual(env.toolbar.length, 0,
            'with no page id there is nothing to insert {{drawio>...}} into, so no toolbar item should be registered');
    }
}

console.log('OK: script.js loads in every context it actually meets without throwing, and the next plugin script still runs (#16)');

// --- 2: opening a diagram asks the server for permission first (get_auth) -
{
    const { sandbox, postCalls } = buildSandbox();
    loadScript(sandbox);

    sandbox.edit(makeImage('perm.png'));
    const authCall = postCalls.find((c) => c.data && c.data.action === 'get_auth');
    assert.ok(authCall, 'opening a diagram must ask the server via get_auth first');

    authCall.successCb(true);
    assert.strictEqual(sandbox.editorOpen, true, 'a granted get_auth must open the editor');
}

console.log('OK: opening a diagram asks the server for permission (get_auth) before the editor opens');

// --- 3: opening prefers the diagram's source XML, falling back to the image
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
    assert.strictEqual(msg.xml, xml, 'the editor must be loaded from the source XML when one exists');

    const legacy = loadMessageFor({ content: 'data:image/png;base64,Zm9v' }, 'nosource.png', 'png');
    assert.strictEqual(legacy.xmlpng, 'data:image/png;base64,Zm9v',
        'a diagram with no source must still open from its image');
}

console.log('OK: opening a diagram prefers its source file and falls back to the image');

// --- 4: a saved draft round-trips: write it, then open the same diagram and
// get it back --------------------------------------------------------------
{
    const { sandbox, messageListeners, iframes, postCalls } = buildSandbox();
    loadScript(sandbox);

    const draftXml = '<mxGraphModel>local draft, please restore me</mxGraphModel>';
    sandbox.localStorage.setItem('.draft-restoreme.png',
        JSON.stringify({ lastModified: new Date(), xml: draftXml }));

    sandbox.edit_cb(makeImage('restoreme.png'));
    const receive = messageListeners[messageListeners.length - 1];
    const frame = iframes[iframes.length - 1];
    const posted = [];
    frame.contentWindow.postMessage = (m) => posted.push(JSON.parse(m));

    receive({ source: frame.contentWindow, data: JSON.stringify({ event: 'init' }) });

    const loadMsg = posted.find((m) => m.action === 'load');
    assert.ok(loadMsg, "restoring a draft must still post the editor's 'load' action");
    assert.strictEqual(loadMsg.xml, draftXml, 'a draft round trip must load exactly what was written, not the stored image');
    assert.ok(!postCalls.some((c) => c.data && /^(draft_get|get_png|get_svg)$/.test(c.data.action)),
        'a local draft found must not be re-fetched from the server at all');
}

console.log('OK: a saved draft round-trips through localStorage back into the editor');

// --- 5: saving a diagram sends its XML source alongside the image ---------
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
        'the save post must carry the diagram XML, not just the exported image');
}

console.log('OK: saving a diagram sends its XML source alongside the exported image');

// --- 6: advisory lock warning ----------------------------------------------
//
// The editor must take the lock on open (without waiting for the answer),
// warn (without blocking) when someone else already holds it, and stay
// silent when nobody does.
{
    const { sandbox: freeSandbox, alerts: freeAlerts, postCalls: freePostCalls, iframes: freeIframes } = buildSandbox();
    loadScript(freeSandbox);
    freeSandbox.edit_cb(makeImage('free.png'));
    const freeLockCall = freePostCalls.find((c) => c.data && c.data.action === 'lock');
    assert.ok(freeLockCall, 'opening a diagram must ask the server for its lock status');
    assert.strictEqual(freeIframes.length, 1, 'the editor must open without waiting for the lock response');
    freeLockCall.successCb({ locked_by: null, since: null });
    assert.strictEqual(freeAlerts.length, 0, 'no one else holds the lock - nothing to warn about');

    const { sandbox, alerts, postCalls } = buildSandbox();
    loadScript(sandbox);
    sandbox.edit_cb(makeImage('contested.png'));
    const lockCall = postCalls.find((c) => c.data && c.data.action === 'lock');
    const since = Math.floor(Date.now() / 1000) - 120;
    lockCall.successCb({ locked_by: 'alice', since });

    assert.strictEqual(alerts.length, 1, 'someone else holding the lock must be warned about');
    assert.ok(alerts[0].includes('alice'), 'the warning must name who holds it: ' + alerts[0]);
    assert.strictEqual(sandbox.editorOpen, true,
        'the warning is informational only - the editor that already opened must stay open');
}

console.log('OK: opening a diagram checks its advisory lock and warns (without blocking) when someone else holds it');

// --- 7: media-manager button appears for both a rendering and its .drawio
// source, matching core's own markup -----------------------------------
{
    const { sandbox, mmRoot } = buildSandbox();
    loadScript(sandbox);
    buildMediaPanelDom(mmRoot, 'test:plan.png');

    sandbox.drawioAddMediaManagerButton();

    const li = querySelectorAll(mmRoot, 'li.drawio__mmbtn')[0];
    assert.ok(li, 'no <li class="drawio__mmbtn"> was added for the .png rendering');
    const div = li.children[0];
    assert.ok(div && div.tagName === 'DIV' && div.hasClass('no'),
        "core wraps its buttons in <div class=\"no\">; this control must too, to inherit its layout CSS");
    const button = div.children[0];
    assert.strictEqual(button.tagName, 'BUTTON', 'core renders a real <button>, not an <a href="#">');
    assert.strictEqual(button.textContent, sandbox.JSINFO.plugin_drawio.editbutton);

    const { sandbox: sourceSandbox, mmRoot: sourceRoot } = buildSandbox();
    loadScript(sourceSandbox);
    buildMediaPanelDom(sourceRoot, 'test:plan.drawio', { withImage: false });
    sourceSandbox.drawioAddMediaManagerButton();
    assert.strictEqual(querySelectorAll(sourceRoot, 'li.drawio__mmbtn').length, 1,
        'the .drawio source (core renders no <img> for it at all) must get the button too');
}

console.log('OK: the media manager button appears for a diagram\'s rendering and its .drawio source, matching core\'s own markup');

// --- 8: toolbar entry ------------------------------------------------------
//
// Two configured extensions register one 'picker' entry with one 'format'
// item per extension; a single configured extension registers one plain
// 'format' entry directly, no picker wrapper.
{
    const { sandbox } = buildSandbox();
    sandbox.JSINFO.plugin_drawio.toolbar_possible_extension = ['png', 'svg'];
    const toolbar = withToolbar(sandbox);
    loadScript(sandbox);

    assert.strictEqual(toolbar.length, 1, 'two extensions must register exactly one (picker) toolbar entry');
    assert.strictEqual(toolbar[0].type, 'picker');
    assert.strictEqual(toolbar[0].list.length, 2, 'the picker must offer one item per configured extension');
    assert.strictEqual(toolbar[0].list[0].open, '{{drawio>test:diagram1.png}}');

    const { sandbox: singleSandbox } = buildSandbox();
    singleSandbox.JSINFO.plugin_drawio.toolbar_possible_extension = ['png'];
    const singleToolbar = withToolbar(singleSandbox);
    loadScript(singleSandbox);

    assert.strictEqual(singleToolbar.length, 1, 'a single extension must still register exactly one toolbar entry');
    assert.strictEqual(singleToolbar[0].type, 'format', 'a single-extension entry is a plain format item, not a picker');
    assert.strictEqual(singleToolbar[0].open, '{{drawio>test:diagram1.png}}');
}

console.log('OK: the toolbar entry is a picker for multiple configured extensions, and a plain format item for one');

// --- 9: configured ui/url/zIndex reach the editor iframe -------------------
{
    const { sandbox, iframes } = buildSandbox();
    sandbox.JSINFO.plugin_drawio.ui = 'min';
    sandbox.JSINFO.plugin_drawio.url = 'https://self-hosted.example/drawio/';
    sandbox.JSINFO.plugin_drawio.zIndex = 12345;
    loadScript(sandbox);

    sandbox.edit_cb(makeImage('configtest.png'));

    const src = iframes[iframes.length - 1].getAttribute('src');
    const style = iframes[iframes.length - 1].getAttribute('style');
    assert.ok(src.includes('ui=min'), 'iframe src must carry the configured ui: ' + src);
    assert.ok(src.startsWith('https://self-hosted.example/drawio/'),
        'iframe src must start with the configured url: ' + src);
    assert.ok(style.includes('z-index: 12345'), 'iframe style must carry the configured zIndex: ' + style);
}

console.log('OK: the configured ui, url and zIndex all reach the editor iframe');

// --- 10: every ajax call carries the DokuWiki security token (sectok) -----
{
    const SECTOK = 'sek-9f8e7d';
    const { sandbox, messageListeners, iframes, postCalls } = buildSandbox();
    sandbox.JSINFO.plugin_drawio.sectok = SECTOK;
    loadScript(sandbox);

    sandbox.edit(makeImage('sectok.png')); // get_auth
    sandbox.edit_cb(makeImage('sectok.png')); // draft_get, lock
    const receive = messageListeners[messageListeners.length - 1];
    const source = iframes[iframes.length - 1].contentWindow;

    receive({ source, data: JSON.stringify({ event: 'init' }) }); // get_png
    receive({ source, data: JSON.stringify({ event: 'autosave', xml: '<mxGraphModel/>' }) }); // draft_save
    receive({ source, data: JSON.stringify({ event: 'save', xml: '<mxGraphModel/>' }) }); // draft_save
    receive({
        source,
        data: JSON.stringify({ event: 'export', format: 'xmlpng', data: 'data:image/png;base64,Zm9v' }),
    }); // save, then draft_rm on .done()
    const saveCall = postCalls.find((c) => c.data && c.data.action === 'save');
    assert.ok(saveCall && typeof saveCall.doneCb === 'function', 'test setup: save must be postable');
    saveCall.doneCb();

    const svg = buildSandbox();
    svg.sandbox.JSINFO.plugin_drawio.sectok = SECTOK;
    svg.sandbox.JSINFO.plugin_drawio.toolbar_possible_extension = ['svg'];
    loadScript(svg.sandbox);
    svg.sandbox.edit_cb(makeImage('sectok.svg'));
    const svgReceive = svg.messageListeners[svg.messageListeners.length - 1];
    const svgSource = svg.iframes[svg.iframes.length - 1].contentWindow;
    svgReceive({ source: svgSource, data: JSON.stringify({ event: 'init' }) }); // get_svg

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

// --- 11: a draft for one diagram must not leak into another's (#26) -------
{
    const { sandbox, localStorage, messageListeners, iframes } = buildSandbox();
    loadScript(sandbox);

    sandbox.edit_cb(makeImage('diagramA.png'));
    autosave(sandbox, messageListeners, '<mxGraphModel>A</mxGraphModel>', iframes);

    // edit_cb() refuses to open a second editor while one is open (#4/#5) -
    // reset the flag the way any real close path does, without its other
    // side effects (which include clearing the draft this test checks for).
    sandbox.editorOpen = false;

    sandbox.edit_cb(makeImage('diagramB.png'));
    autosave(sandbox, messageListeners, '<mxGraphModel>B</mxGraphModel>', iframes);

    const draftA = localStorage.getItem('.draft-diagramA.png');
    const draftB = localStorage.getItem('.draft-diagramB.png');
    assert.ok(draftA, 'diagramA draft missing');
    assert.ok(draftB, 'diagramB draft missing');
    assert.notStrictEqual(draftA, draftB, 'both diagrams got the same draft content');
    assert.ok(JSON.parse(draftA).xml.includes('A'), 'diagramA draft has wrong content');
    assert.ok(JSON.parse(draftB).xml.includes('B'), 'diagramB draft has wrong content');
}

console.log('OK: two diagrams get two independent draft keys (#26)');

// --- 12: issue #62 - the "Edit with draw.io" button opens the same editor -
// as the image click (syntax.php renders the button; this only has to
// prove drawioEditButtonClick() finds the image by id and hands it to
// edit(), which is what the rest of this file already exercises).
{
    const { sandbox, postCalls } = buildSandbox();
    loadScript(sandbox);

    const image = makeImage('editbtn.png');
    sandbox.document.getElementById = (id) => (id === 'editbtn.png' ? image : null);
    const button = makeImage('editbtn.png'); // reused only for its getAttribute()
    button.setAttribute('data-image-id', 'editbtn.png');

    sandbox.drawioEditButtonClick(button);

    const authCall = postCalls.find((c) => c.data && c.data.action === 'get_auth');
    assert.ok(authCall, 'the edit button must open the diagram via edit(), which asks get_auth first');
    assert.strictEqual(authCall.data.imageName, 'editbtn.png');
}

console.log('OK: the "Edit with draw.io" button opens the same editor as clicking the image (#62)');

// --- 13: issue #50 - a configured top_offset pushes the iframe down -------
{
    const { sandbox, iframes } = buildSandbox();
    sandbox.JSINFO.plugin_drawio.topOffset = 56;
    loadScript(sandbox);

    sandbox.edit_cb(makeImage('topoffset.png'));

    const style = iframes[iframes.length - 1].getAttribute('style');
    assert.ok(style.includes('top:56px'), 'iframe style must carry the configured top offset: ' + style);
    assert.ok(style.includes('height:calc(100vh - 56px)'), 'iframe height must shrink by the offset: ' + style);
}

console.log('OK: a configured top_offset pushes the editor iframe down and shrinks it to match (#50)');
