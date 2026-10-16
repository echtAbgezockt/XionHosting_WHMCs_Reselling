# XionHosting Reseller for WHMCS

Version 1.0.0 · 16 October 2026 · tested against Reseller API version 2 · [Deutsch](README.md)

This module lets you sell KVM servers from your XionHosting reseller account through WHMCS. WHMCS orders, suspends, changes and terminates the servers with us. You invoice your customers in WHMCS at your own prices. We bill you hourly by resources, see [Reseller API](https://xionhosting.com/cp/reseller).

## Requirements

- WHMCS 8.x with PHP 7.4 or newer and the curl extension
- an approved reseller account with XionHosting
- an API key (`xr_…`) from the panel under **Reseller → API**

## Installation

**With the installer** (a single file that contains everything):

```bash
curl -fsSLo install-xionreseller-whmcs.sh https://github.com/echtAbgezockt/XionHosting_WHMCs_Reselling/releases/latest/download/install-xionreseller-whmcs.sh
sudo bash install-xionreseller-whmcs.sh /var/www/whmcs
```

**From the repository:**

```bash
git clone https://github.com/echtAbgezockt/XionHosting_WHMCs_Reselling.git
cd XionHosting_WHMCs_Reselling
sudo bash install.sh /var/www/whmcs
```

Without a path, the script looks for WHMCS in `/var/www/whmcs`, `/var/www/html/whmcs`, `/var/www/html` and `/home/*/public_html`. It checks the PHP files, moves an existing copy to `/var/backups/xionreseller-<time>` and copies the module. Settings and the database are not touched.

**By hand:** copy the folder `modules/servers/xionreseller` into your WHMCS directory so that `…/modules/servers/xionreseller/xionreseller.php` exists.

### Setting it up in WHMCS

1. **System Settings → Servers → Add New Server**:

   | Field | Value |
   |---|---|
   | Name | XionHosting |
   | Hostname | `api.xionhosting.com` |
   | Module | XionHosting Reseller (KVM) |
   | Password | your API key `xr_…` |
   | Secure | on |

   **Test Connection** checks the key and the account status.
2. Create a server group containing this server.
3. Create a product and select the module and the server group under **Module Settings**.

The module creates its table `mod_xionreseller` on first use.

## Product settings

| Field | Meaning |
|---|---|
| vCores | 1 to 16 |
| RAM (GB) | 1 to 64 |
| Speicher (GB) | storage, 10 to 400, multiple of 10 |
| IPv4-Adressen | IPv4 addresses, 1 to 8 |
| Betriebssystem | operating system, chosen from your Xion account, loaded live |
| Bezeichnung bei Xion | label shown in your Xion panel, placeholders `{serviceid}` `{clientid}` `{domain}` `{email}` |
| Auf Bereitstellung warten (s) | default 90. Creation waits this long for IP and password so both are in the welcome email |
| Mailvorlage „Server bereit“ | name of a product email template for servers that only finish after the wait. Empty means no email |
| Kunde darf neu installieren | reinstall form in the client area |
| Kunde darf Konsole öffnen | noVNC in the client area |

The field names in WHMCS are German; the table gives their meaning.

## Configurable options

To let customers choose sizes, create configurable options. The name before the `|` decides, upper and lower case do not matter. A value set here overrides the product setting.

| Resource | recognised names | Option type |
|---|---|---|
| vCores | `vcores`, `cpu`, `cores` | quantity or dropdown |
| RAM in GB | `ram_gb`, `ram`, `memory`, `arbeitsspeicher` | quantity or dropdown |
| Storage in GB | `disk_gb`, `disk`, `storage`, `speicher`, `ssd`, `nvme` | quantity or dropdown |
| IPv4 | `ipv4_count`, `ipv4`, `ips` | quantity |
| Operating system | `os`, `template`, `betriebssystem` | dropdown |

For a dropdown, the value goes before the `|` of the sub-option, for example `8|8 GB RAM`. For the operating system the module accepts the template ID (`9|Debian 12`), the slug (`debian-12|Debian 12`) or the name. `GET /v1/reseller/templates` returns the IDs.

## What the module does on our side

| WHMCS | XionHosting | Note |
|---|---|---|
| Create | `POST /servers` | provisioning usually takes one to three minutes. If it fails, the server costs nothing |
| Suspend | `POST /servers/:id/lock` | the VM is stopped, billing on our side continues |
| Unsuspend | `POST /servers/:id/unlock` | lifts only your own suspension. A suspension by us (abuse, overdue) stays, the module reports the reason |
| Terminate | `DELETE /servers/:id` | VM and data are gone immediately. From the next full hour you pay nothing |
| Change Package | add-ons | vCores and RAM go both ways and apply after the next restart. Storage only goes up. The number of IPv4 addresses stays fixed |

Admin buttons: start, shut down, restart, power off, new root password, reinstall (with the current operating system), sync with Xion.

Client area: state, IPs, credentials, power on and off, new root password, console, reverse DNS per IPv4 and reinstall with a choice of operating system. Reinstalling requires a confirmation checkbox. The client area texts are in German.

## Sync via cron

`hooks.php` hooks into the WHMCS cron:

- after every cron run: servers still being set up are checked. As soon as one is running, IP, user and password are stored in the service and the "server ready" email template is sent, if set.
- once a day: all linked servers are synced so suspensions and IPs from our side reach WHMCS.

Results are in the Activity Log with the prefix "Xion-Reseller".

## Taking over existing servers

The service under **Products/Services** has the field **Xion-Server-ID**. Enter the ID, UUID or identifier (`SRV-13046`) there and save. The module fetches IP and credentials and does not send a ready email. Saving an empty field removes the link; the server on our side stays untouched.

## Error messages

| Message starts with | Cause |
|---|---|
| API-Schlüssel ungültig | key entered incorrectly or revoked in the panel |
| Freibetrag bei Xion reicht nicht | your credit limit is used up or an invoice is overdue |
| Bei Xion ist gerade kein Platz frei | no node has enough RAM or storage, try again later |
| Zu viele Anfragen | more than 20 orders or 120 changes per minute |
| Der Server ist von XionHosting gesperrt | abuse or overdue payment, our support handles this |

Every API call is in the module log (**System Logs → Module Log**). Keys and passwords are masked there.

## Security

- Put the key in the Password field, which WHMCS stores encrypted. The Access Hash field also works and takes priority if both are filled. Give the key its own name in the Xion panel, then you can see its last use and IP there.
- A key cannot create further keys and cannot do anything outside the reseller area.
- Your customer's browser opens the console directly with us. The session is valid for 55 seconds to connect and only for this one server.

## Files

```
modules/servers/xionreseller/
  xionreseller.php        module functions
  hooks.php               cron sync
  lib/Client.php          HTTP client
  lib/Service.php         link and sync to tblhosting
  lib/functions.php       shared helpers
  templates/clientarea.tpl
  templates/console.tpl
install.sh                installation from the repository
tools/build-installer.sh  builds dist/install-xionreseller-whmcs.sh (a single file with everything)
```

## License

MIT, see [LICENSE](LICENSE). You may use, change and share the module, including the templates under `templates/` for your own design. The copyright notice must be kept.

Tip for your own changes: edits made directly in the module folder are lost on the next update, because the installation moves the old folder away. Keep your adapted templates separately.
