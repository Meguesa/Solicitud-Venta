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

$sourcePath = __DIR__ . '/index.html';
$source = is_file($sourcePath) ? file_get_contents($sourcePath) : false;
if (!is_string($source) || $source === '') {
    http_response_code(500);
    exit('No fue posible cargar la interfaz de Solicitud de Venta.');
}

// Solicitud de Venta reutiliza la sesion autenticada del Portal. Ya no inicia
// una segunda sesion MSAL independiente dentro de la herramienta.
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

// Ajustes visuales seguros. No modifican el wizard ni el estado de la solicitud.
$uiCleanupStyle = <<<'HTML'
<style id="solicitudUiCleanup">
  .app-header > div:first-child > h1 {
    display: none !important;
  }

  #btnRegresarInicioSolicitud,
  #btnRegresarInicioInferior {
    display: none !important;
  }

  #wizardSummary:not([hidden]) + #wizardNav #wizardNext {
    display: none !important;
  }
</style>
HTML;

// El Resumen final del wizard es un clon de las secciones. Si el enlace remoto
// se genera mientras ese resumen esta visible, el formulario original recibe la
// URL pero el clon conserva el estado anterior (oculto). Este puente replica la
// URL al resumen inmediatamente, sin recargar ni volver a abrir la solicitud.
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
    area.value = valor;
    area.setAttribute('readonly', 'readonly');
    area.style.position = 'fixed';
    area.style.opacity = '0';
    document.body.appendChild(area);
    area.select();
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

    resultBox.hidden = false;
    resultBox.removeAttribute('hidden');

    const row = resultBox.querySelector('.remote-signature-link-row');
    const input = row?.querySelector('input');
    if (input) {
      input.disabled = false;
      input.readOnly = true;
      input.value = firmaUrl;
      input.setAttribute('value', firmaUrl);
    }

    if (row && !row.querySelector('[data-summary-copy-remote-link]')) {
      const button = document.createElement('button');
      button.type = 'button';
      button.textContent = 'Copiar enlace';
      button.dataset.summaryCopyRemoteLink = '1';
      button.addEventListener('click', () => copiarTexto(firmaUrl));
      row.appendChild(button);
    }
  }

  function programarSincronizacion() {
    if (pendiente) return;
    pendiente = true;
    window.requestAnimationFrame(sincronizarEnlaceResumen);
  }

  function iniciar() {
    programarSincronizacion();

    const observer = new MutationObserver(programarSincronizacion);
    observer.observe(document.body, {
      subtree: true,
      childList: true,
      attributes: true,
      attributeFilter: ['hidden', 'class']
    });

    document.addEventListener('click', (event) => {
      const target = event.target instanceof Element ? event.target.closest('#btnValidate') : null;
      if (!target) return;
      [150, 500, 1200, 2500, 5000].forEach((delay) => {
        window.setTimeout(programarSincronizacion, delay);
      });
    }, true);

    // Respaldo para cambios de value que no generan mutaciones DOM.
    window.setInterval(programarSincronizacion, 1000);
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', iniciar, { once: true });
  } else {
    iniciar();
  }
})();
</script>
HTML;

if (strpos($source, '</head>') !== false) {
    $source = str_replace(
        '</head>',
        '  ' . $bootstrapScript . "\n  " . $uiCleanupStyle . "\n  " . $remoteLinkSummaryFix . "\n</head>",
        $source
    );
} else {
    $source = $bootstrapScript . $uiCleanupStyle . $remoteLinkSummaryFix . $source;
}

header('Content-Type: text/html; charset=utf-8');
header('Cache-Control: no-store');
echo $source;