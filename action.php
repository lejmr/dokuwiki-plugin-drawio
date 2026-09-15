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
                'editbutton' => $this->getLang('editbutton'),
                // CSRF token for the ajax calls below. Core publishes the very
                // same value in every page's HTML itself - see tpl_metaheaders()
                // (JSINFO is printed inline) and formSecurityToken(), which puts
                // it in a hidden field of every form - so this exposes nothing
                // new: the token is bound to the session, and an attacker who
                // could read the page as the victim would not need CSRF at all.
                'sectok' => getSecurityToken()
            ];
	    }

        /**
         * Same check as core's checkSecurityToken() (inc/common.php), minus the
         * msg() it calls on failure: once headers_sent() msg() echoes the message
         * straight into the response body, which would corrupt the JSON the
         * actions below return.
         */
        private function _check_token() {
            global $INPUT;
            // no logged in user means no session to ride - core's own rule
            if (!$INPUT->server->str('REMOTE_USER')) return true;
            return getSecurityToken() === $INPUT->post->str('sectok');
        }

        /**
         * Write $content to the media file $fl.
         *
         * Both writing actions go through here, because this plugin's own
         * history is the argument for one write path: it had two, they drifted
         * apart, and the draft one was still writing with a bare fopen() when
         * this round started.
         *
         * The content goes to a temp file which is then rename()d over the
         * target: rename() is atomic on the same filesystem, fopen($fl,'w') is
         * not, so a reader never sees a half-written file and a failed write
         * never leaves a truncated one behind. That matters most for 'save'
         * with mediarevisions off, where there is no attic copy to recover
         * from. chmod() applies the configured mode rather than the umask's.
         *
         * rename()'s return value has to be checked too: it can fail (the
         * destination is on another filesystem, a permission problem, the
         * destination path is itself a directory, ...), and an unchecked
         * failure used to make this function report success anyway - leaving
         * the .tmp file behind forever and, worse, letting the caller fire
         * MEDIA_UPLOAD_FINISH and tell script.js the save worked for a file
         * that was never written. Verified live: obstructing the destination
         * (a directory in its place, so rename() cannot replace it) left an
         * orphaned .tmp file in the media directory and the event still fired.
         *
         * @param string $fl      full path of the target file
         * @param string $content bytes to write
         * @return bool           false if nothing was written
         */
        private function _write_file($fl, $content) {
            global $conf;
            $tmp = $fl . '.' . uniqid('drawio', true) . '.tmp';
            if (file_put_contents($tmp, $content) === false) {
                @unlink($tmp);
                return false;
            }
            if (!rename($tmp, $fl)) {
                @unlink($tmp);
                return false;
            }
            chmod($fl, $conf['fmode']);
            return true;
        }

        /**
         * The stored XML source of a diagram, or '' if it should not be used.
         *
         * The source wins over the image - that is the whole point of storing
         * it - *unless* the image is the newer of the two. The plugin cannot
         * tell whether an image and a source actually disagree (that would
         * mean rendering the XML and comparing pictures), but it can tell
         * which one the wiki touched last, and last-write-wins is what a wiki
         * does with everything else.
         *
         * That is not a detail. Saving writes the image and then the source,
         * so a normal save always leaves the source at least as new and the
         * source is used. An image that is newer than its source got there
         * some other way: an upload over it in the media manager, or
         * restoring an older revision from the attic. Preferring the source
         * unconditionally would make "restore this revision" appear to work
         * and then silently hand the editor the newest diagram anyway -
         * a regression against today's behaviour, where restoring an old
         * image restores the XML embedded in it too. So: whatever was written
         * last is the diagram, and no prompt asks the user to adjudicate
         * something they have no way to inspect.
         *
         * Equal mtimes resolve to the source (filemtime has one second
         * resolution and both files are written inside the same second).
         *
         * A zero byte source is treated as absent, the same way syntax.php
         * treats a zero byte diagram (issue #66).
         *
         * @param string $media_id the diagram's id
         * @param string $fl       the diagram image's path on disk
         * @return string          the xml, or ''
         */
        private function _source_xml($media_id, $fl) {
            $helper = plugin_load('helper', 'drawio');
            if (!$helper) return '';
            $src_id = $helper->sourceID($media_id);
            if ($src_id === '') return '';
            $src_fl = mediaFN($src_id);
            if (!file_exists($src_fl) || filesize($src_fl) === 0) return '';
            if (@filemtime($src_fl) < @filemtime($fl)) return '';
            return file_get_contents($src_fl);
        }

        /**
         * handle ajax requests
         *
         * The status codes used below, so that each one means one thing. No
         * failure path returns a message body: an HTTP status is what
         * script.js's ajax .fail() can actually see, since the hook's return
         * value is discarded by EventHandler::process_event().
         *
         *   403  the caller may not do this - not a POST, missing or wrong
         *        security token, or the ACL says no. It never distinguishes
         *        "denied" from "does not exist"; that difference is not the
         *        caller's to learn.
         *   400  the request is malformed - an id this handler does not
         *        accept, or content that is not what the action is defined to
         *        carry. Nothing has been written when this is sent.
         *   500  the request was acceptable and the write failed anyway.
         *
         * A refused action writes nothing at all rather than writing and then
         * undoing it.
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

            // Every action below acts on the caller's media with the caller's
            // rights, so all of them - the read-only ones included - are POST
            // only and CSRF checked. script.js only ever uses jQuery.post(), so
            // one rule covers the lot and there is no second, weaker path to get
            // wrong. Reading through $INPUT->post rather than $INPUT (which is
            // $_REQUEST, i.e. GET too) is the half that makes it stick: a GET is
            // what turns a plain link into the attack, and the session cookie's
            // SameSite=Lax does not stop a top level GET navigation.
            if ($INPUT->server->str('REQUEST_METHOD') !== 'POST' || !$this->_check_token()) {
                http_status(403);
                return;
            }

            $name = $INPUT->post->str('imageName');
            $action = $INPUT->post->str('action');

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

			// The subject of the permission check must be derived from the very
			// id the file is resolved from ($media_id), never from $name again:
			// deriving them separately let a crafted name be cleaned into one
			// namespace for the check and another for the write.
			//
			// And it is the *namespace* that decides a media file's permission,
			// as core does it - this is the body of core's mediaAclPath()
			// (inc/auth.php), inlined because that helper does not exist in all
			// supported releases (it is missing from 2025-05-14b "Librarian",
			// which is still oldstable); inc/media.php in that release spells
			// the same expression out inline too.
			$acl_path = ltrim(getNS($media_id) . ':*', ':');

			// Check ACL. Mirror core's inc/media.php media_save(): AUTH_UPLOAD is
			// the baseline for everything (creating a new diagram, and even the
			// read-only get_png/get_svg/draft_get), and the higher $auth_ow bar
			// (needed because overwriting loses the old revision outright when
			// mediarevisions is off) applies only to actually overwriting an
			// existing file with 'save' - not to every action. Previously $auth_ow
			// gated everything: verified live with `* @ALL 8` (AUTH_UPLOAD) and
			// mediarevisions off, get_auth returned false and the diagram was not
			// even clickable for anyone below admin, including to create new ones.
			$auth = auth_aclcheck($acl_path, $user, $groups);
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
			if (!$access_granted) {
				// EventHandler::process_event() discards every hook's return value,
				// so nothing has ever read a returned [message, code] pair here -
				// an HTTP status is what script.js's ajax .fail() can actually see.
				http_status(403);
				return;
			}

			// A draft only ever belongs to one diagram, so the only ids a draft
			// action may name are a png's or an svg's. Checking it once here
			// covers draft_save, draft_rm and draft_get alike, and rejects an
			// imageName that already ends in .draft instead of stacking a
			// second suffix onto it ('x.draft' -> 'x.draft.draft'). These are
			// the same two formats the ['png', 'svg'] list in 'save' allows -
			// change one and you have to change the other.
			if ($suffix !== '' && !preg_match('/\.(png|svg)\.draft$/', $media_id)) {
				http_status(400);
				return;
			}

		    if($action == 'save'){

				if (!$overwrite_granted) {
					http_status(403);
					return;
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
				// Keep this list and the \.(png|svg)\.draft$ pattern in the draft
				// gate above in step: a third output format has to be added to
				// both, or drafts stop working for diagrams saved in it.
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
				$content = $INPUT->post->str('content');
				if (!preg_match('/^data:[^;,]*;base64,(.+)$/s', $content, $matches)) {
					http_status(400);
					return;
				}
				$decoded = base64_decode($matches[1], true);
				if ($decoded === false) {
					http_status(400);
					return;
				}

				// The regex above only checks the *shape* of the data: URL; the
				// mediatype in it comes from the caller and was never compared
				// against the bytes. The extension decides how DokuWiki serves
				// the file, so the bytes have to match the extension.
				if (pathinfo($media_id, PATHINFO_EXTENSION) === 'png') {
					// The PNG signature. A magic byte check rather than
					// getimagesize(): getimagesize() wants the bytes on disk (or
					// the data:// wrapper, which needs allow_url_fopen) and
					// answers "which image is this", while the question here is
					// only "is this a PNG" - which is precisely these 8 bytes.
					if (strncmp($decoded, "\x89PNG\r\n\x1a\n", 8) !== 0) {
						http_status(400);
						return;
					}
				} else {
					// SVG. This is NOT a sanitiser - the list below is longer
					// than it was, which makes it look more capable than it is,
					// so: it is a blocklist of obvious markers, and a blocklist
					// is not a sanitiser. It rejects content that is not an SVG
					// document at all, and the markers core's own
					// media_contentcheck() looks for (inc/media.php), scanned
					// over the whole file rather than only its first 256 bytes.
					// <a> and <img> are left out of that marker list on purpose:
					// draw.io puts real <a> links in its exports.
					// The prologue is xml declaration, then doctype and comments
					// in whatever order - a real draw.io export puts its
					// "Do not edit this file" comment between the two.
					if (!preg_match('/^\s*(<\?xml\b[^>]*\?>\s*)?((<!--.*?-->|<!DOCTYPE\b[^>]*>)\s*)*<svg[\s>]/is', $decoded)) {
						http_status(400);
						return;
					}
					// Plus the obvious script vectors, as defence in depth. None
					// of them can execute today: DokuWiki serves media under
					// default-src 'none' and the plugin no longer inlines svg
					// into the page - but that defence is a header this plugin
					// does not control and a proxy or a direct data/media server
					// can drop.
					//
					// NOT rejected: <foreignObject. Every genuine draw.io export
					// with a text label contains one (verified against four real
					// exports, 3 to 31 occurrences each) - rejecting it would
					// reject the plugin's own output.
					//
					// ponytail: 'javascript:' and '<use' are literal scans, so a
					// diagram whose label happens to contain the text
					// "javascript:" is refused. Narrow the scan to attribute
					// values if anyone ever hits that.
					if (preg_match('/<(script|iframe|html|body|use)[\s>]/i', $decoded)
						|| preg_match('/[\s"\x27]on\w+\s*=/i', $decoded)
						|| stripos($decoded, 'javascript:') !== false) {
						http_status(400);
						return;
					}
				}

				// The diagram's XML source, sent alongside the exported image by
				// script.js (the editor hands it both on its 'save' event). It
				// is optional: a cached older script.js, or any other caller,
				// sends no 'xml' at all and still saves the image exactly as
				// before - that is what keeps existing wikis working while
				// diagrams pick up a source lazily, one save at a time.
				//
				// The id it is written under comes from the helper, derived
				// from $media_id, so no request ever names a .drawio file -
				// and the png/svg whitelist above has already refused an
				// imageName ending in .drawio anyway.
				//
				// Validated here, before the image is written, for the same
				// reason as everything else on this path: a refused action
				// writes nothing at all. Same 2 MiB cap and same
				// bytes-match-the-name rule the draft and the image get.
				$xml = $INPUT->post->str('xml');
				$src_id = '';
				if ($xml !== '') {
					if (strlen($xml) > 2 * 1024 * 1024) {
						http_status(400);
						return;
					}
					$helper = plugin_load('helper', 'drawio');
					if ($helper && $helper->isDiagramXml($xml)) {
						$src_id = $helper->sourceID($media_id);
					}
					if ($src_id === '') {
						http_status(400);
						return;
					}
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

                if (!$this->_write_file($fl, $decoded)) {
                    http_status(500);
                    return;
                }

				@clearstatcache(true, $fl);
				$new = @filemtime($fl);

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

				// Now the source, after the image and only if the image got
				// written. If this write fails the save still counts as
				// successful: the image on disk is the new diagram, it still
				// has the XML embedded in it the way it always did, and it is
				// now newer than any stale .drawio left behind - which is
				// exactly the condition the read path uses to ignore one. So a
				// failure here leaves the user precisely where they were
				// before this feature existed, instead of failing a save that
				// actually worked and telling them their change was lost.
				//
				// $src_id is shared by ns:plan.png and ns:plan.svg - that is
				// the point, not a bug: the diagram's identity is ns:plan,
				// the extension only names a rendering of it, and saving
				// either format is a save of the same diagram (see helper.php's
				// sourceID() for the full reasoning, including the one real
				// hazard and why the *other* format's image is deliberately
				// never regenerated here). Nothing above refuses this write
				// because $src_fl already exists - overwriting it is exactly
				// what every save of the same diagram is supposed to do.
				//
				// No attic copy and no media changelog entry for the source:
				// the image already has both, and its attic copies carry the
				// embedded XML, so the history is not lost by keeping it in
				// one place instead of doubling every diagram's entries. The
				// MEDIA_UPLOAD_FINISH is fired though - a backup plugin that
				// commits the derived image but not the source it was derived
				// from would be backing up the wrong file.
				if ($src_id !== '') {
					$src_fl = mediaFN($src_id);
					$src_overwrite = file_exists($src_fl);
					if ($this->_write_file($src_fl, $xml)) {
						$src_data = [basename($src_fl), $src_fl, $src_id, 'application/xml', $src_overwrite, null];
						\dokuwiki\Extension\Event::createAndTrigger('MEDIA_UPLOAD_FINISH', $src_data, null, false);
					}
				}
            }
            if($action == 'get_png' || $action == 'get_svg'){
				if (!file_exists($fl)) return;
                // Return image in the base64 for draw.io
                header('Content-Type: application/json');				
                $fc = file_get_contents($fl);
				$mime = $action == 'get_png' ? 'image/png' : 'image/svg+xml';
				$out = ["content" => "data:$mime;base64,".base64_encode($fc)];
				// The XML source, when there is one. script.js loads the
				// editor straight from this and never touches the image;
				// without it, it falls back to xmlpng/xmlsvg exactly as
				// before, which is what keeps every existing diagram working.
				// Same file, same namespace, so the ACL checked above is the
				// one that governs it - nothing extra to check here.
				$xml = $this->_source_xml($media_id, $fl);
				if ($xml !== '') $out['xml'] = $xml;
				echo json_encode($out);
            }
            
            // Draft section
            if($action == 'draft_save'){
                // A draft is a scratch copy of one diagram, and the only thing
                // that ever writes one is the editor, which sends the JSON
                // {"lastModified":...,"xml":...} that draft_get hands straight
                // back to it. Without these three checks this was a write
                // primitive: arbitrary bytes, any size, under any base name -
                // the same hole that was closed for 'save' one function away.
                // (That the id is a diagram's is checked for every draft action
                // above.)
                $content = $INPUT->post->str('content');

                // A size cap, checked before parsing so a huge body is dropped
                // rather than decoded. draw.io XML for a big diagram is tens to
                // a few hundred KB; 2 MiB is well clear of that and still bounds
                // what an autosave can drop on the disk.
                if (strlen($content) > 2 * 1024 * 1024) {
                    http_status(400);
                    return;
                }

                // The shape the client actually sends. This is not a sanitiser
                // for the XML inside - it only makes sure the file is the JSON
                // envelope draft_get is expected to return.
                $draft = json_decode($content, true);
                if (!is_array($draft) || !isset($draft['xml']) || !is_string($draft['xml'])) {
                    http_status(400);
                    return;
                }

                // prepare directory
                io_createNamespace($media_id, 'media');

                if (!$this->_write_file($fl, $content)) {
                    http_status(500);
                    return;
                }
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
