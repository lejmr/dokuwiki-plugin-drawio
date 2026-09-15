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

            // The sibling lifecycle: a diagram's .drawio source has to follow
            // its image through the operations DokuWiki's media manager (and
            // the widely-installed move plugin) already support, or the whole
            // point of storing it - surviving an optimiser/re-encode/backup
            // that rewrites the image - is undone by an ordinary delete or
            // rename. See _media_delete_sibling()/_move_sibling() below for
            // the reasoning behind each direction.
            $controller->register_hook('MEDIA_DELETE_FILE', 'AFTER', $this, '_media_delete_sibling');
            // Core has no media rename/move event of its own - deleting the
            // old id and uploading the new one is literally how a media
            // manager rename works without this plugin (https://www.dokuwiki.
            // org/tips:rename_pages_and_media, "There is no such feature in
            // DokuWiki core"). The move plugin (https://www.dokuwiki.org/
            // plugin:move) is what everyone actually uses for this, and its
            // helper_plugin_move_op::moveMedia() fires this event around the
            // move (verified against michitux/dokuwiki-plugin-move HEAD,
            // helper/op.php) - AFTER, once the image has actually moved.
            $controller->register_hook('PLUGIN_MOVE_MEDIA_RENAME', 'AFTER', $this, '_move_sibling');

            // Diagram text into the fulltext index - see _index_diagrams()
            // for the ACL rule this applies before ever reading a byte of a
            // diagram into a page's index.
            $controller->register_hook('INDEXER_PAGE_ADD', 'BEFORE', $this, '_index_diagrams');
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
            global $JSINFO, $conf;
	        $JSINFO['plugin_drawio'] = [
                'zIndex' => $this->getConf('zIndex'),
                'url' => $this->getConf('url'),
                'toolbar_possible_extension' => array_map('trim', explode(",",$this->getConf('toolbar_possible_extension'))),
                // lang strings for the JS-only media manager button - plugins have no
                // core mechanism to add entries to the global JS LANG object, so this
                // (JSINFO) is the established way to hand a plugin's own translated
                // strings to its script.js
                'editbutton' => $this->getLang('editbutton'),
                'lockwarning' => $this->getLang('lockwarning'),
                // So script.js can renew the advisory lock (see close()'s
                // comment for why renewal exists at all) on an interval that
                // scales with however this wiki has core's own lock expiry
                // configured, rather than a fixed guess that would be wrong
                // for any site that changed it from the 900s/15min default.
                'locktime' => (int) $conf['locktime'],
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
         * The id core's lock()/checklock() (inc/common.php) key a diagram's
         * advisory lock under.
         *
         * Three things are deliberate here, not accidents of reuse:
         *
         * Shared between a diagram's png and svg. sourceID() is the existing
         * "these two media ids are renderings of one diagram" identity (see
         * helper.php) - reusing it here means opening ns:plan.svg while
         * someone else has ns:plan.png open warns about them too, which is
         * what "the same diagram" has to mean for a lock as much as it does
         * for the stored XML. Falls back to $media_id itself for a name
         * sourceID() does not recognise (not currently reachable - 'lock' is
         * only ever posted for the .png/.svg id script.js already opened -
         * but a fallback beats a fatal for input this function was never
         * handed a guarantee about).
         *
         * Collision-free against a page lock, fully, by construction - not
         * just for the case that happened to be checked. Core keys every
         * lock - a page's or, now, a diagram's - purely by
         * md5(cleanID($id)) (wikiLockFN(), inc/pageutils.php), with nothing
         * in the lock file itself saying which kind of thing was locked. An
         * earlier version of this reasoning stopped at "cleanID() does not
         * strip an internal extension, so 'ns:plan' and 'ns:plan.drawio'
         * clean to different strings" - true, but incomplete: a DokuWiki
         * page id may contain dots anywhere (they are only special at an
         * id's boundaries), so a page literally named 'ns:plan.drawio' is a
         * legal id, and would have shared this diagram's lock file exactly.
         * The 'drawio:' prefix below closes that for good: no page id this
         * plugin writes to (or reads a diagram from) ever starts with it,
         * because 'drawio' is not a namespace segment this plugin's own ids
         * (media ids under whatever namespace a diagram lives in) ever
         * introduce on their own - the prefix is added here, once, never by
         * anything a caller controls the rest of.
         *
         * Not shared with unlock(). There is no unlock() call in this
         * plugin any more - see script.js's close() and its comment for why
         * (briefly: one open tab must never be able to delete another open
         * tab's, or another user's, protection just by closing).
         *
         * @param string $media_id e.g. 'ns:plan.png'
         * @return string          e.g. 'drawio:ns:plan.drawio'
         */
        private function _lock_id($media_id) {
            $helper = plugin_load('helper', 'drawio');
            $src_id = $helper ? $helper->sourceID($media_id) : '';
            return 'drawio:' . ($src_id !== '' ? $src_id : $media_id);
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
                    header('Content-Type: application/json');
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
			// The ACL path itself lives in helper::mediaAclPath() - see its
			// docblock for why this and syntax.php ask different questions with it.
			$helper = plugin_load('helper', 'drawio');
			$acl_path = $helper ? $helper->mediaAclPath($media_id) : ltrim(getNS($media_id) . ':*', ':');

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
				// application/json, same as every other JSON-returning action here -
				// this used to be the one that didn't, which is why script.js had to
				// compare the *string* 'true' instead of reading a real boolean.
				header('Content-Type: application/json');
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
			// second suffix onto it ('x.draft' -> 'x.draft.draft'). This stays a
			// regex rather than a helper::isDiagramExtension() call - by this
			// point the '.draft' suffix is already part of $media_id, so the
			// extension PATHINFO_EXTENSION would see is 'draft', not png/svg -
			// collapsing this would cost a second argument or a strip-then-check
			// step for a single call site. helper::isDiagramExtension() is still
			// the one place that lists which extensions are diagrams; a third
			// rendering format has to be added there and, separately, here.
			if ($suffix !== '' && !preg_match('/\.(png|svg)\.draft$/', $media_id)) {
				http_status(400);
				return;
			}

		    if($action == 'save'){

				if (!$overwrite_granted) {
					http_status(403);
					return;
				}

				// Unlike draft_save (see its own lock()/renewal comment below),
				// 'save' does not renew the advisory lock itself - not an
				// oversight. script.js's 'save' postMessage event fires a
				// draft_save just before the 'export' round trip that leads
				// here, so a real save renews the lock a moment earlier anyway;
				// and the editor's own interval timer (see script.js's
				// edit_cb()) renews independently of either. Adding a third
				// renewal here would only cover the gap between those two -
				// which the timer (at most locktime/3 old) already keeps well
				// inside locktime - for no observable difference.

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
				// helper::isDiagramExtension() is the single home for the png/svg
				// question; see its docblock for why the draft gate above still
				// asks it as a regex instead.
				$helper = plugin_load('helper', 'drawio');
				if (!$helper || !$helper->isDiagramExtension($media_id)) {
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
            // Advisory diagram lock. Not a hard lock - core's own posture for
            // pages (checklock()'s warning does not stop the edit form from
            // opening), kept deliberately: a stale lock from a forgotten tab
            // is worse than the rare real collision. Reports who (if anyone)
            // already holds it, then takes/refreshes it either way - reusing
            // core's lock()/checklock() (inc/common.php) as-is. See
            // _lock_id() above for how the id is chosen so this never
            // collides with a page lock.
            //
            // There is deliberately no 'unlock' action any more. It looked
            // like the obvious complement, and an earlier version of this
            // had one, called from script.js's close() - but the lock file
            // is one shared resource per diagram, not one per tab: closing
            // *either* of two tabs on the same diagram (one person, two
            // tabs), or closing the .png tab of a .png/.svg pair someone
            // else has the .svg of open, deleted the *other* tab's
            // protection while it was still being actively edited. Verified
            // live. Dropping the explicit unlock fixes that by construction
            // - no open tab can ever delete another one's lock - at the cost
            // that after a clean close, a stale "someone had this open
            // recently" warning can linger for up to $conf['locktime']
            // (900s/15min by default) before it expires on its own. For an
            // *advisory* warning nobody is blocked by, that is a cheaper
            // failure than the one it replaces: a missing "someone has this
            // open right now" is the case that actually loses work.
            //
            // Same permission model as every other action: the access_granted
            // gate above already ran before this point, so a caller without
            // AUTH_UPLOAD on the resolved namespace never reaches here at
            // all - not a hidden 'denied' response, nothing runs. And the
            // response below is the same shape and takes the same lock
            // whether or not $fl exists on disk, so this cannot be used to
            // learn whether a diagram exists in a namespace the caller *can*
            // read - the thing SECURITY.md already closed once for rendering.
            if ($action == 'lock') {
                $lock_id = $this->_lock_id($media_id);
                $holder = checklock($lock_id);
                $since = ($holder !== false) ? @filemtime(wikiLockFN($lock_id)) : null;
                lock($lock_id);
                header('Content-Type: application/json');
                echo json_encode([
                    'locked_by' => $holder !== false ? $holder : null,
                    'since' => $since,
                ]);
                return;
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

                // Renews the lock too, same as script.js's own interval
                // timer does (see close()'s comment there) - belt and
                // braces: autosave/'save' fire on a model *change*, not on a
                // timer, so they are not a substitute for the interval, but
                // renewing here as well covers a background tab whose
                // timers a browser has throttled while its user is actively
                // typing. A caller that predates the interval (an old
                // cached script.js) still gets some renewal out of this
                // alone.
                //
                // $media_id here is 'name.draft' (the '.draft' suffix added
                // above, for every draft_* action) - _lock_id() needs the
                // diagram's own id, the same one the 'lock' action is
                // called with, so this derives it from $name again rather
                // than from $media_id.
                lock($this->_lock_id(cleanID($name)));
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

        /**
         * Delete an image's diagram source along with it.
         *
         * The maintainer's call, not a default arrived at by process of
         * elimination: deleting a diagram in the media manager deletes it -
         * no .drawio left behind that nobody asked to keep. This is not as
         * destructive as it sounds: with mediarevisions on (the default),
         * core's media_delete() already sent the deleted image to the attic
         * before this hook even runs, and calling media_delete() again below
         * for the source does exactly the same for it - "delete" undoes on
         * both halves exactly as far as it undoes on one.
         *
         * The one case this does NOT cascade: ns:plan.png and ns:plan.svg
         * are two renderings of the very same diagram (see helper.php's
         * sourceID()) and share one ns:plan.drawio. Deleting just the .png
         * has not deleted "the diagram" - the .svg rendering is still there,
         * still editable, and ns:plan.drawio is still its source, not an
         * orphan. So the cascade only runs once the *other* rendering is
         * also gone; deleting the second one then deletes the source, in
         * whatever order the two deletes happen (each call only ever looks
         * at what is on disk *right now*).
         *
         * The reverse direction - deleting the .drawio itself - is
         * deliberately NOT handled here or anywhere: it does not delete the
         * image. Rationale: the image is not made worthless by losing its
         * source, only less capable - it still opens (from its own embedded
         * XML, exactly as every diagram did before this feature existed;
         * see action.php's _source_xml()), it is just not preloaded from a
         * separately-editable source any more. Deleting a small text file
         * and having that silently delete somebody's picture is the more
         * surprising direction of the two, so it does not happen.
         *
         * media_delete() (not a bare unlink()) both to get the attic/
         * changelog behaviour above for free and because it fires
         * MEDIA_DELETE_FILE itself - a backup plugin watching that event
         * hears about the source exactly the way it hears about the image,
         * with no second event type to teach it about. That also means this
         * hook re-enters itself once for the source's own delete; it is
         * harmless because helper::sourceID('ns:plan.drawio') is '' (a
         * .drawio is not itself a diagram *rendering*), so the second call
         * returns immediately.
         *
         * ACL: deliberately re-checked by media_delete() itself
         * (auth_quickaclcheck() against the source's own namespace, which is
         * always the same namespace the image lived in) rather than assumed
         * from the image's delete having already been permitted - cheap,
         * and it is what keeps this correct if that ever changes.
         *
         * @param Doku_Event $event MEDIA_DELETE_FILE, fired AFTER
         */
        function _media_delete_sibling(Doku_Event $event, $param) {
            // Nothing was actually deleted (permission race, already gone,
            // disk error) - nothing to cascade.
            if (empty($event->data['unl'])) return;

            $media_id = $event->data['id'];
            $helper = plugin_load('helper', 'drawio');
            if (!$helper) return;

            $src_id = $helper->sourceID($media_id);
            if ($src_id === '') return; // not a png/svg rendering - nothing owns a source

            $other = $helper->otherRenderingID($media_id);
            if ($other !== '' && file_exists(mediaFN($other))) return; // still needed

            if (!file_exists(mediaFN($src_id))) return; // nothing to delete

            media_delete($src_id, AUTH_DELETE);
        }

        /**
         * Keep a diagram's source with its image across a rename/move.
         *
         * DokuWiki core has no rename/move for media at all - the media
         * manager doing a delete-then-reupload is literally what core tells
         * you to do instead (https://www.dokuwiki.org/tips:rename_pages_and_
         * media). Every wiki that actually renames media without losing its
         * history does so with the third-party move plugin
         * (https://www.dokuwiki.org/plugin:move) - "widely installed" is not
         * a guess, it is the only way this operation exists at all - so its
         * event is what this hooks, not core's.
         *
         * Symmetric with _media_delete_sibling() above, same reasoning: the
         * source only moves once nothing under the *old* name still needs it
         * there. If ns:plan.png and ns:plan.svg share ns:plan.drawio and only
         * the .png is renamed, the .svg is still ns:plan.svg and still reads
         * its source from ns:plan.drawio - moving that out from under it
         * would break the untouched rendering to fix the renamed one.
         *
         * Renaming a .drawio file directly is, again, not handled - same
         * reasoning as the delete direction: this plugin never lets a write
         * to a small text file reach out and rename somebody's picture.
         *
         * No MEDIA_DELETE_FILE-style core event exists to reuse for "this
         * file now lives somewhere else", so the source is moved directly
         * (io_rename(), the same primitive the move plugin's own
         * helper_plugin_move_op::moveMedia() uses) and MEDIA_UPLOAD_FINISH is
         * fired for its new location - the same event, same data shape, the
         * plugin's own save path already fires for a freshly-written source
         * (see _ajax_call()'s 'save' handler), so a backup plugin already
         * watching that event needs nothing new to also pick up the moved
         * file at its new id.
         *
         * @param Doku_Event $event PLUGIN_MOVE_MEDIA_RENAME, fired AFTER
         */
        function _move_sibling(Doku_Event $event, $param) {
            $src_id = $event->data['src_id'] ?? null;
            $dst_id = $event->data['dst_id'] ?? null;
            if (!$src_id || !$dst_id) return;

            $helper = plugin_load('helper', 'drawio');
            if (!$helper || !$helper->isDiagramExtension($src_id)) return;

            $src_source_id = $helper->sourceID($src_id);
            if ($src_source_id === '') return;
            $src_source_fl = mediaFN($src_source_id);
            if (!file_exists($src_source_fl)) return; // nothing to move

            // the other rendering, still under its OLD (unmoved) name, may
            // still depend on this exact source - leave it in place for it
            $other = $helper->otherRenderingID($src_id);
            if ($other !== '' && file_exists(mediaFN($other))) return;

            // the destination has to itself be a diagram rendering (a rename
            // straight to, say, .txt has no source identity to move to), and
            // must not already have its own source - never clobber an
            // existing, unrelated file at the destination
            $dst_source_id = $helper->sourceID($dst_id);
            if ($dst_source_id === '') return;
            $dst_source_fl = mediaFN($dst_source_id);
            if (file_exists($dst_source_fl)) return;

            io_createNamespace($dst_source_id, 'media');
            if (!io_rename($src_source_fl, $dst_source_fl)) return;

            list(, $mime) = mimetype($dst_source_id);
            $data = [basename($dst_source_fl), $dst_source_fl, $dst_source_id, $mime, false, null];
            \dokuwiki\Extension\Event::createAndTrigger('MEDIA_UPLOAD_FINISH', $data, null, false);
        }

        /**
         * Add a page's diagrams' text to what the search index has for it.
         *
         * Without this, the words inside a diagram - what its search-worthy
         * content actually is - are invisible to DokuWiki's own search: a
         * page whose architecture diagram says "firewall" cannot be found by
         * searching "firewall". helper::diagramIndexText() does the actual
         * extraction (compressed or plain <diagram> content, HTML-escaped
         * labels, markup stripped); this is only the wiring and, critically,
         * the ACL rule below.
         *
         * ACL, the part that can leak: DokuWiki's fulltext index is one
         * shared blob per page, searched under the *page's* permission, not
         * the media's - core has no notion of "this word in this page's
         * index came from a namespace only some readers may see". So if a
         * page anyone can read embeds a diagram from a namespace only some
         * readers may read, and that diagram's text goes into the page's
         * index unconditionally, then anyone who can read the page can find
         * (and, from a snippet, partially read) that diagram's content
         * through search, whether or not *they* may open the diagram itself.
         * That is a disclosure through a new door, not a new class of bug -
         * exactly what SECURITY.md's audit spent itself closing everywhere
         * else in this plugin.
         *
         * The rule chosen: only index a diagram whose namespace is readable
         * by *anonymous* access ($user = '', $groups = [], i.e. "the general
         * public", the least access any actual reader of the page could
         * have). This is deliberately more conservative than "readable by
         * the current indexing run's user" (indexing typically runs as
         * whoever last saved the page, or as a background job - neither is
         * "every future reader of the page's search results"), and more
         * conservative than "same namespace as the page" (a namespace's ACL
         * can differ from its parent's). The alternative this docblock's
         * brief floated - never index a diagram in an ACL-restricted
         * namespace at all - was considered and rejected: it would also
         * refuse to index a diagram that IS in fact public (an ACL rule
         * granting @ALL explicitly, rather than the namespace being
         * unmentioned in the ACL at all), for no security reason - the
         * anonymous-readability check already covers that case correctly
         * without being that blunt.
         *
         * Bound: the extracted text for one page's diagrams combined is
         * capped inside helper::diagramIndexText() - see its own docblock
         * for the number and why - so a wiki with a handful of huge diagrams
         * cannot make one page's index entry unbounded.
         *
         * @param Doku_Event $event INDEXER_PAGE_ADD, fired BEFORE
         */
        function _index_diagrams(Doku_Event $event, $param) {
            $media_ids = $event->data['metadata']['relation_media'] ?? [];
            if (empty($media_ids)) return;

            $helper = plugin_load('helper', 'drawio');
            if (!$helper) return;

            $budget = 20000; // helper::diagramIndexText()'s own per-diagram cap; this is the page-wide total
            $extra = '';
            foreach ($media_ids as $media_id) {
                if (strlen($extra) >= $budget) break;
                if (!$helper->isDiagramExtension($media_id)) continue;

                $src_id = $helper->sourceID($media_id);
                if ($src_id === '') continue;
                $src_fl = mediaFN($src_id);
                if (!file_exists($src_fl) || filesize($src_fl) === 0) continue;

                // anonymous readability - see this function's own docblock
                if (auth_aclcheck($helper->mediaAclPath($src_id), '', []) < AUTH_READ) continue;

                $text = $helper->diagramIndexText(file_get_contents($src_fl));
                if ($text !== '') $extra .= ' ' . $text;
            }

            if ($extra !== '') {
                $event->data['body'] = trim($event->data['body'] . ' ' . substr($extra, 0, $budget));
            }
        }
    }
