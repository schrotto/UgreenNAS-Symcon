<?php
require __DIR__ . '/fixtures.php';
require __DIR__ . '/../libs/Telemetry.php';
$f = telemetryFixtures();
$s = UgosTelemetry::normalize($f['machine'], $f['stats'], $f['pools'], $f['disks']);
$s['timestamp'] = 1793458800;
$s['warnings'] = [];
$p = ['enabled' => true, 'fresh' => true, 'data' => $s, 'alerts' => [], 'diagnostic' => 'Designvorschau mit synthetischen Testdaten. Keine Verbindung zu einer NAS.', 'now' => $s['timestamp'], 'staleAfter' => 120, 'nextAllowed' => 0,
    'options' => ['title' => 'Speicherzentrale', 'tileEnabled' => true, 'theme' => 'dark', 'accent' => '#52d6b0', 'compact' => false, 'disks' => true, 'pools' => true, 'fans' => true, 'controls' => true],
    'thresholds' => ['cpu' => 90, 'temperature' => 80, 'storage' => 85]];
$json = json_encode($p, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
file_put_contents(__DIR__ . '/preview-payload.json', $json);
$html = file_get_contents(__DIR__ . '/../UgreenNAS/module.html');
$html = str_replace('<body>', '<body><p style="padding:8px 24px;font:11px system-ui;color:var(--muted)">DESIGNVORSCHAU · SYNTHETISCHE TESTDATEN</p>', $html);
file_put_contents(__DIR__ . '/../docs/preview.html', $html . '<script>handleMessage(' . json_encode($json, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) . ');</script>');
