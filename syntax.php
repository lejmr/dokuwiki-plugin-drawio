<?php
/**
 * DokuWiki Plugin drawio (Syntax Component)
 *
 * @license GPL 2 http://www.gnu.org/licenses/gpl-2.0.html
 * @author  Milos Kozak <milos.kozak@lejmr.com>
 */

// must be run within Dokuwiki
if (!defined('DOKU_INC')) {
    die();
}

class syntax_plugin_drawio extends DokuWiki_Syntax_Plugin
{
    /**
     * @return string Syntax mode type
     */
    public function getType()
    {
        return 'substition';
    }

    /**
     * @return int Sort order - Low numbers go before high numbers
     */
    public function getSort()
    {
        return 303;
    }

    /**
     * Connect lookup pattern to lexer.
     *
     * @param string $mode Parser mode
     */
    public function connectTo($mode)
    {
        $this->Lexer->addSpecialPattern("\{\{drawio>.+?\}\}",$mode,'plugin_drawio'); 
    }

    /**
     * Handle matches of the drawio syntax
     *
     * @param string       $match   The match of the syntax
     * @param int          $state   The state of the handler
     * @param int          $pos     The position in the document
     * @param Doku_Handler $handler The handler
     *
     * @return array Data for the renderer
     */
    public function handle($match, $state, $pos, Doku_Handler $handler)
    {
        return substr($match,9,-2);
    }

    /**
     * URL-encode a media id for a fetch.php query string, keeping the
     * namespace separator readable (this mirrors what DokuWiki's own
     * ml()/idfilter() do for internal media links).
     *
     * @param string $media_id
     * @return string
     */
    private function mediaUrl($media_id)
    {
        return str_replace('%3A', ':', rawurlencode($media_id));
    }

    /**
     * Whether the current user may actually read this media file.
     *
     * media_exists() is a pure filesystem check - it says nothing about
     * permissions. Used to gate the ODT export below: that export reads the
     * media's bytes off disk itself, server-side, so it has to ask this
     * explicitly - unlike xhtml, which never makes this check at all (see
     * the comment on the xhtml <img> below for why).
     *
     * The ACL path itself lives in helper::mediaAclPath() - see its docblock
     * for why this and action.php ask different questions with it.
     *
     * @param string $media_id
     * @return bool
     */
    private function mayReadMedia($media_id)
    {
        $helper = plugin_load('helper', 'drawio');
        return $helper && auth_quickaclcheck($helper->mediaAclPath($media_id)) >= AUTH_READ;
    }

    /**
     * Render xhtml, metadata or odt (issue #7) output
     *
     * dw2pdf (https://www.dokuwiki.org/plugin:dw2pdf), the other big export
     * plugin besides odt, needs no entry here even though it renders under
     * the mode name 'dw2pdf' (p_render('dw2pdf', ...), see its
     * src/Writer.php): its renderer_plugin_dw2pdf is a Doku_Renderer_xhtml
     * subclass that does not override getFormat(), and DokuWiki dispatches a
     * syntax plugin through Doku_Renderer::plugin(), which calls
     * render($this->getFormat(), ...) - the *renderer's* format, not the
     * mode name p_render() was called with. getFormat() on an unoverridden
     * Doku_Renderer_xhtml always returns 'xhtml', so this method already
     * receives $mode === 'xhtml' for a dw2pdf export, reuses the exact same
     * $renderer->doc-building code below, and a real PDF export already
     * embeds the diagram - confirmed against dw2pdf's real renderer in a
     * docker DokuWiki instance, debug HTML and rendered PDF both showing the
     * <img>, ACL-denied diagrams still correctly falling back to the
     * placeholder. There is nothing dw2pdf-specific to add.
     *
     * @param string        $mode     Renderer mode (supported modes: xhtml, metadata, odt)
     * @param Doku_Renderer $renderer The renderer
     * @param array         $data     The data from the handler() function
     *
     * @return bool If rendering was successful.
     */
    public function render($mode, Doku_Renderer $renderer, $data)
    {
        if ($mode !== 'xhtml' && $mode !== 'metadata' && $mode !== 'odt') {
            return false;
        }

        global $conf;

        $data = trim($data);

        // name?params|title - same split as DokuWiki's own {{media}} syntax
        $title = null;
        if (($pipe = strpos($data, '|')) !== false) {
            $title = trim(substr($data, $pipe + 1));
            $data = substr($data, 0, $pipe);
        }
        if ($title === '') {
            // {{drawio>x|}} - an empty title is "no title", not "no alt text"
            $title = null;
        }

        $params = array();
        if (($qm = strpos($data, '?')) !== false) {
            $params = array_filter(array_map('trim', explode('&', substr($data, $qm + 1))));
            $data = substr($data, 0, $qm);
        }

        $linkonly = in_array('linkonly', $params);

        // issue #30: ?static/?interactive always win over the config
        // default (conf/default.php's 'interactive') - two params, not one,
        // because the default itself can be either value, so "neither param
        // given" has to stay distinguishable from "asked for whatever the
        // default currently is".
        if (in_array('static', $params)) {
            $interactive = false;
        } elseif (in_array('interactive', $params)) {
            $interactive = true;
        } else {
            $interactive = (bool) $this->getConf('interactive');
        }

        $width = $height = null;
        foreach ($params as $param) {
            if (preg_match('/^(\d+)(?:x(\d+))?$/', $param, $m)) {
                $width = $m[1];
                $height = isset($m[2]) ? $m[2] : null;
            }
        }

		$current_id = getID();
		$current_ns = getNS($current_id);
		if ($current_ns === false) {
		    $current_ns = '';
		}

        // dokuwiki namespace-template placeholders, see https://www.dokuwiki.org/namespace_templates
        $media_id = str_replace(
            array('@NS@', '@PAGE@', '@FILE@'),
            array($current_ns, noNS($current_id), noNS($current_id)),
            $data
        );

        // e.g. {{drawio>namespace:}} - no diagram name was actually given
        $colon = strrpos($media_id, ':');
        $leaf = substr($media_id, $colon === false ? 0 : $colon + 1);
        if ($leaf === '') {
            if ($mode === 'xhtml') {
                $renderer->doc .= "<span class='drawio-error'>drawio: no diagram name given in '".hsc($data)."'</span>";
            }
            return true;
        }

        // if no extension specified, use png. This asks the same fixed
        // png/svg pair action.php's save gate and helper.php's sourceID()
        // do - not the admin's toolbar_possible_extension, which only
        // decides which extensions the *toolbar* offers a new diagram with
        // and, at its shipped default ('png'), would otherwise make
        // {{drawio>plan.svg}} become 'plan.svg.png'.
        if (!in_array(strtolower(pathinfo($media_id, PATHINFO_EXTENSION)), ['png', 'svg'], true)) {
            $media_id .= ".png";
        }

        // resolve_mediaid() was deprecated 2020-09-30 in favour of
        // dokuwiki\File\MediaResolver (core prints a notice on every render
        // otherwise - the same "long-deprecated symbol with no shim" shape as
        // issue #64). Its own implementation (inc/pageutils.php) is:
        //
        //   $resolver = new MediaResolver("$ns:deprecated");
        //   $media = $resolver->resolveId($media, $rev, $date_at);
        //   $exists = media_exists($media, $rev, false, $date_at);
        //
        // reproduced here directly. The "$ns:deprecated" context id only matters
        // for a leading "~" in $media_id (relative-to-current-page), which
        // drawio's own @NS@/@PAGE@ placeholders already replace above; its
        // namespace half (used for relative ids and "." prefixes) is $ns itself,
        // exactly as passed in. $rev/$date_at are always defaults here, so they
        // are dropped rather than threaded through for two arguments nothing
        // ever sets.
        //
        // This MUST run before $media_id is ever put into an HTML attribute, on
        // every path, otherwise a crafted name (e.g. containing a quote) reaches
        // the markup unsanitized. hsc()/mediaUrl() below are defense in depth on
        // top of that, not a substitute for it.
        $media_id = (new \dokuwiki\File\MediaResolver("$current_ns:deprecated"))->resolveId($media_id);

        // issue #10: the media manager reads media usage from page metadata, so
        // without this a diagram looks unused and is easy to delete by accident.
        if ($mode === 'metadata') {
            $renderer->internalmedia($media_id, $title);
            return true;
        }

        // issue #7: ODT export is provided by the third-party "odt" plugin
        // (https://www.dokuwiki.org/plugin:odt). It is never loaded unless a user
        // installed it, so this code only runs for people who actually have it -
        // everyone else keeps getting `return false` from the mode check above,
        // exactly like today.
        //
        // Its renderer (renderer_plugin_odt_page, see ODT/ODTImage.php upstream)
        // exposes _odtAddImage($path, $width, $height, $align, $title) - $path is
        // a real filesystem path (mediaFN(), not a media id or URL), width/height
        // are plain pixel numbers or null for "use the image's own size". This is
        // the same call graphviz and ditaa (two other diagram plugins) use for
        // their own odt support, so it's copying an established pattern rather
        // than guessing at one.
        //
        // linkonly exists so a click opens the drawio editor instead of showing
        // the picture inline - meaningless in a document with no editor to open,
        // so a linkonly diagram is embedded here same as a normal one; a printed
        // page with a dead link instead of the diagram would be strictly worse.
        //
        // A missing/empty diagram has nothing sensible to embed - skip it rather
        // than exporting the on-wiki placeholder image into a document. Unlike
        // xhtml below, this reads the media's bytes off disk itself, server-side,
        // so - unlike xhtml - it has no choice but to work out existence and
        // permission right here.
        if ($mode === 'odt') {
            // What gets embedded depends on *who* exports (mayReadMedia()
            // below), but the odt plugin caches its render per page, not per
            // viewer - so the first exporter's result would be served to
            // everyone after. Opt this page out of that cache.
            $renderer->nocache();
            $exists = media_exists($media_id, '', false);

            // Security: an export must not embed a diagram the exporting user
            // may not read - see mayReadMedia() above.
            if ($exists && !$this->mayReadMedia($media_id)) {
                $exists = false;
            }

            // issue #66: saving an empty diagram leaves a zero-byte media file
            // behind - nothing sensible to embed either.
            if ($exists && @filesize(mediaFN($media_id)) === 0) {
                $exists = false;
            }

            if ($exists) {
                $renderer->_odtAddImage(mediaFN($media_id), $width, $height, null, $title);
            }
            return true;
        }

        // xhtml from here on. Unlike odt above, this never asks whether the
        // media exists or whether the current viewer may read it - on purpose.
        //
        // The <img>/<a> below is always the same lib/exe/fetch.php?media=...
        // URL, for every diagram and every visitor, exactly like core's own
        // {{image.png}} already works: fetch.php itself is what decides, at
        // request time, whether to serve the bytes (checking the read ACL
        // itself, see inc/fetch.functions.php's checkFileStatus()) or answer
        // 404/403 - this plugin does not need to, and must not, duplicate
        // that decision into the page's own HTML.
        //
        // Two things made this plugin do so anyway, once: showing the new
        // diagram right after it's saved, and not leaking whether a diagram
        // exists in a namespace the viewer can't read (SECURITY.md). Both
        // are handled below, in the browser, instead of at render time:
        //   - onerror swaps a failed image load to the on-wiki placeholder,
        //     so a save is reflected on the very next view - there is
        //     nothing server-side left to go stale, so nocache() is gone.
        //   - the placeholder is reached identically whether fetch.php
        //     answered 404 (missing) or 403 (exists but denied - namespace
        //     ACL is checked *before* file existence in checkFileStatus(),
        //     so within a namespace the viewer can't read, every media id
        //     answers 403 whether or not it actually exists). The HTML is
        //     byte-identical for every viewer regardless of who they are or
        //     whether the diagram exists, which is what actually closes the
        //     existence oracle - not a check this plugin performs and could
        //     get wrong, and not something a shared page cache can leak
        //     between visitors, because there is no per-visitor difference
        //     left to leak.
        //
        // The one thing this loses: a *server-side* consumer of this same
        // xhtml output that cannot run the onerror handler - dw2pdf, see the
        // render() docblock above - shows nothing rather than the on-wiki
        // placeholder for a missing diagram, same as core's own {{image.png}}
        // already does for any missing image in a PDF export. ODT above is
        // unaffected: it embeds bytes directly and always has its own gate.
        // $DATE_AT ("view page at date", inc/actions.php's ACTION_SHOW): core's
        // own {{image.png}} points at the media revision that was current at
        // that date, not today's (Doku_Renderer_xhtml::_media(), via its
        // _getLastMediaRevisionAt() helper and media_exists()'s own $rev/
        // $date_at pair) - do the same here, the same way. $renderer->date_at
        // is a public property on every Doku_Renderer, set by p_render()
        // only when a date_at was actually requested, so it is '' for an
        // ordinary view - $rev then stays '' and every URL below is exactly
        // what it was before this existed. Only the URL changes; no
        // server-side existence/ACL check is added, keeping the rule in the
        // long comment above intact.
        $rev = '';
        if ($renderer->date_at) {
            try {
                $changelogRev = (new \dokuwiki\ChangeLog\MediaChangeLog($media_id))->getLastRevisionAt($renderer->date_at);
                if ($changelogRev !== false) {
                    $rev = $changelogRev;
                }
            } catch (\Throwable $e) {
                // DokuWiki oldstable's ChangeLog::getRelativeRevision() throws
                // "Cannot use bool as array" for a diagram with no changelog
                // file at all (readloglines() returns false there, and it
                // destructures that unconditionally) - a core bug, verified
                // against oldstable's real inc/ChangeLog/ChangeLog.php.
                // Falling back to "no matching revision" (leaving $rev '')
                // is exactly the right outcome anyway for a diagram that has
                // never been revised.
            }
        }
        $revParam = $rev !== '' ? "&amp;rev=".$rev : '';

        if ($linkonly) {
            $text = $title !== null ? $title : $media_id;
            $renderer->doc .= "<a href='".DOKU_BASE."lib/exe/fetch.php?media=".$this->mediaUrl($media_id).$revParam."' id='".hsc($media_id)."'
                        class='drawio-linkonly' onclick='edit(this);return false;'>".hsc($text)."</a>";
            return true;
        }

        // issue #62: an empty diagram saved by draw.io is a valid, invisible
        // 1x1px PNG - the browser loads it (no onerror), so there is nothing
        // to click and the diagram can never be reopened. min-width/
        // min-height give even a 1x1 image a clickable area, regardless of
        // the edit_button setting below.
        $style = "max-width:100%;min-width:24px;min-height:24px;cursor:pointer;";
        if ($width !== null) {
            $style .= "width:".$width."px;".($height !== null ? "height:".$height."px;" : "");
        }
        $alt = $title !== null ? $title : $media_id;
        $src = DOKU_BASE."lib/exe/fetch.php?media=".$this->mediaUrl($media_id).$revParam;
        if ($width !== null) {
            // Ask fetch.php for a resized, server-cached copy instead of
            // downloading the full-size file and shrinking it with CSS -
            // the same mechanism core's own {{image.png?200}} uses via
            // ml() (inc/common.php) and inc/fetch.functions.php's
            // MEDIA_RESIZE handling. A page with ten diagrams sized to
            // thumbnails then downloads ten thumbnails, not ten full-size
            // images.
            //
            // SVG is not special-cased here, on purpose: core isn't
            // either (Doku_Renderer_xhtml::_media() sends w/h to ml() for
            // every image mime type). fetch.php's own MEDIA_RESIZE handler
            // already skips resizing for image/svg+xml, so a sized SVG
            // still gets the size params but is served unmodified -
            // harmless (resizing a vector server-side is meaningless, not
            // wrong), and identical to how core already treats a sized
            // SVG everywhere else in a wiki.
            //
            // media_get_token() (inc/media.php) is an HMAC over the media
            // id and size, keyed by a server secret (auth_cookiesalt()) -
            // not anything about the viewer - so this URL is still the
            // exact same string for every visitor. fetch.php's
            // checkFileStatus() checks this token, then the read ACL, then
            // file existence, in that order, for every request - it still
            // enforces access at request time, same as the unsized case
            // above.
            $h = $height !== null ? (int) $height : 0;
            $src .= "&amp;w=".$width;
            if ($h) {
                $src .= "&amp;h=".$h;
            }
            $src .= "&amp;tok=".media_get_token($media_id, (int) $width, $h);
        }
        $placeholder = DOKU_BASE."lib/plugins/drawio/blank-image.png";

        $img = "<img class='mediacenter' id='".hsc($media_id)."'
                        style='".$style."' onclick='edit(this);'
                        src='".$src."'
                        onerror=\"this.onerror=null;this.src='".$placeholder."';\"
                        alt='".hsc($alt)."'".($title !== null ? " title='".hsc($title)."'" : "")." />";

        $editButton = "<button type='button' class='drawio-editbutton'
                        style='display:block;margin:0.25em auto 0;font-size:85%;'
                        data-image-id='".hsc($media_id)."' onclick='drawioEditButtonClick(this);'
                        >".hsc($this->getLang('editbutton'))."</button>";

        // issue #30: draw.io's own viewer instead of a static image - links
        // inside the diagram stay clickable, plus zoom/pan/layers/lightbox.
        //
        // Deliberately NOT the diagram's XML inlined into the page: that
        // would make the xhtml differ by ACL/existence, which is exactly
        // what the long comment above this method exists to prevent. Instead
        // this emits the viewer's own documented markup (GraphViewer.
        // createViewerForElement(), jgraph/drawio's src/main/webapp/js/
        // diagramly/GraphViewer.js) - a 'data-mxgraph' JSON attribute whose
        // 'url' the viewer fetches itself, client-side, with a plain XHR GET
        // (GraphViewer.getUrl()) once its script has loaded - so the
        // existence/ACL decision stays exactly where it already lives for
        // the image case: fetch.php, at request time, per visitor. json_
        // encode() then hsc() (never the other order - the attribute must
        // end up with '&' as '&amp;', not double-escaped) is what actually
        // gets a JSON string safely into a single-quoted HTML attribute.
        //
        // The url points at the diagram's *source* (ns:plan.drawio, see
        // helper::sourceID()), not its rendering - the viewer needs XML, not
        // a picture. fetch.php serves it fine with no changes: '.drawio' has
        // no registered mimetype (conf/mime.conf), so mimetype($media,
        // false) falls back to [$ext, 'application/octet-stream', true] -
        // "download" (Content-Disposition: attachment) rather than inline,
        // but that only affects a *navigation*, never an XHR's ability to
        // read the response body, and checkFileStatus() enforces the same
        // namespace read ACL either way (inc/fetch.functions.php - it keys
        // purely on mediaAclPath($media), never on the extension). Verified
        // against a real DokuWiki checkout's fetch.functions.php/mime.conf;
        // no mime.conf entry needed, and none is added, since editing
        // mime.local.conf is not something this plugin can do for a user
        // anyway.
        //
        // 'highlight'/'nav'/'resize'/'toolbar'/'lightbox' are the same
        // documented GraphViewer config keys draw.io's own embed docs use;
        // 'toolbar' lists zoom/layers/lightbox because those are the only
        // toolbar buttons that mean anything without draw.io's separate
        // comments backend, which this plugin does not integrate with.
        if ($interactive) {
            $helper = plugin_load('helper', 'drawio');
            $src_id = $helper ? $helper->sourceID($media_id) : '';
            // Not html-escaped like $revParam above: this becomes part of a
            // JSON string, and the whole attribute is hsc()'d as one string
            // right below - escaping '&' here first would leave '&amp;amp;'
            // in the page.
            $revParamRaw = $rev !== '' ? "&rev=".$rev : '';
            $sourceUrl = DOKU_BASE."lib/exe/fetch.php?media=".$this->mediaUrl($src_id).$revParamRaw;

            $divStyle = "max-width:100%;";
            if ($width !== null) {
                $divStyle .= "width:".$width."px;".($height !== null ? "height:".$height."px;" : "");
            }
            $mxConfig = array(
                'url' => $sourceUrl,
                'highlight' => '#0000ff',
                'nav' => true,
                'resize' => true,
                'toolbar' => 'zoom layers lightbox',
                'lightbox' => true,
            );
            $renderer->doc .= "<div class='mxgraph drawio-interactive' id='".hsc($media_id)."'"
                            .($title !== null ? " title='".hsc($title)."'" : "")
                            ." style='".$divStyle."'"
                            ." data-mxgraph='".hsc(json_encode($mxConfig))."'></div>";

            // issue #30: a diagram with no .drawio source yet (never opened/
            // saved since this feature shipped) makes the viewer's XHR 404 -
            // this fallback <img>, a sibling of the div above (never a
            // child: the viewer only ever appends into its container, it
            // never needs to clear it first, but a sibling is correct either
            // way and costs nothing), is what a visitor sees until, and
            // unless, the viewer script actually renders something into it -
            // see script.js's drawioInitInteractive() for the client side of
            // this. Same fetch.php image URL and onerror placeholder as the
            // plain <img> path above, wrapped so script.js can find and hide
            // it by class alone.
            $renderer->doc .= "<div class='drawio-interactive-fallback'>".$img."</div>";

            // Always rendered here (unlike the plain-image path below, where
            // it is opt-in via edit_button) - there is no image to click at
            // all when the viewer script hasn't loaded (or has nothing to
            // show), so this is the only click target a diagram-less
            // interactive placeholder has.
            $renderer->doc .= $editButton;
            return true;
        }

        $renderer->doc .= $img;

        // issue #62: an explicit "Edit with draw.io" button under the image,
        // for wikis that want a click target that stays clickable even for a
        // diagram whose image happens to render as nothing at all (not just
        // the 1x1px case above - a broken/oversized/off-canvas render too).
        // Off by default (conf/default.php) to keep current behaviour
        // unchanged; server-rendered like the image itself, so no ACL/
        // existence check is added and the xhtml stays the same for every
        // viewer, exactly like the image above.
        if ($this->getConf('edit_button')) {
            $renderer->doc .= $editButton;
        }
        return true;
    }
}
