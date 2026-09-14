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
                'toolbar_possible_extension' => array_map('trim', explode(",",$this->getConf('toolbar_possible_extension')))
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

            $suffix = strpos($action, "draft_") === 0 ? '.draft':'';
            $media_id = $name . $suffix;
			$media_id = cleanID($media_id);
			$fl = mediaFN($media_id);
			
			// Get user info		
			global $USERINFO;
			global $INPUT;
			global $INFO;
			
			$user = $INPUT->server->str('REMOTE_USER');
			$groups = (array) $USERINFO['grps'];
			$auth_ow = (($conf['mediarevisions']) ? AUTH_UPLOAD : AUTH_DELETE);
			$id = cleanID($name);
			
			// Check ACL
			$auth = auth_aclcheck($id, $user, $groups);
			$access_granted = ($auth >= $auth_ow);
		
			// AJAX request
			if ($action == 'get_auth')
            {
				echo json_encode($access_granted);
				return;
            }
						;
			if (!$access_granted)
				return [$lang['media_perm_upload'], 0];

			io_makeFileDir($fl);
		    if($action == 'save'){
				
				$old = @filemtime($fl);
				if(!file_exists(mediaFN($media_id, $old)) && file_exists($fl)) {
					// add old revision to the attic if missing
					media_saveOldRevision($media_id);
				}
				$overwrite = file_exists($fl);
				$filesize_old = $overwrite ? filesize($fl) : 0;

				// prepare directory
				io_createNamespace($media_id, 'media');

                // Write content to file
                $content = $INPUT->str('content');
                $base64data = explode(",", $content)[1];
                //$whandle = fopen($file_path,'w');
                $whandle = fopen($fl, 'w');
                fwrite($whandle,base64_decode($base64data));
                fclose($whandle);

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
                unlink($fl);
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
