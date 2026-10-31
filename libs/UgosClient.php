<?php
declare(strict_types=1);

final class UgosApiException extends RuntimeException {}

/** Nur die hier fest hinterlegten Lese-Endpunkte dürfen aufgerufen werden. */
final class UgosClient
{
    private string $token;
    private array $cookies = [];
    private float $deadline;
    private const READ_PATHS = [
        '/ugreen/v1/sysinfo/machine/common',
        '/ugreen/v1/taskmgr/stat/get_all',
        '/ugreen/v1/taskmgr/stat/overview',
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

    public function read(string $path): array
    {
        if (!in_array($path, self::READ_PATHS, true)) {
            throw new UgosApiException('Nicht freigegebener Endpunkt.');
        }
        if ($this->token === '') {
            $this->login();
        }
        try {
            return $this->readOnce($path);
        } catch (UgosApiException $e) {
            if (!in_array($e->getCode(), [401, 1024], true)) {
                throw $e;
            }
            $this->token = '';
            $this->cookies = [];
            $this->login();
            return $this->readOnce($path); // höchstens ein erneuter Login
        }
    }

    private function readOnce(string $path): array
    {
        [$json] = $this->request('GET', $path . '?token=' . rawurlencode($this->token));
        $this->checkCode($json);
        if (!is_array($json['data'] ?? null)) {
            throw new UgosApiException('UGOS liefert keine auswertbaren Daten.');
        }
        return $json['data'];
    }

    private function login(): void
    {
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

    private function request(string $method, string $path, ?array $body = null): array
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
        $cookies = [];
        foreach ($this->cookies as $name => $value) {
            $cookies[] = $name . '=' . $value;
        }
        curl_setopt_array($ch, [
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTP,
            CURLOPT_PROXY => '',
            CURLOPT_CONNECTTIMEOUT_MS => min(4000, $remaining),
            CURLOPT_TIMEOUT_MS => min($this->timeout * 1000, $remaining),
            CURLOPT_HTTPHEADER => ['Accept: application/json', 'Content-Type: application/json'],
            CURLOPT_USERAGENT => 'Symcon-UGREEN-Monitor/0.3',
            CURLOPT_COOKIE => implode('; ', $cookies),
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
        $errno = curl_errno($ch);
        curl_close($ch);
        if ($ok === false) {
            throw new UgosApiException($oversize ? 'UGOS-Antwort überschreitet 2 MiB.' : 'NAS-Verbindung fehlgeschlagen (cURL ' . $errno . '). HTTP-Adresse, Port und Erreichbarkeit prüfen.');
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
