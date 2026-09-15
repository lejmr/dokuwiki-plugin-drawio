<?php
/**
 * English language file
 *
 */

$lang['editbutton'] = 'Edit with draw.io';
$lang['lockwarning'] = 'This diagram was opened by %USER% %MINUTES% minute(s) ago and may still be open there. Continue anyway?';

// admin.php: bulk-convert old diagrams to a stored .drawio source
$lang['menu'] = 'Draw.io: convert old diagrams';
$lang['intro'] = 'A diagram saved before this plugin started storing its XML source separately only carries that XML embedded inside its image, where any tool that rewrites the image (an optimiser, a format conversion, a re-encoding backup) destroys it for good. Saving a diagram again gives it a source automatically - this page does the same thing for every diagram at once, without waiting for someone to open each one. It only ever adds a source that is missing; it never overwrites one that already exists, and it never touches the image.';
$lang['summary'] = '%d diagram(s) found. %d already have a source. %d do not yet.';
$lang['none_found'] = 'No diagrams found in the media tree.';
$lang['done'] = 'Every diagram has been scanned.';
$lang['batch_heading'] = 'Diagrams %d-%d of %d';
$lang['col_diagram'] = 'Diagram';
$lang['col_status'] = 'Status';
$lang['status_has_source'] = 'already has a source - left untouched';
$lang['status_recoverable'] = 'source can be recovered from the image';
$lang['status_no_xml'] = 'no draw.io XML found in the image - cannot be recovered';
$lang['status_unreadable'] = 'the image file could not be read';
$lang['status_converted'] = 'source written';
$lang['status_write_failed'] = 'writing the source failed';
$lang['btn_convert'] = 'Convert this batch';
$lang['btn_continue'] = 'Scan next batch »';
$lang['btn_restart'] = '« Back to the start';
