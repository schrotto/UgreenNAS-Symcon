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
if (str_starts_with($token, 'oversize')) {
    echo str_repeat('x', 2200000);
    exit;
}
$f = telemetryFixtures();
$map = ['/ugreen/v1/sysinfo/machine/common' => 'machine', '/ugreen/v1/taskmgr/stat/get_all' => 'stats',
    '/ugreen/v1/taskmgr/stat/overview' => 'stats', '/ugreen/v1/storage/pool/list' => 'pools', '/ugreen/v2/storage/disk/list' => 'disks'];
if (!isset($map[$path])) {
    http_response_code(404);
    $response([]);
}
$response($f[$map[$path]]);
