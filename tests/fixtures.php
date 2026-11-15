<?php
// Ausschließlich synthetische Testdaten, keine NAS-Aufzeichnung.
function telemetryFixtures(): array
{
    return [
        'machine' => ['common' => ['nas_name' => 'Speicherzentrale', 'model' => 'DXP8800 Plus', 'system_version' => '1.20.0.0142', 'run_time' => 500000],
            'hardware' => ['cpu' => [['temperature' => 52]]]],
        'desktop' => ['cpu_usage_rate' => 17.4, 'ram_usage_rate' => '38.2', 'upload_speed' => ['raw' => 12500.5], 'download_speed' => ['raw' => '0']],
        'thermal' => ['cpu_temperature' => 49, 'fan_list' => [['speed' => 820], ['speed' => 840]]],
        'pools' => ['result' => [['name' => 'pool1', 'label' => 'Datenspeicher', 'level' => 'RAID 6', 'total' => 48000000000000, 'used' => 48000000000000, 'status' => 'normal',
            'volumes' => [['name' => 'volume1', 'total' => 48000000000000, 'used' => 19000000000000, 'available' => 28000000000000]]]]],
        'disks' => ['result' => [
            ['dev_name' => '/dev/sda', 'serial' => 'fixture-a', 'label' => 'Laufwerk 1', 'model' => 'NAS HDD', 'size' => 8000000000000, 'status' => 'normal', 'temperature' => 34, 'power_on_hours' => '8760'],
            ['dev_name' => '/dev/sdb', 'serial' => 'fixture-b', 'label' => 'Laufwerk 2', 'model' => 'NAS HDD', 'size' => 8000000000000, 'status' => 'normal', 'temperature' => 36, 'power_on_hours' => 0]
        ]]
    ];
}
