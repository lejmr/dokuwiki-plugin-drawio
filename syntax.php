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
     * permissions. Using it alone to choose between the real fetch.php URL
     * and the placeholder turns this plugin into an existence oracle across
     * ACL boundaries: fetch.php itself returns an identical 403 whether a
     * file is denied or simply absent, so any difference in *our* output is
     * a disclosure this plugin invents, not one core makes.
     *
     * The permission is decided on the namespace-wildcard ACL path the media
     * id is actually governed by - media has no per-file ACLs. That is the
     * whole body of core's mediaAclPath() (inc/auth.php), inlined because
     * that helper does not exist on oldstable, whose own inc/media.php
     * spells the identical expression out at the call sites that need it.
     * action.php inlines it the same way, so both files ask the question
     * exactly once and identically.
     *
     * @param string $media_id
     * @return bool
     */
    private function mayReadMedia($media_id)
    {
        return auth_quickaclcheck(ltrim(getNS($media_id) . ':*', ':')) >= AUTH_READ;
    }

    /**
     * Render xhtml, metadata or odt (issue #7) output
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
		$renderer->nocache();

        // Validate that the image exists otherwise pring a default image
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

        // if no extention specified, use png
        if(!in_array(pathinfo($media_id, PATHINFO_EXTENSION),array_map('trim',explode(",",$this->getConf('toolbar_possible_extension'))) )){
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
        $exists = media_exists($media_id, '', false);

        // Security: a viewer who may not read this media must see the exact
        // same thing as one where the file plain doesn't exist - anything
        // else lets a page editor probe ACL-restricted namespaces file by
        // file (see mayReadMedia() above). This intentionally does not touch
        // metadata mode below: media-usage recording must happen regardless
        // of who triggers the render (a save, the indexer, ...), exactly as
        // core's own internalmedia()/_recordMediaUsage() do.
        if ($exists && !$this->mayReadMedia($media_id)) {
            $exists = false;
        }

        // issue #66: saving an empty diagram leaves a zero-byte media file behind,
        // which is neither viewable nor (without this) clickable to fix again - treat
        // it as missing everywhere (placeholder, odt export, ...) rather than just
        // in the xhtml path below. This does NOT change metadata mode (issue #10):
        // internalmedia() there goes through _recordMediaUsage(), which ignores the
        // $exists we compute and re-derives existence itself via file_exists() - a
        // zero-byte diagram is still correctly recorded as "used" either way.
        if ($exists && @filesize(mediaFN($media_id)) === 0) {
            $exists = false;
        }

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
        // than exporting the on-wiki placeholder image into a document.
        if ($mode === 'odt') {
            if ($exists) {
                $renderer->_odtAddImage(mediaFN($media_id), $width, $height, null, $title);
            }
            return true;
        }

        if ($linkonly) {
            $text = $title !== null ? $title : $media_id;
            $renderer->doc .= "<a href='".DOKU_BASE."lib/exe/fetch.php?media=".$this->mediaUrl($media_id)."' id='".hsc($media_id)."'
                        class='drawio-linkonly' onclick='edit(this);return false;'>".hsc($text)."</a>";
            return true;
        }

        $style = "max-width:100%;cursor:pointer;";
        if ($width !== null) {
            $style .= "width:".$width."px;".($height !== null ? "height:".$height."px;" : "");
        }
        $alt = $title !== null ? $title : $media_id;
        $src = $exists
            ? DOKU_BASE."lib/exe/fetch.php?media=".$this->mediaUrl($media_id)
            : DOKU_BASE."lib/plugins/drawio/blank-image.png";

        $renderer->doc .= "<img class='mediacenter' id='".hsc($media_id)."'
                        style='".$style."' onclick='edit(this);'
                        src='".$src."'
                        alt='".hsc($alt)."'".($title !== null ? " title='".hsc($title)."'" : "")." />";
        return true;
    }
}
