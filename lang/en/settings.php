<?php
/**
 * English language file for config
 *
 */

$lang['zIndex']   = 'Set zIndex for DrawIO iFrame (defaults to 999)';
$lang['url']      = 'Set URL to draw.io instance (defaults to https://embed.diagrams.net/)';
$lang['toolbar_possible_extension'] = "Formats offered by the editor toolbar";
$lang['toolbar_possible_extension_other'] = "leave empty - only png and svg are supported, anything else is rejected";
$lang['ui']       = 'Interface style of the draw.io editor (kennedy is the default, atlas and min are more compact, sketch is hand-drawn, dark/sketch/min/simple can follow the browser\'s dark mode)';
$lang['edit_button'] = 'Show an "Edit with draw.io" button under every diagram, in addition to clicking the image itself (an empty diagram saved by draw.io is an invisible 1x1px image with nothing to click - this button gives it a way back in)';
$lang['top_offset'] = 'Push the draw.io editor down by this many pixels (in a template with a fixed top navbar that would otherwise cover the editor\'s own menu bar); 0 keeps the editor covering the whole browser window';
$lang['interactive'] = 'Render every diagram with draw.io\'s interactive viewer (clickable links, pan/zoom, layers) instead of a static image, unless a diagram overrides this with ?static; a diagram can also opt in on its own with ?interactive while this stays off';
$lang['viewer_url'] = 'URL of draw.io\'s viewer script, loaded only on pages with an interactive diagram (defaults to https://viewer.diagrams.net/js/viewer-static.min.js); a self-hosted draw.io serves the same file at <host>/js/viewer-static.min.js';

