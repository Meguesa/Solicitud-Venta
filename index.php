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
// 3) En el Paso 11 / Resumen no debe existir una accion "Siguiente".
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

if (strpos($source, '</head>') !== false) {
    $source = str_replace('</head>', '  ' . $bootstrapScript . "\n  " . $uiCleanupStyle . "\n</head>", $source);
} else {
    $source = $bootstrapScript . $uiCleanupStyle . $source;
}

header('Content-Type: text/html; charset=utf-8');
header('Cache-Control: no-store');
echo $source;
