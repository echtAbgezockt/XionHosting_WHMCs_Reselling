{* Übersicht eines Xion-KVM-Servers im WHMCS-Kundenbereich (Bootstrap 4, Theme six / twenty-one) *}
{assign var=aktion value="clientarea.php?action=productdetails&id=`$serviceid`&modop=custom&a="}

<style>
  .xr-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(14rem, 1fr)); gap: 1rem; margin-bottom: 1.25rem; }
  .xr-kachel { border: 1px solid rgba(0,0,0,.125); border-radius: .5rem; padding: 1rem; }
  .xr-kachel dt { font-size: .8rem; font-weight: 600; text-transform: uppercase; letter-spacing: .03em; opacity: .7; margin-bottom: .25rem; }
  .xr-kachel dd { margin: 0; font-size: 1.05rem; word-break: break-word; }
  .xr-punkt { display: inline-block; width: .6rem; height: .6rem; border-radius: 50%; margin-right: .4rem; vertical-align: middle; background: #9ca3af; }
  .xr-punkt.an { background: #16a34a; } .xr-punkt.aus { background: #dc2626; } .xr-punkt.warte { background: #d97706; }
  .xr-knoepfe { display: flex; flex-wrap: wrap; gap: .5rem; margin-bottom: 1.25rem; }
  .xr-knoepfe form { margin: 0; }
  .xr-pw { font-family: ui-monospace, SFMono-Regular, Menlo, monospace; }
  .xr-tabelle td, .xr-tabelle th { vertical-align: middle; }
  .xr-tabelle .form-control { min-width: 12rem; }
  @media (prefers-reduced-motion: no-preference) { .xr-knoepfe .btn { transition: transform .12s ease-out; } .xr-knoepfe .btn:active { transform: scale(.97); } }
</style>

{if $error}
  <div class="alert alert-danger" role="alert">{$error|escape}</div>
{elseif $server}

  {if $server.status eq 'pending' or $server.status eq 'provisioning'}
    <div class="alert alert-info" role="status">
      Dein Server wird gerade eingerichtet. Das dauert meist ein bis drei Minuten. Lade die Seite danach neu.
    </div>
  {elseif $server.status eq 'failed'}
    <div class="alert alert-danger" role="alert">Die Einrichtung ist gescheitert. Bitte wende dich an den Support.</div>
  {elseif $server.status eq 'suspended'}
    <div class="alert alert-warning" role="alert">Dieser Server ist gesperrt. Start und Konsole sind nicht verfügbar.</div>
  {elseif $server.status eq 'terminated'}
    <div class="alert alert-secondary" role="status">Dieser Server ist gekündigt und gelöscht.</div>
  {/if}

  <dl class="xr-grid">
    <div class="xr-kachel">
      <dt>Zustand</dt>
      <dd>
        {if $vm && $vm.status eq 'running'}<span class="xr-punkt an" aria-hidden="true"></span>Läuft
        {elseif $vm && $vm.status eq 'stopped'}<span class="xr-punkt aus" aria-hidden="true"></span>Ausgeschaltet
        {elseif $server.status eq 'pending' or $server.status eq 'provisioning'}<span class="xr-punkt warte" aria-hidden="true"></span>In Einrichtung
        {else}<span class="xr-punkt" aria-hidden="true"></span>Unbekannt{/if}
      </dd>
    </div>
    <div class="xr-kachel">
      <dt>IPv4</dt>
      <dd>{if $server.ipv4}{foreach $server.ipv4 as $ip}{$ip|escape}<br>{/foreach}{else}–{/if}</dd>
    </div>
    <div class="xr-kachel">
      <dt>Ausstattung</dt>
      <dd>{$server.spec.vcores} vCores · {$server.spec.ram_gb} GB RAM · {$server.spec.disk_gb} GB</dd>
    </div>
    <div class="xr-kachel">
      <dt>Betriebssystem</dt>
      <dd>{if $server.template}{$server.template.name|escape}{else}–{/if}</dd>
    </div>
    <div class="xr-kachel">
      <dt>Benutzer</dt>
      <dd class="xr-pw">{if $rootuser}{$rootuser|escape}{elseif $server.username}{$server.username|escape}{else}root{/if}</dd>
    </div>
    <div class="xr-kachel">
      <dt>Passwort</dt>
      <dd>
        {if $rootpassword}
          <span class="xr-pw" id="xr-pw" data-pw="{$rootpassword|escape}">••••••••••</span>
          <button type="button" class="btn btn-link btn-sm p-0 ml-2" id="xr-pw-knopf" aria-controls="xr-pw" aria-pressed="false">Anzeigen</button>
        {else}–{/if}
      </dd>
    </div>
  </dl>

  {if $server.status eq 'active'}
    <div class="xr-knoepfe" role="group" aria-label="Server steuern">
      {if !$vm || $vm.status neq 'running'}
        <form method="post" action="{$aktion}start"><input type="hidden" name="token" value="{$token}"><button class="btn btn-success">Starten</button></form>
      {else}
        <form method="post" action="{$aktion}restart"><input type="hidden" name="token" value="{$token}"><button class="btn btn-outline-primary">Neustart</button></form>
        <form method="post" action="{$aktion}shutdown"><input type="hidden" name="token" value="{$token}"><button class="btn btn-outline-secondary">Herunterfahren</button></form>
        <form method="post" action="{$aktion}stop" onsubmit="return confirm('Server hart ausschalten? Ungespeicherte Daten gehen verloren.');"><input type="hidden" name="token" value="{$token}"><button class="btn btn-outline-danger">Hart ausschalten</button></form>
      {/if}
      {if $allowConsole}
        <a class="btn btn-outline-dark" href="{$aktion}console">Konsole öffnen</a>
      {/if}
      <form method="post" action="{$aktion}resetpassword" onsubmit="return confirm('Neues Root-Passwort setzen? Der Server startet dabei neu.');"><input type="hidden" name="token" value="{$token}"><button class="btn btn-outline-secondary">Neues Root-Passwort</button></form>
    </div>
  {/if}

  {if $ips}
    <h3 class="h5 mt-4">Reverse DNS</h3>
    <div class="table-responsive">
      <table class="table table-sm xr-tabelle">
        <thead><tr><th scope="col">Adresse</th><th scope="col">Hostname</th><th scope="col"><span class="sr-only">Aktion</span></th></tr></thead>
        <tbody>
          {foreach $ips as $ip}
            <tr>
              <td class="xr-pw">{$ip.ip|escape}</td>
              {if $ip.type eq 'ipv4' and $server.status eq 'active'}
                <td colspan="2">
                  <form method="post" action="{$aktion}rdns" class="form-inline" style="gap:.5rem">
                    <input type="hidden" name="token" value="{$token}">
                    <input type="hidden" name="ip" value="{$ip.ip|escape}">
                    <label class="sr-only" for="rdns-{$ip@index}">Hostname für {$ip.ip|escape}</label>
                    <input type="text" class="form-control form-control-sm" id="rdns-{$ip@index}" name="hostname" value="{$ip.rdns|escape}" placeholder="mail.example.com" autocomplete="off">
                    <button class="btn btn-sm btn-outline-primary">Speichern</button>
                  </form>
                </td>
              {else}
                <td>{$ip.rdns|escape|default:'–'}</td><td></td>
              {/if}
            </tr>
          {/foreach}
        </tbody>
      </table>
    </div>
    <p class="small text-muted">Leeres Feld speichern setzt den Eintrag zurück.</p>
  {/if}

  {if $allowReinstall and $templates and $server.status eq 'active'}
    <h3 class="h5 mt-4">Neu installieren</h3>
    <form method="post" action="{$aktion}reinstall" class="mb-4" id="xr-neu">
      <input type="hidden" name="token" value="{$token}">
      <div class="form-group">
        <label for="xr-os">Betriebssystem</label>
        <select class="form-control" id="xr-os" name="template_id" style="max-width:24rem">
          {foreach $templates as $t}
            <option value="{$t.id}"{if $server.template and $server.template.id eq $t.id} selected{/if}>{$t.name|escape}</option>
          {/foreach}
        </select>
      </div>
      <div class="form-check mb-3">
        <input class="form-check-input" type="checkbox" value="1" id="xr-ok" name="confirm" required>
        <label class="form-check-label" for="xr-ok">Mir ist klar, dass alle Daten auf dem Server gelöscht werden. Die IP-Adressen bleiben.</label>
      </div>
      <button class="btn btn-danger">Jetzt neu installieren</button>
    </form>
  {/if}

  <script>
    (function () {
      var knopf = document.getElementById('xr-pw-knopf');
      var feld = document.getElementById('xr-pw');
      if (!knopf || !feld) return;
      knopf.addEventListener('click', function () {
        var offen = knopf.getAttribute('aria-pressed') === 'true';
        feld.textContent = offen ? '••••••••••' : feld.getAttribute('data-pw');
        knopf.textContent = offen ? 'Anzeigen' : 'Verbergen';
        knopf.setAttribute('aria-pressed', offen ? 'false' : 'true');
      });
    })();
  </script>
{/if}
