<?php

declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');

require_once dirname(__DIR__, 2) . '/includes/bootstrap.php';
require_once __DIR__ . '/_common.php';
require_once __DIR__ . '/pdf-final-lib.php';

function vfError(int $status, string $code, string $message): void
{
    http_response_code($status);
    echo json_encode(['ok' => false, 'error' => $code, 'message' => $message], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') vfError(405, 'METHOD_NOT_ALLOWED', 'Metodo no permitido.');
if (!portal_is_authenticated()) vfError(401, 'AUTH_REQUIRED', 'La sesion del Portal Interno no esta activa.');

$etapa = strtolower(trim((string) ($_GET['etapa'] ?? 'comercial')));
if (!in_array($etapa, ['comercial', 'cobranza'], true)) vfError(400, 'INVALID_STAGE', 'La etapa no es valida.');
if ($etapa === 'cobranza') {
    if (!portal_user_can_cobranza_vobo()) vfError(403, 'COBRANZA_FORBIDDEN', 'No tienes autorizacion para firmar el Vo.Bo. de Cobranza.');
} elseif (!portal_user_can_vobo()) {
    vfError(403, 'VOBO_FORBIDDEN', 'No tienes autorizacion para firmar el Vo.Bo. Comercial.');
}

$raw = file_get_contents('php://input');
$payload = json_decode(is_string($raw) ? $raw : '', true);
if (!is_array($payload)) vfError(400, 'INVALID_JSON', 'El cuerpo de la solicitud no es valido.');

$folio = strtoupper(trim((string) ($payload['folio'] ?? '')));
if (!preg_match('/^SV-\d{4}-\d{6,}$/', $folio)) vfError(400, 'INVALID_FOLIO', 'El folio no es valido.');
$dataUrl = trim((string) ($payload['firma'] ?? ''));
if (!preg_match('#^data:image/png;base64,(.+)$#s', $dataUrl, $match)) vfError(400, 'SIGNATURE_REQUIRED', 'La firma de autorizacion es obligatoria.');
$png = base64_decode($match[1], true);
if (!is_string($png) || substr($png, 0, 8) !== "\x89PNG\x0D\x0A\x1A\x0A") vfError(400, 'INVALID_SIGNATURE', 'La firma recibida no es un PNG valido.');
if (strlen($png) < 100 || strlen($png) > 2 * 1024 * 1024) vfError(413, 'INVALID_SIGNATURE_SIZE', 'La firma tiene un tamano no permitido.');

$config = svConfig();
try {
    $graphToken = svGraphToken($config['tenantId'], $config['clientId'], $config['clientSecret']);
    $url = 'https://graph.microsoft.com/v1.0/sites/' . rawurlencode($config['siteId'])
        . '/lists/' . rawurlencode($config['listId']) . '/items?$expand=fields&$top=200';
    $principal = null;
    $pages = 0;
    while ($url !== '' && $pages < 50) {
        $data = svCurlJson($url, 'GET', ['Authorization: Bearer ' . $graphToken, 'Accept: application/json']);
        foreach (($data['value'] ?? []) as $item) {
            if (!is_array($item)) continue;
            $fields = is_array($item['fields'] ?? null) ? $item['fields'] : [];
            $group = strtoupper(trim((string) ($fields['Solicitud_Grupo'] ?? '')));
            $title = strtoupper(trim((string) ($fields['Title'] ?? '')));
            if ($group !== $folio && $title !== $folio) continue;
            $numero = (int) ($fields['Componente_Numero'] ?? 0);
            if (svPdfBool($fields['Es_Principal'] ?? false) || $numero === 1 || $principal === null) $principal = $fields;
            if (svPdfBool($fields['Es_Principal'] ?? false) || $numero === 1) break 2;
        }
        $url = trim((string) ($data['@odata.nextLink'] ?? ''));
        $pages++;
    }
    if (!is_array($principal)) vfError(404, 'REQUEST_NOT_FOUND', 'No se encontro la solicitud indicada.');
    $expected = $etapa === 'cobranza' ? 'PENDIENTE COBRANZA' : 'PENDIENTE VOBO';
    $status = strtoupper(trim((string) ($principal['field_1'] ?? '')));
    if ($status !== $expected) vfError(409, 'INVALID_STATUS', 'La solicitud ya no se encuentra pendiente en esta etapa.');

    $driveId = svPdfDriveExpedientes($graphToken, (string) $config['siteId']);
    svPdfAsegurarCarpeta($graphToken, $driveId, $folio);
    $name = $etapa === 'cobranza' ? 'FIRMA_VOBO_COBRANZA.png' : 'FIRMA_VOBO_COMERCIAL.png';
    $path = rawurlencode($folio) . '/' . rawurlencode($name);
    $uploadUrl = 'https://graph.microsoft.com/v1.0/drives/' . rawurlencode($driveId) . '/root:/' . $path . ':/content';
    $item = svCurlJson($uploadUrl, 'PUT', [
        'Authorization: Bearer ' . $graphToken,
        'Accept: application/json',
        'Content-Type: image/png',
    ], $png);
} catch (Throwable $error) {
    error_log('Solicitud Venta firma VoBo ' . $etapa . ' ' . $folio . ': ' . $error->getMessage());
    vfError(502, 'SIGNATURE_SAVE_FAILED', 'No fue posible guardar la firma de autorizacion.');
}

$user = portal_user();
http_response_code(200);
echo json_encode([
    'ok' => true,
    'folio' => $folio,
    'etapa' => $etapa,
    'archivo' => $name,
    'driveItemId' => (string) ($item['id'] ?? ''),
    'firmadoPor' => trim((string) ($user['name'] ?? '')),
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
