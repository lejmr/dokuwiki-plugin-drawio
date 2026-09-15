<?php
/*
 * configuration metadata
 *
 */

$meta['zIndex']    = array('string');
$meta['url']       = array('string');
// script.js only ever exports xmlpng/xmlsvg (see edit_cb()'s 'save' handler),
// and action.php's 'save' action hardcodes the same png/svg whitelist - so a
// third value here could never actually work. multicheckbox with '_other' =>
// 'never' makes the config UI itself unable to set one, instead of leaving
// admin and code free to disagree about what's supported.
$meta['toolbar_possible_extension'] = array('multicheckbox', '_choices' => array('png', 'svg'), '_other' => 'never');
