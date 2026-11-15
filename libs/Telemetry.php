<?php
declare(strict_types=1);

final class UgosTelemetry
{
    public const PRIMARY_METRIC_IDS = ['CPU', 'RAM', 'CPUTemperature'];

    public static function number(mixed $value, float $min = 0, float $max = PHP_FLOAT_MAX): ?float
    {
        if (is_bool($value) || !is_numeric($value)) {
            return null;
        }
        $n = (float) $value;
        return is_finite($n) && $n >= $min && $n <= $max ? $n : null;
    }

    public static function text(mixed $value): string
    {
        return is_scalar($value) && !is_bool($value) ? mb_substr((string) $value, 0, 160) : '';
    }

    public static function isAdditionalMetric(string $ident): bool
    {
        return in_array($ident, ['LANSend', 'LANReceive', 'RAMFree', 'RAMCache'], true)
            || preg_match('/^(?:Volume_[a-f0-9]{16}_Available|Disk_[a-f0-9]{16}_PowerOnHours)$/D', $ident) === 1;
    }

    /** Pool-used ist Volume-Zuweisung, nicht die Dateisystembelegung. */
    public static function volumeUsage(mixed $volumes): array
    {
        if (!is_array($volumes) || $volumes === []) {
            return ['total' => null, 'used' => null, 'percent' => null];
        }
        $total = 0.0;
        $used = 0.0;
        $seen = [];
        foreach ($volumes as $volume) {
            if (!is_array($volume)) {
                return ['total' => null, 'used' => null, 'percent' => null];
            }
            $capacity = self::number($volume['total'] ?? null);
            $occupied = self::number($volume['used'] ?? null);
            $name = self::text($volume['name'] ?? '');
            if ($capacity === null || $capacity <= 0 || $occupied === null || $occupied > $capacity
                || ($name !== '' && isset($seen[$name]))) {
                return ['total' => null, 'used' => null, 'percent' => null];
            }
            $seen[$name] = true;
            $total += $capacity;
            $used += $occupied;
        }
        return ['total' => $total, 'used' => $used, 'percent' => 100 * $used / $total];
    }

    public static function lanRates(mixed $network): array
    {
        $summary = $network['series'][0] ?? null;
        return [self::number($summary['send_rate'] ?? null), self::number($summary['recv_rate'] ?? null)];
    }

    public static function desktopLanRates(array $desktop): array
    {
        return [self::number($desktop['upload_speed']['raw'] ?? null), self::number($desktop['download_speed']['raw'] ?? null)];
    }

    public static function resourceUsage(array $stats): array
    {
        $overview = $stats['overview'] ?? $stats;
        return [
            self::number($overview['cpu'][0]['used_percent'] ?? null, 0, 100),
            self::number($overview['mem'][0]['used_percent'] ?? null, 0, 100)
        ];
    }

    public static function normalize(array $machine, array $stats, ?array $pools, ?array $disks, ?array $network = null,
        array $desktop = []): array
    {
        $overview = $stats['overview'] ?? $stats;
        $common = $machine['common'] ?? [];
        $metrics = [];
        $add = static function (string $id, string $name, int $type, string $unit, mixed $value, string $group = 'system') use (&$metrics): void {
            $metrics[$id] = compact('id', 'name', 'type', 'unit', 'value', 'group');
        };
        $add('Online', 'Verbindung', 0, '', true);
        [$cpu, $ram] = self::resourceUsage($stats);
        $add('CPU', 'CPU-Auslastung', 2, '%', $cpu ?? self::number($desktop['cpu_usage_rate'] ?? null, 0, 100));
        $add('RAM', 'RAM-Auslastung', 2, '%', $ram ?? self::number($desktop['ram_usage_rate'] ?? null, 0, 100));
        $add('CPUTemperature', 'CPU-Temperatur', 2, '°C', self::number($overview['cpu'][0]['temp'] ?? null, -20, 130)
            ?? self::number($machine['hardware']['cpu'][0]['temperature'] ?? null, 1, 130));
        $uptime = self::number($common['run_time'] ?? null, 0, 315576000);
        $add('Uptime', 'Betriebszeit', 1, 's', $uptime === null ? null : (int) $uptime);
        [$send, $receive] = self::lanRates($stats['net'] ?? null);
        [$fallbackSend, $fallbackReceive] = self::lanRates($network);
        [$desktopSend, $desktopReceive] = self::desktopLanRates($desktop);
        $add('LANSend', 'LAN senden (gesamt)', 2, 'B/s', $send ?? $fallbackSend ?? $desktopSend);
        $add('LANReceive', 'LAN empfangen (gesamt)', 2, 'B/s', $receive ?? $fallbackReceive ?? $desktopReceive);
        $add('RAMFree', 'Freier RAM', 2, 'B', self::number($stats['mem']['structure']['free'] ?? null));
        $add('RAMCache', 'RAM-Cache', 2, 'B', self::number($stats['mem']['structure']['cache'] ?? null));
        foreach (['cpu_fan' => 'CPU-Lüfter', 'device_fan' => 'Gehäuselüfter'] as $kind => $label) {
            foreach (array_slice(is_array($overview[$kind] ?? null) ? $overview[$kind] : [], 0, 16) as $i => $fan) {
                $id = 'Fan_' . $kind . '_' . $i;
                $value = self::number($fan['speed'] ?? null, 0, 40000);
                $add($id, $label . ' ' . ($i + 1), 1, 'rpm', $value === null ? null : (int) $value, 'fan');
            }
        }
        $poolCards = [];
        $volumeCount = 0;
        foreach (array_slice($pools['result'] ?? [], 0, 32) as $pool) {
            if (!is_array($pool) || self::text($pool['name'] ?? '') === '') {
                continue;
            }
            $id = 'Pool_' . substr(hash('sha256', (string) $pool['name']), 0, 16);
            $name = self::text($pool['label'] ?? '') ?: self::text($pool['name']);
            ['total' => $total, 'used' => $used, 'percent' => $percent] = self::volumeUsage($pool['volumes'] ?? null);
            $status = self::text($pool['status'] ?? null);
            $add($id . '_Used', $name . ' – Volume-Belegung', 2, '%', $percent, 'pool');
            $add($id . '_Status', $name . ' – UGOS-Status', 3, '', $status === '' ? null : $status, 'pool');
            $poolCards[] = ['id' => $id, 'name' => $name, 'total' => $total, 'used' => $used,
                'percent' => $percent, 'status' => $status, 'raid' => self::text($pool['level'] ?? '')];
            $volumes = $pool['volumes'] ?? [];
            if (!is_array($volumes)) {
                continue;
            }
            foreach ($volumes as $volume) {
                if ($volumeCount >= 256) {
                    break;
                }
                if (!is_array($volume)) {
                    continue;
                }
                $volumeName = self::text($volume['name'] ?? '');
                $available = self::number($volume['available'] ?? null);
                $capacity = self::number($volume['total'] ?? null);
                if ($volumeName === '' || $available === null || ($capacity !== null && $available > $capacity)) {
                    continue;
                }
                $volumeId = 'Volume_' . substr(hash('sha256', json_encode([$id, $volumeName], JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR)), 0, 16) . '_Available';
                if (isset($metrics[$volumeId])) {
                    continue;
                }
                $volumeLabel = self::text($volume['label'] ?? '') ?: $volumeName;
                $add($volumeId, $name . ' / ' . $volumeLabel . ' – Verfügbarer Speicher', 2, 'B', $available, 'volume');
                $volumeCount++;
            }
        }
        $diskCards = [];
        // Temperaturzuordnung nur über Geräteidentität, niemals über Reihenfolge.
        $temperatures = [];
        foreach (($stats['disk']['series'] ?? []) as $entry) {
            $device = self::text($entry['dev_name'] ?? $entry['name'] ?? '');
            if ($device !== '' && $device !== 'overview') {
                $temperatures[basename($device)] = self::number($entry['temperature'] ?? null, -20, 130);
            }
        }
        foreach (array_slice($disks['result'] ?? [], 0, 32) as $disk) {
            if (!is_array($disk)) {
                continue;
            }
            $device = self::text($disk['dev_name'] ?? $disk['name'] ?? '');
            $identity = self::text($disk['serial'] ?? '') ?: $device;
            if ($identity === '') {
                continue;
            }
            $id = 'Disk_' . substr(hash('sha256', $identity), 0, 16);
            $name = self::text($disk['label'] ?? '') ?: ($device ?: 'Laufwerk');
            $temperature = $temperatures[basename($device)] ?? null;
            $status = self::text($disk['status'] ?? null);
            $add($id . '_Temperature', $name . ' – Temperatur', 2, '°C', $temperature, 'disk');
            $add($id . '_Status', $name . ' – UGOS-Status', 3, '', $status === '' ? null : $status, 'disk');
            $hours = self::number($disk['power_on_hours'] ?? null, 0, 2147483647);
            if ($hours !== null && floor($hours) === $hours) {
                $add($id . '_PowerOnHours', $name . ' – Betriebsstunden', 1, 'h', (int) $hours, 'disk');
            }
            $diskCards[] = ['id' => $id, 'name' => $name, 'model' => self::text($disk['model'] ?? ''),
                'size' => self::number($disk['size'] ?? null), 'temperature' => $temperature, 'status' => $status];
        }
        return ['name' => self::text($common['nas_name'] ?? ''), 'model' => self::text($common['model'] ?? ''),
            'version' => self::text($common['system_version'] ?? ''), 'metrics' => $metrics,
            'pools' => $poolCards, 'disks' => $diskCards];
    }
}
