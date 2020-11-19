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

        // Validate that the image exists otherwise pring a default image
        global $conf;
        $media_id = $data;
        // if no extention specified, use png
        if(!in_array(pathinfo($media_id, PATHINFO_EXTENSION),array_map('trim',explode(",",$this->getConf('toolbar_possible_extension'))) )){
            $media_id .= ".png";
        }
		
		$current_id = getID();
		$current_ns = getNS($current_id);
		
		resolve_mediaid($current_ns, $media_id, $exists);
				
        if(!$exists){
            $renderer->doc .= "<img class='mediacenter' id='".$media_id."' 
                        style='max-width:100%;cursor:pointer;' onclick='edit(this);'
                        src='".DOKU_BASE."lib/plugins/drawio/blank-image.png' 
                        alt='".$media_id."' />";
            return true;
        }
        $renderer->doc .= "<img class='mediacenter' id='".$media_id."' 
                        style='max-width:100%;cursor:pointer;' onclick='edit(this);'
						src='".DOKU_BASE."lib/exe/fetch.php?media=".$media_id."' 
                        alt='".$media_id."' />";
        return true;
    }
}
