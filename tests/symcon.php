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
(new ReflectionMethod($module, 'SetBuffer'))->invoke($module, 'LastAttempt', (string) (time() - 6));
verify(str_contains($module->TestConnection(), 'Nächster Test'), 'Manueller Test beachtet die Sperre bei abgelehnter Anmeldung');

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
verify(str_contains($report, 'Desktop-Monitoring: UGOS-Sitzung ist abgelaufen') && !str_contains($report, 'automatischer Versuch'),
    'Separater Fehler der Desktop-Abfrage blockiert die gültige Sitzung nicht');
IPS_SetProperty($id, 'Username', 'monitor');
IPS_ApplyChanges($id);
verify($module->Update(), 'Neue Messwerte für den Auswahltest erkannt');
$persistence = $module->GetPersistence();
$persistence['attributes']['HiddenTileMetrics'] = '["CPU","LANSend"]';
$migrated = json_decode($module->Migrate(json_encode($persistence)), true);
$module->ApplyPersistence($migrated);
IPS_ApplyChanges($id);
$form = json_decode($module->GetConfigurationForm(), true);
$visualSettings = array_column($form['elements'][2]['items'], null, 'name');
$tileValues = array_column($visualSettings['TileMetrics']['values'], 'Visible', 'Ident');
verify(count($tileValues) === 10 && !$tileValues['CPU'] && !$tileValues['LANSend'] && $tileValues['RAM'],
    'Modulkonfiguration enthält Haupt- und Zusatzwerte mit übernommener bisheriger Auswahl');
$tileData = json_decode((new ReflectionMethod($module, 'payload'))->invoke($module), true);
verify($tileData['hiddenTileMetrics'] === ['CPU', 'LANSend'], 'Migrierte Auswahl bleibt in der Kachel wirksam');
IPS_SetProperty($id, 'TileMetrics', '[{"Ident":"CPU","Visible":true},{"Ident":"RAM","Visible":false},{"Ident":"LANSend","Visible":false}]');
IPS_ApplyChanges($id);
$tileData = json_decode((new ReflectionMethod($module, 'payload'))->invoke($module), true);
verify($tileData['hiddenTileMetrics'] === ['RAM', 'LANSend'], 'Übernehmen speichert die Sichtbarkeit pro Messwert');
verify(!str_contains($module->GetVisualizationTile(), 'Messwerte auswählen'), 'Kachel enthält kein Auswahlmenü');
try {
    $module->RequestAction('SetTileMetricVisibility', ['id' => 'CPU', 'visible' => false]);
    throw new RuntimeException('Alte Kachelaktion akzeptiert');
} catch (InvalidArgumentException $e) {
    verify(true, 'Aus alten geöffneten Kacheln kann die Anzeigeauswahl nicht mehr verändert werden');
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
IPS_SetProperty($id, 'Username', 'slow');
IPS_SetProperty($id, 'Timeout', 2);
IPS_ApplyChanges($id);
$report = $module->TestConnection();
$tileData = json_decode((new ReflectionMethod($module, 'payload'))->invoke($module), true);
verify(str_contains($report, 'Ressourcenstatistik: Zeitlimit') && str_contains($report, 'Geräteinformationen: Zeitlimit')
    && str_contains($report, 'cURL 28') && $tileData['fresh'], 'Reale HTTP-Timeouts werden als Teilabruf gemeldet');
verify($tileData['data']['metrics']['CPU']['value'] === 17.4
    && $tileData['data']['metrics']['Uptime']['value'] === null
    && count($tileData['data']['disks']) === 2, 'Ressourcenübersicht und Laufwerksdaten bleiben bei zwei langsamen Teilabfragen verfügbar');

IPS_SetProperty($id, 'Username', 'machinefirst');
IPS_ApplyChanges($id);
$report = $module->TestConnection();
$tileData = json_decode((new ReflectionMethod($module, 'payload'))->invoke($module), true);
$metrics = array_column($tileData['data']['metrics'], 'value', 'id');
verify(!str_contains($report, 'Teilabruf') && $metrics['CPU'] === 17.4 && $metrics['RAM'] === 38.2,
    'Geräteinformationen werden vor der Ressourcenstatistik mit derselben Sitzung gelesen');
verify($metrics['LANSend'] === 12500.5 && $metrics['LANReceive'] === 0
    && GetValue($lanId) === 12500.5, 'LAN-Gesamtraten aus get_all aktualisieren Kachel und Variablen ohne separate LAN-Abfrage');

IPS_SetProperty($id, 'Username', 'incomplete');
IPS_ApplyChanges($id);
$report = $module->TestConnection();
$tileData = json_decode((new ReflectionMethod($module, 'payload'))->invoke($module), true);
$metrics = array_column($tileData['data']['metrics'], 'value', 'id');
verify(!str_contains($report, 'Teilabruf') && $metrics['CPU'] === 0 && $metrics['RAM'] === 38.2
    && $metrics['CPUTemperature'] === 49 && $metrics['RAMFree'] === 8589934592,
    'Ressourcenübersicht ergänzt fehlende Werte und erhält vorhandene Werte und Speicherdetails');
verify($metrics['LANSend'] === 1200 && $metrics['LANReceive'] === 0,
    'Separate LAN-Statistik ergänzt eine fehlende Gesamtrate');

IPS_SetProperty($id, 'Username', 'tasktimeout');
IPS_ApplyChanges($id);
$report = $module->TestConnection();
$tileData = json_decode((new ReflectionMethod($module, 'payload'))->invoke($module), true);
verify(substr_count($report, 'cURL 28') === 3 && str_contains($report, 'Ohne aktuellen Messwert: CPU-Auslastung, RAM-Auslastung')
    && count($tileData['data']['pools']) === 1 && count($tileData['data']['disks']) === 2,
    'Drei Ressourcen-Timeouts lassen Speicher- und Laufwerksdaten verfügbar und benennen die fehlenden Messwerte');
verify($tileData['data']['metrics']['LANSend']['value'] === null && GetValue($lanId) === 1200.0,
    'Fehlende LAN-Daten überschreiben die gespeicherte Variable nicht mit null oder 0');

IPS_SetProperty($id, 'Username', 'desktoponly');
IPS_ApplyChanges($id);
$report = $module->TestConnection();
$tileData = json_decode((new ReflectionMethod($module, 'payload'))->invoke($module), true);
$metrics = array_column($tileData['data']['metrics'], 'value', 'id');
verify(str_contains($report, 'Desktop-Monitoring liefert Ersatzwerte') && $metrics['CPU'] === 0
    && $metrics['RAM'] === 35.11 && $metrics['CPUTemperature'] === 52 && $metrics['LANSend'] === 7436
    && $metrics['LANReceive'] === 3480 && GetValue($lanId) === 7436.0,
    'Desktop-Abfrage und Geräteinformationen liefern auch bei ausgefallener Detailstatistik aktuelle Werte');
verify(!str_contains($report, 'LAN-Statistik') && str_contains($report, 'Ohne aktuellen Messwert: Freier RAM, RAM-Cache')
    && $metrics['RAMFree'] === null && $metrics['RAMCache'] === null,
    'Desktop-Raten ersparen eine separate LAN-Abfrage. RAM-Details bleiben ohne Quelle unbekannt');
verify(str_contains($report, 'Separate RAM-Abfrage: NAS antwortet mit HTTP 404')
    && IPS_GetInstance($id)['InstanceStatus'] === 102, 'Fehlende separate RAM-Abfrage unterbricht die übrigen Daten nicht');

IPS_SetProperty($id, 'Username', 'ramdetails');
IPS_ApplyChanges($id);
$report = $module->TestConnection();
$tileData = json_decode((new ReflectionMethod($module, 'payload'))->invoke($module), true);
$metrics = array_column($tileData['data']['metrics'], 'value', 'id');
verify(substr_count($report, 'cURL 28') === 2 && str_contains($report, 'Separate RAM-Abfrage liefert 2 Messwerte')
    && !str_contains($report, 'Ohne aktuellen Messwert') && $metrics['CPU'] === 0
    && $metrics['RAMFree'] === 0 && $metrics['RAMCache'] === 3221225472,
    'Separate RAM-Abfrage liefert freie Bytes und Cache-Bytes trotz Zeitlimits der Sammelstatistik');
verify(GetValue(IPS_GetObjectIDByIdent('RAMFree', $id)) === 0.0
    && GetValue(IPS_GetObjectIDByIdent('RAMCache', $id)) === 3221225472.0,
    'Die vorhandenen RAM-Variablen erhalten die gemeldeten Byte-Rohwerte');

IPS_SetProperty($id, 'Username', 'rampartial');
IPS_ApplyChanges($id);
$report = $module->TestConnection();
$tileData = json_decode((new ReflectionMethod($module, 'payload'))->invoke($module), true);
$metrics = array_column($tileData['data']['metrics'], 'value', 'id');
verify($metrics['RAMFree'] === 8589934592 && $metrics['RAMCache'] === 1610612736,
    'Die separate RAM-Abfrage ergänzt einen fehlenden Cache-Wert und erhält den freien RAM aus der Sammelstatistik');

IPS_SetProperty($id, 'Username', 'allfail');
IPS_ApplyChanges($id);
$report = $module->TestConnection();
verify(str_contains($report, 'Anmeldung: erfolgreich') && str_contains($report, 'Ressourcenübersicht: NAS antwortet mit HTTP 503')
    && str_contains($report, 'Nächster automatischer Versuch in 10 s.')
    && IPS_GetInstance($id)['InstanceStatus'] === 202, 'Ausfall sämtlicher Datenabrufe meldet einen klaren Fehler mit kurzem Wiederholintervall');
$setBuffer = new ReflectionMethod($module, 'SetBuffer');
$setBuffer->invoke($module, 'LastAttempt', (string) (time() - 6));
$setBuffer->invoke($module, 'Failures', '6');
$report = $module->TestConnection();
verify(str_contains($report, 'Nächster automatischer Versuch in 60 s.'),
    'Manueller Test kann vor der automatischen Wiederholung erneut prüfen. Wartezeit bleibt auf 60 s begrenzt');

$websocketUrl = parse_url(getenv('UGNAS_TEST_WS_URL'));
IPS_SetProperty($id, 'Host', $websocketUrl['host']);
IPS_SetProperty($id, 'Port', $websocketUrl['port']);
IPS_SetProperty($id, 'Username', 'wslive');
IPS_ApplyChanges($id);
$report = $module->TestConnection();
$tileData = json_decode((new ReflectionMethod($module, 'payload'))->invoke($module), true);
$metrics = array_column($tileData['data']['metrics'], 'value', 'id');
verify(str_contains($report, 'UGOS-Live-Verbindung geöffnet') && !str_contains($report, 'Teilabruf')
    && $metrics['CPU'] === 17.4 && $metrics['RAMFree'] === 8589934592 && $metrics['RAMCache'] === 4294967296
    && $metrics['LANSend'] === 12500.5 && $metrics['LANReceive'] === 0,
    'Nach Ablauf der ersten Statistikabfrage liefert das authentifizierte Live-Abonnement wieder sämtliche Detailwerte');
$loginCount = file_get_contents(getenv('UGNAS_TEST_TMP') . '/logins');
$setBuffer->invoke($module, 'LastAttempt', (string) (time() - 6));
$started = microtime(true);
verify($module->Update() && microtime(true) - $started < 2
    && file_get_contents(getenv('UGNAS_TEST_TMP') . '/logins') === $loginCount,
    'Automatisches Polling mit erfolgreichem Live-Abruf öffnet die Verbindung vor der Statistik mit der bestehenden Sitzung');
IPS_SetProperty($id, 'Username', 'wsbad');
IPS_ApplyChanges($id);
$report = $module->TestConnection();
verify(str_contains($report, 'UGOS-Live-Verbindung nicht verfügbar') && str_contains($report, 'Desktop-Monitoring liefert Ersatzwerte')
    && !is_file(getenv('UGNAS_TEST_TMP') . '/invalid-handshake-frame') && GetValue($lanId) === 7436.0,
    'Ungültiger WebSocket-Handshake wird abgelehnt und Desktop-Messungen bleiben verfügbar');
echo "Bestanden: $checks SymconStub-Prüfungen\n";
