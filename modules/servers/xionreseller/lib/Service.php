<?php
/**
 * Verknüpfung WHMCS-Dienst ↔ Xion-Server und Abgleich nach tblhosting.
 *
 * Eigene Tabelle mod_xionreseller statt Custom Field: sie lässt sich ohne
 * Produktpflege anlegen und überlebt Paketwechsel.
 */

namespace XionReseller;

use WHMCS\Database\Capsule;

class Service
{
    const TABLE = 'mod_xionreseller';

    /** Status, in denen der Xion-Server noch existiert. */
    const LEBEND = ['pending', 'provisioning', 'active', 'suspended'];

    public static function ensureTable()
    {
        $schema = Capsule::schema();
        if ($schema->hasTable(self::TABLE)) {
            return;
        }
        $schema->create(self::TABLE, function ($t) {
            $t->unsignedInteger('service_id')->primary();
            $t->unsignedInteger('server_id');
            $t->string('uuid', 64)->nullable();
            $t->string('identifier', 64)->nullable();
            $t->string('status', 32)->default('pending');
            $t->boolean('ready_mail_sent')->default(false);
            $t->timestamp('synced_at')->nullable();
            $t->timestamp('created_at')->nullable();
            $t->index('status');
        });
    }

    /** @return object|null */
    public static function get($serviceId)
    {
        self::ensureTable();
        return Capsule::table(self::TABLE)->where('service_id', (int) $serviceId)->first();
    }

    public static function link($serviceId, array $server)
    {
        self::ensureTable();
        $row = [
            'server_id' => (int) $server['id'],
            'uuid' => $server['uuid'] ?? null,
            'identifier' => $server['identifier'] ?? null,
            'status' => $server['status'] ?? 'pending',
            // Ein schon laufender Server, der nachträglich verknüpft wird, braucht keine Bereit-Mail.
            'ready_mail_sent' => ($server['status'] ?? '') === 'active' ? 1 : 0,
            'synced_at' => date('Y-m-d H:i:s'),
        ];
        if (self::get($serviceId)) {
            Capsule::table(self::TABLE)->where('service_id', (int) $serviceId)->update($row);
        } else {
            $row['service_id'] = (int) $serviceId;
            $row['created_at'] = date('Y-m-d H:i:s');
            Capsule::table(self::TABLE)->insert($row);
        }
    }

    public static function unlink($serviceId)
    {
        self::ensureTable();
        Capsule::table(self::TABLE)->where('service_id', (int) $serviceId)->delete();
    }

    /**
     * Xion-Server-Stand in WHMCS übernehmen: IPs, Benutzer, Passwort, Status.
     *
     * @param array      $server      Server-Objekt der API
     * @param array|null $credentials {username, password} oder null
     * @return bool true, wenn der Server gerade aktiv geworden ist
     */
    public static function sync($serviceId, array $server, $credentials = null)
    {
        self::ensureTable();
        $vorher = self::get($serviceId);
        $status = (string) ($server['status'] ?? '');

        Capsule::table(self::TABLE)->where('service_id', (int) $serviceId)->update([
            'uuid' => $server['uuid'] ?? ($vorher->uuid ?? null),
            'identifier' => $server['identifier'] ?? ($vorher->identifier ?? null),
            'status' => $status,
            'synced_at' => date('Y-m-d H:i:s'),
        ]);

        $ipv4 = array_values(array_filter((array) ($server['ipv4'] ?? [])));
        $ipv6 = array_values(array_filter((array) ($server['ipv6'] ?? [])));
        $update = [];
        if ($ipv4) {
            $update['dedicatedip'] = $ipv4[0];
            $update['assignedips'] = implode("\n", array_merge(array_slice($ipv4, 1), $ipv6));
        } elseif ($ipv6) {
            $update['dedicatedip'] = $ipv6[0];
            $update['assignedips'] = implode("\n", array_slice($ipv6, 1));
        }
        if (is_array($credentials)) {
            if (!empty($credentials['username'])) {
                $update['username'] = (string) $credentials['username'];
            }
            if (!empty($credentials['password'])) {
                $update['password'] = self::encrypt((string) $credentials['password']);
            }
        } elseif (!empty($server['username'])) {
            $update['username'] = (string) $server['username'];
        }
        if ($update) {
            Capsule::table('tblhosting')->where('id', (int) $serviceId)->update($update);
        }

        // Nur der Übergang aus der Einrichtung zählt, nicht das Entsperren.
        return $status === 'active' && $vorher && in_array($vorher->status, ['pending', 'provisioning'], true);
    }

    /** Neues Passwort nach Neuinstallation oder Passwortwechsel speichern. */
    public static function storePassword($serviceId, $username, $password)
    {
        $update = ['password' => self::encrypt((string) $password)];
        if ($username) {
            $update['username'] = (string) $username;
        }
        Capsule::table('tblhosting')->where('id', (int) $serviceId)->update($update);
    }

    public static function markReadyMailSent($serviceId)
    {
        Capsule::table(self::TABLE)->where('service_id', (int) $serviceId)->update(['ready_mail_sent' => 1]);
    }

    private static function encrypt($plain)
    {
        if (function_exists('localAPI')) {
            $r = localAPI('EncryptPassword', ['password2' => $plain]);
            if (($r['result'] ?? '') === 'success') {
                return $r['password'];
            }
        }
        if (function_exists('encrypt')) {
            return encrypt($plain);
        }
        return $plain;
    }
}
