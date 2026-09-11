<?php

declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');
header('X-Robots-Tag: noindex, nofollow, noarchive');

require_once __DIR__ . '/_common.php';
require_once __DIR__ . '/consentimiento-privacidad.php';

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    svResponderError(405, 'METHOD_NOT_ALLOWED', 'Metodo no permitido.');
}

$config = svConfig();
$raw = file_get_contents('php://input');
$payload = json_decode(is_string($raw) ? $raw : '', true);
if (!is_array($payload)) svResponderError(400, 'INVALID_JSON', 'El cuerpo debe ser JSON valido.');

$tokenPlano = trim((string) ($payload['token'] ?? ''));
if (!preg_match('/^(SV-\d{4}-\d{6,})\.([a-fA-F0-9]{64})$/', $tokenPlano, $match)) {
    svResponderError(400, 'INVALID_TOKEN', 'El enlace de firma no es valido.');
}
$folio = strtoupper($match[1]);

if (!(bool) ($payload['consentimientoPrivacidad'] ?? false)) {
    svResponderError(400, 'CONSENT_REQUIRED', 'Debes aceptar el Aviso de Privacidad antes de firmar.');
}
if (!hash_equals(svConsentimientoPrivacidadVersion(), trim((string) ($payload['consentimientoVersion'] ?? '')))) {
    svResponderError(409, 'CONSENT_VERSION_MISMATCH', 'La version del consentimiento ya no es vigente. Recarga el enlace de firma.');
}
if (!hash_equals(svAvisoPrivacidadVersion(), trim((string) ($payload['avisoPrivacidadVersion'] ?? '')))) {
    svResponderError(409, 'PRIVACY_VERSION_MISMATCH', 'La version del Aviso de Privacidad ya no es vigente. Recarga el enlace de firma.');
}

$firmaDataUrl = trim((string) ($payload['firmaDataUrl'] ?? ''));
if (!preg_match('/^data:image\/png;base64,(.+)$/s', $firmaDataUrl, $firmaMatch)) {
    svResponderError(400, 'INVALID_SIGNATURE', 'La firma no tiene un formato valido.');
}
$firmaBinaria = base64_decode($firmaMatch[1], true);
if ($firmaBinaria === false || strlen($firmaBinaria) < 500) {
    svResponderError(400, 'EMPTY_SIGNATURE', 'La firma esta vacia.');
}
if (strlen($firmaBinaria) > 3 * 1024 * 1024) {
    svResponderError(413, 'SIGNATURE_TOO_LARGE', 'La firma supera el limite permitido.');
}

try {
    $graphToken = svGraphToken($config['tenantId'], $config['clientId'], $config['clientSecret']);
    $driveId = svrConsentimientoDrive($graphToken, $config['siteId']);
    $estado = svrConsentimientoCargarEstado($graphToken, $driveId, $folio);

    if (strtoupper(trim((string) ($estado['folio'] ?? ''))) !== $folio) {
        svResponderError(409, 'TOKEN_MISMATCH', 'El enlace no corresponde a esta solicitud.');
    }
    $hash = trim((string) ($estado['tokenHash'] ?? ''));
    if ($hash === '' || !hash_equals($hash, hash('sha256', $tokenPlano))) {
        svResponderError(403, 'TOKEN_FORBIDDEN', 'El enlace de firma no es valido.');
    }
    $expira = strtotime((string) ($estado['expiraUtc'] ?? ''));
    if ($expira !== false && time() > $expira) {
        svResponderError(410, 'TOKEN_EXPIRED', 'El enlace de firma ha expirado. Solicita uno nuevo a tu asesor.');
    }
    if ((bool) ($estado['firmado'] ?? false)) {
        svResponderError(409, 'ALREADY_SIGNED', 'Esta solicitud ya fue firmada.');
    }

    $aceptadoUtc = gmdate('c');
    $evidencia = svConsentimientoPrivacidadCrearEvidencia(
        $folio,
        'REMOTA',
        $aceptadoUtc,
        hash('sha256', $firmaBinaria),
        [
            'ipCliente' => (string) ($_SERVER['REMOTE_ADDR'] ?? ''),
            'userAgentCliente' => substr((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 1000),
            'tokenHash' => hash('sha256', $tokenPlano),
            'snapshotSha256' => trim((string) ($estado['snapshotSha256'] ?? '')),
        ]
    );
    svConsentimientoPrivacidadGuardar($graphToken, $driveId, $folio, $evidencia);

    http_response_code(200);
    echo json_encode([
        'ok' => true,
        'folio' => $folio,
        'aceptadoUtc' => $aceptadoUtc,
        'metodo' => 'REMOTA',
        'consentimientoVersion' => svConsentimientoPrivacidadVersion(),
        'avisoPrivacidadVersion' => svAvisoPrivacidadVersion(),
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
} catch (Throwable $error) {
    error_log('Solicitud Venta consentimiento remoto: ' . $error->getMessage());
    svResponderError(502, 'REMOTE_CONSENT_FAILED', 'No fue posible registrar el consentimiento.');
}

function svrConsentimientoDrive(string $token, string $siteId): string
{
    $data = svCurlJson(
        'https://graph.microsoft.com/v1.0/sites/' . rawurlencode($siteId) . '/drives?$select=id,name',
        'GET',
        ['Authorization: Bearer ' . $token, 'Accept: application/json']
    );
    foreach (($data['value'] ?? []) as $drive) {
        if (!is_array($drive)) continue;
        $nombre = strtolower(trim((string) ($drive['name'] ?? '')));
        if (in_array($nombre, ['expedientes_ventas', 'expedientes ventas'], true)) {
            $id = trim((string) ($drive['id'] ?? ''));
            if ($id !== '') return $id;
        }
    }
    throw new RuntimeException('No se encontro Expedientes_Ventas.');
}

/** @return array<string,mixed> */
function svrConsentimientoCargarEstado(string $token, string $driveId, string $folio): array
{
    $path = rawurlencode($folio) . '/' . rawurlencode('_FIRMA_REMOTA.json');
    $url = 'https://graph.microsoft.com/v1.0/drives/' . rawurlencode($driveId) . '/root:/' . $path . ':/content';
    $data = svCurlJson($url, 'GET', ['Authorization: Bearer ' . $token, 'Accept: application/json']);
    return is_array($data) ? $data : [];
}
