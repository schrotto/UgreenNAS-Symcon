<?php
require __DIR__ . '/fixtures.php';
$directory = getenv('UGNAS_TEST_TMP');
$key = file_get_contents($directory . '/key.pem');
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
header('Content-Type: application/json');
$body = json_decode(file_get_contents('php://input'), true) ?: [];
$response = static function (mixed $data, int $code = 200): never {
    echo json_encode(['code' => $code, 'data' => $data]);
    exit;
};
if ($path === '/ugreen/v1/verify/check') {
    $public = openssl_pkey_get_details(openssl_pkey_get_private($key))['key'];
    header('x-rsa-token: ' . base64_encode($public));
    $response([]);
}
if ($path === '/ugreen/v1/verify/login') {
    $cipher = base64_decode($body['password'] ?? '', true);
    if (!$cipher || !openssl_private_decrypt($cipher, $plain, $key, OPENSSL_PKCS1_PADDING) || $plain !== 'test-secret') {
        $response([], 1003);
    }
    $countFile = $directory . '/logins';
    $count = (int) @file_get_contents($countFile) + 1;
    file_put_contents($countFile, (string) $count);
    if (($body['username'] ?? '') === 'twofactor') {
        $response(['enable_otp' => true]);
    }
    $token = $body['username'] . '-' . $count;
    setcookie('token_uid', '1000');
    $response(['token' => $token]);
}
$token = $_GET['token'] ?? '';
if ($token === '' || str_starts_with($token, 'expired') || ($_COOKIE['token_uid'] ?? '') !== '1000') {
    $response([], 1024);
}
if (str_starts_with($token, 'invalidjson')) {
    echo '<html>keine API</html>';
    exit;
}
if ($path === '/ugreen/v1/taskmgr/stat/memory') {
    if (str_starts_with($token, 'ramdetails-')) {
        $response(['structure' => ['free' => '0', 'cache' => 3221225472]]);
    }
    if (str_starts_with($token, 'rampartial-')) {
        $response(['mem' => ['structure' => ['free' => 1, 'cache' => 1610612736]]]);
    }
    http_response_code(404);
    $response([]);
}
if (str_starts_with($token, 'slow-') && in_array($path, ['/ugreen/v1/taskmgr/stat/get_all', '/ugreen/v1/sysinfo/machine/common'], true)) {
    usleep(2200000);
}
if (str_starts_with($token, 'tasktimeout-') && str_starts_with($path, '/ugreen/v1/taskmgr/stat/')) {
    usleep(2200000);
}
$desktopMonitoring = str_starts_with($token, 'desktoponly-') || str_starts_with($token, 'wsbad-')
    || str_starts_with($token, 'ramdetails-');
if ($desktopMonitoring && str_starts_with($path, '/ugreen/v1/taskmgr/stat/')) {
    usleep(2200000);
}
if (preg_match('/^wslive-\d+$/D', $token) && str_starts_with($path, '/ugreen/v1/taskmgr/stat/')) {
    $marker = $directory . '/' . $token;
    if (!is_file($marker)) {
        usleep(2200000);
    }
}
if (preg_match('/^machinefirst-\d+$/D', $token)) {
    $first = $directory . '/' . $token;
    if (!is_file($first)) {
        file_put_contents($first, $path);
    }
    if (str_starts_with($path, '/ugreen/v1/taskmgr/') && file_get_contents($first) !== '/ugreen/v1/sysinfo/machine/common') {
        usleep(2200000);
    }
    if ($path === '/ugreen/v1/taskmgr/stat/net') {
        http_response_code(503);
        $response([]);
    }
}
if (str_starts_with($token, 'allfail-')) {
    http_response_code(503);
    $response([]);
}
if (str_starts_with($token, 'oversize')) {
    echo str_repeat('x', 2200000);
    exit;
}
if (str_starts_with($token, 'partial-') && $path === '/ugreen/v2/storage/disk/list') {
    $response([], 1008);
}
if (str_starts_with($token, 'partial-') && $path === '/ugreen/v1/taskmgr/stat/net') {
    http_response_code(404);
    $response([]);
}
if ($path === '/ugreen/v1/desktop/components/data' && ($_GET['id'] ?? '') === 'desktop.component.DeviceMonitoring') {
    if (str_starts_with($token, 'partial-')) {
        $response([], 1024);
    }
    if ($desktopMonitoring) {
        $response(['cpu_usage_rate' => 0, 'ram_usage_rate' => '35.11', 'upload_speed' => ['raw' => 7436],
            'download_speed' => ['raw' => '3480', 'unit' => 'KB/s', 'value' => 3.4]]);
    }
}
$f = telemetryFixtures();
if ($desktopMonitoring) {
    $f['machine']['hardware']['cpu'][0]['temperature'] = 52;
}
if (str_starts_with($token, 'machinefirst-')) {
    $f['stats']['net'] = $f['network'];
}
if (str_starts_with($token, 'incomplete-') && $path === '/ugreen/v1/taskmgr/stat/get_all') {
    $f['stats']['overview']['cpu'][0] = ['used_percent' => 0];
    unset($f['stats']['overview']['mem']);
    $f['stats']['net']['series'][0] = ['send_rate' => 1200];
}
if (str_starts_with($token, 'rampartial-')) {
    unset($f['stats']['mem']['structure']['cache']);
}
$map = ['/ugreen/v1/sysinfo/machine/common' => 'machine', '/ugreen/v1/taskmgr/stat/get_all' => 'stats',
    '/ugreen/v1/taskmgr/stat/overview' => 'stats', '/ugreen/v1/storage/pool/list' => 'pools', '/ugreen/v2/storage/disk/list' => 'disks',
    '/ugreen/v1/taskmgr/stat/net' => 'network'];
if (!isset($map[$path])) {
    http_response_code(404);
    $response([]);
}
if ($path === '/ugreen/v1/taskmgr/stat/overview') {
    $response($f['stats']['overview']);
}
$response($f[$map[$path]]);
