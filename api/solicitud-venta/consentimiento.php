<?php

declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');

require_once __DIR__ . '/_common.php';
require_once __DIR__ . '/consentimiento-privacidad.php';

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    svResponderError(405, 'METHOD_NOT_ALLOWED', 'Metodo no permitido.');
}

$config = svConfig();
$claims = svUsuarioAutenticado($config['tenantId'], $config['clientId']);
$correoUsuario = strtolower(trim((string) ($claims['preferred_username'] ?? $claims['upn'] ?? '')));
if ($correoUsuario === '') {
    svResponderError(403, 'USER_EMAIL_REQUIRED', 'No fue posible identificar el correo del usuario.');
}

$raw = file_get_contents('php://input');
$payload = json_decode(is_string($raw) ? $raw : '', true);
if (!is_array($payload)) {
    svResponderError(400, 'INVALID_JSON', 'El cuerpo debe ser JSON valido.');
}

$folio = strtoupper(trim((string) ($payload['folio'] ?? '')));
if (!preg_match('/^SV-\d{4}-\d{6,}$/', $folio)) {
    svResponderError(400, 'INVALID_FOLIO', 'El folio no es valido.');
}
if (!(bool) ($payload['consentimientoPrivacidad'] ?? false)) {
    svResponderError(400, 'CONSENT_REQUIRED', 'El consentimiento de privacidad es obligatorio.');
}
if (!hash_equals(svConsentimientoPrivacidadVersion(), trim((string) ($payload['consentimientoVersion'] ?? '')))) {
    svResponderError(409, 'CONSENT_VERSION_MISMATCH', 'La version del consentimiento ya no es vigente. Recarga la solicitud.');
}
if (!hash_equals(svAvisoPrivacidadVersion(), trim((string) ($payload['avisoPrivacidadVersion'] ?? '')))) {
    svResponderError(409, 'PRIVACY_VERSION_MISMATCH', 'La version del Aviso de Privacidad ya no es vigente. Recarga la solicitud.');
}
$metodo = strtoupper(trim((string) ($payload['metodo'] ?? '')));
if ($metodo !== 'PRESENCIAL') {
    svResponderError(400, 'INVALID_METHOD', 'El metodo de consentimiento no es valido para este endpoint.');
}

try {
    $graphToken = svGraphToken($config['tenantId'], $config['clientId'], $config['clientSecret']);
    $principal = svConsentimientoBuscarPrincipal($graphToken, $config['siteId'], $config['listId'], $folio);
    $fields = is_array($principal['fields'] ?? null) ? $principal['fields'] : [];
    $correoVendedor = strtolower(trim((string) ($fields['Vendedor_Correo'] ?? '')));
    $estatus = strtoupper(trim((string) ($fields['field_1'] ?? '')));
    if ($correoVendedor === '' || !hash_equals($correoVendedor, $correoUsuario)) {
        svResponderError(403, 'FOLIO_FORBIDDEN', 'La solicitud no pertenece al vendedor autenticado.');
    }
    if ($estatus !== 'BORRADOR') {
        svResponderError(409, 'NOT_DRAFT', 'El consentimiento presencial solo puede registrarse mientras la solicitud esta en borrador.');
    }

    $driveId = svConsentimientoDriveExpedientes($graphToken, $config['siteId']);
    $firmaCliente = svConsentimientoDescargarFirma($graphToken, $driveId, $folio, 'FIRMA_CLIENTE');
    if ($firmaCliente === null) {
        svResponderError(409, 'CLIENT_SIGNATURE_REQUIRED', 'La firma del cliente debe estar guardada antes de registrar el consentimiento.');
    }

    $aceptadoUtc = gmdate('c');
    $evidencia = svConsentimientoPrivacidadCrearEvidencia(
        $folio,
        'PRESENCIAL',
        $aceptadoUtc,
        hash('sha256', $firmaCliente),
        [
            'registradoPor' => $correoUsuario,
            'ipRegistro' => (string) ($_SERVER['REMOTE_ADDR'] ?? ''),
            'userAgentRegistro' => substr((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 1000),
        ]
    );
    svConsentimientoPrivacidadGuardar($graphToken, $driveId, $folio, $evidencia);

    http_response_code(200);
    echo json_encode([
        'ok' => true,
        'folio' => $folio,
        'aceptadoUtc' => $aceptadoUtc,
        'metodo' => 'PRESENCIAL',
        'consentimientoVersion' => svConsentimientoPrivacidadVersion(),
        'avisoPrivacidadVersion' => svAvisoPrivacidadVersion(),
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
} catch (Throwable $error) {
    error_log('Solicitud Venta consentimiento presencial: ' . $error->getMessage());
    svResponderError(502, 'CONSENT_SAVE_FAILED', 'No fue posible registrar la evidencia de consentimiento.');
}

/** @return array<string,mixed> */
function svConsentimientoBuscarPrincipal(string $token, string $siteId, string $listId, string $folio): array
{
    $url = 'https://graph.microsoft.com/v1.0/sites/' . rawurlencode($siteId)
        . '/lists/' . rawurlencode($listId)
        . '/items?$expand=fields($select=Title,field_1,Vendedor_Correo,Solicitud_Grupo,Componente_Numero,Es_Principal)&$top=200';

    $candidato = null;
    while ($url !== '') {
        $data = svCurlJson($url, 'GET', ['Authorization: Bearer ' . $token, 'Accept: application/json']);
        foreach (($data['value'] ?? []) as $item) {
            if (!is_array($item)) continue;
            $fields = is_array($item['fields'] ?? null) ? $item['fields'] : [];
            $title = strtoupper(trim((string) ($fields['Title'] ?? '')));
            $grupo = strtoupper(trim((string) ($fields['Solicitud_Grupo'] ?? '')));
            if ($title !== $folio && $grupo !== $folio) continue;
            $principal = filter_var($fields['Es_Principal'] ?? false, FILTER_VALIDATE_BOOLEAN)
                || (int) ($fields['Componente_Numero'] ?? 0) === 1;
            if ($principal) return $item;
            if ($candidato === null) $candidato = $item;
        }
        $url = trim((string) ($data['@odata.nextLink'] ?? ''));
    }
    if (is_array($candidato)) return $candidato;
    throw new RuntimeException('No se encontro la solicitud indicada.');
}

function svConsentimientoDriveExpedientes(string $token, string $siteId): string
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

function svConsentimientoDescargarFirma(string $token, string $driveId, string $folio, string $prefix): ?string
{
    $listUrl = 'https://graph.microsoft.com/v1.0/drives/' . rawurlencode($driveId)
        . '/root:/' . rawurlencode($folio) . ':/children?$select=id,name&$top=200';
    $data = svCurlJson($listUrl, 'GET', ['Authorization: Bearer ' . $token, 'Accept: application/json']);
    $wanted = strtoupper($prefix);
    foreach (($data['value'] ?? []) as $item) {
        if (!is_array($item)) continue;
        $name = strtoupper(trim((string) ($item['name'] ?? '')));
        $id = trim((string) ($item['id'] ?? ''));
        if ($id === '' || !str_starts_with($name, $wanted) || !str_ends_with($name, '.PNG')) continue;
        $url = 'https://graph.microsoft.com/v1.0/drives/' . rawurlencode($driveId) . '/items/' . rawurlencode($id) . '/content';
        $curl = curl_init($url);
        if ($curl === false) continue;
        curl_setopt_array($curl, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_CONNECTTIMEOUT => 8,
            CURLOPT_TIMEOUT => 20,
            CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $token, 'Accept: image/png'],
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
        ]);
        $body = curl_exec($curl);
        $status = (int) curl_getinfo($curl, CURLINFO_HTTP_CODE);
        curl_close($curl);
        if (is_string($body) && $status >= 200 && $status < 300 && substr($body, 0, 8) === "\x89PNG\x0D\x0A\x1A\x0A") {
            return $body;
        }
    }
    return null;
}
