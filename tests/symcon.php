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
verify(count($form['elements'][1]['items'][1]['values']) === 9, 'Grundkatalog ohne Gerätezugriff verfügbar');
verify(!str_contains(json_encode($form), 'VerifyTLS') && !str_contains(json_encode($form), 'CAFile'), 'Zertifikats- und CA-Einstellungen sind vollständig aus dem Formular entfernt');
verify(IPS_GetProperty($id, 'Host') === '' && IPS_GetProperty($id, 'Port') === 9999, 'Neue Instanzen haben getrennte Felder und Port 9999');
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
IPS_SetVariableCustomProfile($variable, '~Temperature');
IPS_ApplyChanges($id);
verify(IPS_GetObjectIDByIdent('CPU', $id) === $variable && IPS_GetName($variable) === 'Eigener Name'
    && IPS_GetObject($variable)['ObjectPosition'] === 30, 'ID, Name und Position bleiben bei der Umstellung erhalten');
$cpuPresentation = IPS_GetVariablePresentation($variable);
verify($cpuPresentation['PRESENTATION'] === VARIABLE_PRESENTATION_VALUE_PRESENTATION && $cpuPresentation['SUFFIX'] === ' %',
    'Vorhandenes Legacy-Profil wird durch native CPU-Darstellung ersetzt');
$rows[0]['Enabled'] = false;
IPS_SetProperty($id, 'Variables', json_encode($rows));
IPS_ApplyChanges($id);
verify(IPS_VariableExists($variable), 'Abwahl löscht bestehende Variable nicht');
IPS_SetProperty($id, 'Enabled', true);
IPS_ApplyChanges($id);
verify(IPS_GetInstance($id)['InstanceStatus'] === 201, 'Fehlende Zugangsdaten bleiben stabil mit Konfigurationsfehler');
$persistence = $module->GetPersistence();
unset($persistence['configuration']['Host'], $persistence['configuration']['Port']);
$persistence['configuration']['BaseURL'] = getenv('UGNAS_TEST_URL');
$persistence['configuration']['Username'] = 'monitor';
$persistence['configuration']['Password'] = 'test-secret';
$persistence['configuration']['CAFile'] = '/nicht-vorhanden/alte-einstellung.pem';
$migrated = json_decode($module->Migrate(json_encode($persistence)), true, 64, JSON_THROW_ON_ERROR);
$module->ApplyPersistence($migrated);
IPS_ApplyChanges($id);
verify((new ReflectionMethod($module, 'baseUrl'))->invoke($module) === getenv('UGNAS_TEST_URL'), 'Migration übernimmt Adresse und vorhandenen eigenen Port');
verify(!isset($migrated['configuration']['BaseURL']) && !isset($migrated['configuration']['CAFile']), 'Veraltete Felder werden bei der Migration entfernt');
verify($module->Migrate(json_encode($migrated)) === '', 'Wiederholter Start migriert bereits getrennte Felder nicht erneut');
$legacy = ['configuration' => ['BaseURL' => 'http://[fd00::2]/', 'Username' => 'monitor'],
    'attributes' => ['Source' => hash('sha256', 'http://[fd00::2]/|monitor'), 'Snapshot' => '{"timestamp":42}']];
$converted = json_decode($module->Migrate(json_encode($legacy)), true);
$ipv6 = $converted['configuration'];
verify($ipv6['Host'] === 'fd00::2' && $ipv6['Port'] === 80, 'Alte IPv6-Adresse ohne Port behält HTTP-Port 80');
verify($converted['attributes']['Source'] === hash('sha256', 'http://[fd00::2]:80|monitor')
    && $converted['attributes']['Snapshot'] === '{"timestamp":42}', 'Migration erhält die Zuordnung vorhandener Messdaten');
verify((new ReflectionMethod($module, 'GetTimerInterval'))->invoke($module, 'Poll') === 1000, 'Erste Erkennung startet nach Übernehmen ohne volles Pollingintervall');
$initialRows = json_decode($module->GetConfigurationForm(), true)['elements'][1]['items'][1]['values'];
$initialRows[0]['Name'] = 'Noch nicht gespeichert';
$initialRows[0]['Enabled'] = true;
$module->RememberVariableSelection($initialRows);
$report = $module->TestConnection();
verify(str_contains($report, 'Antwort erhalten') && str_contains($report, 'Anmeldung: erfolgreich') && str_contains($report, '20 Messwerte'), 'Verbindungstest meldet HTTP, neue Anmeldung und Datenfunde');
$liveRows = json_decode((new ReflectionMethod($module, 'GetBuffer'))->invoke($module, 'FormRows'), true);
verify(count($liveRows) === 20, 'Erste Erkennung ergänzt offene Variablenliste ohne erneutes Öffnen');
verify($liveRows[0]['Name'] === 'Noch nicht gespeichert' && $liveRows[0]['Enabled'], 'Erkennung erhält noch nicht übernommene Auswahl und Namen');
verify((new ReflectionMethod($module, 'GetTimerInterval'))->invoke($module, 'Poll') === 60000, 'Nach Erkennung gilt wieder das normale Pollingintervall');
verify(count(IPS_GetChildrenIDs($id)) === 1, 'Erkennung erzeugt keine zusätzlichen Variablen');
$form = json_decode($module->GetConfigurationForm(), true);
verify(count($form['elements'][1]['items'][1]['values']) === 20, 'Dynamischer Katalog enthält Pools, Volumes, Disks und Lüfter');
verify(!$module->Update(), 'Doppelklick wird begrenzt');
$snapshot = json_decode((new ReflectionMethod($module, 'ReadAttributeString'))->invoke($module, 'Snapshot'), true);
unset($snapshot['metrics'][array_key_last($snapshot['metrics'])]);
(new ReflectionMethod($module, 'WriteAttributeString'))->invoke($module, 'Snapshot', json_encode($snapshot));
verify(count(json_decode($module->GetConfigurationForm(), true)['elements'][1]['items'][1]['values']) === 20, 'Bereits erkannte Messwerte bleiben bei vorübergehenden Datenlücken auswählbar');
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
$report = $module->TestConnection();
verify(str_contains($report, 'Antwort erhalten') && str_contains($report, 'Anmeldung abgelehnt') && !str_contains($report, 'Datenabruf:'), 'Verbindungstest trennt erreichbare NAS von fehlerhafter Anmeldung');
$p = json_decode((new ReflectionMethod($module, 'payload'))->invoke($module), true);
verify(!$p['fresh'] && $p['nextAllowed'] >= time() + 899, 'Anmeldefehler macht Daten alt und setzt 15-Minuten-Sperre');
verify(!$module->Update(), 'Wiederholsperre verhindert unmittelbaren neuen Login');
IPS_SetProperty($id, 'Username', 'anderes-geraet');
IPS_ApplyChanges($id);
$p = json_decode((new ReflectionMethod($module, 'payload'))->invoke($module), true);
verify($p['data'] === [], 'Datenquellenwechsel entfernt alten Snapshot ohne Variablenlöschung');
verify(IPS_VariableExists($variable), 'Datenquellenwechsel bewahrt vorhandene Variable');
IPS_SetProperty($id, 'Username', 'invalidjson');
IPS_SetProperty($id, 'Password', 'test-secret');
IPS_ApplyChanges($id);
$report = $module->TestConnection();
verify(str_contains($report, 'Anmeldung: erfolgreich') && str_contains($report, 'kein gültiges JSON'), 'Verbindungstest trennt bestätigten Login von fehlerhafter API-Antwort');
IPS_SetProperty($id, 'Username', 'partial');
IPS_ApplyChanges($id);
$report = $module->TestConnection();
verify(str_contains($report, 'Anmeldung: erfolgreich') && str_contains($report, 'Teilabruf: Laufwerke') && str_contains($report, 'Leserechte'), 'Verbindungstest meldet Teilabrufe und fehlende Leserechte');
verify(str_contains($report, 'LAN-Statistik') && str_contains($report, 'HTTP 404') && IPS_GetInstance($id)['InstanceStatus'] === 102, 'Fehlende LAN-Statistik lässt übrige Messungen weiterlaufen');
IPS_SetProperty($id, 'Username', 'monitor');
IPS_ApplyChanges($id);
verify($module->Update(), 'Neue Messwerte für den Auswahltest erkannt');
$module->RequestAction('SetTileMetricVisibility', ['id' => 'LANSend', 'visible' => false]);
$tileData = json_decode((new ReflectionMethod($module, 'payload'))->invoke($module), true);
verify(count($tileData['extraMetrics']) === 7 && $tileData['hiddenTileMetrics'] === ['LANSend'],
    'Kachelauswahl speichert nur den betroffenen Messwert und funktioniert ohne angelegte Zusatzvariablen');
IPS_ApplyChanges($id);
$tileData = json_decode((new ReflectionMethod($module, 'payload'))->invoke($module), true);
verify($tileData['hiddenTileMetrics'] === ['LANSend'], 'Kachelauswahl bleibt nach Übernehmen bestehen');
$module->RequestAction('SetTileMetricVisibility', ['id' => 'LANSend', 'visible' => true]);
$tileData = json_decode((new ReflectionMethod($module, 'payload'))->invoke($module), true);
verify($tileData['hiddenTileMetrics'] === [], 'Ausgeblendeter Wert lässt sich gezielt wieder einblenden');
try {
    $module->RequestAction('SetTileMetricVisibility', ['id' => 'CPU', 'visible' => false]);
    throw new RuntimeException('Fremder Ident akzeptiert');
} catch (InvalidArgumentException $e) {
    verify(true, 'Kachelauswahl akzeptiert nur die freigegebenen Messwert-Idents');
}
$snapshot = json_decode((new ReflectionMethod($module, 'ReadAttributeString'))->invoke($module, 'Snapshot'), true);
$selections = [];
foreach ($snapshot['metrics'] as $ident => $metric) {
    if (in_array($ident, ['LANSend', 'LANReceive', 'RAMFree', 'RAMCache'], true)
        || $metric['group'] === 'volume' || str_ends_with($ident, '_PowerOnHours')) {
        $selections[] = ['Ident' => $ident, 'Enabled' => true, 'Target' => 0];
    }
}
IPS_SetProperty($id, 'Variables', json_encode($selections));
IPS_ApplyChanges($id);
verify(count($selections) === 7 && count(IPS_GetChildrenIDs($id)) === 8 && $module->Update(), 'Alle neuen Messwerte lassen sich einzeln als Variablen anlegen und aktualisieren');
foreach ($selections as ['Ident' => $ident]) {
    $variableId = IPS_GetObjectIDByIdent($ident, $id);
    $metric = $snapshot['metrics'][$ident];
    $expected = $metric['type'] === 2 ? (float) $metric['value'] : $metric['value'];
    verify(GetValue($variableId) === $expected && IPS_GetVariable($variableId)['VariableType'] === $metric['type'],
        'Symcon-Variable übernimmt Messwert und Typ für ' . $ident);
    verify(IPS_GetVariablePresentation($variableId)['PRESENTATION'] === VARIABLE_PRESENTATION_VALUE_PRESENTATION,
        'Native Darstellung für ' . $ident);
}
$lanId = IPS_GetObjectIDByIdent('LANSend', $id);
$lanIntervals = json_decode(IPS_GetVariablePresentation($lanId)['INTERVALS'], true);
$ramIntervals = json_decode(IPS_GetVariablePresentation(IPS_GetObjectIDByIdent('RAMFree', $id))['INTERVALS'], true);
verify($lanIntervals[0]['IntervalMinValue'] === 0 && $lanIntervals[0]['ConversionFactor'] === 1000
    && $lanIntervals[0]['SuffixValue'] === ' kB/s' && $ramIntervals[2]['ConversionFactor'] === 1000000000
    && $ramIntervals[2]['SuffixValue'] === ' GB', 'Native Darstellung skaliert LAN und RAM bei unveränderten Rohwerten');
echo "Bestanden: $checks SymconStub-Prüfungen\n";
