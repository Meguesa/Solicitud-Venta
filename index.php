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

if (strpos($source, '</head>') !== false) {
    $source = str_replace('</head>', '  ' . $bootstrapScript . "\n  " . $uiCleanupStyle . "\n</head>", $source);
} else {
    $source = $bootstrapScript . $uiCleanupStyle . $source;
}

if (strpos($source, '</body>') !== false) {
    $source = str_replace('</body>', $firmasVisibilityFix . "\n</body>", $source);
} else {
    $source .= $firmasVisibilityFix;
}

header('Content-Type: text/html; charset=utf-8');
header('Cache-Control: no-store');
echo $source;
