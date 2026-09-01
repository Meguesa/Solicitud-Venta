<?php

declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    http_response_code(405);
    header('Allow: POST');
    echo json_encode(['ok' => false, 'error' => 'METHOD_NOT_ALLOWED']);
    exit;
}

require_once __DIR__ . '/_common.php';

$config = svConfig();
$claims = svUsuarioAutenticado($config['tenantId'], $config['clientId']);
$correoUsuario = strtolower(trim((string) ($claims['preferred_username'] ?? $claims['upn'] ?? '')));
if ($correoUsuario === '') {
    svResponderError(403, 'USER_EMAIL_REQUIRED', 'No fue posible identificar el correo del usuario autenticado.');
}

$raw = file_get_contents('php://input');
$payload = [];
if (is_string($raw) && trim($raw) !== '') {
    $decoded = json_decode($raw, true);
    if (!is_array($decoded)) {
        svResponderError(400, 'INVALID_JSON', 'El cuerpo de la solicitud debe ser JSON valido.');
    }
    $payload = $decoded;
}

$itemId = trim((string) ($payload['itemId'] ?? ''));
$folio = strtoupper(trim((string) ($payload['folio'] ?? '')));
$importe = $payload['importeAnualidad'] ?? null;

if ($itemId === '' || !ctype_digit($itemId) || (int) $itemId <= 0) {
    svResponderError(400, 'INVALID_ITEM_ID', 'El identificador de la solicitud no es valido.');
}
if ($folio !== '' && !preg_match('/^SV-\d{4}-\d+$/', $folio)) {
    svResponderError(400, 'INVALID_FOLIO', 'El folio de la solicitud no es valido.');
}
if (!is_numeric($importe) || !is_finite((float) $importe) || (float) $importe < 0) {
    svResponderError(400, 'INVALID_ANNUAL_AMOUNT', 'El importe de anualidad debe ser un numero mayor o igual a cero.');
}
$importeAnualidad = round((float) $importe, 2);

try {
    $graphToken = svGraphToken($config['tenantId'], $config['clientId'], $config['clientSecret']);

    $itemUrl = 'https://graph.microsoft.com/v1.0/sites/' . rawurlencode($config['siteId'])
        . '/lists/' . rawurlencode($config['listId'])
        . '/items/' . rawurlencode($itemId)
        . '?$expand=fields($select=Title,field_1,Vendedor_Correo)';

    $item = svCurlJson($itemUrl, 'GET', [
        'Authorization: Bearer ' . $graphToken,
        'Accept: application/json',
    ]);

    $fields = is_array($item['fields'] ?? null) ? $item['fields'] : [];
    $estado = strtoupper(trim((string) ($fields['field_1'] ?? '')));
    $correoSolicitud = strtolower(trim((string) ($fields['Vendedor_Correo'] ?? '')));
    $folioGuardado = strtoupper(trim((string) ($fields['Title'] ?? '')));

    if ($estado !== 'BORRADOR') {
        svResponderError(409, 'DRAFT_REQUIRED', 'La solicitud ya no esta en estado BORRADOR.');
    }
    if ($correoSolicitud === '' || !hash_equals($correoSolicitud, $correoUsuario)) {
        svResponderError(403, 'DRAFT_OWNER_MISMATCH', 'La solicitud no pertenece al usuario autenticado.');
    }
    if ($folio !== '' && $folioGuardado !== '' && !hash_equals($folioGuardado, $folio)) {
        svResponderError(409, 'FOLIO_MISMATCH', 'El folio no corresponde al registro indicado.');
    }

    $updateUrl = 'https://graph.microsoft.com/v1.0/sites/' . rawurlencode($config['siteId'])
        . '/lists/' . rawurlencode($config['listId'])
        . '/items/' . rawurlencode($itemId)
        . '/fields';

    $body = json_encode([
        'Importe_Anualidad' => $importeAnualidad,
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if (!is_string($body)) {
        throw new RuntimeException('No fue posible serializar el importe de anualidad.');
    }

    svCurlJson($updateUrl, 'PATCH', [
        'Authorization: Bearer ' . $graphToken,
        'Accept: application/json',
        'Content-Type: application/json',
    ], $body);

    http_response_code(200);
    echo json_encode([
        'ok' => true,
        'itemId' => $itemId,
        'folio' => $folio !== '' ? $folio : $folioGuardado,
        'importeAnualidad' => $importeAnualidad,
        'columna' => 'Importe_Anualidad',
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
} catch (Throwable $error) {
    error_log('Solicitud Venta anualidad: ' . $error->getMessage());
    svResponderError(502, 'ANNUAL_AMOUNT_SAVE_FAILED', 'No fue posible guardar el importe de anualidad en SharePoint: ' . $error->getMessage());
}
