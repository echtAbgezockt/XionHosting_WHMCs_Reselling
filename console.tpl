{* VNC-Konsole. Der Browser verbindet sich direkt mit der Xion-API, WHMCS leitet nichts durch. *}
<style>
  .xr-konsole-kopf { display: flex; flex-wrap: wrap; align-items: center; gap: .75rem; margin-bottom: .75rem; }
  .xr-konsole-kopf .xr-status { margin-right: auto; }
  #xr-bildschirm { width: 100%; height: 70vh; min-height: 22rem; background: #111; border-radius: .5rem; overflow: hidden; }
</style>

<p><a href="clientarea.php?action=productdetails&amp;id={$serviceid}">← Zurück zum Server</a></p>

{if $error}
  <div class="alert alert-danger" role="alert">{$error|escape}</div>
{else}
  <div class="xr-konsole-kopf">
    <span class="xr-status" id="xr-status" role="status" aria-live="polite">Verbinde …</span>
    <button type="button" class="btn btn-sm btn-outline-secondary" id="xr-cad" disabled>Strg+Alt+Entf senden</button>
    <button type="button" class="btn btn-sm btn-outline-secondary" id="xr-voll">Vollbild</button>
  </div>
  <div id="xr-bildschirm"></div>
  <p class="small text-muted mt-2">Die Sitzung gilt nur für diesen Aufruf. Lädt die Seite neu, wird eine neue Sitzung geöffnet.</p>

  <script type="module">
    import RFB from 'https://cdn.jsdelivr.net/npm/@novnc/novnc@1.4.0/core/rfb.js';

    const status = document.getElementById('xr-status');
    const cad = document.getElementById('xr-cad');
    const ziel = document.getElementById('xr-bildschirm');

    const rfb = new RFB(ziel, {$websocketJson}, { credentials: { password: {$vncpasswordJson} } });
    rfb.scaleViewport = true;
    rfb.resizeSession = false;

    rfb.addEventListener('connect', () => { status.textContent = 'Verbunden'; cad.disabled = false; rfb.focus(); });
    rfb.addEventListener('disconnect', (e) => {
      cad.disabled = true;
      status.textContent = e.detail.clean ? 'Verbindung beendet.' : 'Verbindung abgebrochen. Seite neu laden für eine neue Sitzung.';
    });
    rfb.addEventListener('securityfailure', () => { status.textContent = 'Anmeldung an der Konsole fehlgeschlagen.'; });

    cad.addEventListener('click', () => rfb.sendCtrlAltDel());
    document.getElementById('xr-voll').addEventListener('click', () => {
      if (ziel.requestFullscreen) ziel.requestFullscreen();
    });
  </script>
{/if}
