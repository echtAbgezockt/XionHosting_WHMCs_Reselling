<?php
/**
 * Gemeinsame Hilfen für Modul und Cron-Hook. Eigene Datei, damit hooks.php
 * sie laden kann, ohne die Moduldatei ein zweites Mal einzubinden.
 */

if (!defined('WHMCS')) {
    die('This file cannot be accessed directly');
}

require_once __DIR__ . '/Client.php';
require_once __DIR__ . '/Service.php';

use XionReseller\ApiException;
use XionReseller\Client;
use XionReseller\Service;

/* ------------------------------------------------------------------------
 * Hilfen
 * --------------------------------------------------------------------- */

function xionreseller_client(array $params)
{
    return Client::fromParams($params);
}

/** Xion-Server-ID des Dienstes, sonst Ausnahme mit lesbarer Meldung. */
function xionreseller_serverId(array $params)
{
    $link = Service::get($params['serviceid']);
    if (!$link) {
        throw new \Exception('Dienst ist mit keinem Xion-Server verknüpft. ID im Reiter „Xion-Server-ID“ eintragen oder neu erstellen.');
    }
    return (int) $link->server_id;
}

/**
 * Ausstattung aus Produkt und konfigurierbaren Optionen.
 * Optionen schlagen Produktwerte, Optionsnamen ohne Groß- und Kleinschreibung.
 */
function xionreseller_spec(array $params)
{
    $spec = [
        'vcores' => (int) ($params['configoption1'] ?? 2),
        'ram_gb' => (int) ($params['configoption2'] ?? 4),
        'disk_gb' => (int) ($params['configoption3'] ?? 40),
        'ipv4_count' => (int) ($params['configoption4'] ?? 1),
    ];
    $namen = [
        'vcores' => ['vcores', 'vcore', 'cpu', 'cores'],
        'ram_gb' => ['ram_gb', 'ram', 'memory', 'arbeitsspeicher'],
        'disk_gb' => ['disk_gb', 'disk', 'storage', 'speicher', 'ssd', 'nvme'],
        'ipv4_count' => ['ipv4_count', 'ipv4', 'ips'],
    ];
    $opt = array_change_key_case((array) ($params['configoptions'] ?? []), CASE_LOWER);
    foreach ($namen as $feld => $aliase) {
        foreach ($aliase as $a) {
            if (isset($opt[$a]) && $opt[$a] !== '' && (int) $opt[$a] > 0) {
                $spec[$feld] = (int) $opt[$a];
                break;
            }
        }
    }
    return $spec;
}

/** Template aus Option „os“ (ID, Slug oder Name) oder aus der Produkteinstellung. */
function xionreseller_templateId(array $params)
{
    $opt = array_change_key_case((array) ($params['configoptions'] ?? []), CASE_LOWER);
    foreach (['os', 'template_id', 'template', 'betriebssystem'] as $a) {
        if (!isset($opt[$a]) || $opt[$a] === '') {
            continue;
        }
        $wert = trim((string) $opt[$a]);
        if (ctype_digit($wert)) {
            return (int) $wert;
        }
        foreach (xionreseller_templates($params) as $t) {
            if (strcasecmp($t['slug'] ?? '', $wert) === 0 || strcasecmp($t['name'], $wert) === 0) {
                return (int) $t['id'];
            }
        }
        throw new \Exception('Betriebssystem „' . $wert . '“ gibt es bei Xion nicht.');
    }
    $id = (int) ($params['configoption5'] ?? 0);
    return $id > 0 ? $id : null;
}

function xionreseller_templates(array $params)
{
    static $cache = [];
    $k = ($params['serverhostname'] ?? '') . '|' . ($params['serveraccesshash'] ?? '') . ($params['serverpassword'] ?? '');
    if (!isset($cache[$k])) {
        $cache[$k] = xionreseller_client($params)->get('/templates')['templates'] ?? [];
    }
    return $cache[$k];
}

function xionreseller_ips(Client $api, $id)
{
    $r = $api->get('/servers/' . $id . '/ips');
    $out = [];
    foreach ((array) ($r['ips'] ?? $r['addresses'] ?? []) as $ip) {
        if (is_string($ip)) {
            $out[] = ['ip' => $ip, 'rdns' => null, 'type' => strpos($ip, ':') !== false ? 'ipv6' : 'ipv4'];
            continue;
        }
        $adr = $ip['ip'] ?? $ip['address'] ?? $ip['ip_address'] ?? null;
        if (!$adr) {
            continue;
        }
        $out[] = [
            'ip' => $adr,
            'rdns' => $ip['rdns'] ?? $ip['hostname'] ?? $ip['ptr'] ?? null,
            'type' => $ip['type'] ?? (strpos($adr, ':') !== false ? 'ipv6' : 'ipv4'),
        ];
    }
    return $out;
}

function xionreseller_label(array $params)
{
    $c = (array) ($params['clientsdetails'] ?? []);
    $text = strtr((string) ($params['configoption6'] ?? 'WHMCS #{serviceid} {domain}'), [
        '{serviceid}' => (string) $params['serviceid'],
        '{clientid}' => (string) ($params['userid'] ?? $c['userid'] ?? ''),
        '{domain}' => (string) ($params['domain'] ?? ''),
        '{email}' => (string) ($c['email'] ?? ''),
    ]);
    return mb_substr(trim(preg_replace('/\s+/', ' ', $text)), 0, 120);
}

/** Wartet, bis der Server aktiv oder gescheitert ist, höchstens $sekunden. */
function xionreseller_waitReady(Client $api, $id, $sekunden)
{
    $ende = time() + $sekunden;
    $s = ['id' => $id, 'status' => 'pending'];
    do {
        sleep(5);
        try {
            $s = $api->get('/servers/' . $id)['server'];
        } catch (ApiException $e) {
            if ($e->status() !== 429 && $e->status() < 500) {
                throw $e;
            }
        }
    } while (in_array($s['status'], ['pending', 'provisioning'], true) && time() < $ende);
    return $s;
}

/**
 * Server laden und nach WHMCS übernehmen. Schickt die Mail „Server bereit“,
 * wenn der Server seit dem letzten Abgleich aktiv geworden ist.
 */
function xionreseller_pull(array $params, Client $api, $id)
{
    $s = $api->get('/servers/' . $id)['server'];
    $sid = (int) $params['serviceid'];
    $neuAktiv = Service::sync($sid, $s, $s['credentials'] ?? null);
    $vorlage = trim((string) ($params['configoption8'] ?? ''));
    $link = Service::get($sid);
    if ($neuAktiv && $vorlage !== '' && $link && !$link->ready_mail_sent && !xionreseller_inCreate()) {
        $r = localAPI('SendEmail', ['messagename' => $vorlage, 'id' => $sid]);
        if (($r['result'] ?? '') === 'success') {
            Service::markReadyMailSent($sid);
        } else {
            logActivity('Xion-Reseller: Mail „' . $vorlage . '“ nicht gesendet: ' . ($r['message'] ?? '?') . ' - Service ID: ' . $sid);
        }
    } elseif ($neuAktiv && $link) {
        // Fertig während der Erstellung: die Willkommensmail von WHMCS trägt die Daten schon.
        Service::markReadyMailSent($sid);
    }
    return $s;
}

/** true, solange CreateAccount läuft. Dann geht die Willkommensmail, keine zweite. */
function xionreseller_inCreate()
{
    foreach (debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 8) as $f) {
        if (($f['function'] ?? '') === 'xionreseller_CreateAccount') {
            return true;
        }
    }
    return false;
}

function xionreseller_wsUrl(array $params, $prep)
{
    $http = preg_replace('#/v1/reseller$#', '', rtrim(Client::fromParams($params)->baseUrl(), '/'));
    $ws = preg_replace('#^http#i', 'ws', $http);
    return $ws . '/v1/account/kvm-console?prep=' . rawurlencode($prep);
}

function xionreseller_fehler(\Exception $e)
{
    $text = $e->getMessage();
    if ($e instanceof ApiException) {
        switch ($e->status()) {
            case 401:
                return 'API-Schlüssel ungültig oder widerrufen. ' . $text;
            case 402:
                return 'Freibetrag bei Xion reicht nicht oder Bestellungen sind gesperrt: ' . $text;
            case 429:
                return 'Zu viele Anfragen an Xion, bitte gleich erneut versuchen. ' . $text;
            case 503:
                return 'Bei Xion ist gerade kein Platz frei: ' . $text;
        }
    }
    return $text;
}
