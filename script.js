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
    imageFormat = imagePointer.getAttribute('id').split('.');
    if (imageFormat.length>2) {
        alert('File name format or extension error: should be filename.extension (available extension :' + toolbarPossibleExtension + ')');
        return;
    } else if (imageFormat.length == 1) {
        console.info('use default exention png');
        imageFormat = "png";
    } else {
        imageFormat=imageFormat.pop();
    }

    var iframe = document.createElement('iframe');
    iframe.setAttribute('frameborder', '0');
    iframe.setAttribute('class', 'drawio');
    iframe.setAttribute('style', 'z-index: ' + zIndex + ';');
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

    var draft = localStorage.getItem('.draft-' + currentDiagramId);

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
                    localStorage.removeItem('.draft-' + currentDiagramId);
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
                localStorage.setItem('.draft-' + currentDiagramId, dr);

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
                    localStorage.setItem('.draft-' + currentDiagramId, dr);
                } 
                else if (imageFormat ==='svg') 
                {
                    iframe.contentWindow.postMessage(JSON.stringify({action: 'export',
                    format: 'xmlsvg', xml: msg.xml, spin: 'Updating page'}), '*');
                    dr = JSON.stringify({lastModified: new Date(), xml: msg.xml});
                    localStorage.setItem('.draft-' + currentDiagramId, dr);
                }
                // Save on-disk - best-effort, see above.
                drawioPost('draft_save', imagePointer.getAttribute('id'), { content: dr }, null, true);
            }
            else if (msg.event == 'exit')
            {
                localStorage.removeItem('.draft-' + currentDiagramId);
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
    seq = JSINFO.id.split(":");
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


if (typeof window.toolbar !== 'undefined') {
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

    var $li = jQuery('<li class="drawio__mmbtn"></li>');
    var $link = jQuery('<a href="#"></a>').text(conf['editbutton']);
    $link.on('click', function (e) {
        e.preventDefault();
        edit(target);
    });
    $li.append($link);
    jQuery('div.file ul.actions').append($li);
}

// guarded: the test sandboxes in _test/script.test.js don't stub a real
// jQuery, and script.js must still load harmlessly there (see #16 above)
if (typeof jQuery === 'function') {
    jQuery(function () {
        drawioAddMediaManagerButton();
        jQuery(document).ajaxComplete(drawioAddMediaManagerButton);
    });
}
