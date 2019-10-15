<?php
    /*
     * plugin should use this method to register its handlers 
     * with the dokuwiki's event controller
     */

    if(!defined('DOKU_INC')) die();
 
 
    class action_plugin_drawio extends DokuWiki_Action_Plugin {

        public function register(Doku_Event_Handler $controller) {
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
         * handle ajax requests
         */
        function _ajax_call(Doku_Event $event, $param) {
            if ($event->data !== 'plugin_drawio') {
                return;
            }
            //no other ajax call handlers needed
            $event->stopPropagation();
            $event->preventDefault();
        
            //e.g. access additional request variables
            global $conf;
            global $INPUT; //available since release 2012-10-13 "Adora Belle"
            $name = $INPUT->str('imageName');
            $action = $INPUT->str('action');

            // Convert image name to absolute path         
            $name = strtolower(trim($name));
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

            
            
            if($action == 'save'){
                // Write content to file
                $content = $INPUT->str('content');
                $base64data = explode(",", $content)[1];
                $whandle = fopen($file_path,'w');
                fwrite($whandle,base64_decode($base64data));
                fclose($whandle);
            }

            if($action == 'get'){
                // Return image in the base64 for draw.io
                $json = new JSON();
                header('Content-Type: application/json');
                $fc = file_get_contents($file_path);
                echo $json->encode(array("content" => "data:image/png;base64,".base64_encode($fc)));
            }
        }

    }
?>