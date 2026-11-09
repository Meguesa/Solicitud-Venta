<?php
declare(strict_types=1);

// QA puro: sin token, SharePoint ni correo real.
require __DIR__ . '/../../api/solicitud-venta/_common.php';
require __DIR__ . '/../../api/solicitud-venta/notificaciones-flujo.php';

function qaAssert(bool $condition, string $message): void {
    if (!$condition) throw new RuntimeException('QA FAILED: ' . $message);
}

$cases = [
    [false, 'No se encontraron destinatarios', 'NO_ENVIADO'],
    [false, 'Error de certificado', 'NO_ENVIADO'],
    [true, 'Microsoft Graph respondio HTTP 403: Forbidden', 'NO_ENVIADO'],
    [true, 'Microsoft Graph respondio HTTP 429', 'NO_ENVIADO'],
    [true, 'La solicitud remota fallo: Operation timed out', 'SIN_CONFIRMACION'],
    [true, 'Microsoft Graph respondio HTTP 500: Server failure', 'SIN_CONFIRMACION'],
];
foreach ($cases as [$started, $message, $expected]) {
    qaAssert(svEstadoResultadoEnvio($started, $message) === $expected, 'Clasificacion de correo: ' . $message);
}

$cases = [
    [['estado' => 'NO_ENVIADO', 'intentos' => 1], true],
    [['estado' => 'NO_ENVIADO', 'intentos' => 2], true],
    [['estado' => 'NO_ENVIADO', 'intentos' => 3], false],
    [['estado' => 'REINTENTO_EN_PROCESO', 'intentos' => 2], false],
    [['estado' => 'SIN_CONFIRMACION', 'intentos' => 1], false],
    [['estado' => 'ACEPTADO_POR_GRAPH', 'intentos' => 1], false],
    [['enviadoUtc' => '2026-11-09T20:00:00Z'], false],
    [[], false],
];
foreach ($cases as [$evidence, $expected]) {
    qaAssert(svPermisoReintentoNotificacion($evidence)['reintentoPermitido'] === $expected,
        'Politica de reintento ' . json_encode($evidence));
}

$notificaciones = file_get_contents(__DIR__ . '/../../api/solicitud-venta/notificaciones-flujo.php');
qaAssert(str_contains($notificaciones, "':?\$select=id,eTag'"), 'URL Graph metadata con dos puntos final');
qaAssert(str_contains($notificaciones, "'If-Match: ' . \$ifMatch"), 'Control condicional de evidencia');
qaAssert(str_contains($notificaciones, "'@microsoft.graph.conflictBehavior' => 'fail'"),
    'Reserva rechaza intento ya existente');
qaAssert(str_contains($notificaciones, "'_REINTENTO_CORREO_COMERCIAL_' . $intento"),
    'Reserva identificada por numero de intento');
$vobo = file_get_contents(__DIR__ . '/../../api/solicitud-venta/vobo.php');
qaAssert(str_contains($vobo, "NOTIFICATION_CSRF_INVALID"), 'Reintento exige protección de sesión');
qaAssert(strpos($vobo, 'svReservarReintentoComercial(') <
    strpos($vobo, "svGuardarEvidenciaNotificacion(\n            \$graphToken, \$config['siteId']"),
    'Reserva debe suceder antes de intentar el correo');

$source = file_get_contents(__DIR__ . '/../../api/solicitud-venta/borrador.php');
$start = strpos($source, 'function versionItemSharePoint(');
$end = strpos($source, 'function verificarBorradorUsuario(', $start);
qaAssert($start !== false && $end !== false, 'Funciones de control de version encontradas');
$functions = substr($source, $start, $end - $start);
eval($functions);

$qaRevision = '"v1"';
$qaFields = ['field_8' => 'Original'];
$qaRequests = [];
function ejecutarCurlJson(string $url, string $method, array $headers, ?string $body = null): array {
    global $qaRevision, $qaFields, $qaRequests;
    $qaRequests[] = ['method' => $method, 'headers' => $headers];
    if ($method === 'GET') return ['eTag' => $qaRevision];
    if ($method !== 'PATCH') throw new RuntimeException('Accion simulada invalida');
    $match = '';
    foreach ($headers as $h) if (str_starts_with($h, 'If-Match: ')) $match = substr($h, 10);
    if ($match !== $qaRevision) throw new RuntimeException('Servicio remoto respondio HTTP 412');
    $qaFields = array_merge($qaFields, json_decode((string) $body, true, flags: JSON_THROW_ON_ERROR));
    $version = (int) trim($qaRevision, '"v');
    $qaRevision = '"v' . ($version + 1) . '"';
    return ['ok' => true];
}

$readA = versionItemSharePoint('TEST', 'SITE', 'LIST', '123');
$readB = versionItemSharePoint('TEST', 'SITE', 'LIST', '123');
qaAssert($readA === $readB, 'Dos pestañas obtienen version inicial');
actualizarCamposItemSharePoint('TEST', 'SITE', 'LIST', '123', ['field_8' => 'Sesion A'], $readA);
qaAssert($qaFields['field_8'] === 'Sesion A', 'Primera sesion guardada');
$blocked = false;
try {
    actualizarCamposItemSharePoint('TEST', 'SITE', 'LIST', '123', ['field_8' => 'Sesion B'], $readB);
} catch (RuntimeException $e) {
    $blocked = str_contains($e->getMessage(), 'HTTP 412');
}
qaAssert($blocked && $qaFields['field_8'] === 'Sesion A', 'Sesion B no sobreescribe datos de A');
$fresh = versionItemSharePoint('TEST', 'SITE', 'LIST', '123');
actualizarCamposItemSharePoint('TEST', 'SITE', 'LIST', '123', ['field_8' => 'Sesion B recargada'], $fresh);
qaAssert($qaFields['field_8'] === 'Sesion B recargada', 'Reapertura con revision reciente permite guardar');

qaAssert(str_contains($source, 'DRAFT_REVISION_REQUIRED'), 'Borradores antiguos sin version se bloquean sin sobrescribir');
qaAssert(str_contains($source, 'DRAFT_CONFLICT'), 'Error 412 se comunica como conflicto');
qaAssert(str_contains($source, 'El folio no corresponde al item del borrador'), 'Folio/ID pertenecen al mismo registro');
$restore = file_get_contents(__DIR__ . '/../../api/solicitud-venta/estado-borrador.php');
qaAssert(str_contains($restore, "'revision' => (string)"), 'Recuperacion de borrador devuelve revision');

echo "PASS: 6 estados de correo + 8 politicas de reintento + 3 interacciones ETag + 8 controles estaticos.\n";
