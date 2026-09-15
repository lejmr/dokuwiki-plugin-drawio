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
    drawioPost('get_auth', imgPointer.getAttribute('id'), null, function (data) {
        if (data != 'true') return;
        edit_cb(imgPointer);
    });
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

    var close = function()
    {
        window.removeEventListener('message', receive);
        editorOpen = false;
        document.body.removeChild(iframe);
    };
    
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
                    // scripts or handlers inside it.
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
                    var tdElement = document.getElementById(image.id);
                    var trElement = tdElement.parentNode;
                    var svgImg = document.createElement('img');
                    svgImg.setAttribute("class","mediacenter");
                    svgImg.setAttribute("style","max-width:100%;cursor:pointer;");
                    svgImg.setAttribute('onclick','edit(this);');
                    svgImg.id = image.id;
                    svgImg.src = msg.data;
                    trElement.replaceChild(svgImg,tdElement);
                    trElement.style.textAlign = "center" ;
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
    iframe.setAttribute('src', conf['url'] + '?embed=1&ui=atlas&spin=1&proto=json');
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
    if (conf['toolbar_possible_extension'].indexOf(ext) === -1) return;

    var $img = jQuery('div.file div.image img').first();
    if (!$img.length) return;
    $img.attr('id', mediaId);

    var $li = jQuery('<li class="drawio__mmbtn"></li>');
    var $link = jQuery('<a href="#"></a>').text(conf['editbutton']);
    $link.on('click', function (e) {
        e.preventDefault();
        edit($img[0]);
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
