<?php
declare(strict_types=1);

final class UgosResourceSubscription
{
    /**
     * Öffnet das UGOS-Live-Abonnement auf dem konfigurierten HTTP-Port.
     * @return resource|null Die Verbindung bleibt bis zum Ende der Abfrage offen.
     */
    public static function open(string $baseUrl, string $peer, string $token, string $cookies, float $deadline)
    {
        $host = parse_url($baseUrl, PHP_URL_HOST);
        if (!is_string($host) || preg_match('/[\x00-\x20\x7f]/', $host)
            || $token === '' || !filter_var($peer, FILTER_VALIDATE_IP) || $deadline <= microtime(true)) {
            return null;
        }
        $port = parse_url($baseUrl, PHP_URL_PORT) ?? 80;
        $address = str_contains($peer, ':') ? '[' . $peer . ']' : $peer;
        $timeout = max(0.001, $deadline - microtime(true));
        $socket = @stream_socket_client('tcp://' . $address . ':' . $port, $errno, $error, $timeout);
        if ($socket === false) {
            return null;
        }
        $subscribed = false;
        try {
            stream_set_blocking($socket, false);
            $random = random_bytes(16);
            $random[6] = chr((ord($random[6]) & 0x0F) | 0x40);
            $random[8] = chr((ord($random[8]) & 0x3F) | 0x80);
            $clientId = vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($random), 4)) . '-WEB';
            $key = base64_encode(random_bytes(16));
            $query = http_build_query(['client_id' => $clientId, 'lang' => 'de-DE', 'token' => $token], '', '&', PHP_QUERY_RFC3986);
            $request = "GET /ugreen/v1/desktop/ws?$query HTTP/1.1\r\n"
                . "Host: $host:$port\r\nOrigin: $baseUrl\r\nConnection: Upgrade\r\nUpgrade: websocket\r\n"
                . "Sec-WebSocket-Version: 13\r\nSec-WebSocket-Key: $key\r\nPragma: no-cache\r\nCache-Control: no-cache\r\n";
            if ($cookies !== '') {
                $request .= "Cookie: $cookies\r\n";
            }
            if (!self::write($socket, $request . "\r\n", $deadline) || !self::accepted($socket, $key, $deadline)) {
                return null;
            }
            $payload = (string) json_encode(['op' => 'subscribe', 'topics' => ['cpu_usage', 'cpu_temp'],
                'ts' => (int) (microtime(true) * 1000)], JSON_THROW_ON_ERROR);
            $mask = random_bytes(4);
            $masked = $payload ^ str_repeat($mask, (int) ceil(strlen($payload) / 4));
            $subscribed = self::write($socket, "\x81" . chr(0x80 | strlen($payload)) . $mask . $masked, $deadline);
            return $subscribed ? $socket : null;
        } finally {
            if (!$subscribed) {
                fclose($socket);
            }
        }
    }

    private static function accepted($socket, string $key, float $deadline): bool
    {
        $response = '';
        while (($end = strpos($response, "\r\n\r\n")) === false) {
            if (strlen($response) >= 8192 || !self::ready($socket, $deadline, false)) {
                return false;
            }
            $part = @fread($socket, 1024);
            if ($part === false || ($part === '' && feof($socket))) {
                return false;
            }
            $response .= $part;
        }
        $lines = explode("\r\n", substr($response, 0, $end));
        if (!preg_match('/^HTTP\/1\.[01] 101(?: |$)/', array_shift($lines))) {
            return false;
        }
        $headers = [];
        foreach ($lines as $line) {
            if (str_contains($line, ':')) {
                [$name, $value] = explode(':', $line, 2);
                $headers[strtolower(trim($name))] = trim($value);
            }
        }
        $accept = base64_encode(sha1($key . '258EAFA5-E914-47DA-95CA-C5AB0DC85B11', true));
        $connection = preg_split('/\s*,\s*/', strtolower($headers['connection'] ?? ''));
        return strtolower($headers['upgrade'] ?? '') === 'websocket' && in_array('upgrade', $connection, true)
            && hash_equals($accept, $headers['sec-websocket-accept'] ?? '');
    }

    private static function write($socket, string $data, float $deadline): bool
    {
        while ($data !== '' && self::ready($socket, $deadline, true)) {
            $written = @fwrite($socket, $data);
            if ($written === false || feof($socket)) {
                return false;
            }
            $data = substr($data, $written);
        }
        return $data === '';
    }

    private static function ready($socket, float $deadline, bool $writing): bool
    {
        $remaining = (int) (($deadline - microtime(true)) * 1000000);
        if ($remaining < 1) {
            return false;
        }
        $read = $writing ? [] : [$socket];
        $write = $writing ? [$socket] : [];
        $except = null;
        return @stream_select($read, $write, $except, intdiv($remaining, 1000000), $remaining % 1000000) === 1;
    }
}
