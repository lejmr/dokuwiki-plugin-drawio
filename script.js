// Embeded editor
//
// JSINFO['plugin_drawio'] is populated by action.php's addjsinfo(), hooked to
// DOKUWIKI_STARTED/MEDIAMANAGER_STARTED - not every page fires one of those
// (fixes #16). DokuWiki concatenates every plugin's script.js into a single
// response (js_pluginscripts() in lib/exe/js.php), so a top-level throw here
// used to abort that whole bundle and silently break every plugin script
// that happened to sort after "drawio" in it. Nothing below may run at
// parse/load time without checking drawioConf() first.
function drawioConf() {
    return (typeof JSINFO !== 'undefined' && JSINFO['plugin_drawio']) ? JSINFO['plugin_drawio'] : null;
}

// JSINFO itself is not guaranteed to be declared at all - it is emitted by
// core's jsinfo()/tpl_metaheaders(), which not every template/popup calls
// (issue #37: a Snippets-plugin popup skips it entirely). A bare `JSINFO`
// reference in that case is a ReferenceError, not a friendly `undefined` -
// same class of load-time throw as #16, just one property up. Route every
// read of JSINFO.id through here so no caller can reintroduce the bare
// reference.
function drawioPageId() {
    return (typeof JSINFO !== 'undefined' && JSINFO) ? JSINFO.id : null;
}

// Every ajax call to this plugin must carry DokuWiki's CSRF/security token
// (published as JSINFO['plugin_drawio']['sectok']) as the `sectok` request
// param - the server-side handler now requires a valid one and rejects
// anything else. Routing every call through this one helper means a call
// added here in the future can't forget it (see _test/script.test.js, which
// checks every post ever made, not just today's seven actions).
//
// `silent` is for the handful of calls that were already best-effort before
// this fix (autosave, draft cleanup) or that already have their own, more
// specific .fail() handler ('save', below) - everything else alerts on
// failure here, so a rejected token (or any other failure) is never mistaken
// for success.
function drawioPost(action, imageName, extraData, success, silent)
{
    var conf = drawioConf();
    var data = {
        call: 'plugin_drawio',
        imageName: imageName,
        action: action,
        sectok: conf ? conf['sectok'] : ''
    };
    if (extraData) {
        for (var key in extraData) {
            if (Object.prototype.hasOwnProperty.call(extraData, key)) {
                data[key] = extraData[key];
            }
        }
    }
    var req = jQuery.post(DOKU_BASE + 'lib/exe/ajax.php', data, success);
    if (!silent) {
        req.fail(function () {
            alert('drawio: the request to the server failed (action: ' + action + '). ' +
                'Your action was NOT completed.');
        });
    }
    return req;
}

// The local draft is a convenience cache (#32/#57): a large diagram makes
// localStorage.setItem() throw QuotaExceededError, and an unguarded throw
// here used to abort the postMessage handler mid-way, breaking the very
// save/autosave it was piggybacking on. The server-side draft (draft_save,
// posted right after every one of these calls) is the durable copy, so a
// failed local write is swallowed rather than allowed to stop anything
// downstream of it.
function drawioLocalStorageGet(key)
{
    try {
        return localStorage.getItem(key);
    } catch (e) {
        console.log('drawio: localStorage.getItem failed, continuing without a local draft', e);
        return null;
    }
}

function drawioLocalStorageSet(key, value)
{
    try {
        localStorage.setItem(key, value);
    } catch (e) {
        console.log('drawio: localStorage.setItem failed (quota?), continuing without a local draft', e);
    }
}

function drawioLocalStorageRemove(key)
{
    try {
        localStorage.removeItem(key);
    } catch (e) {
        console.log('drawio: localStorage.removeItem failed, continuing', e);
    }
}

var toolbarPossibleExtension = drawioConf() ? drawioConf()['toolbar_possible_extension'] : [];
var initial = null;
var currentDiagramId = null;
var imagePointer = null;
// guards against a second click stacking a second iframe + 'message' listener
// on top of an already-open editor (every autosave would then fire duplicate
// draft_save requests for the rest of the session).
// known gap: if the iframe never loads at all (ad blocker, a corporate proxy
// blocking diagrams.net) this stays stuck true with no cancel affordance -
// not fixed here, needs a real UI (e.g. a close button/timeout on the iframe).
var editorOpen = false;

// issue #62: the "Edit with draw.io" button syntax.php renders under a
// diagram (conf['edit_button']) when the image itself is invisible - an
// empty diagram draw.io saves is a valid, unclickable 1x1px PNG - so this
// looks the image up by id rather than needing a direct element reference,
// the same trick the media manager button below already relies on.
function drawioEditButtonClick(button)
{
    var image = document.getElementById(button.getAttribute('data-image-id'));
    if (image) edit(image);
}

function edit(image)
{
    // check auth
    var imgPointer = image;
    var mediaId = imgPointer.getAttribute('id');

    // A click on the diagram's *source* (ns:plan.drawio) rather than one of
    // its renderings - the media manager button offers both now (see
    // drawioAddMediaManagerButton()). Every action below this point
    // (get_auth included) is keyed to a png/svg id - script.js never sends
    // a .drawio one anywhere else - so this has to resolve to the rendering
    // it belongs to before anything else runs. 'resolve_source' does that
    // resolution AND the same auth check 'get_auth' does, in one request
    // (see action.php's own comment on it for why a second, plain get_auth
    // round trip after resolving is not needed), so the .drawio path costs
    // exactly the same one request as the normal open does.
    if (mediaId && mediaId.split('.').pop().toLowerCase() === 'drawio') {
        drawioPost('resolve_source', mediaId, null, function (data) {
            if (!data || data.granted !== true || !data.id) return;
            // A stub, never attached to the page: edit_cb()/the 'export'
            // handler below only ever call getAttribute('id') on it and, on
            // save, setAttribute('src', ...) to refresh a live preview -
            // there is no <img> for a .drawio in the media manager panel to
            // refresh in the first place (core never renders one; .drawio
            // has no registered mimetype - verified against the real panel
            // markup), so a detached element is exactly as capable as a real
            // one here and updating it is simply a no-op.
            var stub = document.createElement('img');
            stub.setAttribute('id', data.id);
            edit_cb(stub);
        }, true);
        return;
    }

    // get_auth now sends a JSON content type, like every other endpoint here,
    // so `data` is the real boolean jQuery parsed it into - compare it as
    // one, not against the string 'true' the old text/html response forced.
    //
    // silent: true because a denial (a 200 response carrying `false`, seen
    // below) already shows nothing, and a failed request (expired sectok,
    // network hiccup) is the more likely of the two - showing a generic
    // "the request failed" alert for the *unlikely* case while staying
    // silent for the likely one had it backwards.
    drawioPost('get_auth', mediaId, null, function (data) {
        if (data !== true) return;
        edit_cb(imgPointer);
    }, true);
}

function edit_cb(image)
{
    var conf = drawioConf();
    if (!conf) {
        console.log('drawio: plugin_drawio config missing from JSINFO, cannot open editor');
        return;
    }
    if (editorOpen) {
        // ignore the click rather than layering a second editor on top
        return;
    }
    var zIndex = conf['zIndex'];

    imagePointer = image;
    currentDiagramId = imagePointer.getAttribute('id');
    // The extension is whatever follows the LAST dot. Splitting on every dot
    // refused names like ns:release-v1.2.png with an alert (a fork carried
    // the fix for three years); a dot inside a namespace never counts either.
    var idParts = imagePointer.getAttribute('id').split(/\.(?=[^.:]*$)/);
    imageFormat = idParts.length < 2 ? 'png' : idParts[1].toLowerCase();

    var iframe = document.createElement('iframe');
    iframe.setAttribute('frameborder', '0');
    iframe.setAttribute('class', 'drawio');
    // issue #50: style.css positions the iframe as top:0/bottom:0/height:100vh,
    // covering the whole viewport - fine on its own, but a template with a
    // fixed top navbar (Bootstrap3 among them) then draws over the editor's
    // own menu bar. topOffset (0 by default, so this is a no-op for anyone
    // who hasn't set it) pushes the top edge down and shrinks the height by
    // the same amount, rather than adding padding-top, so the iframe's own
    // border still starts exactly where the navbar ends.
    var topOffset = conf['topOffset'] || 0;
    var iframeStyle = 'z-index: ' + zIndex + ';';
    if (topOffset > 0) {
        iframeStyle += 'top:' + topOffset + 'px;height:calc(100vh - ' + topOffset + 'px);';
    }
    iframe.setAttribute('style', iframeStyle);
    editorOpen = true;

    // The advisory lock's renewal timer, cleared in close() below. One
    // module-level variable is enough: editorOpen already guards against a
    // second editor (and so a second timer) ever being open at once.
    var lockRenewTimer = null;

    var close = function()
    {
        window.removeEventListener('message', receive);
        editorOpen = false;
        if (lockRenewTimer) {
            clearInterval(lockRenewTimer);
            lockRenewTimer = null;
        }
        document.body.removeChild(iframe);

        // No unlock call here on purpose - there used to be one. The lock
        // file is one shared resource per diagram (see action.php's
        // _lock_id() - .png and .svg share it too), not one per tab, so
        // closing *either* of two tabs on the same diagram - one person
        // with two tabs open, or two different people editing the .png and
        // the .svg - deleted the *other* tab's protection while it was
        // still actively being edited. Verified live. Simply never
        // unlocking fixes that by construction: no open tab can delete
        // another one's lock, at the cost that a stale "someone had this
        // open recently" warning can linger for up to $conf['locktime']
        // (900s/15min by default) after a clean close. For an advisory
        // warning nobody is blocked by, that is a far cheaper failure than
        // the one it replaces - a missing warning is the one that actually
        // loses work.
    };

    // Advisory lock: warn, but never block, when someone else already has
    // this diagram open. Fired in parallel with opening the editor below,
    // not awaited - same as draft_get/get_png/get_svg, none of which delay
    // the iframe either. The lock is taken (or renewed) by this call
    // regardless of what locked_by says: "whatever the user answers, the
    // editor opens" - there is no answer to gate on here at all, only
    // information, so a plain alert() rather than a confirm() whose Cancel
    // would misleadingly look like it does something.
    drawioPost('lock', currentDiagramId, null, function (data) {
        if (!data || !data.locked_by) return;
        var tmpl = (conf && conf['lockwarning']) ||
            'This diagram was opened by %USER% %MINUTES% minute(s) ago and may still be open there. Continue anyway?';
        var minutes = Math.max(0, Math.round((Date.now() / 1000 - data.since) / 60));
        alert(tmpl.replace('%USER%', data.locked_by).replace('%MINUTES%', minutes));
    }, true);

    // Keep the lock alive for as long as this editor stays open, on a
    // plain interval rather than relying on autosave/'save' to do it:
    // draw.io fires those on a model *change*, not on a timer (see its
    // embed docs), so someone who opens a diagram and reads it a while
    // before touching anything - the ordinary prelude to editing, not a
    // forgotten tab - had no lock left and the next opener got no warning
    // at all. The interval is a third of $conf['locktime'] (published via
    // JSINFO so it tracks whatever this wiki has that set to, not a fixed
    // guess against the 900s/15min default), so a single missed renewal
    // still leaves margin before expiry. locktime <= 0 means core's own
    // locking is disabled site-wide - nothing to renew, so no timer either.
    var locktime = conf && conf['locktime'];
    if (locktime > 0) {
        lockRenewTimer = setInterval(function () {
            drawioPost('lock', currentDiagramId, null, null, true);
        }, Math.max(10000, Math.floor(locktime * 1000 / 3)));
    }


    // The xml the editor sends with its 'save' event, kept until the 'export'
    // event that follows it so the save request can carry the source as well
    // as the picture. It used to be used for the draft and then dropped, which
    // left the exported image as the only copy of the diagram's source.
    var pendingXml = null;

    var draft = drawioLocalStorageGet('.draft-' + currentDiagramId);

    // Prefer the draft from browser cache
    if(draft == null){
        // Try to find on-disk stored draft file
        drawioPost('draft_get', imagePointer.getAttribute('id'), null, function (data) {
            if (data.content != 'NaN') {

                // Set draft from received data
                draft = data;

                // Handle the discard - remove on disk
                if (!confirm("A version of this diagram from " + new Date(data.lastModified) + " is available. Would you like to continue editing?"))
                {
                    // clean draft variable
                    draft = null;

                     // Remove all draft files - best-effort, same as the other
                     // draft_rm call sites below.
                    drawioPost('draft_rm', imagePointer.getAttribute('id'), null, null, true);
                }
            }
        });
    }
    else 
    {

        draft = JSON.parse(draft);
                    
        if (!confirm("A version of this diagram from " + new Date(draft.lastModified) + " is available. Would you like to continue editing?"))
        {
            draft = null;
            // Discard it for good, exactly like the server-fetched branch
            // above does - otherwise the same stale draft is offered again on
            // every open of this diagram.
            drawioLocalStorageRemove('.draft-' + currentDiagramId);
            drawioPost('draft_rm', imagePointer.getAttribute('id'), null, null, true);
        }
    }
    
    var receive = function(evt)
    {
        // the listener is on window, not the iframe, so without this any script
        // on the page (not just the draw.io iframe) could post a synthesised
        // {"event":"save",...} and have it acted on. A string origin check
        // breaks the moment a redirect/reverse proxy/SSO gateway sits in front
        // of a self-hosted draw.io (the 'url' setting supports that) - compare
        // identity instead, same as drawio's own reference integration
        // (jgraph/drawio-integration, examples/embed-mode/diagram-editor.js).
        if (evt.source !== iframe.contentWindow) return;
        if (evt.data.length > 0)
        {
            var msg;
            try {
                msg = JSON.parse(evt.data);
            } catch (e) {
                // a partial message during load, a heartbeat, a protocol hiccup -
                // an unguarded throw here would abort before close() ever runs,
                // leaving editorOpen stuck true and the diagram uneditable until
                // a reload (same fix the reference integration applies)
                console.log('drawio: ignoring non-JSON postMessage', e);
                return;
            }
			// wait for init msg
            if (msg.event == 'init')
            {
                if (draft != null) // send draft 
                {
                    iframe.contentWindow.postMessage(JSON.stringify({action: 'load',
                        autosave: 1, xml: draft.xml}), '*');
                    iframe.contentWindow.postMessage(JSON.stringify({action: 'status',
                        modified: true}), '*');
                }
                else // get local image
                {                    
                    // data.xml is the diagram's stored source (ns:plan.drawio),
                    // present whenever the server has one that is not older than
                    // the image - load the editor straight from it. Without one
                    // (every diagram saved before this existed, until its next
                    // save) fall back to making drawio dig the xml back out of
                    // the exported image, which is what it always did and what
                    // loses the diagram the moment anything rewrites that file.
                    var load = function (data, imageKey) {
                        var msg = {action: 'load', autosave: 1};
                        if (data.xml) {
                            msg.xml = data.xml;
                        } else {
                            msg[imageKey] = data.content;
                        }
                        iframe.contentWindow.postMessage(JSON.stringify(msg), '*');
                    };
                    if (imageFormat == 'png')
                    {
                        drawioPost('get_png', imagePointer.getAttribute('id'), null, function (data) {
                            load(data, 'xmlpng');
                        });
                    }
                    else if (imageFormat == 'svg')
                    {
                        drawioPost('get_svg', imagePointer.getAttribute('id'), null, function (data) {
                            load(data, 'xml');
                        });
                    }
					else {
                        console.log('error extension not compatible');
                    }
                }
            }
            else if (msg.event == 'export')
            {
                imgData=null;
                if(msg.format == 'xmlpng')
                {
                    imgData = msg.data ;
                    image.setAttribute('src', imgData);
                }
                else if (msg.format == 'svg')
                {
                    // Used to decode the data URI and inline the raw SVG
                    // markup into the page via innerHTML, to keep links
                    // inside the diagram clickable. That markup comes
                    // straight from the editor iframe, and innerHTML runs
                    // inline event handlers (onload, onerror, ...) on
                    // whatever it inserts, in the wiki's own origin -
                    // lib/exe/fetch.php sends a strict CSP, but this path
                    // never goes through it, and doku.php sends none at all.
                    //
                    // Load it as an <img> instead, the same way the PNG
                    // branch above already does: a data: URI in an <img src>
                    // is decoded in image context, which never executes
                    // scripts or handlers inside it. Also fixes: the old
                    // replaceChild() swapped in a fresh <img> with a
                    // hardcoded class/style, dropping the width/height/
                    // title/alt syntax.php computed for this diagram until
                    // the next page load - setAttribute('src', ...) on the
                    // existing node, same as the PNG branch, keeps them.
                    //
                    // User-visible change: links inside an SVG diagram were
                    // only ever clickable in this specific post-save state,
                    // until the next full page load - render() in syntax.php
                    // emits a plain <img> for every SVG diagram too, so a
                    // reload already made them unclickable again. This drops
                    // that transient window; a normal page view/reload is
                    // unaffected. Rendering wiki [[links]] *inside* a diagram
                    // (issue #61) is a different, larger feature and is not
                    // affected either way by this change.
                    imgData = msg.data;
                    image.setAttribute('src', imgData);
                }
                
				close();

                // Save into dokuwiki. The image above was already updated to look
                // saved and the editor already closed - the draft (localStorage +
                // on-disk) is the only thing that still says otherwise, so it must
                // only be cleared once the save is confirmed to have worked. If it
                // were cleared up front and the server then rejected the save (bad
                // extension, bad payload, no permission), the user's change would
                // exist nowhere at all - not on disk, not in the draft - and that
                // is exactly the case the draft exists to cover.
                var payload = { content: msg.data };
                // Omitted, not sent empty, when there is no xml to send: the
                // server treats a missing 'xml' as "this caller has no source"
                // and saves the image alone, exactly as before.
                if (pendingXml) payload.xml = pendingXml;
                drawioPost('save', imagePointer.getAttribute('id'), payload, null, true)
                .done(function() {
                    drawioRefreshDiagramElement(imagePointer);

                    drawioLocalStorageRemove('.draft-' + currentDiagramId);
                    draft = null;

                    // Remove all draft files - best-effort scratch-file cleanup,
                    // not worth alerting over; a failure here just means a stale
                    // draft lingers (offering to restore it next time this
                    // diagram opens).
                    drawioPost('draft_rm', imagePointer.getAttribute('id'), null, null, true)
                    .fail(function() {
                        console.log('drawio: draft_rm failed, a stale draft may linger');
                    });
                }).fail(function() {
                    // Draft is untouched on purpose (see above) - draft_get will
                    // offer it back next time this diagram is opened. Reload is
                    // still fine here: alert() is modal (the user has dismissed it
                    // before reload runs), and nothing else touches the draft on
                    // this path - only the .done() branch above does, and that
                    // never runs when we're here.
                    alert('Saving the diagram failed - your change was NOT saved, but ' +
                        'was kept as a draft. Reopen this diagram to get it back.');
                    window.location.reload();
                });
            }
            else if (msg.event == 'autosave')
            {
                dr = JSON.stringify({lastModified: new Date(), xml: msg.xml});
                drawioLocalStorageSet('.draft-' + currentDiagramId, dr);

                // Save on-disk - best-effort, same as the other draft_save/
                // draft_rm calls (a failure here just means a stale draft).
                drawioPost('draft_save', imagePointer.getAttribute('id'), { content: dr }, null, true);
            }
            else if (msg.event == 'save')
            {
                
                pendingXml = msg.xml;
                if (imageFormat === 'png')
                {
                    iframe.contentWindow.postMessage(JSON.stringify({action: 'export',
                    format: 'xmlpng', xml: msg.xml, spin: 'Updating page'}), '*');
                    dr = JSON.stringify({lastModified: new Date(), xml: msg.xml});
                    drawioLocalStorageSet('.draft-' + currentDiagramId, dr);
                } 
                else if (imageFormat ==='svg') 
                {
                    iframe.contentWindow.postMessage(JSON.stringify({action: 'export',
                    format: 'xmlsvg', xml: msg.xml, spin: 'Updating page'}), '*');
                    dr = JSON.stringify({lastModified: new Date(), xml: msg.xml});
                    drawioLocalStorageSet('.draft-' + currentDiagramId, dr);
                }
                // Save on-disk - best-effort, see above.
                drawioPost('draft_save', imagePointer.getAttribute('id'), { content: dr }, null, true);
            }
            else if (msg.event == 'exit')
            {
                drawioLocalStorageRemove('.draft-' + currentDiagramId);
                draft = null;

                // Remove all draft files - best-effort, see above.
                drawioPost('draft_rm', imagePointer.getAttribute('id'), null, null, true);

                // Final close (dont know why though)
                close();
            }
        }
    };
    window.addEventListener('message', receive);
    // The interface (kennedy/min/atlas/dark/sketch/simple - see draw.io's own
    // "Supported URL parameters" docs) used to be hardcoded to atlas; it's
    // now an admin setting, published through JSINFO like every other
    // config value this plugin has (conf/metadata.php, action.php's
    // addjsinfo()). The fallback covers a page whose cached JSINFO predates
    // this setting - same reason zIndex/lockwarning fall back elsewhere in
    // this file.
    //
    // dark=auto is appended unconditionally: it only affects the themes
    // that support a dark variant (min/sketch/simple) and is otherwise
    // inert, so every wiki gets an editor that follows the visitor's
    // browser/OS dark-mode preference for free, without a second setting.
    // DokuWiki templates have no common, script-readable signal for "the
    // wiki is currently in dark mode" (each ships its own toggle/CSS), so
    // there is nothing reliable to read instead.
    var ui = conf['ui'] || 'atlas';
    iframe.setAttribute('src', conf['url'] + '?embed=1&ui=' + ui + '&dark=auto&spin=1&proto=json');
    document.body.appendChild(iframe);
};


// Toolbar menu items
function getImageName(){
    seq = drawioPageId().split(":");
    seq = seq.slice(0,seq.length-1);
    seq.push("diagram1");
    return seq.join(":");
};

 function generateToolBar(){
     var listExt= [];
     for (extension in toolbarPossibleExtension) {
         listExt[extension] = {
             type   : "format",
             title  : "",
             icon   : "../../plugins/drawio/icon_" + toolbarPossibleExtension[extension] + ".png" ,
             open   : "{{drawio>" + getImageName() + "." + toolbarPossibleExtension[extension] + "}}",
             close  : ""
            }
     }
     return listExt;
 };


// window.toolbar exists on the editor toolbar of an article page, but also
// on pages that have no current page id at all - the media manager among
// them (JSINFO.id is null there, verified against the real response: `curl
// .../lib/exe/mediamanager.php` prints "id":null). getImageName() calls
// JSINFO.id.split(':'), so registering a toolbar item that opens
// {{drawio>...}} with no page to insert it into is both meaningless and, via
// that split() on null, exactly the load-time throw the comment at the top
// of this file warns about (it killed core's own styling/usermanager/
// locktimer code after it, the same failure mode as #16). Skip the whole
// block when there is no page id to build a link for.
if (typeof window.toolbar !== 'undefined' && typeof drawioPageId() === 'string' && drawioPageId()) {
    // toobar definition in case of multi extension defined in conf
    if (toolbarPossibleExtension.length >1 ) {
        toolbar[toolbar.length] = {
            type: "picker",
            title: "",
            icon: "../../plugins/drawio/icon.png",
            key: "",
            class  : "pk_hl",
            block  : true,
            list: generateToolBar(),
            close: ""
    };
    
    } else if (toolbarPossibleExtension.length == 1) {
        // toobar definition in case of only one extension defined
        if (toolbarPossibleExtension[0] == ""){
            toolbar[toolbar.length] = {
                type: "format",
                title: "",
                icon: "../../plugins/drawio/icon.png",
                key: "",
                open: "{{drawio>" + getImageName() + "}}",
                close: ""
            };
        } else {
            // toobar definition in case of none extension defined
            toolbar[toolbar.length] = {
                type: "format",
                title: "",
                icon: "../../plugins/drawio/icon.png",
                key: "",
                open: "{{drawio>" + getImageName() + "." + toolbarPossibleExtension[0] + "}}",
                close: ""
            };
        }

    }
};


// Media manager: "Edit with draw.io" button (maintainer request, issue #16
// context) - a diagram no page references cannot otherwise be edited at all.
// Core builds the file detail panel's action buttons directly in
// media_preview_buttons() (inc/media.php) with no event to extend them, and
// that panel is loaded both by a direct page load (?do=media&image=...) and
// by ajax (lib/scripts/media.js replaces div.file's content). So this reacts
// to the DOM instead, re-running after every ajax call, and reuses edit() -
// which already checks 'get_auth' server-side - rather than duplicating the
// editor-opening logic or auth logic here.
function drawioAddMediaManagerButton() {
    var conf = drawioConf();
    if (!conf) return;

    jQuery('.drawio__mmbtn').remove();

    // inc/template.php's tpl_mediaFileDetails() always prints the raw media id
    // as this link's text (unlike the tabs, which aren't links at all for
    // whichever tab is currently selected) - this is the one place it's
    // reliably available regardless of file type or config.
    var $header = jQuery('div.file .panelHeader a.mediafile').first();
    if (!$header.length) return;
    var mediaId = jQuery.trim($header.text());
    if (!mediaId) return;

    var ext = mediaId.split('.').pop();
    // The source itself - ns:plan.drawio, the more important half of the
    // pair (see edit()'s 'resolve_source' branch, which is what actually
    // opens it) - is not one of the renderings 'toolbar_possible_extension'
    // lists (that setting only decides which *new* diagram types the
    // toolbar picker offers; it has never gated which existing renderings
    // this button opens, and now it doesn't gate the source either).
    var isSource = ext.toLowerCase() === 'drawio';
    if (!isSource && conf['toolbar_possible_extension'].indexOf(ext) === -1) return;

    // core's file detail panel only ever renders a <div class="image"><img>
    // for a mimetype it recognises as an image (inc/media.php's
    // tpl_mediaFileDetails()) - .drawio has no registered mimetype, so for
    // it there is no <img> in the panel at all (verified against the real
    // media manager markup: a plain <div class="panelHeader"> and nothing
    // else where a rendering's <div class="image"> would be). A detached
    // element carries the id exactly as well for edit()'s purposes - see
    // its own comment on why that is a genuine no-op here, not a shortcut.
    var $img = jQuery('div.file div.image img').first();
    var target;
    if ($img.length) {
        $img.attr('id', mediaId);
        target = $img[0];
    } else if (isSource) {
        target = document.createElement('img');
        target.setAttribute('id', mediaId);
    } else {
        return;
    }

    // Matches core's own markup for Delete/Upload new version - a
    // <li><div class="no"><button>...</button></div></li> built by
    // media_preview_buttons() in inc/media.php - so this control inherits
    // the same styling (ul.actions li { display: inline } from the
    // template, div.no { display: inline; margin/padding: 0 } from
    // lib/styles/all.css - both apply regardless of a <form>) with no CSS
    // of our own. Unlike core's buttons this isn't wrapped in a <form>:
    // there is nothing here to POST to (edit() is a client-side action, not
    // a server round trip), and type="button" - not "submit" - means a
    // click can never submit or navigate even if something later wraps it
    // in a form.
    var $li = jQuery('<li class="drawio__mmbtn"></li>');
    var $button = jQuery('<button type="button"></button>').text(conf['editbutton']);
    $button.on('click', function (e) {
        e.preventDefault();
        edit(target);
    });
    $li.append(jQuery('<div class="no"></div>').append($button));
    jQuery('div.file ul.actions').append($li);
}

// issue #30: draw.io's own viewer script for {{drawio>...?interactive}} (or
// the 'interactive' config default) - syntax.php renders a
// '.mxgraph.drawio-interactive' div for it to pick up (its own
// GraphViewer.processElements(), run on the script's 'load' event scans the
// whole document itself; nothing here has to drive that part). Loaded at
// most once per page, and only when the page actually has one - no
// third-party request for a wiki that never uses the feature (issue #42).
var viewerScriptInjected = false;

// Batch 4 row 3: after a successful save the rendering on the page must
// update without a reload. The static <img> case already does that on its
// own (the 'export' handler above sets image.src to the data the editor
// just exported) - only the GraphViewer container is left stale, since it
// was never told a save happened: its 'data-mxgraph' url still points at
// the same fetch.php URL it loaded on page view, and nothing tells
// GraphViewer (or the browser's own HTTP cache in front of that URL) to
// look again. Called from the save 'export' handler's .done() with
// whatever element edit_cb() was invoked on - a no-op for the static <img>
// case (no 'drawio-interactive' class there), so one call covers both save
// paths without a branch at the call site.
function drawioRefreshDiagramElement(element) {
    if (!element || !element.classList || typeof element.classList.contains !== 'function') return;
    if (!element.classList.contains('drawio-interactive')) return;
    var cfg;
    try {
        cfg = JSON.parse(element.getAttribute('data-mxgraph') || '{}');
    } catch (e) {
        return;
    }
    if (!cfg || !cfg.url) return;

    // Cache-bust with a plain, unrecognised query param: fetch.php's own
    // $INPUT reads only 'media'/'cache'/'w'/'h'/'fit'/'rev'/'tok' (verified
    // against .cache/dokuwiki-stable's lib/exe/fetch.php) and
    // checkFileStatus()'s tok/ACL check keys purely on 'media'
    // (inc/fetch.functions.php) - an extra 't' param is never read by
    // either, so it can't break access, only defeat a cached response for
    // the XHR GraphViewer is about to make.
    cfg.url = /[?&]t=\d+/.test(cfg.url)
        ? cfg.url.replace(/([?&]t=)\d+/, '$1' + Date.now())
        : cfg.url + (cfg.url.indexOf('?') === -1 ? '?' : '&') + 't=' + Date.now();
    element.setAttribute('data-mxgraph', JSON.stringify(cfg));

    // Clear the previous render - the viewer's own DOM, never anything
    // fetched from elsewhere, so plain node removal is correct here (never
    // innerHTML, see the 'export'/svg handling above for why).
    while (element.firstChild) {
        element.removeChild(element.firstChild);
    }

    // drawioInitInteractive() above may have pulled 'mxgraph' off this
    // container if it had no .drawio source yet (issue #30) - the save
    // that just happened means one exists now, so restore it before asking
    // the viewer to look again. createViewerForElement() itself doesn't
    // require the class (it reads data-mxgraph directly), but leaving it
    // off would desync this container from any later
    // GraphViewer.processElements() scan of the page.
    if (typeof element.classList.add === 'function') {
        element.classList.add('mxgraph');
    }

    if (window.GraphViewer && typeof window.GraphViewer.createViewerForElement === 'function') {
        window.GraphViewer.createViewerForElement(element);
    }
}

function drawioInitInteractive() {
    // document.querySelectorAll is unconditionally available in every real
    // browser; guarded anyway (typeof, not a bare call) so a test sandbox
    // that doesn't stub it exercises exactly nothing here, the same
    // tolerance every other DOM access in this file already has (see #16).
    if (typeof document.querySelectorAll !== 'function') return;
    var containers = document.querySelectorAll('.drawio-interactive');
    if (!containers.length) return;

    // issue #30 (a missing .drawio source): GraphViewer's own
    // processElements() (run synchronously the moment its script finishes
    // loading - not on any later 'load'/idle event) inserts its (empty) SVG
    // canvas into a container as soon as it starts working on it, well
    // before it has fetched and parsed that container's XML - so "the
    // container gained a child" is not proof a diagram actually rendered,
    // only that GraphViewer attempted it. A 404 (or any body it can't parse
    // as a diagram) makes it throw ("Not a diagram file", uncaught) instead
    // of ever putting real content in - but the empty canvas it already
    // inserted is still there, which used to both hide the fallback image
    // and leave an uncaught console error.
    //
    // Checked here first instead, with a plain HEAD against the very same
    // fetch.php URL the viewer's own XHR would use (so the ACL/existence
    // decision stays exactly where it always lived - fetch.php, per
    // visitor - nothing here special-cases anything), and only handed to
    // GraphViewer at all (by leaving its 'mxgraph' class in place) once
    // that comes back ok. A container that fails the check has 'mxgraph'
    // removed before the viewer script is even requested, so
    // GraphViewer.processElements() (document.getElementsByClassName
    // ('mxgraph'), read live when it runs) never finds it, never throws for
    // it, and the fallback - never explicitly hidden for it - simply stays
    // visible, the same as it would for any other JS disabled/unavailable.
    //
    // fetch() is unconditionally available in every real browser new enough
    // to run GraphViewer's own bundle in the first place; guarded (typeof)
    // anyway for the same reason as every other browser API in this file -
    // a test sandbox, or a browser too old for either, simply skips the
    // check and behaves exactly as before this fix (GraphViewer decides).
    var checks = [];
    if (typeof fetch === 'function') {
        Array.prototype.forEach.call(containers, function (container) {
            if (typeof container.getAttribute !== 'function' || !container.classList) return;
            var cfg;
            try {
                cfg = JSON.parse(container.getAttribute('data-mxgraph') || '{}');
            } catch (e) {
                return;
            }
            if (!cfg || !cfg.url) return;
            checks.push(
                fetch(cfg.url, { method: 'HEAD', credentials: 'same-origin' }).then(function (r) {
                    if (!r.ok) container.classList.remove('mxgraph');
                }, function () {
                    container.classList.remove('mxgraph');
                })
            );
        });
    }

    function proceed() {
        if (!viewerScriptInjected) {
            viewerScriptInjected = true;
            var conf = drawioConf();
            var url = (conf && conf['viewer_url']) || 'https://viewer.diagrams.net/js/viewer-static.min.js';
            var script = document.createElement('script');
            script.setAttribute('src', url);
            document.body.appendChild(script);
        }

        // syntax.php renders a fallback <img> (same fetch.php image URL/
        // onerror placeholder as a static diagram) as a sibling of each
        // container - a missing .drawio source (caught above) or a
        // blocked/failed viewer script (#42) both leave the container
        // empty, which is exactly when that fallback must stay visible.
        // Hide it only once the viewer has actually rendered something
        // into its container; never on a timer, so a failed/blocked viewer
        // keeps showing the fallback instead of an empty box.
        // MutationObserver is unconditionally available in every real
        // browser too - same tolerance as above for a sandbox that doesn't
        // stub it.
        if (typeof MutationObserver !== 'function') return;
        Array.prototype.forEach.call(containers, function (container) {
            var fallback = container.nextElementSibling;
            if (!fallback || !fallback.classList || !fallback.classList.contains('drawio-interactive-fallback')) {
                return;
            }
            if (container.children.length) {
                fallback.hidden = true;
                return;
            }
            var observer = new MutationObserver(function () {
                if (container.children.length) {
                    fallback.hidden = true;
                    observer.disconnect();
                }
            });
            observer.observe(container, { childList: true });
        });
    }

    // The viewer script (and the scan it does the moment it finishes
    // loading) must not be requested until every existence check above has
    // settled - otherwise a slow HEAD racing a fast (cached) script load
    // could let GraphViewer reach a container before its class was pulled.
    // A same-origin HEAD is negligible next to downloading and parsing the
    // third-party viewer bundle either way.
    if (checks.length && typeof Promise !== 'undefined' && Promise.all) {
        Promise.all(checks).then(proceed, proceed);
    } else {
        proceed();
    }
}

// guarded: the test sandboxes in _test/script.test.js don't stub a real
// jQuery, and script.js must still load harmlessly there (see #16 above)
if (typeof jQuery === 'function') {
    jQuery(function () {
        drawioAddMediaManagerButton();
        jQuery(document).ajaxComplete(drawioAddMediaManagerButton);
        drawioInitInteractive();
    });
}
