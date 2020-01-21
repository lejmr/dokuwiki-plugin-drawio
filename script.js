// Embeded editor
var editor = 'https://www.draw.io/?embed=1&ui=atlas&spin=1&proto=json';
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
    imagePointer = image;

    var iframe = document.createElement('iframe');
    iframe.setAttribute('frameborder', '0');
    iframe.setAttribute('class', 'drawio');

    var close = function()
    {
        window.removeEventListener('message', receive);
        document.body.removeChild(iframe);
    };

    var draft = localStorage.getItem('.draft-' + name);
                
    if (draft != null)
    {
        draft = JSON.parse(draft);
                    
        if (!confirm("A version of this page from " + new Date(draft.lastModified) + " is available. Would you like to continue editing?"))
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
                
                // Clean cache of this page
                var url = new URL(window.location.href);
                url.searchParams.set('purge', 'true');
                jQuery.get(url);
            }
            else if (msg.event == 'autosave')
            {
                localStorage.setItem('.draft-' + name, JSON.stringify({lastModified: new Date(), xml: msg.xml}));
            }
            else if (msg.event == 'save')
            {
                iframe.contentWindow.postMessage(JSON.stringify({action: 'export',
                    format: 'xmlpng', xml: msg.xml, spin: 'Updating page'}), '*');
                localStorage.setItem('.draft-' + name, JSON.stringify({lastModified: new Date(), xml: msg.xml}));
            }
            else if (msg.event == 'exit')
            {
                localStorage.removeItem('.draft-' + name);
                draft = null;
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