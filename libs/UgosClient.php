<?php
declare(strict_types=1);

require_once __DIR__ . '/UgosResourceSubscription.php';

final class UgosApiException extends RuntimeException {}

/** Nur die hier fest hinterlegten Lese-Endpunkte dürfen aufgerufen werden. */
final class UgosClient
{
    private string $token;
    private array $cookies = [];
    private float $deadline;
    private bool $httpResponded = false;
    private bool $authenticated = false;
    private bool $loginStarted = false;
    private string $peerAddress = '';
    private const DESKTOP_MONITORING = '/ugreen/v1/desktop/components/data?id=desktop.component.DeviceMonitoring';
    private const READ_PATHS = [
        '/ugreen/v1/sysinfo/machine/common',
        '/ugreen/v1/taskmgr/stat/get_all',
        '/ugreen/v1/taskmgr/stat/overview',
        '/ugreen/v1/taskmgr/stat/net',
        '/ugreen/v1/storage/pool/list',
        '/ugreen/v2/storage/disk/list'
    ];

    public function __construct(
        private string $baseUrl,
        private string $username,
        private string $password,
        private int $timeout = 8,
        string $session = ''
    ) {
        $parts = parse_url($baseUrl);
        if (!$parts || ($parts['scheme'] ?? '') !== 'http'
            || empty($parts['host']) || isset($parts['user']) || isset($parts['pass'])
            || isset($parts['query']) || isset($parts['fragment'])
            || !in_array($parts['path'] ?? '', ['', '/'], true)) {
            throw new UgosApiException('NAS-Adresse muss http://Host:Port ohne Pfad oder Zugangsdaten sein. Diese Version verwendet ausschließlich HTTP.');
        }
        $this->baseUrl = rtrim($baseUrl, '/');
        $this->timeout = max(2, min(30, $timeout));
        // Ein Polling-Durchlauf einschließlich Login bleibt zeitlich begrenzt.
        $this->deadline = microtime(true) + min(55, $this->timeout * 6);
        $saved = json_decode($session, true);
        $this->token = is_string($saved['token'] ?? null) ? $saved['token'] : '';
        foreach (($saved['cookies'] ?? []) as $name => $value) {
            if (is_string($name) && is_string($value) && preg_match('/^[a-zA-Z0-9_]+$/D', $name)
                && !preg_match('/[\r\n;]/', $value)) {
                $this->cookies[$name] = $value;
            }
        }
    }

    public function session(): string
    {
        return json_encode(['token' => $this->token, 'cookies' => $this->cookies], JSON_THROW_ON_ERROR);
    }

    public function hasSession(): bool
    {
        return $this->token !== '';
    }

    /** @return resource|null */
    public function openResourceSubscription()
    {
        return UgosResourceSubscription::open($this->baseUrl, $this->peerAddress, $this->token,
            $this->cookieHeader(), min($this->deadline, microtime(true) + 2));
    }

    public function readDesktopMonitoring(): array
    {
        // Eine abgelehnte optionale Desktop-Abfrage darf eine gültige Sitzung nicht zurücksetzen.
        return $this->readOnce(self::DESKTOP_MONITORING, 4000);
    }

    public function readMemoryStatistics(): array
    {
        return $this->readOnce('/ugreen/v1/taskmgr/stat/memory', 2500);
    }

    public function connectionSummary(): string
    {
        if (!$this->httpResponded) {
            return 'HTTP-Verbindung: keine Antwort.';
        }
        $login = match (true) {
            $this->authenticated => 'erfolgreich',
            $this->loginStarted => 'nicht abgeschlossen',
            default => 'Sitzung nicht bestätigt'
        };
        return 'HTTP-Verbindung: Antwort erhalten. UGOS-Anmeldung: ' . $login . '.';
    }

    public function read(string $path, ?int $timeoutMs = null): array
    {
        if (!in_array($path, self::READ_PATHS, true)) {
            throw new UgosApiException('Nicht freigegebener Endpunkt.');
        }
        if ($this->token === '') {
            $this->login();
        }
        try {
            return $this->readOnce($path, $timeoutMs);
        } catch (UgosApiException $e) {
            if (!in_array($e->getCode(), [401, 1024], true)) {
                throw $e;
            }
            $this->token = '';
            $this->cookies = [];
            $this->login();
            return $this->readOnce($path, $timeoutMs); // höchstens ein erneuter Login
        }
    }

    private function readOnce(string $path, ?int $timeoutMs): array
    {
        $separator = str_contains($path, '?') ? '&' : '?';
        [$json] = $this->request('GET', $path . $separator . 'token=' . rawurlencode($this->token), timeoutMs: $timeoutMs);
        $this->checkCode($json);
        $this->authenticated = true;
        if (!is_array($json['data'] ?? null)) {
            throw new UgosApiException('UGOS liefert keine auswertbaren Daten.');
        }
        return $json['data'];
    }

    private function login(): void
    {
        $this->loginStarted = true;
        $this->authenticated = false;
        if ($this->username === '' || $this->password === '') {
            throw new UgosApiException('Benutzername oder Passwort fehlt.', 1003);
        }
        $this->cookies = [];
        [$check, $headers] = $this->request('POST', '/ugreen/v1/verify/check?token=', ['username' => $this->username]);
        $this->checkCode($check);
        $keyHeader = $headers['x-rsa-token'] ?? '';
        $decoded = base64_decode($keyHeader, true);
        $key = str_contains($keyHeader, '-----BEGIN') ? $keyHeader : ($decoded ?: '');
        $publicKey = false;
        if ($key !== '' && !str_contains($key, '-----BEGIN')) {
            foreach (['PUBLIC KEY', 'RSA PUBLIC KEY'] as $label) {
                $pem = '-----BEGIN ' . $label . "-----\n" . chunk_split(base64_encode($key), 64, "\n") . '-----END ' . $label . "-----\n";
                $publicKey = @openssl_pkey_get_public($pem);
                if ($publicKey !== false) {
                    break;
                }
            }
        } else {
            $publicKey = @openssl_pkey_get_public($key);
        }
        if ($publicKey === false || !@openssl_public_encrypt($this->password, $encrypted, $publicKey, OPENSSL_PKCS1_PADDING)) {
            throw new UgosApiException('UGOS-RSA-Schlüssel fehlt oder ist ungültig.');
        }
        [$json] = $this->request('POST', '/ugreen/v1/verify/login', [
            'username' => $this->username, 'password' => base64_encode($encrypted),
            'is_simple' => true, 'keepalive' => true, 'otp' => true
        ]);
        $this->checkCode($json);
        $token = $json['data']['token'] ?? null;
        if (!is_string($token) || $token === '') {
            throw new UgosApiException('Keine Sitzung erhalten. Bei aktivierter Zwei-Faktor-Anmeldung wird die Gerätefreigabe noch nicht unterstützt.', 1003);
        }
        if (strlen($token) > 4096 || preg_match('/[\r\n;]/', $token)) {
            throw new UgosApiException('Ungültiges Sitzungsformat.');
        }
        $this->token = $token;
        $this->cookies['token'] = rawurlencode($token);
        $this->authenticated = true;
    }

    private function checkCode(array $json): void
    {
        $code = (int) ($json['code'] ?? -1);
        if ($code !== 200) {
            // Keine ungefilterten Meldungen der NAS: können Benutzername oder Tokens enthalten.
            throw new UgosApiException(match ($code) {
                1003 => 'Anmeldung abgelehnt. Zugangsdaten und Kontostatus prüfen.',
                1008 => 'Dem NAS-Benutzer fehlen Leserechte.',
                1024 => 'UGOS-Sitzung ist abgelaufen.',
                default => 'UGOS-Anfrage abgelehnt (Code ' . $code . ').'
            }, $code);
        }
    }

    private function cookieHeader(): string
    {
        $cookies = [];
        foreach ($this->cookies as $name => $value) {
            $cookies[] = $name . '=' . $value;
        }
        return implode('; ', $cookies);
    }

    private function request(string $method, string $path, ?array $body = null, ?int $timeoutMs = null): array
    {
        if (!extension_loaded('curl') || !extension_loaded('openssl')) {
            throw new UgosApiException('PHP-Erweiterungen cURL und OpenSSL werden benötigt.');
        }
        $remaining = (int) floor(($this->deadline - microtime(true)) * 1000);
        if ($remaining < 1) {
            throw new UgosApiException('Gesamtzeitlimit der Abfrage erreicht.');
        }
        $headers = [];
        $response = '';
        $oversize = false;
        $ch = curl_init($this->baseUrl . $path);
        $requestTimeout = min($this->timeout * 1000, $remaining, $timeoutMs ?? PHP_INT_MAX);
        curl_setopt_array($ch, [
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTP,
            CURLOPT_PROXY => '',
            CURLOPT_CONNECTTIMEOUT_MS => min(4000, $requestTimeout),
            CURLOPT_TIMEOUT_MS => $requestTimeout,
            CURLOPT_HTTPHEADER => ['Accept: application/json', 'Content-Type: application/json'],
            CURLOPT_USERAGENT => 'Symcon-UGREEN-Monitor/0.8',
            CURLOPT_COOKIE => $this->cookieHeader(),
            CURLOPT_HEADERFUNCTION => function ($handle, string $line) use (&$headers): int {
                $length = strlen($line);
                if (str_contains($line, ':')) {
                    [$name, $value] = explode(':', $line, 2);
                    $headers[strtolower(trim($name))] = trim($value);
                    if (strtolower(trim($name)) === 'set-cookie'
                        && preg_match('/^([a-zA-Z0-9_]+)=([^;\r\n]*)/', trim($value), $m)) {
                        $this->cookies[$m[1]] = $m[2];
                    }
                }
                return $length;
            },
            CURLOPT_WRITEFUNCTION => static function ($handle, string $chunk) use (&$response, &$oversize): int {
                if (strlen($response) + strlen($chunk) > 2 * 1024 * 1024) {
                    $oversize = true;
                    return 0;
                }
                $response .= $chunk;
                return strlen($chunk);
            }
        ]);
        if ($body !== null) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body, JSON_THROW_ON_ERROR));
        }
        $ok = curl_exec($ch);
        $http = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $this->httpResponded = $this->httpResponded || $http > 0;
        if ($http > 0) {
            $this->peerAddress = (string) curl_getinfo($ch, CURLINFO_PRIMARY_IP);
        }
        $errno = curl_errno($ch);
        curl_close($ch);
        if ($ok === false) {
            $message = match ($errno) {
                CURLE_COULDNT_RESOLVE_HOST => 'NAS-Hostname konnte nicht aufgelöst werden.',
                CURLE_COULDNT_CONNECT => 'Verbindung zum NAS-Port konnte nicht aufgebaut werden.',
                CURLE_OPERATION_TIMEDOUT => 'Zeitlimit für die NAS-Anfrage erreicht.',
                default => 'HTTP-Anfrage fehlgeschlagen.'
            };
            throw new UgosApiException($oversize ? 'UGOS-Antwort überschreitet 2 MiB.' : $message . ' (cURL ' . $errno . ')',
                $errno === CURLE_OPERATION_TIMEDOUT ? CURLE_OPERATION_TIMEDOUT : 0);
        }
        if ($http !== 200) {
            throw new UgosApiException('NAS antwortet mit HTTP ' . $http . '.', $http);
        }
        try {
            $json = json_decode($response, true, 64, JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            throw new UgosApiException('NAS liefert kein gültiges JSON. API-Port prüfen.');
        }
        if (!is_array($json)) {
            throw new UgosApiException('Ungültige UGOS-Antwort.');
        }
        return [$json, $headers];
    }
}
