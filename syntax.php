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
     * @return string Paragraph type
     */
    // public function getPType()
    // {
    //     return 'normal';
    // }

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
        // $this->Lexer->addSpecialPattern('<FIXME>', $mode, 'plugin_drawio');
        // $this->Lexer->addSpecialPattern("<drawio>", $mode, 'plugin_drawio');
        $this->Lexer->addSpecialPattern("\{\{drawio>.+?\}\}",$mode,'plugin_drawio'); 
        // 
//        $this->Lexer->addEntryPattern('<FIXME>', $mode, 'plugin_drawio');
    }

//    public function postConnect()
//    {
//        $this->Lexer->addExitPattern('</FIXME>', 'plugin_drawio');
//    }

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

        //check name 
        $name = $data;
        global $conf;

        $name = strtolower(trim($name));
        if (strlen($name)==0) {
          $renderer->doc.="---INVALID_DIAGRAM---";
          return true;
        }

        // Test if file exists
        $namespace="";
        $lastColonPos = strripos($name,":");
        if ($lastColonPos>0) {
        	$namespace=substr($name, 0, $lastColonPos);
        	$name = substr($name, $lastColonPos+1);
        }
                
        $namespace.=':';
        $media_dir = join("/", array($conf['mediadir'], trim(str_replace(":","/",$namespace), "/") ));
        if (! file_exists($media_dir)) {
        	mkdir ($media_dir, 0755, true);
        }
        
        $image_file = $name.'.png';
        $file_path = "/".join("/", array(trim($media_dir, "/"), $image_file));
        $load_file_path = $file_path;

        // Override file path if file does not exist (yet)
        if(!file_exists($file_path)){
            $load_file_path = join("/", array($conf["mediadir"], "wiki", "dokuwiki-128.png"));
        }
        $fc = file_get_contents($load_file_path);

        // Render image
        $renderer->doc .= $name." ".$file_path." <br /> <span class='drawio'><img class='mediacenter' id='".trim($data)."' style='max-width:100%;cursor:pointer;' onclick='edit(this);' src='data:image/png;base64,".base64_encode($fc)."' alt='".$file_name."' /></span>";
        return true;
    }
}

