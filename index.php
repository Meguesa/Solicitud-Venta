<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/bootstrap.php';
portal_require_authentication();

$user = portal_user();
$session = [
    'authenticated' => true,
    'user' => [
        'id' => (string) ($user['id'] ?? ''),
        'name' => (string) ($user['name'] ?? 'Usuario'),
        'email' => strtolower(trim((string) ($user['email'] ?? ''))),
    ],
];
$nameEsc = htmlspecialchars((string) ($user['name'] ?? 'Usuario'), ENT_QUOTES, 'UTF-8');
$emailEsc = htmlspecialchars(strtolower(trim((string) ($user['email'] ?? ''))), ENT_QUOTES, 'UTF-8');

$sourcePath = __DIR__ . '/index.html';
$source = is_file($sourcePath) ? file_get_contents($sourcePath) : false;
if (!is_string($source) || $source === '') {
    http_response_code(500);
    exit('No fue posible cargar la interfaz de Solicitud de Venta.');
}

$source = str_replace(
    '<div class="folio-box"><span>Folio</span><strong>PENDIENTE</strong></div>',
    '<div class="user-box"><button id="btnVolverMisSolicitudes" class="secondary-button" type="button" onclick="window.location.href=\'/solicitud-venta/inicio/\'">Mis solicitudes</button><div class="folio-box"><span>Folio</span><strong>PENDIENTE</strong></div></div>',
    $source
);

$source = preg_replace(
    '#\s*<script[^>]+(?:msal-browser|alcdn\.msauth|cdn\.jsdelivr\.net/npm/@azure/msal-browser)[^>]*></script>\s*#i',
    "\n",
    $source
) ?? $source;

$sessionJson = json_encode(
    $session,
    JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT
);
$bootstrapScript = '<script>window.SOLICITUD_PORTAL_SESSION=' . $sessionJson . ';</script>';

$toolbar = <<<HTML
<header class="solicitud-topbar">
  <div class="solicitud-topbar-inner">
    <div class="solicitud-topbar-left">
      <img class="solicitud-topbar-logo" src="/mapa/assets/logo.jpg" alt="Jardines de Juan Pablo">
      <div class="solicitud-topbar-title">
        <strong>Solicitud de Venta</strong>
        <span>Portal Interno JdJP · Jardines de Juan Pablo</span>
      </div>
    </div>
    <div class="solicitud-topbar-context">Captura digital de solicitudes comerciales</div>
    <div class="solicitud-topbar-actions">
      <a class="solicitud-topbar-back" href="/">Regresar al portal</a>
      <details class="account-menu">
        <summary class="account-trigger" aria-label="Abrir menú de usuario" title="{$nameEsc}">
          <svg viewBox="0 0 24 24" aria-hidden="true">
            <circle cx="12" cy="8" r="4" fill="currentColor" />
            <path d="M4 20c0-4.1 3.6-6 8-6s8 1.9 8 6v1H4z" fill="currentColor" />
          </svg>
        </summary>
        <div class="account-menu-panel">
          <div class="account-menu-info"><strong>{$nameEsc}</strong><span>{$emailEsc}</span></div>
          <a class="account-menu-logout" href="/logout.php">Cerrar sesión</a>
        </div>
      </details>
    </div>
  </div>
</header>
HTML;

$uiCleanupStyle = <<<'HTML'
<style id="solicitudUiCleanup">
  .app-header { display: none !important; }
  #btnRegresarInicioSolicitud,
  #btnRegresarInicioInferior { display: none !important; }
  #wizardSummary:not([hidden]) + #wizardNav #wizardNext { display: none !important; }
  #pdfPreliminarActions { border-left: 4px solid var(--jp-gold); }
  #pdfPreliminarActions .pdf-preliminar-copy { margin-bottom: 14px; }
  #pdfPreliminarActions .pdf-preliminar-copy h3 { margin-bottom: 5px; }
  #pdfPreliminarActions .pdf-preliminar-copy p { margin: 0; color: var(--jp-muted); font-size: 12px; line-height: 1.5; }
  #pdfPreliminarActions .pdf-preliminar-buttons { display: flex; flex-wrap: wrap; gap: 10px; }
</style>
HTML;

$saveBeforeSubmitFix = <<<'HTML'
<script id="solicitudSaveBeforeSubmitFix">
(() => {
  let guardando = false;
  let reenvioAutorizado = false;
  function mostrar(texto, tipo = '') {
    if (typeof window.mostrarMensaje === 'function') { window.mostrarMensaje(texto, tipo); return; }
    const mensaje = document.getElementById('formMessage');
    if (!mensaje) return;
    mensaje.textContent = texto || '';
    mensaje.className = `form-message ${tipo}`.trim();
  }
  function iniciar() {
    const form = document.getElementById('solicitudForm');
    if (!form || form.dataset.saveBeforeSubmitFix === '1') return;
    form.dataset.saveBeforeSubmitFix = '1';
    form.addEventListener('submit', async (event) => {
      if (reenvioAutorizado) { reenvioAutorizado = false; return; }
      const params = new URLSearchParams(window.location.search);
      if (params.get('correccion') === '1') return;
      if (document.getElementById('modalidadFirma')?.value === 'REMOTA') return;
      if (typeof window.guardarBorrador !== 'function') return;
      event.preventDefault();
      event.stopImmediatePropagation();
      if (guardando) return;
      guardando = true;
      const submitter = event.submitter instanceof HTMLElement ? event.submitter : document.getElementById('btnValidate');
      try {
        mostrar('Guardando los ultimos cambios antes de enviar a Vo.Bo...');
        await window.guardarBorrador();
        reenvioAutorizado = true;
        if (typeof form.requestSubmit === 'function') {
          if (submitter instanceof HTMLButtonElement || submitter instanceof HTMLInputElement) form.requestSubmit(submitter);
          else form.requestSubmit();
        } else {
          reenvioAutorizado = false;
          throw new Error('El navegador no permite continuar automaticamente con la validacion.');
        }
      } catch (error) {
        reenvioAutorizado = false;
        console.error('No fue posible guardar antes de enviar a Vo.Bo.:', error);
        mostrar(`No fue posible guardar los ultimos cambios antes de enviar a Vo.Bo.: ${error?.message || error}`, 'error');
      } finally { guardando = false; }
    }, true);
  }
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', iniciar, { once: true });
  else iniciar();
})();
</script>
HTML;

$remoteLinkSummaryFix = <<<'HTML'
<script id="solicitudRemoteLinkSummaryFix">
(() => {
  let pendiente = false;
  function copiarTexto(texto) {
    const valor = String(texto || '').trim();
    if (!valor) return;
    if (navigator.clipboard && typeof navigator.clipboard.writeText === 'function') {
      navigator.clipboard.writeText(valor).catch(() => copiarFallback(valor));
      return;
    }
    copiarFallback(valor);
  }
  function copiarFallback(valor) {
    const area = document.createElement('textarea');
    area.value = valor; area.setAttribute('readonly', 'readonly'); area.style.position = 'fixed'; area.style.opacity = '0';
    document.body.appendChild(area); area.select();
    try { document.execCommand('copy'); } catch (_) {}
    area.remove();
  }
  function sincronizarEnlaceResumen() {
    pendiente = false;
    const sourceInput = document.getElementById('firmaRemotaUrl');
    const firmaUrl = String(sourceInput?.value || '').trim();
    if (!firmaUrl) return;
    const summary = document.getElementById('wizardSummary');
    if (!summary || summary.hidden) return;
    const resultBox = summary.querySelector('.remote-signature-result');
    if (!resultBox) return;
    resultBox.hidden = false; resultBox.removeAttribute('hidden');
    const row = resultBox.querySelector('.remote-signature-link-row');
    const input = row?.querySelector('input');
    if (input) { input.disabled = false; input.readOnly = true; input.value = firmaUrl; input.setAttribute('value', firmaUrl); }
    if (row && !row.querySelector('[data-summary-copy-remote-link]')) {
      const button = document.createElement('button'); button.type = 'button'; button.textContent = 'Copiar enlace'; button.dataset.summaryCopyRemoteLink = '1';
      button.addEventListener('click', () => copiarTexto(firmaUrl)); row.appendChild(button);
    }
  }
  function programarSincronizacion() { if (pendiente) return; pendiente = true; window.requestAnimationFrame(sincronizarEnlaceResumen); }
  function iniciar() {
    programarSincronizacion();
    const observer = new MutationObserver(programarSincronizacion);
    observer.observe(document.body, { subtree: true, childList: true, attributes: true, attributeFilter: ['hidden', 'class'] });
    document.addEventListener('click', (event) => {
      const target = event.target instanceof Element ? event.target.closest('#btnValidate') : null;
      if (!target) return;
      [150, 500, 1200, 2500, 5000].forEach((delay) => window.setTimeout(programarSincronizacion, delay));
    }, true);
    window.setInterval(programarSincronizacion, 1000);
  }
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', iniciar, { once: true });
  else iniciar();
})();
</script>
HTML;

$pdfPreliminarUi = <<<'HTML'
<script id="solicitudPdfPreliminarUi">
(() => {
  let ultimoFolio = '';

  function folioActual() {
    const folio = String(document.querySelector('.folio-box strong')?.textContent || '').trim().toUpperCase();
    return /^SV-\d{4}-\d{6,}$/.test(folio) ? folio : '';
  }

  function estatusActual() {
    return String(document.querySelector('.status-pill')?.textContent || document.body.dataset.solicitudEstatus || '')
      .trim()
      .toUpperCase();
  }

  function urlPdf(folio) {
    return `/api/solicitud-venta/pdf-preliminar.php?folio=${encodeURIComponent(folio)}`;
  }

  function mostrarMensaje(texto, tipo = '') {
    if (typeof window.mostrarMensaje === 'function') {
      window.mostrarMensaje(texto, tipo);
      return;
    }
    const mensaje = document.getElementById('formMessage');
    if (!mensaje) return;
    mensaje.textContent = texto || '';
    mensaje.className = `form-message ${tipo}`.trim();
  }

  async function obtenerArchivo(folio) {
    const response = await fetch(urlPdf(folio), { method: 'GET', cache: 'no-store', credentials: 'same-origin' });
    if (!response.ok) {
      const data = await response.json().catch(() => null);
      throw new Error(data?.message || data?.error || `HTTP ${response.status}`);
    }
    const blob = await response.blob();
    if (!blob.size) throw new Error('El PDF preliminar se recibió vacío.');
    return new File([blob], `SOLICITUD_PRELIMINAR_${folio}.pdf`, { type: 'application/pdf' });
  }

  function descargarArchivo(file) {
    const enlace = document.createElement('a');
    const objectUrl = URL.createObjectURL(file);
    enlace.href = objectUrl;
    enlace.download = file.name;
    enlace.style.display = 'none';
    document.body.appendChild(enlace);
    enlace.click();
    enlace.remove();
    window.setTimeout(() => URL.revokeObjectURL(objectUrl), 2000);
  }

  async function compartir(folio, button) {
    const textoOriginal = button.textContent;
    button.disabled = true;
    button.textContent = 'Preparando PDF...';
    try {
      const file = await obtenerArchivo(folio);
      const shareData = {
        title: `Solicitud de Venta ${folio}`,
        text: `Documento preliminar no oficial de la Solicitud de Venta ${folio}.`,
        files: [file]
      };

      if (typeof navigator.share === 'function' && (!navigator.canShare || navigator.canShare(shareData))) {
        await navigator.share(shareData);
        mostrarMensaje(`PDF preliminar ${folio} listo para compartir.`, 'ok');
        return;
      }

      descargarArchivo(file);
      mostrarMensaje('Este navegador no permite compartir archivos directamente. El PDF se descargó para que puedas adjuntarlo por correo, WhatsApp u otra aplicación.', 'ok');
    } catch (error) {
      if (error?.name === 'AbortError') return;
      console.error('No fue posible compartir el PDF preliminar:', error);
      mostrarMensaje(`No fue posible compartir el PDF preliminar: ${error?.message || error}`, 'error');
    } finally {
      button.disabled = false;
      button.textContent = textoOriginal;
    }
  }

  async function descargar(folio, button) {
    const textoOriginal = button.textContent;
    button.disabled = true;
    button.textContent = 'Preparando...';
    try {
      const file = await obtenerArchivo(folio);
      descargarArchivo(file);
      mostrarMensaje(`PDF preliminar ${folio} descargado.`, 'ok');
    } catch (error) {
      console.error('No fue posible descargar el PDF preliminar:', error);
      mostrarMensaje(`No fue posible descargar el PDF preliminar: ${error?.message || error}`, 'error');
    } finally {
      button.disabled = false;
      button.textContent = textoOriginal;
    }
  }

  function crearAcciones(folio) {
    const existente = document.getElementById('pdfPreliminarActions');
    if (existente) {
      existente.dataset.folio = folio;
      return;
    }

    const form = document.getElementById('solicitudForm');
    const actions = form?.querySelector('.form-actions');
    if (!form || !actions) return;

    const section = document.createElement('section');
    section.id = 'pdfPreliminarActions';
    section.className = 'form-section';
    section.dataset.folio = folio;
    section.innerHTML = `
      <div class="pdf-preliminar-copy">
        <h3>PDF preliminar disponible</h3>
        <p>Documento de consulta mientras la solicitud se encuentra en Vo.Bo. Comercial. Está marcado como NO OFICIAL y no incluye los Vo.Bo. ni firmas de Comercial o Cobranza.</p>
      </div>
      <div class="pdf-preliminar-buttons">
        <button id="btnVerPdfPreliminar" class="secondary-button" type="button">Ver PDF preliminar</button>
        <button id="btnDescargarPdfPreliminar" class="secondary-button" type="button">Descargar PDF</button>
        <button id="btnCompartirPdfPreliminar" class="primary-button" type="button">Compartir PDF</button>
      </div>`;

    form.insertBefore(section, actions);

    section.querySelector('#btnVerPdfPreliminar')?.addEventListener('click', () => {
      window.open(urlPdf(folio), '_blank', 'noopener');
    });
    section.querySelector('#btnDescargarPdfPreliminar')?.addEventListener('click', (event) => descargar(folio, event.currentTarget));
    section.querySelector('#btnCompartirPdfPreliminar')?.addEventListener('click', (event) => compartir(folio, event.currentTarget));
  }

  function sincronizar() {
    const folio = folioActual();
    const estatus = estatusActual();
    const disponible = folio && ['PENDIENTE VOBO', 'PENDIENTE COBRANZA'].includes(estatus);

    if (!disponible) {
      document.getElementById('pdfPreliminarActions')?.remove();
      ultimoFolio = '';
      return;
    }

    if (folio !== ultimoFolio || !document.getElementById('pdfPreliminarActions')) {
      ultimoFolio = folio;
      crearAcciones(folio);
    }
  }

  function iniciar() {
    sincronizar();
    const observer = new MutationObserver(() => window.requestAnimationFrame(sincronizar));
    observer.observe(document.body, { subtree: true, childList: true, characterData: true, attributes: true, attributeFilter: ['class', 'data-solicitud-estatus'] });
    window.setInterval(sincronizar, 1200);
  }

  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', iniciar, { once: true });
  else iniciar();
})();
</script>
HTML;

if (strpos($source, '</head>') !== false) {
    $source = str_replace(
        '</head>',
        '  <link rel="stylesheet" href="/assets/css/account-menu.css">' . "\n  " . $bootstrapScript . "\n  " . $uiCleanupStyle . "\n  " . $saveBeforeSubmitFix . "\n  " . $remoteLinkSummaryFix . "\n  " . $pdfPreliminarUi . "\n</head>",
        $source
    );
} else {
    $source = $bootstrapScript . $uiCleanupStyle . $saveBeforeSubmitFix . $remoteLinkSummaryFix . $pdfPreliminarUi . $source;
}

if (strpos($source, '<body>') !== false) {
    $source = str_replace('<body>', '<body>' . "\n" . $toolbar, $source);
}

header('Content-Type: text/html; charset=utf-8');
header('Cache-Control: no-store');
echo $source;