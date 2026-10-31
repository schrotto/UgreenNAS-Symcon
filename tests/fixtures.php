<?php
// Ausschließlich synthetische Testdaten, keine NAS-Aufzeichnung.
function telemetryFixtures(): array
{
    return [
        'machine' => ['common' => ['nas_name' => 'Speicherzentrale', 'model' => 'DXP8800 Plus', 'system_version' => '1.20.0.0142', 'run_time' => 500000]],
        'stats' => ['overview' => ['cpu' => [['used_percent' => 17.4, 'temp' => 49]], 'mem' => [['used_percent' => 38.2]],
            'device_fan' => [['speed' => 820], ['speed' => 840]]],
            'disk' => ['series' => [['name' => 'overview'], ['name' => 'sdb', 'temperature' => 36], ['name' => 'sda', 'temperature' => 34]]]],
        'pools' => ['result' => [['name' => 'pool1', 'label' => 'Datenspeicher', 'level' => 'RAID 6', 'total' => 48000000000000, 'used' => 19000000000000, 'status' => 'normal']]],
        'disks' => ['result' => [
            ['dev_name' => '/dev/sda', 'serial' => 'fixture-a', 'label' => 'Laufwerk 1', 'model' => 'NAS HDD', 'size' => 8000000000000, 'status' => 'normal'],
            ['dev_name' => '/dev/sdb', 'serial' => 'fixture-b', 'label' => 'Laufwerk 2', 'model' => 'NAS HDD', 'size' => 8000000000000, 'status' => 'normal']
        ]]
    ];
}
