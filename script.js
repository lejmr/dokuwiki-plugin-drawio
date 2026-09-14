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
    jQuery.post(
        DOKU_BASE + 'lib/exe/ajax.php',
        {
            call: 'plugin_drawio', 
            imageName: imgPointer.getAttribute('id'),
            action: 'get_auth'
        },
		function(data) {
			if (data != 'true') return;
			edit_cb(imgPointer);
		}
	);
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
    
    var draft = localStorage.getItem('.draft-' + currentDiagramId);

    // Prefer the draft from browser cache
    if(draft == null){
        // Try to find on-disk stored draft file
        jQuery.post(
            DOKU_BASE + 'lib/exe/ajax.php',
            {
                call: 'plugin_drawio', 
                imageName: imagePointer.getAttribute('id'),
                action: 'draft_get'
            },
            function(data) {
                if (data.content != 'NaN') {

                    // Set draft from received data
                    draft = data;

                    // Handle the discard - remove on disk
                    if (!confirm("A version of this diagram from " + new Date(data.lastModified) + " is available. Would you like to continue editing?"))
                    {   
                        // clean draft variable
                        draft = null;

                         // Remove all draft files
                        jQuery.post(
                            DOKU_BASE + 'lib/exe/ajax.php',
                            {
                                call: 'plugin_drawio', 
                                imageName: imagePointer.getAttribute('id'),
                                action: 'draft_rm'
                            }
                        );
                    }
                }
            }
        );
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
                    if (imageFormat == 'png')
                    {
                        jQuery.post(
                            DOKU_BASE + 'lib/exe/ajax.php',
                            {
                                call: 'plugin_drawio', 
                                imageName: imagePointer.getAttribute('id'),
                                action: 'get_png'
                            },
                            function(data){
                                iframe.contentWindow.postMessage(JSON.stringify({action: 'load',
                                    autosave: 1, xmlpng: data.content}), '*');
                            }
                        );
                    }
                    else if (imageFormat == 'svg')
                    {
                        jQuery.post(
                            DOKU_BASE + 'lib/exe/ajax.php',
                            {
                                call: 'plugin_drawio', 
                                imageName: imagePointer.getAttribute('id'),
                                action: 'get_svg'
                            },
                            function(data){
                                //var svg = new XMLSerializer().serializeToString(data.content.firstChild);
                                iframe.contentWindow.postMessage(JSON.stringify({action: 'load',
                                    autosave: 1, xml: data.content}), '*');
                            }
                        );
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
                    // Extracts SVG DOM from data URI to enable links
                    imgData = atob(msg.data.substring(msg.data.indexOf(',') + 1));
                    var tdElement = document.getElementById(image.id);
                    var trElement=  tdElement.parentNode;
                    var svgImg= document.createElement('svg');
                    svgImg.setAttribute("class","mediacenter");
                    svgImg.setAttribute("style","max-width:100%;cursor:pointer;");
                    svgImg.setAttribute('onclick','edit(this);');
                    svgImg.id=image.id;
                    svgImg.innerHTML=imgData;
                    trElement.replaceChild(svgImg,tdElement);
                    trElement.style.textAlign = "center" ;
                }
                
                localStorage.removeItem('.draft-' + currentDiagramId);
                draft = null;
				close();

                // Save into dokuwiki
                jQuery.post(
                    DOKU_BASE + 'lib/exe/ajax.php',
                    {
                        call: 'plugin_drawio', 
                        imageName: imagePointer.getAttribute('id'),
                        content: msg.data,
                        action: 'save'
                    }
                );

                // Remove all draft files
                jQuery.post(
                    DOKU_BASE + 'lib/exe/ajax.php',
                    {
                        call: 'plugin_drawio', 
                        imageName: imagePointer.getAttribute('id'),
                        action: 'draft_rm'
                    }
                );
            }
            else if (msg.event == 'autosave')
            {
                dr = JSON.stringify({lastModified: new Date(), xml: msg.xml});
                localStorage.setItem('.draft-' + currentDiagramId, dr);

                // Save on-disk
                jQuery.post(
                    DOKU_BASE + 'lib/exe/ajax.php',
                    {
                        call: 'plugin_drawio', 
                        imageName: imagePointer.getAttribute('id'),
                        content: dr,
                        action: 'draft_save'
                    }
                );
            }
            else if (msg.event == 'save')
            {
                
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
                // Save on-disk
                jQuery.post(
                    DOKU_BASE + 'lib/exe/ajax.php',
                    {
                        call: 'plugin_drawio', 
                        imageName: imagePointer.getAttribute('id'),
                        content: dr,
                        action: 'draft_save'
                    }
                );
            }
            else if (msg.event == 'exit')
            {
                localStorage.removeItem('.draft-' + currentDiagramId);
                draft = null;

                // Remove all draft files
                jQuery.post(
                    DOKU_BASE + 'lib/exe/ajax.php',
                    {
                        call: 'plugin_drawio', 
                        imageName: imagePointer.getAttribute('id'),
                        action: 'draft_rm'
                    }
                );

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
