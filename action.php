<?php
    /*
     * plugin should use this method to register its handlers 
     * with the dokuwiki's event controller
     * 
     * 2025-06-05  axel  replace deprecated JSON object; short array syntax
     */

    if(!defined('DOKU_INC')) die();
 
 
    class action_plugin_drawio extends DokuWiki_Action_Plugin {

        // How many pages one diagram save reindexes synchronously - see
        // _reindex_diagram_pages()'s own docblock for the cost reasoning.
        // A property (not a constant) so a test can shrink it without
        // needing dozens of real pages to prove the cap works.
        protected $reindexPageCap = 50;

        // The mtime a diagram image had the instant before core deleted it -
        // captured by _media_delete_capture_mtime() (BEFORE) so
        // _media_delete_sibling() (AFTER) can find the exact attic copy core
        // just archived it to (mediaFN($id, $mtime) - see that function's own
        // docblock for why). By the time the AFTER hook runs the live file is
        // already gone, so its mtime has to be captured before that happens;
        // keyed by id rather than a single scalar only for the (never
        // observed, cheap-to-guard-anyway) case of one request deleting more
        // than one diagram rendering.
        protected $_deletedMtime = [];

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
            $controller->register_hook('MEDIA_DELETE_FILE', 'BEFORE', $this, '_media_delete_capture_mtime');
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
                // which draw.io interface the editor opens with (conf/default.php)
                'ui' => $this->getConf('ui'),
                // issue #50: pushes the editor iframe down in script.js, so a
                // template's own fixed top navbar doesn't cover the editor's
                // menu bar. 0 (the default) leaves the iframe covering the
                // full viewport, exactly as before this setting existed.
                'topOffset' => (int) $this->getConf('top_offset'),
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
					// ponytail: 'javascript:' is a literal scan, so a diagram
					// whose label happens to contain the text "javascript:" is
					// refused. Narrow the scan to attribute values if anyone
					// ever hits that.
					//
					// <use> is not on the blanket tag blocklist below because a
					// real math export (Extras > Mathematical Typesetting) is
					// one: MathJax's default SVG output renders every formula
					// as a local <defs>/<use> pair (fontCache: 'local', see
					// MathJax's SVG output docs) purely to reuse glyph paths
					// within the *same* document - rejecting it outright
					// rejected the plugin's own legitimate output (issue #49).
					// What is still not allowed is a <use> that reaches
					// outside the document it lives in: only a same-document
					// fragment reference (href="#...") is permitted, so a
					// <use> pointing at an external SVG, a data: URI, or a
					// javascript: URL is rejected exactly as before.
					// Every href-like attribute of every <use> must be a
					// fragment: SVG2 prefers href over xlink:href, so checking
					// only the first one found would let a second, external
					// one through. Quoted values may contain '>', hence the
					// quote-aware tag match.
					$badUse = false;
					if (preg_match_all('/<use\b((?:[^>"\x27]|"[^"]*"|\x27[^\x27]*\x27)*)>/i', $decoded, $useTags)) {
						foreach ($useTags[1] as $attrs) {
							if (preg_match_all('/(?:xlink:)?href\s*=\s*(?:"([^"]*)"|\x27([^\x27]*)\x27|([^\s>]+))/i', $attrs, $hrefs, PREG_SET_ORDER)) {
								foreach ($hrefs as $h) {
									$val = ltrim($h[1] . $h[2] . (isset($h[3]) ? $h[3] : ''));
									if ($val === '' || $val[0] !== '#') { $badUse = true; break 2; }
								}
							}
						}
					}
					if ($badUse
						|| preg_match('/<(script|iframe|html|body)[\s>]/i', $decoded)
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

						// The whole point of _index_diagrams() (below) is
						// undone if nothing ever reindexes the page after
						// this point - see _reindex_diagram_pages()'s own
						// docblock. Never lets a reindexing problem turn a
						// successful diagram save into a failed request: the
						// diagram is the user's work and it is already
						// safely on disk by this line; the index is a
						// convenience on top of it, not the other way round.
						try {
							$this->_reindex_diagram_pages($media_id);
						} catch (\Throwable $e) {
							// best effort - see comment above
						}
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

            /**
             * Which rendering a click on the *source* (ns:plan.drawio)
             * itself should open - see script.js's edit(), which calls this
             * instead of 'get_auth' when the media manager selection is a
             * .drawio and then re-enters the ordinary png/svg open flow with
             * whatever id this returns. Every other action here only ever
             * receives a png/svg id (script.js derives it once, here, and
             * reuses it); this is the one place a .drawio id is accepted at
             * all.
             *
             * Existing rendering wins over the fixed default, and png wins
             * over svg when *both* renderings exist: helper::
             * renderingCandidates() already puts png first for exactly that
             * default (see its own docblock), so "prefer whichever exists,
             * falling back to that order" is one loop, not a second rule.
             * Neither existing is the ordinary case for a diagram created by
             * the bulk-conversion admin task straight from an old xmlpng-
             * only image with no separate .drawio before it, or for a pair
             * broken by deleting one rendering by hand - the fallback default
             * (png) opens the editor on the source XML with no image loaded,
             * and the *next* save is what actually creates that rendering,
             * exactly as it already does for a brand new diagram.
             *
             * Security: this asks nothing that 'get_auth' does not already
             * ask, on the same acl_path (mediaAclPath() is namespace-only -
             * it does not care whether $media_id ends in .drawio, .png or
             * .svg, so the ACL check above already answered this exact
             * question). $access_granted already gated everything above this
             * point, so a caller without it never reaches here - same 403,
             * same lack of a body, whether test:secret.drawio does not exist
             * or the caller simply may not see test:secret:* at all. And
             * telling an *allowed* caller which of their own two renderings
             * exists reveals nothing they could not already see by listing
             * the same namespace in the media manager themselves.
             */
            if ($action == 'resolve_source') {
                $candidates = $helper ? $helper->renderingCandidates($media_id) : [];
                if (empty($candidates)) {
                    // not a .drawio id at all - script.js never sends one,
                    // same class of malformed request as everywhere else here
                    http_status(400);
                    return;
                }
                $resolved = $candidates[0];
                foreach ($candidates as $candidate) {
                    if (file_exists(mediaFN($candidate))) {
                        $resolved = $candidate;
                        break;
                    }
                }
                // Same formula $overwrite_granted used above, recomputed
                // against the *resolved* rendering rather than the .drawio
                // id itself - $auth/$auth_ow are unaffected by that (the ACL
                // path is namespace-only, see this action's own comment),
                // only which file the overwrite bar applies to changes: a
                // diagram whose rendering does not exist yet is a *create*,
                // needing only AUTH_UPLOAD, not the higher overwrite bar a
                // pre-existing .drawio id would otherwise have implied.
                $resolved_overwrite_granted = $access_granted
                    && (!file_exists(mediaFN($resolved)) || $auth >= $auth_ow);
                header('Content-Type: application/json');
                echo json_encode(['granted' => $resolved_overwrite_granted, 'id' => $resolved]);
                return;
            }

            if($action == 'get_png' || $action == 'get_svg'){
				// The XML source, when there is one. script.js loads the
				// editor straight from this and never touches the image;
				// without it, it falls back to xmlpng/xmlsvg exactly as
				// before, which is what keeps every existing diagram working.
				// Same file, same namespace, so the ACL checked above is the
				// one that governs it - nothing extra to check here.
				//
				// Fetched before the file_exists($fl) bailout below: a source
				// clicked through the media manager (see 'resolve_source')
				// can resolve to a rendering id that has never been saved at
				// all - neither png nor svg exists yet, only the .drawio -
				// and that diagram must still open, from its source, exactly
				// as the save path already creates the rendering the first
				// time it is saved. Bailing out here before checking for a
				// source would instead hand script.js an empty body and open
				// a blank diagram, discarding a source that is sitting right
				// there.
				$xml = $this->_source_xml($media_id, $fl);
				if (!file_exists($fl) && $xml === '') return;
                // Return image in the base64 for draw.io
                header('Content-Type: application/json');
				$out = [];
				if (file_exists($fl)) {
					$fc = file_get_contents($fl);
					$mime = $action == 'get_png' ? 'image/png' : 'image/svg+xml';
					$out['content'] = "data:$mime;base64,".base64_encode($fc);
				}
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
         * Remember a diagram image's mtime the instant before core deletes
         * it, so the AFTER hook below can find the exact attic copy core is
         * about to archive it to - see that function's own docblock for why.
         *
         * BEFORE, not AFTER: by the time MEDIA_DELETE_FILE's AFTER phase
         * runs the file is already unlinked, so its mtime has to be read
         * while it still exists. Core computes its own archive timestamp
         * (media.php's media_delete(), `$old = @filemtime($file)`) at this
         * same point in the same request, right after the BEFORE phase
         * completes - so reading it here, with nothing of ours writing to
         * the file in between, reads the identical value.
         *
         * @param Doku_Event $event MEDIA_DELETE_FILE, fired BEFORE
         */
        function _media_delete_capture_mtime(Doku_Event $event, $param) {
            $media_id = $event->data['id'];
            $helper = plugin_load('helper', 'drawio');
            if (!$helper) return;
            // a rendering (png/svg) - or the source itself, deleted directly
            if ($helper->sourceID($media_id) === '' && substr($media_id, -7) !== '.drawio') return;

            $this->_deletedMtime[$media_id] = @filemtime(mediaFN($media_id));
        }

        /**
         * Delete an image's diagram source along with it - archiving the
         * source's current XML into the image's own attic copy on the way
         * out, instead of leaving the .drawio behind as a file of its own.
         *
         * The maintainer's call, not a default arrived at by process of
         * elimination: deleting a diagram in the media manager deletes it -
         * no .drawio left behind that nobody asked to keep.
         *
         * Why embed rather than archive the .drawio separately (the
         * original design here, before this defect was found): core builds
         * an attic filename from mimetype($id) (inc/pageutils.php's
         * mediaFN()), which only knows extensions listed in mime.conf.
         * '.drawio' never is one, so mimetype() returns false for it and
         * mediaFN()'s arithmetic silently corrupts the name - see
         * _test/data-delta.test.php's
         * testDeletingTheLastRenderingArchivesTheSourceWithACorruptedAtticFilename()
         * for the exact mechanism and a live repro. Registering '.drawio' in
         * mime.conf would dodge that specific bug, but a plain-text .drawio
         * attic entry is still a second, easy-to-miss archive per diagram,
         * with its own changelog and its own (now correctly-named, but
         * still separate) revision history to keep in sync with the
         * image's - exactly the split this plugin exists to heal, not
         * reintroduce on the way out. Embedding sidesteps the naming bug
         * entirely (a .png/.svg attic name was never wrong) and gives a
         * restored revision something this plugin can still open on its
         * own, the same way every diagram could before it had a separate
         * source at all - see helper.php's own docblock.
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
         * No source exists (an old diagram never re-saved through this
         * plugin): nothing to do here at all - its image already carries
         * whatever XML it was exported with, and this function returns
         * before ever looking at the attic.
         *
         * Image missing from the attic (mediarevisions off, so core's own
         * media_saveOldRevision() never archived it in the first place - or
         * this plugin's own captured mtime is unavailable for any reason):
         * there is nothing to embed the source's XML into, so nothing is
         * archived for the source either - it is simply deleted, unarchived,
         * exactly as its image was. Manufacturing a fresh attic entry
         * outside core's own revisions-on/off rule would archive the source
         * more thoroughly than the image it belongs to, which is a
         * inconsistency of its own.
         *
         * Size: _embed_source_into_attic() below bounds the XML it will
         * embed (matching the 2 MiB cap the 'save' handler already enforces
         * on the way in - see its own comment). A diagram whose source
         * somehow exceeds that (never possible through this plugin's own
         * save path) is still archived - just as the plain image core
         * already put in the attic, with nothing embedded - rather than
         * growing the attic file without bound or failing the delete.
         *
         * unlink(), not media_delete(), for the source itself - see the
         * inline comment right above that call for why, and for how a
         * backup plugin still hears about the deletion despite that.
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

            // The source itself deleted straight from the media manager:
            // core archived it first, but .drawio has no mimetype, so
            // mediaFN($id, $rev) came out as "name.drawi.<rev>." - a file
            // nothing can list or restore. Remove it; the image this source
            // belonged to is still there (its own attic keeps the XML).
            if (substr($media_id, -7) === '.drawio') {
                $rev = isset($this->_deletedMtime[$media_id]) ? $this->_deletedMtime[$media_id] : 0;
                unset($this->_deletedMtime[$media_id]);
                if ($rev && substr(mediaFN($media_id, $rev), -1) === '.') @unlink(mediaFN($media_id, $rev));
                return;
            }

            $src_id = $helper->sourceID($media_id);
            if ($src_id === '') return; // not a png/svg rendering - nothing owns a source

            $other = $helper->otherRenderingID($media_id);
            if ($other !== '' && file_exists(mediaFN($other))) return; // still needed

            $src_fl = mediaFN($src_id);
            if (!file_exists($src_fl)) return; // nothing to delete

            $this->_embed_source_into_attic($media_id, $src_fl, $helper);
            unset($this->_deletedMtime[$media_id]);

            // unlink(), not media_delete(): media_delete() is what produces
            // the corrupted attic entry this whole function exists to avoid
            // (see its own docblock), and re-running its ACL check would be
            // redundant - already run, against this exact namespace, for
            // the image this source belongs to (mediaAclPath() is
            // namespace-wide, not per-file). The event is still fired by
            // hand though, in the same shape media_delete() builds it, so a
            // backup plugin watching MEDIA_DELETE_FILE still hears about the
            // source's removal exactly as it always has - only the
            // attic/changelog side effects that event usually carries are
            // skipped, deliberately, by not routing through media_delete().
            $size = filesize($src_fl);
            $unl = @unlink($src_fl);
            if ($unl) io_sweepNS($src_id, 'mediadir');

            $data = ['id' => $src_id, 'name' => basename($src_fl), 'path' => $src_fl, 'size' => $size, 'unl' => $unl, 'del' => false];
            \dokuwiki\Extension\Event::createAndTrigger('MEDIA_DELETE_FILE', $data, null, false);
        }

        /**
         * Fold a diagram source's current XML into the attic copy of the
         * image core just archived, so the revision left behind is
         * self-contained instead of split across two files - see
         * _media_delete_sibling()'s own docblock for why.
         *
         * The source, not whatever the image's own bytes already carry: the
         * two can disagree. Saving one rendering (png/svg) never regenerates
         * the other (helper.php's sourceID() docblock), so an image's own
         * embedded XML can be older than the shared .drawio if the *other*
         * rendering was saved more recently. Reading the .drawio directly is
         * what this diagram's edit history actually says was current.
         *
         * @param string               $media_id the deleted image's id
         * @param string               $src_fl   path to its .drawio source
         * @param helper_plugin_drawio $helper
         */
        private function _embed_source_into_attic($media_id, $src_fl, $helper) {
            global $conf;
            if (empty($conf['mediarevisions'])) return; // core never archives without it

            if (!isset($this->_deletedMtime[$media_id])) return;
            $atticFl = mediaFN($media_id, $this->_deletedMtime[$media_id]);
            if (!file_exists($atticFl)) return; // core didn't archive it - nothing to embed into

            $xml = file_get_contents($src_fl);
            if ($xml === false || $xml === '') return;

            $bytes = file_get_contents($atticFl);
            if ($bytes === false) return;

            $ext = strtolower(pathinfo($media_id, PATHINFO_EXTENSION));
            $embedded = ($ext === 'png') ? $helper->embedPngXml($bytes, $xml) : $helper->embedSvgXml($bytes, $xml);
            if ($embedded === '') return; // malformed image - leave the plain archived copy alone

            $this->_write_file($atticFl, $embedded);
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
         * Reindex every page that embeds a diagram whose source was just
         * (over)written, so a word that exists only inside the diagram
         * becomes searchable without anyone running a manual reindex.
         *
         * The defect this exists to fix: _index_diagrams() below only ever
         * *supplies* text to whatever indexing pass core happens to run for
         * a page - it never triggers one. Saving a diagram writes media
         * files only, never touches a page, so nothing ever reindexed the
         * page embedding it. Verified live before this fix existed: a page
         * whose diagram had a readable, anonymously-accessible source with
         * extractable text still did not turn up in search until the page
         * was reindexed by hand.
         *
         * Which pages: helper::pagesUsing() answers from the metadata
         * index (relation_media), not a live parse of every page's wiki
         * text - see its own docblock for what that means for a page that
         * was never indexed at all. Checked against $media_id (the
         * rendering just saved) and its sibling rendering (see
         * helper::otherRenderingID()) together, because a page can embed
         * either the .png or the .svg of the very same diagram (see
         * helper.php's sourceID()) and both read the source that was just
         * written.
         *
         * Cost: bounded to $reindexPageCap pages, one reindexPage() call
         * each, run synchronously inside this ajax request - the same
         * request a user is waiting on to know their diagram saved. A
         * diagram embedded on a handful of pages (the overwhelmingly common
         * case for a plugin whose own README describes single-diagram-per-
         * page usage) costs a handful of reindexes, comparable to what an
         * ordinary page edit already pays every time core reindexes that
         * page's own content. A diagram embedded on hundreds of pages is
         * not the common case this plugin was built around, and reindexing
         * hundreds of pages synchronously inside one save request would
         * turn an ordinary diagram edit into a multi-second (or, on a large
         * wiki, timed-out) request - worse than a slightly stale index. The
         * cap chooses "stay stale a little longer" over "the save itself
         * gets slow or fails": pages beyond it simply keep whatever the
         * index already had until the next natural trigger (any edit to
         * the page itself, or a maintainer running the bulk-conversion
         * admin task, which reindexes with no such cap - see admin.php's
         * _reindexConverted()) catches them up. This never worked at all
         * before this fix, so "eventually, for most wikis" is strictly
         * better than the status quo, not a regression against one.
         *
         * ponytail: a fixed cap and no queue, the same shape admin.php's
         * $batchSize already uses for the same reason - this plugin has no
         * background job runner anywhere else, and building one for this
         * alone is more machinery than a save-time convenience is worth.
         * Revisit (a real ceiling, not a guess) if a wiki with a diagram
         * shared across hundreds of pages actually shows up.
         *
         * Failure: every reindexPage() call is individually wrapped so one
         * broken page's reindex cannot stop the rest, and the caller wraps
         * this whole method in its own try/catch so nothing in here can
         * turn a successful diagram save into a failed request - the
         * diagram is already safely written to disk by the time this runs.
         *
         * @param string $media_id the rendering id that was just saved (e.g. 'ns:plan.png')
         */
        private function _reindex_diagram_pages($media_id) {
            $helper = plugin_load('helper', 'drawio');
            if (!$helper) return;

            $ids = [$media_id];
            $other = $helper->otherRenderingID($media_id);
            if ($other !== '') $ids[] = $other;

            $pages = [];
            foreach ($ids as $id) {
                foreach ($helper->pagesUsing($id) as $page) {
                    $pages[$page] = true;
                }
            }
            if (!$pages) return;

            // Same pages also need their non-xhtml cached renders discarded -
            // see helper::purgeDiagramPageCache()'s own docblock for why.
            // Same loop, same cap, same per-page isolation as the reindex
            // this was bolted onto: a diagram embedded on a handful of pages
            // is the common case this plugin was built around either way.
            $n = 0;
            foreach (array_keys($pages) as $page) {
                if (++$n > $this->reindexPageCap) break;
                try {
                    $helper->reindexPage($page);
                } catch (\Throwable $e) {
                    // one page's reindex failing must not stop the rest
                }
                try {
                    $helper->purgeDiagramPageCache($page);
                } catch (\Throwable $e) {
                    // best effort - see purgeDiagramPageCache()'s docblock
                }
            }
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
