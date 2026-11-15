<?php
declare(strict_types=1);

require_once __DIR__ . '/../libs/UgosClient.php';
require_once __DIR__ . '/../libs/Telemetry.php';

class UgreenNAS extends IPSModuleStrict
{
    public function Create(): void
    {
        parent::Create();
        foreach (['Enabled' => false, 'TileEnabled' => true,
            'ShowDisks' => true, 'ShowPools' => true, 'ShowFans' => true, 'ShowControls' => true,
            'Compact' => false] as $key => $default) {
            $this->RegisterPropertyBoolean($key, $default);
        }
        foreach (['Host' => '', 'Username' => '', 'Password' => '',
            'Variables' => '[]', 'TileMetrics' => '[]', 'Theme' => 'auto', 'Title' => 'UGREEN NAS'] as $key => $default) {
            $this->RegisterPropertyString($key, $default);
        }
        foreach (['Port' => 9999, 'Interval' => 60, 'Timeout' => 8, 'Accent' => 0x52D6B0,
            'CPUWarning' => 90, 'TemperatureWarning' => 80, 'StorageWarning' => 85] as $key => $default) {
            $this->RegisterPropertyInteger($key, $default);
        }
        $this->RegisterAttributeString('Snapshot', '{}');
        $this->RegisterAttributeString('Catalog', '{}');
        $this->RegisterAttributeString('Source', '');
        $this->RegisterAttributeString('Diagnostic', 'Noch keine Abfrage durchgeführt.');
        $this->RegisterTimer('Poll', 0, 'UGNAS_Update($_IPS["TARGET"]);');
    }

    public function Migrate(string $JSONData): string
    {
        parent::Migrate($JSONData);
        $data = json_decode($JSONData);
        $config = $data->configuration ?? null;
        if (!is_object($config)) {
            return '';
        }
        $changed = false;
        if (isset($data->attributes->HiddenTileMetrics)) {
            $hidden = json_decode((string) $data->attributes->HiddenTileMetrics, true);
            $existing = json_decode((string) ($config->TileMetrics ?? '[]'), true);
            if (is_array($hidden) && empty($existing)) {
                $selection = [];
                foreach ($hidden as $ident) {
                    if (is_string($ident) && $this->isSelectableTileMetric($ident)) {
                        $selection[$ident] = ['Ident' => $ident, 'Visible' => false];
                    }
                }
                $config->TileMetrics = json_encode(array_values($selection), JSON_THROW_ON_ERROR);
            }
            unset($data->attributes->HiddenTileMetrics);
            $changed = true;
        }
        if (!isset($config->BaseURL)) {
            return $changed ? json_encode($data, JSON_THROW_ON_ERROR) : '';
        }
        $oldUrl = $config->BaseURL;
        if (empty($config->Host) && is_string($oldUrl)) {
            $parts = parse_url($oldUrl);
            if (is_array($parts) && ($parts['scheme'] ?? '') === 'http' && !empty($parts['host'])
                && !isset($parts['user']) && !isset($parts['pass'])
                && !isset($parts['query']) && !isset($parts['fragment'])
                && in_array($parts['path'] ?? '', ['', '/'], true)) {
                $config->Host = trim($parts['host'], '[]');
                // Ohne expliziten Port nutzte die frühere HTTP-Adresse Port 80.
                $config->Port = $parts['port'] ?? 80;
                $username = $config->Username ?? '';
                if (($data->attributes->Source ?? '') === hash('sha256', $oldUrl . '|' . $username)) {
                    $address = $parts['host'] . ':' . $config->Port;
                    $data->attributes->Source = hash('sha256', 'http://' . $address . '|' . $username);
                }
            }
        }
        unset($config->BaseURL, $config->VerifyTLS, $config->CAFile);
        return json_encode($data, JSON_THROW_ON_ERROR);
    }

    private function baseUrl(): string
    {
        $host = trim($this->ReadPropertyString('Host'));
        $port = $this->ReadPropertyInteger('Port');
        if ($port < 1 || $port > 65535) {
            throw new InvalidArgumentException('HTTP-Port muss zwischen 1 und 65535 liegen.');
        }
        $ipv6 = $host;
        if (str_starts_with($host, '[') && str_ends_with($host, ']')) {
            $ipv6 = substr($host, 1, -1);
        }
        if (filter_var($ipv6, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
            $host = '[' . $ipv6 . ']';
        } elseif ($host === '' || !filter_var($host, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME)) {
            throw new InvalidArgumentException('NAS-Adresse als IP-Adresse oder Hostname ohne Protokoll oder Port eingeben.');
        }
        return 'http://' . $host . ':' . $port;
    }

    public function ApplyChanges(): void
    {
        parent::ApplyChanges();
        $this->SetTimerInterval('Poll', 0);
        $this->SetVisualizationType($this->ReadPropertyBoolean('TileEnabled') ? 1 : 0);
        // Konfigurationsänderungen erhalten keine alte Sitzung.
        $this->SetBuffer('Session', '');
        $this->SetBuffer('NextAllowed', '0');
        $this->SetBuffer('LastAttempt', '0');
        $this->SetBuffer('Failures', '0');
        $this->SetBuffer('AuthBlocked', '');
        $this->SetBuffer('Current', '0');
        $this->SetBuffer('FormRows', '');
        $this->SetBuffer('TileFormRows', '');
        try {
            $source = '';
            if ($this->ReadPropertyString('Host') !== '' || $this->ReadPropertyBoolean('Enabled')) {
                $source = hash('sha256', $this->baseUrl() . '|' . $this->ReadPropertyString('Username'));
            }
            if ($source !== $this->ReadAttributeString('Source')) {
                $this->WriteAttributeString('Snapshot', '{}');
                $this->WriteAttributeString('Catalog', '{}');
                $this->WriteAttributeString('Source', $source);
            }
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

    private function client(bool $freshLogin = false): UgosClient
    {
        return new UgosClient($this->baseUrl(), $this->ReadPropertyString('Username'),
            $this->ReadPropertyString('Password'),
            $this->ReadPropertyInteger('Timeout'), $freshLogin ? '' : $this->GetBuffer('Session'));
    }

    public function Update(): bool
    {
        return $this->poll(false)[0];
    }

    public function TestConnection(): string
    {
        return $this->poll(true)[1];
    }

    private function poll(bool $testLogin): array
    {
        if (!$this->ReadPropertyBoolean('Enabled')) {
            return [false, 'Überwachung aktivieren und Einstellungen übernehmen.'];
        }
        if (!IPS_SemaphoreEnter('UGNAS_' . $this->InstanceID, 1)) {
            return [false, 'Eine NAS-Abfrage läuft bereits.'];
        }
        $client = null;
        try {
            // Ein manueller Test kann nach einem Transportfehler früher erneut prüfen.
            $waitUntil = (int) $this->GetBuffer('LastAttempt') + 5;
            if (!$testLogin || $this->GetBuffer('AuthBlocked') === '1') {
                $waitUntil = max($waitUntil, (int) $this->GetBuffer('NextAllowed'));
            }
            $wait = $waitUntil - time();
            if ($wait > 0) {
                return [false, 'Nächster Test in ' . $wait . ' s. Letzte Diagnose: ' . $this->ReadAttributeString('Diagnostic')];
            }
            $this->SetBuffer('LastAttempt', (string) time());
            $this->validateConfiguration();
            $client = $this->client($testLogin);
            $warnings = [];
            $machine = $this->readSection($client, '/ugreen/v1/sysinfo/machine/common', 'Geräteinformationen', $warnings,
                static fn (array $data): bool => is_array($data['common'] ?? null));
            $stats = $this->readSection($client, '/ugreen/v1/taskmgr/stat/get_all', 'Ressourcenstatistik', $warnings,
                static fn (array $data): bool => is_array($data['overview'] ?? null) || is_array($data['cpu'] ?? null)
                    || is_array($data['mem'] ?? null) || is_array($data['disk']['series'] ?? null)
                    || is_array($data['net']['series'] ?? null));
            $validList = static fn (array $data): bool => is_array($data['result'] ?? null);
            $pools = $this->readSection($client, '/ugreen/v1/storage/pool/list', 'Speicherpools', $warnings, $validList);
            $disks = $this->readSection($client, '/ugreen/v2/storage/disk/list', 'Laufwerke', $warnings, $validList);
            $stats = $this->completeResourceOverview($client, $stats, $warnings);
            $network = null;
            $combinedRates = UgosTelemetry::lanRates($stats['net'] ?? null);
            if (in_array(null, $combinedRates, true)) {
                $network = $this->readSection($client, '/ugreen/v1/taskmgr/stat/net', 'LAN-Statistik', $warnings,
                    static fn (array $data): bool => UgosTelemetry::lanRates($data) !== [null, null]);
            }
            if ($machine === null && $stats === null && $pools === null && $disks === null && $network === null) {
                throw new UgosApiException(implode(' ', $warnings));
            }
            $snapshot = UgosTelemetry::normalize($machine ?? [], $stats ?? [], $pools, $disks, $network);
            $unavailable = [];
            foreach (['CPU', 'RAM', 'CPUTemperature', 'RAMFree', 'RAMCache', 'LANSend', 'LANReceive'] as $ident) {
                $metric = $snapshot['metrics'][$ident];
                if ($metric['value'] === null) {
                    $unavailable[] = $metric['name'];
                }
            }
            if ($unavailable !== []) {
                $warnings[] = 'Ohne aktuellen Messwert: ' . implode(', ', $unavailable) . '.';
            }
            if ($machine === null) {
                $previous = $this->snapshot();
                foreach (['name', 'model', 'version'] as $key) {
                    $snapshot[$key] = $previous[$key] ?? '';
                }
            }
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
                $this->refreshTileMetricForm();
            }
            $this->SetBuffer('Session', $client->session());
            $this->SetBuffer('Failures', '0');
            $this->SetBuffer('AuthBlocked', '');
            $this->SetBuffer('NextAllowed', '0');
            $this->SetBuffer('Current', '1');
            $this->SetStatus(102);
            $this->writeValues($snapshot['metrics']);
            $count = count(array_filter($snapshot['metrics'], static fn ($metric) => $metric['value'] !== null));
            $report = $client->connectionSummary() . ' Datenabruf: ' . $count . ' Messwerte, ';
            $report .= count($snapshot['pools']) . ' Speicherpools und ' . count($snapshot['disks']) . ' Laufwerke erkannt.';
            if ($warnings !== []) {
                $report .= ' Teilabruf: ' . implode(' ', $warnings);
            }
            $this->WriteAttributeString('Diagnostic', $report);
            return [true, $report];
        } catch (Throwable $e) {
            $authFailed = $e instanceof UgosApiException && in_array($e->getCode(), [1003, 401, 1024], true);
            $this->SetBuffer('Session', !$authFailed && $client?->hasSession() ? $client->session() : '');
            $this->SetBuffer('AuthBlocked', $authFailed ? '1' : '');
            $failures = min(6, (int) $this->GetBuffer('Failures') + 1);
            $this->SetBuffer('Failures', (string) $failures);
            $delay = $authFailed ? 900 : min(60, 5 * (2 ** $failures));
            $this->SetBuffer('NextAllowed', (string) (time() + $delay));
            $this->SetStatus(202);
            $report = $e->getMessage();
            if ($client !== null) {
                $report = $client->connectionSummary() . ' ' . $report;
            }
            $report .= ' Nächster automatischer Versuch in ' . $delay . ' s.';
            $this->WriteAttributeString('Diagnostic', $report);
            $this->markOffline();
            return [false, $report];
        } finally {
            $retryAt = (int) $this->GetBuffer('NextAllowed');
            $seconds = $retryAt > 0 ? max(1, $retryAt - time()) : max(15, $this->ReadPropertyInteger('Interval'));
            $this->SetTimerInterval('Poll', $seconds * 1000);
            IPS_SemaphoreLeave('UGNAS_' . $this->InstanceID);
            $this->publish();
        }
    }

    private function completeResourceOverview(UgosClient $client, ?array $stats, array &$warnings): ?array
    {
        $overview = is_array($stats['overview'] ?? null) ? $stats['overview'] : ($stats ?? []);
        if (UgosTelemetry::number($overview['cpu'][0]['used_percent'] ?? null, 0, 100) !== null
            && UgosTelemetry::number($overview['mem'][0]['used_percent'] ?? null, 0, 100) !== null
            && UgosTelemetry::number($overview['cpu'][0]['temp'] ?? null, -20, 130) !== null) {
            return $stats;
        }
        $fallback = $this->readSection($client, '/ugreen/v1/taskmgr/stat/overview', 'Ressourcenübersicht', $warnings,
            static fn (array $data): bool => is_array($data['cpu'] ?? null) || is_array($data['mem'] ?? null));
        if ($fallback === null) {
            return $stats;
        }
        // Detaildaten aus get_all behalten und nur fehlende Werte aus der Übersicht ergänzen.
        $result = array_replace($fallback, $overview);
        foreach ([['cpu', 'used_percent', 0, 100], ['cpu', 'temp', -20, 130], ['mem', 'used_percent', 0, 100]] as [$key, $field, $min, $max]) {
            $value = UgosTelemetry::number($fallback[$key][0][$field] ?? null, $min, $max);
            if ($value !== null && UgosTelemetry::number($result[$key][0][$field] ?? null, $min, $max) === null) {
                $entry = $result[$key][0] ?? null;
                $result[$key] = is_array($result[$key] ?? null) ? $result[$key] : [];
                $result[$key][0] = is_array($entry) ? $entry : [];
                $result[$key][0][$field] = $value;
            }
        }
        $stats['overview'] = $result;
        return $stats;
    }

    private function readSection(UgosClient $client, string $path, string $label, array &$warnings, callable $valid): ?array
    {
        try {
            $data = $client->read($path);
            if (!$valid($data)) {
                throw new UgosApiException('Unbekanntes Datenformat.');
            }
            return $data;
        } catch (UgosApiException $e) {
            $message = $label . ': ' . $e->getMessage();
            if (!$client->hasSession() || in_array($e->getCode(), [1003, 401, 1024], true)) {
                throw new UgosApiException($message, $e->getCode(), $e);
            }
            $warnings[] = $message;
            return null;
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
        foreach (IPS_GetChildrenIDs($this->InstanceID) as $variableId) {
            if (IPS_VariableExists($variableId)) {
                $this->migratePresentation($variableId, $catalog);
            }
        }
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
            $this->registerMetric($id, $name, $position, $m);
        }
    }

    private function registerMetric(string $id, string $name, int $position, array $metric): void
    {
        $presentation = $this->presentation($metric);
        match ($metric['type']) {
            0 => $this->RegisterVariableBoolean($id, $name, $presentation, $position),
            1 => $this->RegisterVariableInteger($id, $name, $presentation, $position),
            2 => $this->RegisterVariableFloat($id, $name, $presentation, $position),
            3 => $this->RegisterVariableString($id, $name, $presentation, $position)
        };
    }

    private function migratePresentation(int $variableId, array $catalog): void
    {
        $object = IPS_GetObject($variableId);
        $id = $object['ObjectIdent'];
        $metric = $catalog[$id] ?? null;
        if ($metric === null && preg_match('/^(?:Fan_(?:cpu_fan|device_fan)_\d+|(?:Pool|Disk|Volume)_[a-f0-9]{16}_(?:Used|Status|Temperature|PowerOnHours|Available))$/D', $id)) {
            // Die Variable kann nach einem Datenquellenwechsel noch vorhanden sein.
            [$type, $unit] = match (true) {
                str_starts_with($id, 'Fan_') => [1, 'rpm'],
                str_ends_with($id, '_Temperature') => [2, '°C'],
                str_ends_with($id, '_Used') => [2, '%'],
                str_ends_with($id, '_Available') => [2, 'B'],
                str_ends_with($id, '_PowerOnHours') => [1, 'h'],
                default => [3, '']
            };
            $metric = ['type' => $type, 'unit' => $unit];
        }
        $variable = IPS_GetVariable($variableId);
        if ($metric === null || $variable['VariableType'] !== $metric['type']) {
            return;
        }
        // Modulvorgabe ersetzen und vorhandene native Benutzerdarstellungen erhalten.
        $this->registerMetric($id, $object['ObjectName'], $object['ObjectPosition'], $metric);
        if ((IPS_GetVariablePresentation($variableId)['PRESENTATION'] ?? '') === VARIABLE_PRESENTATION_LEGACY) {
            IPS_SetVariableCustomPresentation($variableId, []);
        }
    }

    private function presentation(array $metric): array
    {
        $display = ['PRESENTATION' => VARIABLE_PRESENTATION_VALUE_PRESENTATION];
        if ($metric['type'] === 0) {
            $display['OPTIONS'] = json_encode([
                ['Value' => false, 'Caption' => 'Offline', 'ColorActive' => true, 'ColorValue' => 0xD97B69],
                ['Value' => true, 'Caption' => 'Online', 'ColorActive' => true, 'ColorValue' => 0x52D6B0]
            ], JSON_THROW_ON_ERROR);
        }
        $unit = $metric['unit'];
        if ($unit === '') {
            return $display;
        }
        $display['SUFFIX'] = ' ' . $unit;
        if ($metric['type'] === 2) {
            $display['DIGITS'] = 1;
        }
        if ($unit === '%') {
            $display += ['PERCENTAGE' => true, 'MIN' => 0, 'MAX' => 100];
        }
        if ($unit === '°C') {
            $display['USAGE_TYPE'] = 1;
        }
        if ($unit === 'B' || $unit === 'B/s') {
            $intervals = [];
            foreach (['k', 'M', 'G', 'T', 'P'] as $index => $prefix) {
                $factor = 1000 ** ($index + 1);
                // Endgrenze direkt unter der nächsten Einheit, auch für Fließkommawerte.
                $upper = $factor * 1000 * (1.0 - PHP_FLOAT_EPSILON / 2);
                $intervals[] = [
                    'IntervalMinValue' => $index === 0 ? 0 : $factor,
                    'IntervalMaxValue' => $upper,
                    'ConversionFactor' => $factor,
                    'SuffixActive' => true,
                    'SuffixValue' => ' ' . $prefix . $unit,
                    'DigitsActive' => true,
                    'DigitsValue' => $index === 0 ? 3 : 2,
                    'ConstantActive' => false,
                    'PrefixActive' => false
                ];
            }
            $display['INTERVALS_ACTIVE'] = true;
            $display['INTERVALS'] = json_encode($intervals, JSON_THROW_ON_ERROR);
        }
        return $display;
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
        $catalog = $this->catalog();
        $extraMetrics = [];
        foreach ($catalog as $ident => $metric) {
            if (UgosTelemetry::isAdditionalMetric($ident)) {
                $metric['value'] = $s['metrics'][$ident]['value'] ?? null;
                $extraMetrics[] = $metric;
            }
        }
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
            'extraMetrics' => $extraMetrics, 'hiddenTileMetrics' => $this->hiddenTileMetrics(),
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

    private function hiddenTileMetrics(): array
    {
        return array_keys(array_filter($this->tileSelection(), static fn (bool $visible): bool => !$visible));
    }

    private function isSelectableTileMetric(string $ident): bool
    {
        return in_array($ident, UgosTelemetry::PRIMARY_METRIC_IDS, true) || UgosTelemetry::isAdditionalMetric($ident);
    }

    private function tileSelection(?array $rows = null): array
    {
        $rows ??= json_decode($this->ReadPropertyString('TileMetrics'), true) ?: [];
        $selection = [];
        foreach ($rows as $row) {
            if (is_string($row['Ident'] ?? null) && is_bool($row['Visible'] ?? null)
                && $this->isSelectableTileMetric($row['Ident'])) {
                $selection[$row['Ident']] = $row['Visible'];
            }
        }
        return $selection;
    }

    public function RememberTileSelection(array $Rows): void
    {
        $this->SetBuffer('TileFormRows', json_encode($Rows, JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE));
    }

    private function tileMetricRows(?array $draft = null): array
    {
        $selection = $this->tileSelection($draft);
        $rows = [];
        foreach ($this->catalog() as $ident => $metric) {
            if ($this->isSelectableTileMetric($ident)) {
                $rows[] = ['Ident' => $ident, 'Name' => $metric['name'], 'Visible' => $selection[$ident] ?? true];
            }
        }
        return $rows;
    }

    private function refreshTileMetricForm(): void
    {
        $draft = json_decode($this->GetBuffer('TileFormRows'), true);
        $rows = $this->tileMetricRows(is_array($draft) ? $draft : null);
        $this->RememberTileSelection($rows);
        $this->UpdateFormField('TileMetrics', 'values', json_encode($rows, JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE));
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
            $row['Unit'] = match ($metric['unit']) {
                'B' => 'kB, MB, GB, …',
                'B/s' => 'kB/s, MB/s, …',
                default => $metric['unit']
            };
            $row['Representation'] = 'Wertdarstellung';
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
        $tileRows = $this->tileMetricRows();
        $this->RememberTileSelection($tileRows);
        foreach ($form['elements'][2]['items'] as &$item) {
            if (($item['name'] ?? '') === 'TileMetrics') {
                $item['values'] = $tileRows;
                break;
            }
        }
        $form['actions'][0]['caption'] = $this->ReadAttributeString('Diagnostic');
        return json_encode($form, JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE);
    }
}
