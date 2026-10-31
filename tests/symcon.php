<?php
declare(strict_types=1);
// Diese Tests verwenden unveränderte offizielle SymconStubs. Kein Ersatz für den Symcon-Dienst.
require getenv('SYMCON_STUBS') . '/autoload.php';
require __DIR__ . '/fixtures.php';
$checks = 0;
function verify(bool $ok, string $name): void
{
    global $checks;
    if (!$ok) {
        throw new RuntimeException('FEHLER: ' . $name);
    }
    $checks++;
    echo 'OK ' . $name . "\n";
}
IPS\Kernel::reset();
IPS\ModuleLoader::loadLibrary(__DIR__ . '/../library.json');
$id = IPS_CreateInstance('{E95793D0-6288-4955-B12E-1E0C8D2AEB71}');
$module = IPS\InstanceManager::getInstanceInterface($id);
IPS_ApplyChanges($id);
verify(IPS_GetChildrenIDs($id) === [], 'Neuinstallation legt null Variablen an');
$form = json_decode($module->GetConfigurationForm(), true, 64, JSON_THROW_ON_ERROR);
verify(count($form['elements'][1]['items'][1]['values']) === 5, 'Grundkatalog ohne Gerätezugriff verfügbar');
verify(str_contains($module->GetVisualizationTile(), 'handleMessage('), 'Initiale HTML-SDK-Daten vorhanden');
verify(!$module->Update(), 'Deaktiviert kein Netzwerkzugriff');
try {
    $module->RequestAction('Shutdown', true);
    throw new RuntimeException('Aktion unerwartet erlaubt');
} catch (InvalidArgumentException $e) {
    verify(true, 'Unbekannte Aktion serverseitig abgelehnt');
}
$rows = [['Ident' => 'CPU', 'Enabled' => true, 'Name' => 'Prozessor', 'Target' => 0, 'Position' => 30]];
IPS_SetProperty($id, 'Variables', json_encode($rows));
IPS_ApplyChanges($id);
verify(count(IPS_GetChildrenIDs($id)) === 1, 'Nur einzeln ausgewählte Variable wird angelegt');
$variable = IPS_GetChildrenIDs($id)[0];
verify(IPS_GetVariable($variable)['VariableType'] === 2, 'CPU hat Float-Datentyp');
IPS_SetName($variable, 'Eigener Name');
IPS_ApplyChanges($id);
verify(IPS_GetName($variable) === 'Eigener Name', 'Benutzername bleibt bei ApplyChanges erhalten');
$rows[0]['Enabled'] = false;
IPS_SetProperty($id, 'Variables', json_encode($rows));
IPS_ApplyChanges($id);
verify(IPS_VariableExists($variable), 'Abwahl löscht bestehende Variable nicht');
IPS_SetProperty($id, 'Enabled', true);
IPS_ApplyChanges($id);
verify(IPS_GetInstance($id)['InstanceStatus'] === 201, 'Fehlende Zugangsdaten bleiben stabil mit Konfigurationsfehler');
IPS_SetProperty($id, 'BaseURL', getenv('UGNAS_TEST_URL'));
IPS_SetProperty($id, 'Username', 'monitor');
IPS_SetProperty($id, 'Password', 'test-secret');
IPS_ApplyChanges($id);
verify((new ReflectionMethod($module, 'GetTimerInterval'))->invoke($module, 'Poll') === 1000, 'Erste Erkennung startet nach Übernehmen ohne volles Pollingintervall');
$initialRows = json_decode($module->GetConfigurationForm(), true)['elements'][1]['items'][1]['values'];
$initialRows[0]['Name'] = 'Noch nicht gespeichert';
$initialRows[0]['Enabled'] = true;
$module->RememberVariableSelection($initialRows);
verify($module->Update(), 'Vollständiger Poll gegen lokalen HTTP-Testserver');
$liveRows = json_decode((new ReflectionMethod($module, 'GetBuffer'))->invoke($module, 'FormRows'), true);
verify(count($liveRows) === 13, 'Erste Erkennung ergänzt offene Variablenliste ohne erneutes Öffnen');
verify($liveRows[0]['Name'] === 'Noch nicht gespeichert' && $liveRows[0]['Enabled'], 'Erkennung erhält noch nicht übernommene Auswahl und Namen');
verify((new ReflectionMethod($module, 'GetTimerInterval'))->invoke($module, 'Poll') === 60000, 'Nach Erkennung gilt wieder das normale Pollingintervall');
verify(count(IPS_GetChildrenIDs($id)) === 1, 'Erkennung erzeugt keine zusätzlichen Variablen');
$form = json_decode($module->GetConfigurationForm(), true);
verify(count($form['elements'][1]['items'][1]['values']) === 13, 'Dynamischer Katalog enthält Pools, Disks und Lüfter');
verify(!$module->Update(), 'Doppelklick wird begrenzt');
$snapshot = json_decode((new ReflectionMethod($module, 'ReadAttributeString'))->invoke($module, 'Snapshot'), true);
unset($snapshot['metrics'][array_key_last($snapshot['metrics'])]);
(new ReflectionMethod($module, 'WriteAttributeString'))->invoke($module, 'Snapshot', json_encode($snapshot));
verify(count(json_decode($module->GetConfigurationForm(), true)['elements'][1]['items'][1]['values']) === 13, 'Bereits erkannte Messwerte bleiben bei vorübergehenden Datenlücken auswählbar');
IPS_SetProperty($id, 'Title', '</script><script>alert(1)</script>');
IPS_ApplyChanges($id);
$html = $module->GetVisualizationTile();
verify(!str_contains($html, '</script><script>alert(1)'), 'Initialdaten sicher gegen Script-Einschleusung');
IPS_SetProperty($id, 'Enabled', false);
IPS_ApplyChanges($id);
verify(json_decode((new ReflectionMethod($module, 'payload'))->invoke($module), true)['fresh'] === false, 'Deaktivierung macht alte Daten ungültig');
IPS_SetProperty($id, 'Enabled', true);
IPS_ApplyChanges($id);
verify(json_decode((new ReflectionMethod($module, 'payload'))->invoke($module), true)['fresh'] === false, 'Reaktivierung benötigt neue Messung');
// Gespeicherte Formulardaten enthalten nur editierbare bzw. save-Spalten.
$table = json_decode($module->GetConfigurationForm(), true)['elements'][1]['items'][1];
verify($table['loadValuesFromConfiguration'] === false && $table['columns'][1]['save'] === true, 'Katalog wird anhand gespeicherter stabiler Idents zusammengeführt');
$saved = [];
foreach ($table['values'] as $row) {
    $item = [];
    foreach ($table['columns'] as $column) {
        if (!empty($column['save']) || isset($column['edit'])) {
            $item[$column['name']] = $row[$column['name']] ?? null;
        }
    }
    $saved[] = $item;
}
IPS_SetProperty($id, 'Variables', json_encode($saved));
IPS_ApplyChanges($id);
verify(isset(json_decode($module->GetConfigurationForm(), true)['elements'][1]['items'][1]['values'][0]['Ident']), 'Ident bleibt nach formularähnlichem Speichern erhalten');
$target = IPS_CreateVariable(2);
$rows = [['Ident' => 'CPU', 'Enabled' => true, 'Name' => 'CPU', 'Target' => $target, 'Position' => 0]];
IPS_SetProperty($id, 'Variables', json_encode($rows));
IPS_ApplyChanges($id);
verify($module->Update() && GetValue($target) === 17.4, 'Vorhandene passende Variable erhält Messwert');
verify(count(IPS_GetChildrenIDs($id)) === 1, 'Externes Ziel erzeugt keine doppelte Variable');
$wrong = IPS_CreateVariable(1);
$rows[0]['Target'] = $wrong;
IPS_SetProperty($id, 'Variables', json_encode($rows));
IPS_ApplyChanges($id);
verify(IPS_GetInstance($id)['InstanceStatus'] === 201, 'Falscher externer Datentyp wird abgelehnt');
IPS_SetProperty($id, 'Variables', '[]');
IPS_SetProperty($id, 'Password', 'wrong');
IPS_ApplyChanges($id);
verify(!$module->Update(), 'Abgelehnte Anmeldung führt zum Fehlerzustand');
$p = json_decode((new ReflectionMethod($module, 'payload'))->invoke($module), true);
verify(!$p['fresh'] && $p['nextAllowed'] >= time() + 899, 'Anmeldefehler macht Daten alt und setzt 15-Minuten-Sperre');
verify(!$module->Update(), 'Wiederholsperre verhindert unmittelbaren neuen Login');
IPS_SetProperty($id, 'Username', 'anderes-geraet');
IPS_ApplyChanges($id);
$p = json_decode((new ReflectionMethod($module, 'payload'))->invoke($module), true);
verify($p['data'] === [], 'Datenquellenwechsel entfernt alten Snapshot ohne Variablenlöschung');
verify(IPS_VariableExists($variable), 'Datenquellenwechsel bewahrt vorhandene Variable');
echo "Bestanden: $checks SymconStub-Prüfungen\n";
