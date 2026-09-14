<?php
    /*
     * plugin should use this method to register its handlers 
     * with the dokuwiki's event controller
     * 
     * 2025-06-05  axel  replace deprecated JSON object; short array syntax
     */

    if(!defined('DOKU_INC')) die();
 
 
    class action_plugin_drawio extends DokuWiki_Action_Plugin {

        public function register(Doku_Event_Handler $controller) {
            $controller->register_hook('DOKUWIKI_STARTED', 'AFTER', $this, 'addjsinfo');
            // lib/exe/mediamanager.php never fires DOKUWIKI_STARTED, so without this
            // JSINFO['plugin_drawio'] is missing there - see script.js for why that
            // used to break every plugin loaded after drawio (fixes #16).
            $controller->register_hook('MEDIAMANAGER_STARTED', 'AFTER', $this, 'addjsinfo');
            $controller->register_hook('AJAX_CALL_UNKNOWN', 'BEFORE', $this,'_ajax_call');
            // $controller->register_hook('TOOLBAR_DEFINE', 'AFTER', $this, 'insert_button', array ());
        }
        
        // function insert_button(Doku_Event $event, $param) {
        //     $event->data[] = array (
        //         'type' => 'format',
        //         'title' => $this->getLang('abutton'),
        //         'icon' => '../../plugins/drawio/icon.png',
        //         'open' => '<abutton>',
        //         'close' => '',
        //         'block' => false,
        //     );
        // }

        /**
         *  add drawio config options to jsinfo
         */

	    function addjsinfo($event, $params){
            global $JSINFO;
	        $JSINFO['plugin_drawio'] = [
                'zIndex' => $this->getConf('zIndex'),
                'url' => $this->getConf('url'),
                'toolbar_possible_extension' => array_map('trim', explode(",",$this->getConf('toolbar_possible_extension'))),
                // lang strings for the JS-only media manager button - plugins have no
                // core mechanism to add entries to the global JS LANG object, so this
                // (JSINFO) is the established way to hand a plugin's own translated
                // strings to its script.js
                'editbutton' => $this->getLang('editbutton')
            ];
	    }

        /**
         * handle ajax requests
         */
        function _ajax_call(Doku_Event $event, $param) {
            if ($event->data !== 'plugin_drawio') {
                return;
            }
            //no other ajax call handlers needed
            $event->stopPropagation();
            $event->preventDefault();
        
            //e.g. access additional request variables
            global $conf, $lang;
            global $INPUT; //available since release 2012-10-13 "Adora Belle"
            $name = $INPUT->str('imageName');
            $action = $INPUT->str('action');

            // a missing/empty imageName cleans to '', and mediaFN('') resolves to
            // the media root *directory* rather than a file - every action below
            // assumes $fl is a file, escalating into anything from a stray warning
            // to an uncaught fatal. A normal client never sends this.
            if (trim($name) === '') {
                if ($action == 'get_auth') {
                    echo json_encode(false);
                }
                return;
            }

            $suffix = strpos($action, "draft_") === 0 ? '.draft':'';
            $media_id = $name . $suffix;
			$media_id = cleanID($media_id);
			$fl = mediaFN($media_id);
			
			// Get user info		
			global $USERINFO;
			global $INPUT;
			global $INFO;
			
			$user = $INPUT->server->str('REMOTE_USER');
			$groups = (array) ($USERINFO['grps'] ?? []);
			$id = cleanID($name);

			// Check ACL. Mirror core's inc/media.php media_save(): AUTH_UPLOAD is
			// the baseline for everything (creating a new diagram, and even the
			// read-only get_png/get_svg/draft_get), and the higher $auth_ow bar
			// (needed because overwriting loses the old revision outright when
			// mediarevisions is off) applies only to actually overwriting an
			// existing file with 'save' - not to every action. Previously $auth_ow
			// gated everything: verified live with `* @ALL 8` (AUTH_UPLOAD) and
			// mediarevisions off, get_auth returned false and the diagram was not
			// even clickable for anyone below admin, including to create new ones.
			$auth = auth_aclcheck($id, $user, $groups);
			$auth_ow = (($conf['mediarevisions']) ? AUTH_UPLOAD : AUTH_DELETE);
			$access_granted = ($auth >= AUTH_UPLOAD);
			$overwrite_granted = $access_granted && (!file_exists($fl) || $auth >= $auth_ow);

			// AJAX request - answers whether 'save' would actually be allowed, so
			// the media manager edit button (which relies on this) doesn't offer
			// something that then silently fails.
			if ($action == 'get_auth')
            {
				echo json_encode($overwrite_granted);
				return;
            }
						;
			if (!$access_granted)
				return [$lang['media_perm_upload'], 0];

			io_makeFileDir($fl);
		    if($action == 'save'){

				if (!$overwrite_granted) {
					return [$lang['media_perm_upload'], 0];
				}

				// This handler writes $fl directly, bypassing core's own upload
				// path (inc/media.php's media_save()) and the extension whitelist
				// it enforces there - verified live: imageName=test:pwn.php with a
				// base64 payload wrote a working PHP file straight into data/media.
				// The plugin only ever produces png and svg, so reject anything
				// else outright rather than trying to borrow core's
				// media_contentcheck() (it inspects an already-written file for
				// XSS markers and, for images, that decoded bytes actually match
				// the claimed mimetype - moot here since we control the mimetype
				// ourselves, and it does nothing at all for image/svg+xml).
				if (!in_array(pathinfo($media_id, PATHINFO_EXTENSION), ['png', 'svg'], true)) {
					http_status(400);
					return;
				}

				// The client sends a data: URL ("data:image/png;base64,...."). A
				// failed drawio export, or jQuery serialising an undefined value
				// as the literal string "undefined" (msg.data can be undefined -
				// see script.js), produced neither a match at [1] (a PHP warning)
				// nor valid base64 - and the file below got truncated to garbage
				// or zero bytes before any of that was noticed. Validate first and
				// write nothing at all on a bad payload.
				$content = $INPUT->str('content');
				if (!preg_match('/^data:[^;,]*;base64,(.+)$/s', $content, $matches)) {
					http_status(400);
					return;
				}
				$decoded = base64_decode($matches[1], true);
				if ($decoded === false) {
					http_status(400);
					return;
				}

				$old = @filemtime($fl);
				if(!file_exists(mediaFN($media_id, $old)) && file_exists($fl)) {
					// add old revision to the attic if missing
					media_saveOldRevision($media_id);
				}
				$overwrite = file_exists($fl);
				$filesize_old = $overwrite ? filesize($fl) : 0;

				// prepare directory
				io_createNamespace($media_id, 'media');

                // Write to a temp file and rename() over the target so a reader
                // never sees (and a bad write never leaves behind) a half-written
                // or truncated diagram - rename() is atomic on the same filesystem,
                // fopen($fl,'w') is not. Matters most with mediarevisions off,
                // where media_saveOldRevision() above never ran and there is no
                // attic copy to recover from.
                $tmp = $fl . '.' . uniqid('drawio', true) . '.tmp';
                if (file_put_contents($tmp, $decoded) === false) {
                    @unlink($tmp);
                    http_status(500);
                    return;
                }
                rename($tmp, $fl);

				@clearstatcache(true, $fl);
				$new = @filemtime($fl);
				chmod($fl, $conf['fmode']);

				// Add to log
				$filesize_new = filesize($fl);
				$sizechange = $filesize_new - $filesize_old;
				if ($filesize_old != 0) {
				    addMediaLogEntry($new, $media_id, DOKU_CHANGE_TYPE_EDIT, '', '', null, $sizechange);
				} else {
					addMediaLogEntry($new, $media_id, DOKU_CHANGE_TYPE_CREATE, $lang['created'], '', null, $sizechange);
                }

				// Notify other plugins (e.g. gitbacked) that a media file was written.
				// Same event core fires from media_save()/media_upload_finish() for a
				// normal upload, same data shape (fn_tmp/name, fn, id, mime, overwrite,
				// move) so existing consumers keep working. We already wrote the file
				// ourselves above (this plugin never goes through DokuWiki's upload
				// pipeline), so there's no default action to run and nothing to undo -
				// this is fired purely as an after-the-fact notification.
				list(, $mime) = mimetype($media_id);
				$data = [basename($fl), $fl, $media_id, $mime, $overwrite, null];
				\dokuwiki\Extension\Event::createAndTrigger('MEDIA_UPLOAD_FINISH', $data, null, false);
            }
            if($action == 'get_png'){
				if (!file_exists($fl)) return;
                // Return image in the base64 for draw.io
                header('Content-Type: application/json');				
                //$fc = file_get_contents($file_path);
                $fc = file_get_contents($fl);
				echo json_encode(["content" => "data:image/png;base64,".base64_encode($fc)]);
            }
            if($action == 'get_svg'){
				if (!file_exists($fl)) return;
                // Return image in the base64 for draw.io
                header('Content-Type: application/json');				
                //$fc = file_get_contents($file_path);
                $fc = file_get_contents($fl);
				echo json_encode(["content" => "data:image/svg+xml;base64,".base64_encode($fc)]);
            }
            
            // Draft section
            if($action == 'draft_save'){
                // prepare directory
                io_createNamespace($media_id, 'media');
                
                // Format content of draft file
                $content = $INPUT->str('content');
                
                // Write content to file
                $whandle = fopen($fl, 'w');
                fwrite($whandle, $content);
                fclose($whandle);
            }
            if($action == 'draft_rm'){
                // script.js calls this unconditionally after both 'save' and 'exit',
                // so a normal open/draw/save cycle where autosave never fired (no
                // draft was ever written) logged unlink(): No such file or directory
                if (file_exists($fl)) {
                    unlink($fl);
                }
            }
            if($action == 'draft_get'){
                header('Content-Type: application/json');	
                if (file_exists($fl)){
                    echo file_get_contents($fl);
                }else {
                    echo json_encode(["content" => "NaN"]);
                }
            }
        }
    }
