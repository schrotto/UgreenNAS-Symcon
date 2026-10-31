<?php
declare(strict_types=1);

require_once __DIR__ . '/../libs/UgosClient.php';
require_once __DIR__ . '/../libs/Telemetry.php';

class UgreenNAS extends IPSModuleStrict
{
    public function Create(): void
    {
        parent::Create();
        foreach (['Enabled' => false, 'VerifyTLS' => true, 'TileEnabled' => true,
            'ShowDisks' => true, 'ShowPools' => true, 'ShowFans' => true, 'ShowControls' => true,
            'Compact' => false] as $key => $default) {
            $this->RegisterPropertyBoolean($key, $default);
        }
        foreach (['BaseURL' => 'https://:9443', 'Username' => '', 'Password' => '', 'CAFile' => '',
            'Variables' => '[]', 'Theme' => 'auto', 'Title' => 'UGREEN NAS'] as $key => $default) {
            $this->RegisterPropertyString($key, $default);
        }
        foreach (['Interval' => 60, 'Timeout' => 8, 'Accent' => 0x52D6B0,
            'CPUWarning' => 90, 'TemperatureWarning' => 80, 'StorageWarning' => 85] as $key => $default) {
            $this->RegisterPropertyInteger($key, $default);
        }
        $this->RegisterAttributeString('Snapshot', '{}');
        $this->RegisterAttributeString('Catalog', '{}');
        $this->RegisterAttributeString('Source', '');
        $this->RegisterAttributeString('Diagnostic', 'Noch keine Abfrage durchgeführt.');
        $this->RegisterTimer('Poll', 0, 'UGNAS_Update($_IPS["TARGET"]);');
    }

    public function ApplyChanges(): void
    {
        parent::ApplyChanges();
        $this->SetTimerInterval('Poll', 0);
        $this->SetVisualizationType($this->ReadPropertyBoolean('TileEnabled') ? 1 : 0);
        $source = hash('sha256', $this->ReadPropertyString('BaseURL') . '|' . $this->ReadPropertyString('Username'));
        if ($source !== $this->ReadAttributeString('Source')) {
            $this->WriteAttributeString('Snapshot', '{}');
            $this->WriteAttributeString('Catalog', '{}');
            $this->WriteAttributeString('Source', $source);
        }
        // Auch ein Passwort- oder Zertifikatwechsel darf keine alte Sitzung behalten.
        $this->SetBuffer('Session', '');
        $this->SetBuffer('NextAllowed', '0');
        $this->SetBuffer('LastAttempt', '0');
        $this->SetBuffer('Failures', '0');
        $this->SetBuffer('Current', '0');
        $this->SetBuffer('FormRows', '');
        try {
            $this->configureVariables();
            $this->validateConfiguration();
            if (!$this->ReadPropertyBoolean('Enabled')) {
                $this->SetStatus(104);
                $this->WriteAttributeString('Diagnostic', 'Überwachung deaktiviert.');
                $this->markOffline();
            } else {
                $this->SetStatus(102);
                $this->WriteAttributeString('Diagnostic', 'Geräteerkennung startet. Die Variablenliste wird nach erfolgreicher Abfrage automatisch ergänzt.');
                $this->SetTimerInterval('Poll', 1000);
            }
        } catch (Throwable $e) {
            $this->SetStatus(201);
            $this->WriteAttributeString('Diagnostic', $e->getMessage());
            $this->markOffline();
        }
        $this->publish();
    }

    private function validateConfiguration(): void
    {
        if (!$this->ReadPropertyBoolean('Enabled')) {
            return;
        }
        if (!extension_loaded('curl') || !extension_loaded('openssl') || !extension_loaded('mbstring')) {
            throw new RuntimeException('PHP-Erweiterungen cURL, OpenSSL und mbstring werden benötigt.');
        }
        if ($this->ReadPropertyInteger('Interval') < 15 || $this->ReadPropertyInteger('Interval') > 86400
            || $this->ReadPropertyInteger('Timeout') < 2 || $this->ReadPropertyInteger('Timeout') > 30) {
            throw new RuntimeException('Intervall muss 15–86400 s und Zeitlimit 2–30 s betragen.');
        }
        if ($this->ReadPropertyString('Username') === '' || $this->ReadPropertyString('Password') === '') {
            throw new RuntimeException('NAS-Benutzername und Passwort fehlen.');
        }
        foreach (['CPUWarning', 'StorageWarning'] as $key) {
            if ($this->ReadPropertyInteger($key) < 1 || $this->ReadPropertyInteger($key) > 100) {
                throw new RuntimeException('Prozent-Warnschwellen müssen zwischen 1 und 100 liegen.');
            }
        }
        if ($this->ReadPropertyInteger('TemperatureWarning') < 20 || $this->ReadPropertyInteger('TemperatureWarning') > 120) {
            throw new RuntimeException('Temperatur-Warnschwelle muss zwischen 20 und 120 °C liegen.');
        }
        $this->client(); // URL-Validierung ohne Netzwerkzugriff
    }

    private function client(): UgosClient
    {
        return new UgosClient($this->ReadPropertyString('BaseURL'), $this->ReadPropertyString('Username'),
            $this->ReadPropertyString('Password'), $this->ReadPropertyBoolean('VerifyTLS'),
            $this->ReadPropertyInteger('Timeout'), $this->GetBuffer('Session'), $this->ReadPropertyString('CAFile'));
    }

    public function Update(): bool
    {
        if (!$this->ReadPropertyBoolean('Enabled')) {
            return false;
        }
        if (!IPS_SemaphoreEnter('UGNAS_' . $this->InstanceID, 1)) {
            return false;
        }
        try {
            if (time() < (int) $this->GetBuffer('NextAllowed') || time() - (int) $this->GetBuffer('LastAttempt') < 5) {
                $this->publish();
                return false;
            }
            $this->SetBuffer('LastAttempt', (string) time());
            $this->validateConfiguration();
            $client = $this->client();
            $machine = $client->read('/ugreen/v1/sysinfo/machine/common');
            try {
                $stats = $client->read('/ugreen/v1/taskmgr/stat/get_all');
            } catch (UgosApiException $e) {
                if (!in_array($e->getCode(), [404, 9404], true)) {
                    throw $e;
                }
                $stats = $client->read('/ugreen/v1/taskmgr/stat/overview');
            }
            $overview = $stats['overview'] ?? $stats;
            if (!isset($machine['common']) || !is_array($overview)
                || (!isset($overview['cpu']) && !isset($overview['mem']))) {
                throw new RuntimeException('Unbekanntes UGOS-Datenformat. Keine aktuellen Messwerte übernommen.');
            }
            $warnings = [];
            $optional = function (string $path, string $label) use ($client, &$warnings): ?array {
                try {
                    $result = $client->read($path);
                    if (!isset($result['result']) || !is_array($result['result'])) {
                        throw new RuntimeException('Unbekanntes Listenformat.');
                    }
                    return $result;
                } catch (Throwable $e) {
                    if ($e instanceof UgosApiException && in_array($e->getCode(), [1003, 1024, 401], true)) {
                        throw $e;
                    }
                    $warnings[] = $label . ': ' . $e->getMessage();
                    return null;
                }
            };
            $pools = $optional('/ugreen/v1/storage/pool/list', 'Speicherpools');
            $disks = $optional('/ugreen/v2/storage/disk/list', 'Laufwerke');
            $snapshot = UgosTelemetry::normalize($machine, $stats, $pools, $disks);
            $previousCatalog = $this->catalog();
            $snapshot['timestamp'] = time();
            $snapshot['warnings'] = $warnings;
            $this->WriteAttributeString('Snapshot', json_encode($snapshot, JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE));
            $known = $previousCatalog;
            foreach ($snapshot['metrics'] as $ident => $metric) {
                unset($metric['value']);
                $known[$ident] = $metric;
            }
            foreach ($known as &$metric) {
                unset($metric['value']);
            }
            unset($metric);
            $this->WriteAttributeString('Catalog', json_encode($known, JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE));
            if (array_keys($previousCatalog) !== array_keys($this->catalog())) {
                $this->refreshVariableForm();
            }
            $this->SetBuffer('Session', $client->session());
            $this->SetBuffer('Failures', '0');
            $this->SetBuffer('NextAllowed', '0');
            $this->SetBuffer('Current', '1');
            $this->SetStatus(102);
            $this->WriteAttributeString('Diagnostic', $warnings === [] ? 'Abfrage erfolgreich.' : implode(' ', $warnings));
            $this->writeValues($snapshot['metrics']);
            return true;
        } catch (Throwable $e) {
            $this->SetBuffer('Session', '');
            $failures = min(6, (int) $this->GetBuffer('Failures') + 1);
            $this->SetBuffer('Failures', (string) $failures);
            $delay = $e instanceof UgosApiException && $e->getCode() === 1003 ? 900 : min(900, 15 * (2 ** $failures));
            $this->SetBuffer('NextAllowed', (string) (time() + $delay));
            $this->SetStatus(202);
            $this->WriteAttributeString('Diagnostic', $e->getMessage() . ' Nächster Versuch frühestens in ' . $delay . ' s.');
            $this->markOffline();
            return false;
        } finally {
            $this->SetTimerInterval('Poll', max(15, $this->ReadPropertyInteger('Interval')) * 1000);
            IPS_SemaphoreLeave('UGNAS_' . $this->InstanceID);
            $this->publish();
        }
    }

    public function RequestAction(string $Ident, mixed $Value): void
    {
        if ($Ident !== 'Refresh' || $Value !== true || !$this->ReadPropertyBoolean('TileEnabled')
            || !$this->ReadPropertyBoolean('ShowControls')) {
            throw new InvalidArgumentException('Nicht erlaubte Kachelaktion.');
        }
        $this->Update();
    }

    private function snapshot(): array
    {
        return json_decode($this->ReadAttributeString('Snapshot'), true) ?: [];
    }

    private function rows(): array
    {
        $rows = json_decode($this->ReadPropertyString('Variables'), true);
        if (!is_array($rows) || count($rows) > 512) {
            throw new RuntimeException('Ungültige Variablenauswahl (maximal 512 Einträge).');
        }
        return $rows;
    }

    private function catalog(): array
    {
        $base = UgosTelemetry::normalize([], [], null, null)['metrics'];
        return array_replace($base, json_decode($this->ReadAttributeString('Catalog'), true) ?: [], $this->snapshot()['metrics'] ?? []);
    }

    private function configureVariables(): void
    {
        $catalog = $this->catalog();
        // Vorab alle Ziele prüfen, bevor eine neue Variable angelegt wird.
        $targets = [];
        foreach ($this->rows() as $row) {
            if (empty($row['Enabled'])) {
                continue;
            }
            $id = $row['Ident'] ?? '';
            if (!isset($catalog[$id])) {
                continue; // entfernte Geräte unverändert erhalten
            }
            $target = (int) ($row['Target'] ?? 0);
            if ($target > 0) {
                if (isset($targets[$target])) {
                    throw new RuntimeException('Eine Zielvariable darf nur einem Messwert zugeordnet sein.');
                }
                $targets[$target] = true;
                $this->validateTarget($target, $catalog[$id]['type']);
            }
        }
        foreach ($this->rows() as $row) {
            $id = $row['Ident'] ?? '';
            if (empty($row['Enabled']) || !isset($catalog[$id]) || !empty($row['Target'])) {
                continue;
            }
            $m = $catalog[$id];
            if (@$this->GetIDForIdent($id) !== false) {
                continue; // Namen, Position und Darstellung vorhandener Variablen erhalten
            }
            $name = trim((string) ($row['Name'] ?? '')) ?: $m['name'];
            $position = max(0, (int) ($row['Position'] ?? 0));
            $profile = $this->profile($m);
            match ($m['type']) {
                0 => $this->RegisterVariableBoolean($id, $name, $profile, $position),
                1 => $this->RegisterVariableInteger($id, $name, $profile, $position),
                2 => $this->RegisterVariableFloat($id, $name, $profile, $position),
                3 => $this->RegisterVariableString($id, $name, '', $position)
            };
        }
    }

    private function profile(array $metric): string
    {
        if ($metric['unit'] === '°C') {
            return '~Temperature';
        }
        $name = match ($metric['unit']) { '%' => 'UGNAS.Percent', 'rpm' => 'UGNAS.RPM', 's' => 'UGNAS.Seconds', default => '' };
        if ($name === '' && $metric['type'] === 0) {
            $name = 'UGNAS.Connection';
        }
        if ($name !== '' && !IPS_VariableProfileExists($name)) {
            IPS_CreateVariableProfile($name, $metric['type']);
            if ($metric['type'] === 0) {
                IPS_SetVariableProfileAssociation($name, 0, 'Offline', '', 0xD97B69);
                IPS_SetVariableProfileAssociation($name, 1, 'Online', '', 0x52D6B0);
            } else {
                IPS_SetVariableProfileText($name, '', ' ' . $metric['unit']);
                IPS_SetVariableProfileDigits($name, $metric['type'] === 2 ? 1 : 0);
            }
        }
        return $name;
    }

    private function validateTarget(int $target, int $type): void
    {
        if (!IPS_VariableExists($target) || IPS_GetVariable($target)['VariableType'] !== $type) {
            throw new RuntimeException('Zielvariable ' . $target . ' fehlt oder hat den falschen Datentyp.');
        }
        if ((int) (IPS_GetVariable($target)['VariableAction'] ?? 0) > 0
            || (int) (IPS_GetVariable($target)['VariableCustomAction'] ?? 0) > 0) {
            throw new RuntimeException('Zielvariable ' . $target . ' besitzt eine Aktion. Bitte eine reine Messwertvariable auswählen.');
        }
    }

    private function writeValues(array $metrics): void
    {
        foreach ($this->rows() as $row) {
            $id = $row['Ident'] ?? '';
            if (empty($row['Enabled']) || !isset($metrics[$id]) || $metrics[$id]['value'] === null) {
                continue;
            }
            $m = $metrics[$id];
            $target = (int) ($row['Target'] ?? 0);
            if ($target > 0) {
                $this->validateTarget($target, $m['type']);
                SetValue($target, $m['value']);
            } elseif (@$this->GetIDForIdent($id) !== false) {
                $this->SetValue($id, $m['value']);
            }
        }
    }

    private function markOffline(): void
    {
        $this->SetBuffer('Current', '0');
        try {
            $this->writeValues(['Online' => ['value' => false, 'type' => 0]]);
        } catch (Throwable $e) {
            // Diagnose der eigentlichen Verbindungs-/Konfigurationsursache erhalten.
        }
    }

    private function payload(): string
    {
        $s = $this->snapshot();
        $enabled = $this->ReadPropertyBoolean('Enabled');
        $fresh = $enabled && $this->GetBuffer('Current') === '1' && $this->GetStatus() === 102 && !empty($s['timestamp'])
            && time() - $s['timestamp'] <= max(45, $this->ReadPropertyInteger('Interval') * 2);
        $alerts = [];
        if ($fresh) {
            foreach (['CPU' => ['CPUWarning', 'CPU-Auslastung'], 'CPUTemperature' => ['TemperatureWarning', 'CPU-Temperatur']] as $id => [$prop, $label]) {
                $value = $s['metrics'][$id]['value'] ?? null;
                if ($value !== null && $value >= $this->ReadPropertyInteger($prop)) {
                    $alerts[] = $label . ' über Warnschwelle (' . $this->ReadPropertyInteger($prop) . $s['metrics'][$id]['unit'] . ').';
                }
            }
            foreach ($s['pools'] ?? [] as $pool) {
                if ($pool['percent'] !== null && $pool['percent'] >= $this->ReadPropertyInteger('StorageWarning')) {
                    $alerts[] = $pool['name'] . ': Belegung über ' . $this->ReadPropertyInteger('StorageWarning') . ' %.';
                }
            }
        }
        return json_encode(['enabled' => $enabled, 'fresh' => $fresh, 'data' => $s, 'alerts' => $alerts,
            'diagnostic' => $this->ReadAttributeString('Diagnostic'), 'now' => time(),
            'staleAfter' => max(45, $this->ReadPropertyInteger('Interval') * 2),
            'nextAllowed' => (int) $this->GetBuffer('NextAllowed'),
            'options' => ['tileEnabled' => $this->ReadPropertyBoolean('TileEnabled'),
                'title' => $this->ReadPropertyString('Title'), 'theme' => $this->ReadPropertyString('Theme'),
                'accent' => sprintf('#%06x', $this->ReadPropertyInteger('Accent') & 0xFFFFFF),
                'compact' => $this->ReadPropertyBoolean('Compact'), 'disks' => $this->ReadPropertyBoolean('ShowDisks'),
                'pools' => $this->ReadPropertyBoolean('ShowPools'), 'fans' => $this->ReadPropertyBoolean('ShowFans'),
                'controls' => $this->ReadPropertyBoolean('ShowControls')],
            'thresholds' => ['cpu' => $this->ReadPropertyInteger('CPUWarning'),
                'temperature' => $this->ReadPropertyInteger('TemperatureWarning'), 'storage' => $this->ReadPropertyInteger('StorageWarning')]],
            JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE);
    }

    private function publish(): void
    {
        $this->UpdateVisualizationValue($this->payload());
        $this->UpdateFormField('Diagnostic', 'caption', $this->ReadAttributeString('Diagnostic'));
    }

    public function GetVisualizationTile(): string
    {
        if (!$this->ReadPropertyBoolean('TileEnabled')) {
            return '<p>NAS-Kachel deaktiviert.</p>';
        }
        $html = file_get_contents(__DIR__ . '/module.html');
        $json = json_encode($this->payload(), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_THROW_ON_ERROR);
        return $html . '<script>handleMessage(' . $json . ');</script>';
    }

    public function SelectVariables(string $Mode, array $Rows): void
    {
        if (!in_array($Mode, ['all', 'none', 'recommended'], true)) {
            throw new InvalidArgumentException('Unbekannte Auswahl.');
        }
        foreach ($Rows as &$row) {
            $row['Enabled'] = $Mode === 'all' || ($Mode === 'recommended' && in_array($row['Ident'], ['Online', 'CPU', 'RAM', 'CPUTemperature'], true));
        }
        $this->refreshVariableForm($Rows);
    }

    /** Hält auch noch nicht übernommene Formulareingaben bei der Erkennung fest. */
    public function RememberVariableSelection(array $Rows): void
    {
        if (count($Rows) > 512) {
            throw new InvalidArgumentException('Zu viele Variableneinträge.');
        }
        $this->SetBuffer('FormRows', json_encode($Rows, JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE));
        $this->UpdateFormField('VariableSummary', 'caption', $this->variableSummary($Rows));
    }

    private function variableRows(array $input): array
    {
        $catalog = $this->catalog();
        $rows = [];
        foreach ($input as $row) {
            if (is_string($row['Ident'] ?? null)) {
                $rows[$row['Ident']] = $row;
            }
        }
        foreach ($catalog as $id => $metric) {
            $row = $rows[$id] ?? ['Ident' => $id, 'Enabled' => false, 'Name' => $metric['name'], 'Target' => 0, 'Position' => count($rows) * 10];
            $row['Type'] = ['Boolean', 'Integer', 'Float', 'String'][$metric['type']];
            $row['Unit'] = $metric['unit'];
            $row['Representation'] = match ($metric['unit']) { '%' => 'UGNAS.Percent', '°C' => '~Temperature', 'rpm' => 'UGNAS.RPM', 's' => 'UGNAS.Seconds', default => $metric['type'] === 0 ? 'UGNAS.Connection' : 'Standard' };
            $rows[$id] = $row;
        }
        return array_values($rows);
    }

    private function variableSummary(array $rows): string
    {
        $catalog = $this->catalog();
        $selected = count(array_filter($rows, static fn ($r) => !empty($r['Enabled'])));
        $additional = count(array_filter($rows, fn ($r) => !empty($r['Enabled']) && empty($r['Target']) && isset($catalog[$r['Ident']]) && @$this->GetIDForIdent($r['Ident']) === false));
        $detection = empty($this->snapshot()['timestamp'])
            ? ' Geräteabhängige Messwerte sind nach der ersten erfolgreichen Verbindung auswählbar.'
            : ' Erkannte Messwerte sind vollständig auswählbar.';
        return $selected . ' Messwerte ausgewählt, davon ' . $additional . ' neue Variablen. Standard: 0 Variablen.' . $detection . ' Änderungen mit „Übernehmen“ speichern.';
    }

    private function refreshVariableForm(?array $input = null): void
    {
        if ($input === null) {
            $input = json_decode($this->GetBuffer('FormRows'), true);
            $input = is_array($input) ? $input : $this->rows();
        }
        $rows = $this->variableRows($input);
        $this->RememberVariableSelection($rows);
        $this->UpdateFormField('Variables', 'values', json_encode($rows, JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE));
    }

    public function GetConfigurationForm(): string
    {
        $form = json_decode(file_get_contents(__DIR__ . '/form.json'), true, 64, JSON_THROW_ON_ERROR);
        try {
            $rows = $this->variableRows($this->rows());
        } catch (Throwable $e) {
            $rows = $this->variableRows([]);
        }
        $this->SetBuffer('FormRows', json_encode($rows, JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE));
        $form['elements'][1]['items'][1]['values'] = $rows;
        $form['elements'][1]['items'][0]['caption'] = $this->variableSummary($rows);
        $form['actions'][0]['caption'] = $this->ReadAttributeString('Diagnostic');
        return json_encode($form, JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE);
    }
}
