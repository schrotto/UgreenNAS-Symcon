<?php
declare(strict_types=1);
require __DIR__ . '/../libs/UgosClient.php';
require __DIR__ . '/../libs/Telemetry.php';
require __DIR__ . '/fixtures.php';
$checks = 0;
function check(bool $ok, string $message): void
{
    global $checks;
    if (!$ok) {
        throw new RuntimeException('FEHLER: ' . $message);
    }
    $checks++;
    echo 'OK ' . $message . "\n";
}
function rejects(callable $fn, string $message, ?int $code = null): void
{
    try {
        $fn();
    } catch (Throwable $e) {
        check($code === null || $e->getCode() === $code, $message);
        check(!str_contains($e->getMessage(), 'test-secret'), 'Fehler enthält kein Passwort');
        return;
    }
    throw new RuntimeException('FEHLER: Erwartete Ablehnung: ' . $message);
}
$f = telemetryFixtures();
$s = UgosTelemetry::normalize($f['machine'], $f['stats'], $f['pools'], $f['disks']);
check($s['metrics']['CPU']['value'] === 17.4, 'CPU korrekt');
check($s['disks'][0]['temperature'] === 34.0 && $s['disks'][1]['temperature'] === 36.0, 'Disktemperatur über Identität statt Reihenfolge');
$disks = $f['disks'];
$disks['result'] = array_reverse($disks['result']);
$s2 = UgosTelemetry::normalize($f['machine'], $f['stats'], $f['pools'], $disks);
check($s['disks'][0]['id'] === $s2['disks'][1]['id'], 'Laufwerk-Ident bleibt bei Sortierwechsel stabil');
check(abs($s['pools'][0]['percent'] - 39.5833333) < .0001, 'Pool-Belegung berechnet');
check($f['pools']['result'][0]['used'] === $f['pools']['result'][0]['total'] && $s['pools'][0]['percent'] < 40, '100 Prozent Pool-Zuweisung werden nicht als volle Volumes ausgegeben');
$usage = UgosTelemetry::volumeUsage([['name' => 'a', 'total' => 100, 'used' => 90], ['name' => 'b', 'total' => 900, 'used' => 90]]);
check($usage['percent'] === 18.0, 'Mehrere Volumes werden nach Kapazität gewichtet statt Prozentwerte zu mitteln');
foreach ([null, [], [['total' => 100]], [['total' => 100, 'used' => 101]], [['total' => 0, 'used' => 0]], [['total' => 100, 'used' => 20], ['total' => 100]]] as $volumes) {
    check(UgosTelemetry::volumeUsage($volumes)['percent'] === null, 'Fehlende oder teilweise ungültige Volume-Daten ergeben keine scheinbar gültige Belegung');
}
check(UgosTelemetry::volumeUsage([['total' => 100, 'used' => 0]])['percent'] === 0.0, 'Tatsächlich leeres Volume zeigt null Prozent');
$noVolumes = $f['pools'];
unset($noVolumes['result'][0]['volumes']);
check(UgosTelemetry::normalize($f['machine'], $f['stats'], $noVolumes, $f['disks'])['pools'][0]['percent'] === null, 'Ohne Volume-Daten kein Rückfall auf Pool-Zuweisung');
check(!str_contains(json_encode($s), 'fixture-a'), 'Seriennummer wird nicht an Kachel übertragen');
foreach ([-999, 101, 'oops', true, null] as $bad) {
    check(UgosTelemetry::number($bad, 0, 100) === null, 'Ungültiger Prozentwert bleibt nicht verfügbar');
}
$f['stats']['disk']['series'] = [['temperature' => 50]];
$missing = UgosTelemetry::normalize($f['machine'], $f['stats'], null, $f['disks']);
check($missing['disks'][0]['temperature'] === null, 'Keine Temperatur bei fehlender Gerätezuordnung');
check(UgosTelemetry::normalize([], [], null, null)['metrics']['CPU']['value'] === null, 'Fehlende Daten werden nicht Nullmessungen');
$url = getenv('UGNAS_TEST_URL');
$client = new UgosClient($url, 'monitor', 'test-secret');
check($client->read('/ugreen/v1/sysinfo/machine/common')['common']['model'] === 'DXP8800 Plus', 'HTTP-Login mit tatsächlicher RSA-Entschlüsselung im Testserver');
$session = $client->session();
$reuse = new UgosClient($url, 'monitor', 'test-secret', true, 8, $session);
check(isset($reuse->read('/ugreen/v1/taskmgr/stat/get_all')['overview']), 'Sitzung einschließlich Cookies wiederverwendbar');
$expired = new UgosClient($url, 'monitor', 'test-secret', true, 8, json_encode(['token' => 'expired-session', 'cookies' => ['token_uid' => '1000']]));
check(isset($expired->read('/ugreen/v1/taskmgr/stat/get_all')['overview']), 'Abgelaufene Sitzung wird einmal erneuert');
rejects(fn () => $client->read('/ugreen/v1/power/shutdown'), 'Schreib-/Power-Endpunkt abgelehnt');
rejects(fn () => new UgosClient('http://user:pass@localhost', 'x', 'y'), 'URL mit Zugangsdaten abgelehnt');
rejects(fn () => new UgosClient('http://localhost/evil', 'x', 'y'), 'URL mit Pfad abgelehnt');
rejects(fn () => (new UgosClient($url, 'monitor', 'wrong'))->read('/ugreen/v1/sysinfo/machine/common'), 'Falsches Passwort erkannt', 1003);
rejects(fn () => (new UgosClient($url, 'twofactor', 'test-secret'))->read('/ugreen/v1/sysinfo/machine/common'), '2FA erfordert Freigabe, kein Umgehen', 1003);
rejects(fn () => (new UgosClient($url, 'invalidjson', 'test-secret'))->read('/ugreen/v1/sysinfo/machine/common'), 'HTML statt JSON abgelehnt');
rejects(fn () => (new UgosClient($url, 'oversize', 'test-secret'))->read('/ugreen/v1/sysinfo/machine/common'), 'Antwortlimit durchgesetzt');
echo "Bestanden: $checks Prüfungen\n";
