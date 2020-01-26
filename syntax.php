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

        // Validate that the image exists otherwise pring a default image
        global $conf;
        //$image_in_mediadir = join("/", array($conf['mediadir'], trim(str_replace(":","/",$data), "/") ));
		$media_id = $data . '.png';

		$image_in_mediadir = mediaFN($media_id);
		$id = cleanID($data);

        if(!file_exists($image_in_mediadir)){
            $renderer->doc .= "<img class='mediacenter' id='".trim($data)."' 
                        style='max-width:100%;cursor:pointer;' onclick='edit(this);'
                        src='".DOKU_BASE."lib/plugins/drawio/blank-image.png' 
                        alt='".$media_id."' />";
            // $renderer->doc = $image_in_mediadir;
            return true;
        }
        $renderer->doc .= "<img class='mediacenter' id='".trim($data)."' 
                        style='max-width:100%;cursor:pointer;' onclick='edit(this);'
						src='".DOKU_BASE."lib/exe/fetch.php?media=".$data.".png' 
                        alt='".$media_id."' />";
        return true;
    }
}

