<?php
/**
 * German language file
 *
 */

$lang['editbutton'] = 'Mit draw.io bearbeiten';
$lang['lockwarning'] = 'Dieses Diagramm wurde vor %MINUTES% Minute(n) von %USER% geöffnet und ist dort möglicherweise noch offen. Trotzdem fortfahren?';

// admin.php: alte Diagramme in gespeicherte .drawio-Quellen umwandeln
$lang['menu'] = 'Draw.io: alte Diagramme umwandeln';
$lang['intro'] = 'Ein Diagramm, das gespeichert wurde bevor dieses Plugin die XML-Quelle separat abgelegt hat, trägt diese XML nur eingebettet in seinem Bild - und jedes Werkzeug, das das Bild neu schreibt (ein Optimierer, eine Formatkonvertierung, ein neu kodierendes Backup), zerstört sie dabei endgültig. Ein erneutes Speichern gibt dem Diagramm automatisch eine Quelle - diese Seite macht dasselbe für alle Diagramme auf einmal, ohne dass jedes einzeln geöffnet werden muss. Es wird nur eine fehlende Quelle ergänzt; eine bestehende wird nie überschrieben, und das Bild wird nie angefasst.';
$lang['summary'] = '%d Diagramm(e) gefunden. %d haben bereits eine Quelle. %d noch nicht.';
$lang['none_found'] = 'Keine Diagramme im Medienbaum gefunden.';
$lang['done'] = 'Alle Diagramme wurden geprüft.';
$lang['batch_heading'] = 'Diagramme %d-%d von %d';
$lang['col_diagram'] = 'Diagramm';
$lang['col_status'] = 'Status';
$lang['status_has_source'] = 'hat bereits eine Quelle - unverändert gelassen';
$lang['status_recoverable'] = 'Quelle kann aus dem Bild wiederhergestellt werden';
$lang['status_no_xml'] = 'keine draw.io-XML im Bild gefunden - nicht wiederherstellbar';
$lang['status_unreadable'] = 'die Bilddatei konnte nicht gelesen werden';
$lang['status_converted'] = 'Quelle geschrieben';
$lang['status_write_failed'] = 'Schreiben der Quelle fehlgeschlagen';
$lang['btn_convert'] = 'Diesen Stapel umwandeln';
$lang['btn_continue'] = 'Nächsten Stapel prüfen »';
$lang['btn_restart'] = '« Zurück zum Anfang';
