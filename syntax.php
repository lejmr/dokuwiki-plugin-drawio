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
     * Render xhtml output or metadata
     *
     * @param string        $mode     Renderer mode (supported modes: xhtml)
     * @param Doku_Renderer $renderer The renderer
     * @param array         $data     The data from the handler() function
     *
     * @return bool If rendering was successful.
     */
    public function render($mode, Doku_Renderer $renderer, $data)
    {
        if ($mode !== 'xhtml') {
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
            $renderer->doc .= "<span class='drawio-error'>drawio: no diagram name given in '".hsc($data)."'</span>";
            return true;
        }

        // if no extention specified, use png
        if(!in_array(pathinfo($media_id, PATHINFO_EXTENSION),array_map('trim',explode(",",$this->getConf('toolbar_possible_extension'))) )){
            $media_id .= ".png";
        }

        // resolve_mediaid() cleans/sanitizes $media_id (cleanID) - this MUST run
        // before the id is ever put into an HTML attribute, on every path,
        // otherwise a crafted name (e.g. containing a quote) reaches the
        // markup unsanitized. hsc()/mediaUrl() below are defense in depth on
        // top of that, not a substitute for it.
		resolve_mediaid($current_ns, $media_id, $exists);

        if ($linkonly) {
            $text = $title !== null ? $title : $media_id;
            $renderer->doc .= "<a href='".DOKU_BASE."lib/exe/fetch.php?media=".$this->mediaUrl($media_id)."' id='".hsc($media_id)."'
                        class='drawio-linkonly' onclick='edit(this);return false;'>".hsc($text)."</a>";
            return true;
        }

        // issue #66: saving an empty diagram leaves a zero-byte media file behind,
        // which is neither viewable nor (without this) clickable to fix again - treat
        // it as missing so the placeholder (and its edit link) show up instead.
        if ($exists && @filesize(mediaFN($media_id)) === 0) {
            $exists = false;
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
