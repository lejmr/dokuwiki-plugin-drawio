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

        // If ACLs plugin activated, image can be edited only if page can be 
        $edit_img = true;
        global $INFO;
        if (isset($INFO)) {
            $edit_img = false;
            if ($INFO['writable'] && !$INFO['rev']) {
                $edit_img = true;
            }
        }

        // Validate that the image exists otherwise print a default image
        global $conf;
        $media_id = $data . '.png';
		
        $current_id = getID();
        $current_ns = getNS($current_id);
		
        resolve_mediaid($current_ns, $media_id, $exists);
        $diagram_id = substr($media_id, 0, -4);
		
        if(!$exists){
            if ($edit_img) {
                $renderer->doc .= "<img class='mediacenter' id='".$diagram_id."' 
                style='max-width:100%;cursor:pointer;' onclick='edit(this);'
                src='".DOKU_BASE."lib/plugins/drawio/blank-image.png' 
                alt='".$media_id."' />";
            }
            return true;
        }
	
        $renderer->doc .= "<img class='mediacenter' id='".$diagram_id."'"; 
        $renderer->doc .= "src='".DOKU_BASE."lib/exe/fetch.php?media=".$media_id."'"; 
        if ($edit_img) {
            $renderer->doc .= "style='max-width:100%;cursor:pointer'; onclick='edit(this);'";
        }
        else {
            $renderer->doc .= "style='max-width:100%';";
        }
        $renderer->doc .= "alt='".$media_id."' />";
        return true;
    }
}
