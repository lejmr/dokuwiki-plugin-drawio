// Embeded editor
////var editor = 'https://www.draw.io/?embed=1&ui=atlas&spin=1&proto=json';
var editor= JSINFO['plugin_drawio']['url'] + '?embed=1&ui=atlas&spin=1&proto=json';
var initial = null;
var name = null;
var imagePointer = null;

function edit(image)
{
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
    var zIndex = 999;
    if(JSINFO && JSINFO['plugin_drawio']){
        zIndex = JSINFO['plugin_drawio']['zIndex'];
    }

    imagePointer = image;

    var iframe = document.createElement('iframe');
    iframe.setAttribute('frameborder', '0');
    iframe.setAttribute('class', 'drawio');
    iframe.setAttribute('style', 'z-index: ' + zIndex + ';');

    var close = function()
    {
        window.removeEventListener('message', receive);
        document.body.removeChild(iframe);
    };

    var draft = localStorage.getItem('.draft-' + name);

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
        if (evt.data.length > 0)
        {
            var msg = JSON.parse(evt.data);
			
            if (msg.event == 'init')
            {
                if (draft != null)
                {
                    iframe.contentWindow.postMessage(JSON.stringify({action: 'load',
                        autosave: 1, xml: draft.xml}), '*');
                    iframe.contentWindow.postMessage(JSON.stringify({action: 'status',
                        modified: true}), '*');
                }
                else
                {
                    jQuery.post(
                        DOKU_BASE + 'lib/exe/ajax.php',
                        {
                            call: 'plugin_drawio', 
                            imageName: imagePointer.getAttribute('id'),
                            action: 'get'
                        },
                        function(data){
                            iframe.contentWindow.postMessage(JSON.stringify({action: 'load',
                                autosave: 1, xmlpng: data.content}), '*');
                        }
                    );
					
                }
            }
            else if (msg.event == 'export')
            {
                image.setAttribute('src', msg.data);
                localStorage.setItem(name, JSON.stringify({lastModified: new Date(), data: msg.data}));
                localStorage.removeItem('.draft-' + name);
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
                
                // Clean cache of this page
                var url = new URL(window.location.href);
                url.searchParams.set('purge', 'true');
                jQuery.get(url);
            }
            else if (msg.event == 'autosave')
            {
                dr = JSON.stringify({lastModified: new Date(), xml: msg.xml});
                localStorage.setItem('.draft-' + name, dr);

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
                iframe.contentWindow.postMessage(JSON.stringify({action: 'export',
                    format: 'xmlpng', xml: msg.xml, spin: 'Updating page'}), '*');
                dr = JSON.stringify({lastModified: new Date(), xml: msg.xml});
                localStorage.setItem('.draft-' + name, dr);

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
                localStorage.removeItem('.draft-' + name);
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
    iframe.setAttribute('src', editor);
    document.body.appendChild(iframe);
};


// Toolbar menu items
function getImageName(){
    seq = JSINFO.id.split(":");
    seq = seq.slice(0,seq.length-1);
    seq.push("diagram1");
    return seq.join(":");
}

if (typeof window.toolbar !== 'undefined') {
    toolbar[toolbar.length] = {
        type: "format",
        title: "",
        icon: "../../plugins/drawio/icon.png",
        key: "",
        // open: "{{drawio>" + JSINFO.id + "}}",
        open: "{{drawio>" + getImageName() + "}}",
        close: ""
    };
};
