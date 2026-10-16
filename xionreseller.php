<?php
/**
 * WHMCS-Servermodul für das XionHosting Reseller-Programm.
 *
 * Bestellt, sperrt, entsperrt, ändert und kündigt KVM-Server über
 * https://api.xionhosting.com/v1/reseller. Abgerechnet wird bei XionHosting
 * stündlich, der Reseller rechnet mit seinem Kunden über WHMCS ab.
 *
 * Doku der API: docs/RESELLER-API.md im XionHosting-Repository.
 */

if (!defined('WHMCS')) {
    die('This file cannot be accessed directly');
}

require_once __DIR__ . '/lib/Client.php';
require_once __DIR__ . '/lib/Service.php';
require_once __DIR__ . '/lib/functions.php';

use XionReseller\ApiException;
use XionReseller\Client;
use XionReseller\Service;
use WHMCS\Database\Capsule;

/* ------------------------------------------------------------------------
 * Grunddaten und Einstellungen
 * --------------------------------------------------------------------- */

function xionreseller_MetaData()
{
    return [
        'DisplayName' => 'XionHosting Reseller (KVM)',
        'APIVersion' => '1.1',
        'RequiresServer' => true,
        'DefaultNonSSLPort' => '80',
        'DefaultSSLPort' => '443',
        'ServiceSingleSignOnLabel' => false,
        'AdminSingleSignOnLabel' => false,
    ];
}

/**
 * Produkteinstellungen. Die Reihenfolge legt configoption1 bis 10 fest,
 * neue Felder also nur hinten anfügen.
 */
function xionreseller_ConfigOptions()
{
    return [
        'vcores' => [
            'FriendlyName' => 'vCores',
            'Type' => 'text', 'Size' => '4', 'Default' => '2',
            'Description' => '1 bis 16. Konfigurierbare Option „vcores“ überschreibt den Wert.',
        ],
        'ram_gb' => [
            'FriendlyName' => 'RAM (GB)',
            'Type' => 'text', 'Size' => '4', 'Default' => '4',
            'Description' => '1 bis 64. Option „ram_gb“.',
        ],
        'disk_gb' => [
            'FriendlyName' => 'Speicher (GB)',
            'Type' => 'text', 'Size' => '5', 'Default' => '40',
            'Description' => '10 bis 400, Vielfaches von 10. Option „disk_gb“.',
        ],
        'ipv4_count' => [
            'FriendlyName' => 'IPv4-Adressen',
            'Type' => 'text', 'Size' => '3', 'Default' => '1',
            'Description' => '1 bis 8. Option „ipv4_count“.',
        ],
        'template_id' => [
            'FriendlyName' => 'Betriebssystem',
            'Type' => 'dropdown',
            'Loader' => 'xionreseller_LoadTemplates',
            'SimpleMode' => true,
            'Description' => 'Option „os“ überschreibt die Auswahl (Wert = Template-ID).',
        ],
        'label' => [
            'FriendlyName' => 'Bezeichnung bei Xion',
            'Type' => 'text', 'Size' => '40', 'Default' => 'WHMCS #{serviceid} {domain}',
            'Description' => 'Platzhalter: {serviceid} {clientid} {domain} {email}',
        ],
        'wait' => [
            'FriendlyName' => 'Auf Bereitstellung warten (s)',
            'Type' => 'text', 'Size' => '4', 'Default' => '90',
            'Description' => 'So lange wartet die Erstellung auf IP und Passwort, damit die Willkommensmail sie enthält. 0 = nicht warten.',
        ],
        'ready_mail' => [
            'FriendlyName' => 'Mailvorlage „Server bereit“',
            'Type' => 'text', 'Size' => '40', 'Default' => '',
            'Description' => 'Name einer Produkt-Mailvorlage. Geht raus, wenn der Server erst nach der Wartezeit fertig wird. Leer = keine Mail.',
        ],
        'allow_reinstall' => [
            'FriendlyName' => 'Kunde darf neu installieren',
            'Type' => 'yesno', 'Default' => 'on',
        ],
        'allow_console' => [
            'FriendlyName' => 'Kunde darf Konsole öffnen',
            'Type' => 'yesno', 'Default' => 'on',
        ],
    ];
}

/** Lädt die Betriebssysteme für die Produkteinstellung. */
function xionreseller_LoadTemplates(array $params)
{
    $out = [];
    foreach (xionreseller_templates($params) as $t) {
        $out[(string) $t['id']] = $t['name'];
    }
    if (!$out) {
        throw new \Exception('Keine Betriebssysteme gefunden. API-Schlüssel und Server prüfen.');
    }
    return $out;
}

function xionreseller_TestConnection(array $params)
{
    try {
        $me = xionreseller_client($params)->get('/me');
        $r = $me['reseller'] ?? [];
        if (($r['status'] ?? '') !== 'active') {
            return ['success' => false, 'error' => 'Reseller-Konto ist nicht aktiv (Status: ' . ($r['status'] ?? '?') . ').'];
        }
        return ['success' => true, 'error' => ''];
    } catch (\Exception $e) {
        return ['success' => false, 'error' => $e->getMessage()];
    }
}

/* ------------------------------------------------------------------------
 * Lebenszyklus
 * --------------------------------------------------------------------- */

function xionreseller_CreateAccount(array $params)
{
    try {
        $sid = (int) $params['serviceid'];
        $vorhanden = Service::get($sid);
        if ($vorhanden && in_array($vorhanden->status, Service::LEBEND, true)) {
            return 'Für diesen Dienst existiert schon Xion-Server ' . $vorhanden->server_id
                . '. Erst kündigen oder die Verknüpfung im Reiter „Xion“ lösen.';
        }

        $api = xionreseller_client($params);
        $spec = xionreseller_spec($params);
        $body = $spec + ['label' => xionreseller_label($params)];
        $tpl = xionreseller_templateId($params);
        if ($tpl) {
            $body['template_id'] = $tpl;
        }

        $r = $api->post('/servers', $body);
        $server = $r['server'];
        Service::link($sid, $server);

        $warten = max(0, min(600, (int) ($params['configoption7'] ?? 90)));
        if ($warten > 0) {
            @set_time_limit($warten + 60);
            $server = xionreseller_waitReady($api, $server['id'], $warten);
        }
        if (($server['status'] ?? '') === 'failed') {
            return 'Bereitstellung gescheitert: ' . ($server['provision_error'] ?: 'unbekannter Grund')
                . '. Der Server wird nicht berechnet.';
        }
        xionreseller_pull($params, $api, $server['id']);
        return 'success';
    } catch (\Exception $e) {
        return xionreseller_fehler($e);
    }
}

function xionreseller_SuspendAccount(array $params)
{
    try {
        $id = xionreseller_serverId($params);
        $api = xionreseller_client($params);
        try {
            $api->post('/servers/' . $id . '/lock');
        } catch (ApiException $e) {
            // Schon von uns oder von Xion gesperrt: Ziel erreicht.
            $s = $api->get('/servers/' . $id)['server'];
            if (($s['status'] ?? '') !== 'suspended') {
                throw $e;
            }
        }
        xionreseller_pull($params, $api, $id);
        return 'success';
    } catch (\Exception $e) {
        return xionreseller_fehler($e);
    }
}

function xionreseller_UnsuspendAccount(array $params)
{
    try {
        $id = xionreseller_serverId($params);
        $api = xionreseller_client($params);
        $s = $api->get('/servers/' . $id)['server'];
        if (($s['status'] ?? '') === 'active') {
            return 'success';
        }
        if (($s['lock_reason'] ?? '') !== 'reseller') {
            return 'Der Server ist von XionHosting gesperrt (Grund: ' . ($s['lock_reason'] ?? '?')
                . '). Das hebt nur der Xion-Support auf.';
        }
        $api->post('/servers/' . $id . '/unlock');
        xionreseller_pull($params, $api, $id);
        return 'success';
    } catch (\Exception $e) {
        return xionreseller_fehler($e);
    }
}

function xionreseller_TerminateAccount(array $params)
{
    try {
        $id = xionreseller_serverId($params);
        $api = xionreseller_client($params);
        try {
            $api->delete('/servers/' . $id);
        } catch (ApiException $e) {
            if ($e->status() === 404) {
                // Gibt es bei Xion nicht mehr.
            } elseif ($e->status() === 409) {
                $s = $api->get('/servers/' . $id)['server'];
                if (!in_array($s['status'] ?? '', ['terminated', 'failed'], true)) {
                    throw $e;
                }
            } else {
                throw $e;
            }
        }
        Capsule::table(Service::TABLE)->where('service_id', (int) $params['serviceid'])
            ->update(['status' => 'terminated', 'synced_at' => date('Y-m-d H:i:s')]);
        return 'success';
    } catch (\Exception $e) {
        return xionreseller_fehler($e);
    }
}

/**
 * Up- und Downgrade. vCores und RAM gehen in beide Richtungen, Speicher nur
 * nach oben. Die Anzahl IPv4 lässt sich über die API nicht nachträglich ändern.
 */
function xionreseller_ChangePackage(array $params)
{
    try {
        $id = xionreseller_serverId($params);
        $api = xionreseller_client($params);
        $ziel = xionreseller_spec($params);
        $stand = $api->get('/servers/' . $id . '/addons');
        $ist = $stand['server']['spec'];

        if ($ziel['disk_gb'] < $ist['disk_gb']) {
            return 'Speicher lässt sich nicht verkleinern (' . $ist['disk_gb'] . ' GB → ' . $ziel['disk_gb'] . ' GB).';
        }
        if ($ziel['ipv4_count'] !== (int) $ist['ipv4_count']) {
            return 'Die Anzahl IPv4 lässt sich nicht nachträglich ändern (' . $ist['ipv4_count'] . ' → ' . $ziel['ipv4_count'] . ').';
        }

        $plan = [['cpu', 'vcores'], ['ram', 'ram_gb'], ['disk', 'disk_gb']];
        $addons = $stand['addons'];
        foreach ($plan as [$kind, $feld]) {
            $wert = (int) $ist[$feld];
            $soll = (int) $ziel[$feld];
            if ($soll < $wert) {
                // Jüngste Erweiterungen zuerst entfernen, bis der Wert passt oder darunter liegt.
                $eigene = array_reverse(array_values(array_filter($addons, function ($a) use ($kind) {
                    return $a['kind'] === $kind && empty($a['removed_at']);
                })));
                foreach ($eigene as $a) {
                    if ($wert <= $soll) {
                        break;
                    }
                    $api->delete('/servers/' . $id . '/addons/' . (int) $a['id']);
                    $wert -= (int) $a['amount'];
                }
                if ($wert > $soll) {
                    return 'Unter die bestellte Grundgröße geht es nicht (' . $feld . ': ' . $wert . ' statt ' . $soll . ').';
                }
            }
            if ($soll > $wert) {
                $api->post('/servers/' . $id . '/addons', ['kind' => $kind, 'amount' => $soll - $wert]);
            }
        }
        xionreseller_pull($params, $api, $id);
        return 'success';
    } catch (\Exception $e) {
        return xionreseller_fehler($e);
    }
}

/* ------------------------------------------------------------------------
 * Knöpfe für Admin und Kunde
 * --------------------------------------------------------------------- */

function xionreseller_AdminCustomButtonArray()
{
    return [
        'Starten' => 'start',
        'Herunterfahren' => 'shutdown',
        'Neustart' => 'restart',
        'Hart ausschalten' => 'stop',
        'Neues Root-Passwort' => 'resetpassword',
        'Neu installieren' => 'reinstall',
        'Mit Xion abgleichen' => 'sync',
    ];
}

function xionreseller_ClientAreaCustomButtonArray()
{
    return [
        'Starten' => 'start',
        'Herunterfahren' => 'shutdown',
        'Neustart' => 'restart',
        'Hart ausschalten' => 'stop',
        'Neues Root-Passwort' => 'resetpassword',
    ];
}

/** Zusätzliche Kundenfunktionen, die nicht als Knopf in der Seitenleiste stehen. */
function xionreseller_ClientAreaAllowedFunctions()
{
    return [
        'Neu installieren' => 'reinstall',
        'Konsole' => 'console',
        'Reverse DNS' => 'rdns',
    ];
}

function xionreseller_start(array $params) { return xionreseller_power($params, 'start'); }
function xionreseller_shutdown(array $params) { return xionreseller_power($params, 'shutdown'); }
function xionreseller_restart(array $params) { return xionreseller_power($params, 'restart'); }
function xionreseller_stop(array $params) { return xionreseller_power($params, 'stop'); }

function xionreseller_power(array $params, $action)
{
    try {
        $id = xionreseller_serverId($params);
        xionreseller_client($params)->post('/servers/' . $id . '/power', ['action' => $action]);
        return 'success';
    } catch (\Exception $e) {
        return xionreseller_fehler($e);
    }
}

function xionreseller_resetpassword(array $params)
{
    try {
        $id = xionreseller_serverId($params);
        $r = xionreseller_client($params)->post('/servers/' . $id . '/reset-password');
        Service::storePassword($params['serviceid'], $r['username'] ?? null, $r['root_password']);
        return 'success';
    } catch (\Exception $e) {
        return xionreseller_fehler($e);
    }
}

/**
 * Neuinstallation. Im Kundenbereich mit Feld template_id, im Admin mit dem
 * aktuellen Betriebssystem des Servers.
 */
function xionreseller_reinstall(array $params)
{
    try {
        $imKundenbereich = defined('CLIENTAREA');
        if ($imKundenbereich && ($params['configoption9'] ?? '') !== 'on') {
            return 'Die Neuinstallation ist für dieses Produkt abgeschaltet.';
        }
        $id = xionreseller_serverId($params);
        $api = xionreseller_client($params);

        $tpl = isset($_POST['template_id']) ? (int) $_POST['template_id'] : 0;
        if ($tpl) {
            $erlaubt = array_map(function ($t) { return (int) $t['id']; }, xionreseller_templates($params));
            if (!in_array($tpl, $erlaubt, true)) {
                return 'Unbekanntes Betriebssystem.';
            }
        } else {
            $s = $api->get('/servers/' . $id)['server'];
            $tpl = (int) ($s['template']['id'] ?? 0) ?: xionreseller_templateId($params);
        }
        if ($imKundenbereich && ($_POST['confirm'] ?? '') !== '1') {
            return 'Bitte die Löschung aller Daten bestätigen.';
        }

        $r = $api->post('/servers/' . $id . '/reinstall', $tpl ? ['template_id' => $tpl] : []);
        Service::storePassword($params['serviceid'], $r['username'] ?? null, $r['root_password']);
        return 'success';
    } catch (\Exception $e) {
        return xionreseller_fehler($e);
    }
}

function xionreseller_rdns(array $params)
{
    try {
        $id = xionreseller_serverId($params);
        $ip = trim((string) ($_POST['ip'] ?? ''));
        $host = trim((string) ($_POST['hostname'] ?? ''));
        if (!filter_var($ip, FILTER_VALIDATE_IP)) {
            return 'Ungültige IP-Adresse.';
        }
        $api = xionreseller_client($params);
        $eigene = array_column(xionreseller_ips($api, $id), 'ip');
        if (!in_array($ip, $eigene, true)) {
            return 'Diese IP gehört nicht zum Server.';
        }
        if ($host === '') {
            $api->delete('/servers/' . $id . '/ips/' . rawurlencode($ip) . '/rdns');
        } else {
            $api->put('/servers/' . $id . '/ips/' . rawurlencode($ip) . '/rdns', ['hostname' => $host]);
        }
        return 'success';
    } catch (\Exception $e) {
        return xionreseller_fehler($e);
    }
}

function xionreseller_sync(array $params)
{
    try {
        $id = xionreseller_serverId($params);
        xionreseller_pull($params, xionreseller_client($params), $id);
        return 'success';
    } catch (\Exception $e) {
        return xionreseller_fehler($e);
    }
}

/** Konsole im Kundenbereich: noVNC verbindet sich direkt mit der Xion-API. */
function xionreseller_console(array $params)
{
    $vars = ['error' => null, 'websocketJson' => 'null', 'vncpasswordJson' => '""', 'serviceid' => (int) $params['serviceid']];
    try {
        if (($params['configoption10'] ?? '') !== 'on') {
            throw new \Exception('Die Konsole ist für dieses Produkt abgeschaltet.');
        }
        $id = xionreseller_serverId($params);
        $api = xionreseller_client($params);
        $r = $api->post('/servers/' . $id . '/console');
        // Fertig kodiert, weil die Werte in einem <script> landen.
        $json = JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_SLASHES;
        $vars['websocketJson'] = json_encode(xionreseller_wsUrl($params, $r['prep_key']), $json);
        $vars['vncpasswordJson'] = json_encode((string) ($r['password'] ?? ''), $json);
    } catch (\Exception $e) {
        $vars['error'] = $e->getMessage();
    }
    return [
        'templatefile' => 'templates/console',
        'breadcrumb' => ['clientarea.php?action=productdetails&id=' . (int) $params['serviceid'] . '&modop=custom&a=console' => 'Konsole'],
        'vars' => $vars,
    ];
}

/* ------------------------------------------------------------------------
 * Ansichten
 * --------------------------------------------------------------------- */

function xionreseller_ClientArea(array $params)
{
    $vars = [
        'error' => null, 'server' => null, 'vm' => null, 'ips' => [], 'templates' => [],
        'allowReinstall' => ($params['configoption9'] ?? '') === 'on',
        'allowConsole' => ($params['configoption10'] ?? '') === 'on',
        'serviceid' => (int) $params['serviceid'],
        'rootpassword' => (string) ($params['password'] ?? ''),
        'rootuser' => (string) ($params['username'] ?? ''),
    ];
    try {
        $id = xionreseller_serverId($params);
        $api = xionreseller_client($params);
        $vars['server'] = xionreseller_pull($params, $api, $id);
        if (in_array($vars['server']['status'], ['active', 'suspended'], true)) {
            try {
                $vars['vm'] = $api->get('/servers/' . $id . '/status')['vm'] ?? null;
            } catch (\Exception $e) {
                $vars['vm'] = null;
            }
            try {
                $vars['ips'] = xionreseller_ips($api, $id);
            } catch (\Exception $e) {
                $vars['ips'] = [];
            }
            if ($vars['allowReinstall']) {
                $vars['templates'] = xionreseller_templates($params);
            }
        }
        $pw = Capsule::table('tblhosting')->where('id', (int) $params['serviceid'])->value('password');
        if ($pw && function_exists('decrypt')) {
            $vars['rootpassword'] = decrypt($pw);
        }
    } catch (\Exception $e) {
        $vars['error'] = $e->getMessage();
    }
    return ['tabOverviewReplacementTemplate' => 'templates/clientarea', 'templateVariables' => $vars];
}

function xionreseller_AdminServicesTabFields(array $params)
{
    $link = Service::get($params['serviceid']);
    $feld = '<input type="text" name="xionreseller_server_id" class="form-control input-150" value="'
        . htmlspecialchars($link ? (string) $link->server_id : '') . '" placeholder="z. B. 13046">';
    $out = ['Xion-Server-ID' => $feld . ' <small>leer lassen und speichern löst die Verknüpfung</small>'];
    if (!$link) {
        return $out;
    }
    try {
        $s = xionreseller_client($params)->get('/servers/' . $link->server_id)['server'];
        $sp = $s['spec'];
        $out['Xion-Status'] = htmlspecialchars($s['status'] . ($s['lock_reason'] ? ' (Sperre: ' . $s['lock_reason'] . ')' : ''));
        $out['Xion-Server'] = htmlspecialchars($s['identifier'] . ' · ' . ($s['node']['name'] ?? '–') . ' · VMID ' . ($s['vmid'] ?? '–'));
        $out['Ausstattung'] = htmlspecialchars($sp['vcores'] . ' vCores · ' . $sp['ram_gb'] . ' GB RAM · '
            . $sp['disk_gb'] . ' GB · ' . $sp['ipv4_count'] . ' IPv4 · ' . ($s['template']['name'] ?? '–'));
        $out['Einkauf bei Xion'] = number_format((float) $s['price']['monthly_eur'], 2, ',', '.') . ' € je Monat ('
            . number_format((float) $s['price']['hourly_eur'], 4, ',', '.') . ' € je Stunde)';
        if (!empty($s['provision_error'])) {
            $out['Fehler Bereitstellung'] = htmlspecialchars($s['provision_error']);
        }
    } catch (\Exception $e) {
        $out['Xion-Status'] = '<span class="text-danger">' . htmlspecialchars($e->getMessage()) . '</span>';
    }
    return $out;
}

function xionreseller_AdminServicesTabFieldsSave(array $params)
{
    if (!isset($_POST['xionreseller_server_id'])) {
        return;
    }
    $neu = trim((string) $_POST['xionreseller_server_id']);
    $alt = Service::get($params['serviceid']);
    if ($neu === '') {
        if ($alt) {
            Service::unlink($params['serviceid']);
        }
        return;
    }
    if ($alt && (string) $alt->server_id === $neu) {
        return;
    }
    try {
        $api = xionreseller_client($params);
        $s = $api->get('/servers/' . rawurlencode($neu))['server'];
        Service::link($params['serviceid'], $s);
        xionreseller_pull($params, $api, $s['id']);
    } catch (\Exception $e) {
        logActivity('Xion-Reseller: Server ' . $neu . ' ließ sich nicht verknüpfen: ' . $e->getMessage()
            . ' - Service ID: ' . (int) $params['serviceid']);
    }
}
