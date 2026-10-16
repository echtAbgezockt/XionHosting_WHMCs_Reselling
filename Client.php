<?php
/**
 * HTTP-Client für die XionHosting Reseller-API (/v1/reseller).
 *
 * Wirft XionResellerApiException bei jedem Fehler. Der HTTP-Status steckt im
 * Code der Ausnahme, damit das Modul 404 und 409 gezielt auswerten kann.
 */

namespace XionReseller;

class ApiException extends \Exception
{
    /** @var array|null */
    public $body;

    public function __construct($message, $status = 0, $body = null)
    {
        parent::__construct($message, (int) $status);
        $this->body = $body;
    }

    public function status()
    {
        return $this->getCode();
    }
}

class Client
{
    const VERSION = '1.0.0';

    private $base;
    private $key;
    private $timeout;

    /**
     * @param string $base    z. B. https://api.xionhosting.com/v1/reseller
     * @param string $key     API-Schlüssel xr_… oder xg_…
     * @param int    $timeout Sekunden je Anfrage
     */
    public function __construct($base, $key, $timeout = 30)
    {
        $this->base = rtrim($base, '/');
        $this->key = trim((string) $key);
        $this->timeout = (int) $timeout;
    }

    /**
     * Basis-URL aus den Feldern der WHMCS-Serverkonfiguration.
     * Hostname ohne Schema bekommt https, außer „Secure“ ist aus.
     */
    public static function fromParams(array $params)
    {
        $host = trim((string) ($params['serverhostname'] ?? ''));
        if ($host === '') {
            $host = trim((string) ($params['serverip'] ?? ''));
        }
        if ($host === '') {
            $host = 'api.xionhosting.com';
        }
        if (!preg_match('#^https?://#i', $host)) {
            // WHMCS liefert true/false, ältere Versionen „on“. Fehlt das Feld, gilt https.
            $secure = !array_key_exists('serversecure', $params)
                || in_array($params['serversecure'], [true, 1, '1', 'on'], true);
            $host = ($secure ? 'https://' : 'http://') . $host;
        }
        $host = rtrim($host, '/');
        if (!preg_match('#/v1/reseller$#', $host)) {
            $host .= '/v1/reseller';
        }

        // Schlüssel aus „Access Hash“, sonst aus dem Passwortfeld (das WHMCS verschlüsselt).
        $key = trim((string) ($params['serveraccesshash'] ?? ''));
        if ($key === '') {
            $key = trim((string) ($params['serverpassword'] ?? ''));
        }
        return new self($host, $key);
    }

    public function baseUrl()
    {
        return $this->base;
    }

    public function get($path, array $query = [])
    {
        if ($query) {
            $path .= '?' . http_build_query($query);
        }
        return $this->request('GET', $path);
    }

    public function post($path, array $body = [])
    {
        return $this->request('POST', $path, $body);
    }

    public function patch($path, array $body = [])
    {
        return $this->request('PATCH', $path, $body);
    }

    public function put($path, array $body = [])
    {
        return $this->request('PUT', $path, $body);
    }

    public function delete($path)
    {
        return $this->request('DELETE', $path);
    }

    /**
     * @return array dekodierte Antwort
     * @throws ApiException
     */
    public function request($method, $path, $body = null)
    {
        if ($this->key === '') {
            throw new ApiException('Kein API-Schlüssel hinterlegt. Bitte im WHMCS-Server ins Feld „Password“ eintragen.');
        }
        $url = $this->base . '/' . ltrim($path, '/');
        $headers = [
            'Authorization: Bearer ' . $this->key,
            'Accept: application/json',
            'User-Agent: XionReseller-WHMCS/' . self::VERSION,
        ];
        $payload = null;
        if ($body !== null && $method !== 'GET' && $method !== 'DELETE') {
            $payload = json_encode((object) $body);
            $headers[] = 'Content-Type: application/json';
        }

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT => $this->timeout,
            CURLOPT_FOLLOWLOCATION => false,
        ]);
        if ($payload !== null) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
        }
        $raw = curl_exec($ch);
        $errno = curl_errno($ch);
        $error = curl_error($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        $this->log($method . ' ' . $path, $payload, $status . ' ' . $raw);

        if ($errno) {
            throw new ApiException('Verbindung zur Reseller-API fehlgeschlagen: ' . $error, 0);
        }
        $data = json_decode((string) $raw, true);
        if (!is_array($data)) {
            throw new ApiException('Unerwartete Antwort der Reseller-API (HTTP ' . $status . ').', $status);
        }
        if ($status >= 400 || (isset($data['success']) && $data['success'] === false)) {
            $msg = isset($data['error']) ? (string) $data['error'] : 'Fehler HTTP ' . $status;
            if (!empty($data['details']) && is_array($data['details'])) {
                $msg .= ' (' . implode('; ', array_map('strval', $data['details'])) . ')';
            }
            throw new ApiException($msg, $status, $data);
        }
        return $data;
    }

    /** Schreibt ins WHMCS-Modulprotokoll, Schlüssel und Passwörter geschwärzt. */
    private function log($action, $request, $response)
    {
        if (!function_exists('logModuleCall')) {
            return;
        }
        $mask = [$this->key];
        if (is_string($response) && preg_match_all('/"(?:password|root_password)"\s*:\s*"([^"]+)"/', $response, $m)) {
            $mask = array_merge($mask, $m[1]);
        }
        if (is_string($request) && preg_match_all('/"(?:password|root_password)"\s*:\s*"([^"]+)"/', $request, $m)) {
            $mask = array_merge($mask, $m[1]);
        }
        logModuleCall('xionreseller', $action, $request, $response, null, array_filter($mask));
    }
}
