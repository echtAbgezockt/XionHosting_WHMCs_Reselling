<?php
/**
 * Cron-Abgleich: Server, die bei der Erstellung noch nicht fertig waren,
 * bekommen hier IP und Passwort. Einmal am Tag gleicht der Hook zusätzlich
 * alle laufenden Server ab, damit Sperren und IPs von Xion in WHMCS ankommen.
 */

if (!defined('WHMCS')) {
    die('This file cannot be accessed directly');
}

require_once __DIR__ . '/lib/functions.php';

use WHMCS\Database\Capsule;
use XionReseller\Service;

function xionreseller_cronSync($nurOffene)
{
    if (!function_exists('localAPI')) {
        return;
    }
    Service::ensureTable();
    $q = Capsule::table(Service::TABLE);
    $q = $nurOffene
        ? $q->whereIn('status', ['pending', 'provisioning'])
        : $q->whereIn('status', Service::LEBEND);
    $fertig = 0;
    $fehler = 0;
    foreach ($q->limit(500)->pluck('service_id') as $sid) {
        try {
            $params = xionreseller_cronParams((int) $sid);
            if (!$params) {
                continue;
            }
            $vorher = Service::get($sid);
            xionreseller_pull($params, xionreseller_client($params), (int) $vorher->server_id);
            if (Service::get($sid)->status === 'active' && in_array($vorher->status, ['pending', 'provisioning'], true)) {
                $fertig++;
            }
        } catch (\Exception $e) {
            $fehler++;
            logActivity('Xion-Reseller: Abgleich fehlgeschlagen: ' . $e->getMessage() . ' - Service ID: ' . (int) $sid);
        }
    }
    if ($fertig || $fehler) {
        logActivity('Xion-Reseller: Abgleich ' . ($nurOffene ? 'offener' : 'aller') . ' Server, '
            . $fertig . ' fertig, ' . $fehler . ' Fehler.');
    }
}

/**
 * Baut die Modulparameter eines Dienstes nach. Der Cron hat keinen
 * Modulaufruf, also lesen wir Produkt und Server selbst.
 */
function xionreseller_cronParams($serviceId)
{
    $h = Capsule::table('tblhosting')->where('id', $serviceId)->first();
    if (!$h) {
        return null;
    }
    $p = Capsule::table('tblproducts')->where('id', $h->packageid ?? 0)->first();
    $srv = Capsule::table('tblservers')->where('id', $h->server ?? 0)->first();
    if (!$p || !$srv || $p->servertype !== 'xionreseller') {
        return null;
    }
    $params = [
        'serviceid' => (int) $serviceId,
        'userid' => (int) $h->userid,
        'domain' => (string) $h->domain,
        'serverhostname' => (string) $srv->hostname,
        'serverip' => (string) $srv->ipaddress,
        'serversecure' => $srv->secure ? 'on' : '',
        'serveraccesshash' => (string) $srv->accesshash,
        'serverpassword' => $srv->password ? decrypt($srv->password) : '',
    ];
    for ($i = 1; $i <= 24; $i++) {
        $params['configoption' . $i] = (string) ($p->{'configoption' . $i} ?? '');
    }
    return $params;
}

add_hook('AfterCronJob', 1, function () {
    xionreseller_cronSync(true);
});

add_hook('DailyCronJob', 1, function () {
    xionreseller_cronSync(false);
});
