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

// Ajustes de interfaz comunes a todos los estados de la solicitud:
// 1) El encabezado superior conserva solamente la marca Jardines de Juan Pablo.
// 2) El unico acceso visible a Inicio es el del encabezado superior.
// 3) En el Paso final / Resumen no debe existir una accion "Siguiente".
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

// El modulo de correcciones ya contenia esta reparacion, pero solo se ejecutaba
// con ?correccion=1. La aplicamos a cualquier solicitud para que, cuando el
// wizard indique que el paso activo es Firmas, la seccion dinamica creada por
// extras.js no pueda quedarse con la clase wizard-page-hidden.
$firmasVisibilityFix = <<<'HTML'
<script id="solicitudFirmasVisibilityFix">
(() => {
  const normalizar = (valor) => String(valor || '')
    .trim()
    .toUpperCase()
    .normalize('NFD')
    .replace(/[\u0300-\u036f]/g, '');

  function asegurarFirmasVisibles() {
    const titulo = normalizar(document.getElementById('wizardStepTitle')?.textContent || '');
    if (titulo !== 'FIRMAS') return;

    const section = document.getElementById('firmasSection');
    if (!(section instanceof HTMLElement)) return;

    section.hidden = false;
    section.classList.remove('wizard-page-hidden');
    section.classList.add('wizard-page-active');
    section.style.removeProperty('display');

    ['.section-title', '.remote-signature-mode', '.signature-grid'].forEach((selector) => {
      const node = section.querySelector(selector);
      if (!(node instanceof HTMLElement)) return;
      node.hidden = false;
      node.style.removeProperty('display');
    });
  }

  function iniciar() {
    asegurarFirmasVisibles();

    let ciclos = 0;
    const timer = window.setInterval(() => {
      ciclos += 1;
      asegurarFirmasVisibles();
      if (ciclos >= 80) window.clearInterval(timer);
    }, 250);

    document.addEventListener('click', (event) => {
      const target = event.target instanceof Element
        ? event.target.closest('#wizardNext, #wizardBack')
        : null;
      if (!target) return;
      window.setTimeout(asegurarFirmasVisibles, 0);
      window.setTimeout(asegurarFirmasVisibles, 80);
    }, true);

    window.addEventListener('focus', asegurarFirmasVisibles);
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', iniciar, { once: true });
  } else {
    iniciar();
  }
})();
</script>
HTML;

// Cuando se envia una firma remota desde el Resumen, firma-remota.js actualiza
// la seccion original de Firmas, no la copia que muestra el resumen. Al pasar a
// PENDIENTE FIRMA regresamos automaticamente a la seccion real y restauramos la
// liga guardada localmente para que siempre quede visible y copiable.
$firmaRemotaPendingFix = <<<'HTML'
<script id="solicitudFirmaRemotaPendingFix">
(() => {
  const STORAGE_PREFIX = 'solicitudVenta:borradorActivo:v1:';
  let regresando = false;

  function normalizar(valor) {
    return String(valor || '').trim().toUpperCase().replace(/\s+/g, ' ');
  }

  function estaPendienteFirma() {
    const body = normalizar(document.body.dataset.solicitudEstatus || '');
    const pill = normalizar(document.querySelector('.status-pill')?.textContent || '');
    const boton = normalizar(document.getElementById('btnValidate')?.textContent || '');
    const mensaje = normalizar(document.getElementById('formMessage')?.textContent || '');
    return body.includes('PENDIENTE FIRMA')
      || pill.includes('PENDIENTE FIRMA')
      || boton.includes('PENDIENTE DE FIRMA')
      || mensaje.includes('PENDIENTE DE FIRMA');
  }

  function obtenerFolio() {
    const visible = String(document.querySelector('.folio-box strong')?.textContent || '').trim().toUpperCase();
    if (/^SV-\d{4}-\d+$/.test(visible)) return visible;
    const query = String(new URLSearchParams(location.search).get('folio') || '').trim().toUpperCase();
    return /^SV-\d{4}-\d+$/.test(query) ? query : '';
  }

  function leerReferenciaLocal() {
    try {
      const correo = String(
        window.SOLICITUD_PORTAL_SESSION?.user?.email
        || document.getElementById('userEmail')?.textContent
        || ''
      ).trim().toLowerCase();
      if (!correo) return null;
      const raw = localStorage.getItem(`${STORAGE_PREFIX}${correo}`);
      return raw ? JSON.parse(raw) : null;
    } catch (_) {
      return null;
    }
  }

  function restaurarLiga() {
    const input = document.getElementById('firmaRemotaUrl');
    const resultado = document.getElementById('firmaRemotaResultado');
    if (!(input instanceof HTMLInputElement) || !(resultado instanceof HTMLElement)) return;

    let url = String(input.value || '').trim();
    if (!url) {
      const referencia = leerReferenciaLocal();
      const folio = obtenerFolio();
      const folioGuardado = String(referencia?.folio || '').trim().toUpperCase();
      if (folio && folioGuardado === folio) url = String(referencia?.firmaUrl || '').trim();
    }

    if (url) {
      input.value = url;
      resultado.hidden = false;
      resultado.style.removeProperty('display');
    }
  }

  function mostrarPanelGestion() {
    const panel = document.getElementById('firmaRemotaGestion')
      || document.getElementById('firmaRemotaRecuperacionFix');
    if (!(panel instanceof HTMLElement)) return;
    panel.hidden = false;
    panel.style.removeProperty('display');
  }

  function salirDelResumen() {
    const resumen = document.getElementById('wizardSummary');
    const atras = document.getElementById('wizardBack');
    if (!(resumen instanceof HTMLElement) || resumen.hidden) return;
    if (!(atras instanceof HTMLButtonElement) || atras.disabled || regresando) return;

    regresando = true;
    atras.click();
    window.setTimeout(() => {
      regresando = false;
      restaurarLiga();
      mostrarPanelGestion();
      document.getElementById('firmasSection')?.scrollIntoView({ behavior: 'smooth', block: 'start' });
    }, 120);
  }

  function reconciliar() {
    if (!estaPendienteFirma()) return;
    salirDelResumen();
    restaurarLiga();
    mostrarPanelGestion();
  }

  function iniciar() {
    reconciliar();
    window.setInterval(reconciliar, 500);
    window.addEventListener('focus', reconciliar);
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
    $source = str_replace('</head>', '  ' . $bootstrapScript . "\n  " . $uiCleanupStyle . "\n</head>", $source);
} else {
    $source = $bootstrapScript . $uiCleanupStyle . $source;
}

if (strpos($source, '</body>') !== false) {
    $source = str_replace('</body>', $firmasVisibilityFix . "\n" . $firmaRemotaPendingFix . "\n</body>", $source);
} else {
    $source .= $firmasVisibilityFix . $firmaRemotaPendingFix;
}

header('Content-Type: text/html; charset=utf-8');
header('Cache-Control: no-store');
echo $source;
