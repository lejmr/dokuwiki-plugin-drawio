<?php
/*
 * default configuration settings
 *
 */

$conf['zIndex']    = 999;
$conf['url']       = 'https://embed.diagrams.net/';
$conf['toolbar_possible_extension'] ='png';
$conf['ui']        = 'atlas';
// issue #107: light by default; auto follows the visitor's OS setting
$conf['theme']     = 'light';
// issue #62: off by default to keep current behaviour unchanged
$conf['edit_button'] = 0;
// issue #50: 0 keeps the iframe covering the whole viewport, as before
$conf['top_offset'] = 0;
// issue #30: off by default - {{drawio>...}} keeps rendering a plain <img>
// unless a diagram asks for ?interactive, or this is switched on.
$conf['interactive'] = 0;
// issue #30: draw.io's own hosted viewer script. A self-hosted draw.io
// serves the identical file at <host>/js/viewer-static.min.js - point this
// there instead to avoid the third-party request entirely (see also #42).
$conf['viewer_url'] = 'https://viewer.diagrams.net/js/viewer-static.min.js';

