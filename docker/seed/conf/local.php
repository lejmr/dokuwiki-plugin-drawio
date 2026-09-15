<?php
/**
 * Development wiki configuration (seeded into /storage/conf on first start).
 * Not for production: anonymous users may upload so diagrams are editable
 * without logging in.
 */
$conf['title']     = 'drawio plugin dev wiki';
$conf['useacl']    = 1;
$conf['superuser'] = 'admin';
$conf['lang']      = 'en';

// drawio plugin: allow both output formats in dev
$conf['plugin']['drawio']['toolbar_possible_extension'] = 'png,svg';

// The walkthrough deletes diagrams that whatsnew itself embeds. With refcheck
// on (the production default) core refuses that with "still in use", which is
// correct behaviour but would make sections 6 and 9 undoable here.
$conf['refcheck'] = 0;
