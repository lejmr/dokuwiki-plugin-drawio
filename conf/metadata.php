<?php
/*
 * configuration metadata
 *
 */

$meta['zIndex']    = array('string');
$meta['url']       = array('string');
// script.js only ever exports xmlpng/xmlsvg (see edit_cb()'s 'save' handler),
// and action.php's 'save' action hardcodes the same png/svg whitelist - so a
// third value here could never actually work.
//
// This used to say '_other' => 'never', so the config UI's "other" text box
// never existed and an admin could not even try to type a third value. That
// reads as the cleaner fix, but core's SettingMulticheckbox::array2str()
// reads $input['other'] unconditionally on every save, whether or not the
// 'other' field was ever rendered - with 'never' (or 'exists' while both
// choices are already picked, which is the normal state here) the field is
// missing from the submit and every save of this settings page throws
// "Undefined array key "other"". That is a core bug we cannot patch from a
// plugin. '_other' => 'always' keeps the field in the form (and so in the
// submit) so core has something to read; '_pattern' is what actually keeps
// the restriction to png/svg - typing anything else in that field, or
// nothing at all outside a subset of {png, svg}, fails validation instead of
// being silently accepted.
$meta['toolbar_possible_extension'] = array(
    'multicheckbox',
    '_choices' => array('png', 'svg'),
    '_other' => 'always',
    '_pattern' => '/^(png(,svg)?|svg)?$/',
);
// script.js sends this straight to embed.diagrams.net as its ui= parameter,
// which only understands these six values (draw.io's own "Supported URL
// parameters" docs) - a plain string setting would let an admin type
// anything and get a silently broken editor instead of an error.
$meta['ui'] = array('multichoice', '_choices' => array('kennedy', 'min', 'atlas', 'dark', 'sketch', 'simple'));
// issue #107: script.js maps these to draw.io's dark=0 / dark=1 / dark=auto
$meta['theme'] = array('multichoice', '_choices' => array('light', 'dark', 'auto'));
// issue #62: a plain checkbox - onoff already stores/reads the 0/1 this
// plugin's own getConf('edit_button') check treats as a bool.
$meta['edit_button'] = array('onoff');
// issue #50: pixel offset, so it must be a non-negative integer - anything
// else (a unit suffix, a negative number) would silently break the iframe's
// CSS in script.js rather than fail loudly here.
$meta['top_offset'] = array('numeric', '_pattern' => '/^\d+$/');
// issue #30: a plain checkbox - onoff already stores/reads the 0/1
// getConf('interactive') is checked against.
$meta['interactive'] = array('onoff');
// issue #30: the URL of draw.io's viewer-static.min.js - a plain string,
// same as 'url' above, since it points at anything from the public
// diagrams.net host to a self-hosted draw.io.
$meta['viewer_url'] = array('string');
