<?php

declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');

require_once __DIR__ . '/_common.php';

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    svResponderError(405, 'METHOD_NOT_ALLOWED', 'Metodo no permitido.');
}

$config = svConfig();
$claims = svUsuarioAutenticado($config['tenantId'], $config['clientId']);
$correoUsuario = strtolower(trim((string) ($claims['preferred_username'] ?? $claims['upn'] ?? '')));
if ($correoUsuario === '') svResponderError(403, 'USER_EMAIL_REQUIRED', 'No fue posible identificar al vendedor.');

$raw = file_get_contents('php://input');
$payload = json_decode(is_string($raw) ? $raw : '', true);
if (!is_array($payload)) svResponderError(400, 'INVALID_JSON', 'El cuerpo debe ser JSON valido.');

$accion = strtolower(trim((string) ($payload['accion'] ?? '')));
if (!in_array($accion, ['regenerar', 'cancelar'], true)) {
    svResponderError(400, 'INVALID_ACTION', 'La accion solicitada no es valida.');
}

$folio = strtoupper(trim((string) ($payload['folio'] ?? '')));
if (!preg_match('/^SV-(\d{4})-(\d{6,})$/', $folio, $match)) {
    svResponderError(400, 'INVALID_FOLIO', 'El folio no es valido.');
}
$itemIdPrincipal = (string) ((int) ltrim($match[2], '0'));
if ($itemIdPrincipal === '0') svResponderError(400, 'INVALID_ITEM_ID', 'El folio no contiene un ID valido.');

try {
    $token = svGraphToken($config['tenantId'], $config['clientId'], $config['clientSecret']);
    $principal = gfrObtenerPrincipal($token, $config['siteId'], $config['listId'], $itemIdPrincipal);
    $principalFields = is_array($principal['fields'] ?? null) ? $principal['fields'] : [];
    gfrValidarPrincipal($principalFields, $folio, $correoUsuario);

    $grupo = strtoupper(trim((string) ($principalFields['Solicitud_Grupo'] ?? '')));
    if ($grupo === '') $grupo = $folio;
    $items = gfrObtenerItemsGrupo($token, $config['siteId'], $config['listId'], $grupo, $principal);
    if (!$items) svResponderError(409, 'GROUP_EMPTY', 'No se encontraron componentes de la solicitud.');

    foreach ($items as $item) {
        $fields = is_array($item['fields'] ?? null) ? $item['fields'] : [];
        $estatus = strtoupper(trim((string) ($fields['field_1'] ?? '')));
        $correo = strtolower(trim((string) ($fields['Vendedor_Correo'] ?? '')));
        if ($estatus !== 'PENDIENTE FIRMA') {
            svResponderError(409, 'GROUP_NOT_PENDING_SIGNATURE', 'La solicitud ya no se encuentra pendiente de firma.');
        }
        if ($correo === '' || !hash_equals($correo, $correoUsuario)) {
            svResponderError(403, 'GROUP_FORBIDDEN', 'La solicitud no pertenece al vendedor autenticado.');
        }
    }

    $driveId = gfrObtenerDriveExpedientes($token, $config['siteId']);
    $estado = gfrCargarJson($token, $driveId, $folio, '_FIRMA_REMOTA.json');
    if ((bool) ($estado['firmado'] ?? false)) {
        svResponderError(409, 'ALREADY_SIGNED', 'La solicitud ya fue firmada y no permite modificar el enlace.');
    }

    $ahora = gmdate('c');
    $versionEnlace = max(1, (int) ($estado['versionEnlace'] ?? 1));

    if ($accion === 'cancelar') {
        $estado['cancelado'] = true;
        $estado['canceladoUtc'] = $ahora;
        $estado['tokenHash'] = hash('sha256', random_bytes(32));
        $estado['expiraUtc'] = $ahora;
        $estado['versionEnlace'] = $versionEnlace;
        gfrGuardarJson($token, $driveId, $folio, '_FIRMA_REMOTA.json', $estado);

        http_response_code(200);
        echo json_encode([
            'ok' => true,
            'folio' => $folio,
            'estatus' => 'PENDIENTE FIRMA',
            'enlaceActivo' => false,
            'canceladoUtc' => $ahora,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }

    $tokenPlano = $folio . '.' . bin2hex(random_bytes(32));
    $expira = gmdate('c', time() + (7 * 24 * 60 * 60));
    $estado['tokenHash'] = hash('sha256', $tokenPlano);
    $estado['creadoUtc'] = $ahora;
    $estado['regeneradoUtc'] = $ahora;
    $estado['expiraUtc'] = $expira;
    $estado['cancelado'] = false;
    unset($estado['canceladoUtc']);
    $estado['versionEnlace'] = $versionEnlace + 1;
    gfrGuardarJson($token, $driveId, $folio, '_FIRMA_REMOTA.json', $estado);

    http_response_code(200);
    echo json_encode([
        'ok' => true,
        'folio' => $folio,
        'estatus' => 'PENDIENTE FIRMA',
        'enlaceActivo' => true,
        'expiraUtc' => $expira,
        'firmaUrl' => 'https://portal.juanpablo.com.mx/firma/?token=' . rawurlencode($tokenPlano),
        'versionEnlace' => $estado['versionEnlace'],
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
} catch (Throwable $error) {
    error_log('Solicitud Venta gestionar firma remota: ' . $error->getMessage());
    svResponderError(502, 'REMOTE_SIGNATURE_MANAGE_FAILED', 'No fue posible gestionar el enlace de firma remota.');
}

/** @return array<string,mixed> */
function gfrObtenerPrincipal(string $token, string $siteId, string $listId, string $itemId): array
{
    $campos = 'Title,field_1,Vendedor_Correo,Solicitud_Grupo,Componente_Total';
    $url = 'https://graph.microsoft.com/v1.0/sites/' . rawurlencode($siteId)
        . '/lists/' . rawurlencode($listId) . '/items/' . rawurlencode($itemId)
        . '?$expand=fields($select=' . $campos . ')';
    return svCurlJson($url, 'GET', ['Authorization: Bearer ' . $token, 'Accept: application/json']);
}

/** @param array<string,mixed> $fields */
function gfrValidarPrincipal(array $fields, string $folio, string $correoUsuario): void
{
    $title = strtoupper(trim((string) ($fields['Title'] ?? '')));
    $grupo = strtoupper(trim((string) ($fields['Solicitud_Grupo'] ?? '')));
    $estatus = strtoupper(trim((string) ($fields['field_1'] ?? '')));
    $correo = strtolower(trim((string) ($fields['Vendedor_Correo'] ?? '')));

    if ($title !== $folio && $grupo !== $folio) svResponderError(409, 'REQUEST_MISMATCH', 'El folio no corresponde a la solicitud.');
    if ($correo === '' || !hash_equals($correo, $correoUsuario)) svResponderError(403, 'REQUEST_FORBIDDEN', 'La solicitud no pertenece al vendedor autenticado.');
    if ($estatus === 'PENDIENTE VOBO') svResponderError(409, 'ALREADY_SIGNED', 'La solicitud ya fue firmada por el cliente.');
    if ($estatus !== 'PENDIENTE FIRMA') svResponderError(409, 'NOT_PENDING_SIGNATURE', 'La solicitud no se encuentra pendiente de firma.');
}

/** @param array<string,mixed> $principal @return array<int,array<string,mixed>> */
function gfrObtenerItemsGrupo(string $token, string $siteId, string $listId, string $grupo, array $principal): array
{
    $campos = 'Title,field_1,Vendedor_Correo,Solicitud_Grupo,Componente_Numero,Componente_Total';
    $items = [];
    $url = 'https://graph.microsoft.com/v1.0/sites/' . rawurlencode($siteId)
        . '/lists/' . rawurlencode($listId) . '/items?$expand=fields($select=' . $campos . ')&$top=200';
    while ($url !== '') {
        $respuesta = svCurlJson($url, 'GET', ['Authorization: Bearer ' . $token, 'Accept: application/json']);
        foreach (($respuesta['value'] ?? []) as $item) {
            if (!is_array($item)) continue;
            $fields = is_array($item['fields'] ?? null) ? $item['fields'] : [];
            if (strtoupper(trim((string) ($fields['Solicitud_Grupo'] ?? ''))) === $grupo) $items[] = $item;
        }
        $url = trim((string) ($respuesta['@odata.nextLink'] ?? ''));
    }
    return $items ?: [$principal];
}

function gfrObtenerDriveExpedientes(string $token, string $siteId): string
{
    $drives = svCurlJson('https://graph.microsoft.com/v1.0/sites/' . rawurlencode($siteId) . '/drives?$select=id,name', 'GET', [
        'Authorization: Bearer ' . $token, 'Accept: application/json'
    ]);
    foreach (($drives['value'] ?? []) as $drive) {
        if (!is_array($drive)) continue;
        $nombre = strtolower(trim((string) ($drive['name'] ?? '')));
        if (in_array($nombre, ['expedientes_ventas', 'expedientes ventas'], true)) return (string) ($drive['id'] ?? '');
    }
    throw new RuntimeException('No se encontro Expedientes_Ventas.');
}

/** @return array<string,mixed> */
function gfrCargarJson(string $token, string $driveId, string $folio, string $archivo): array
{
    $path = rawurlencode($folio) . '/' . rawurlencode($archivo);
    $url = 'https://graph.microsoft.com/v1.0/drives/' . rawurlencode($driveId) . '/root:/' . $path . ':/content';
    return svCurlJson($url, 'GET', ['Authorization: Bearer ' . $token, 'Accept: application/json']);
}

/** @param array<string,mixed> $data */
function gfrGuardarJson(string $token, string $driveId, string $folio, string $archivo, array $data): void
{
    $body = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if (!is_string($body)) throw new RuntimeException('No fue posible serializar el estado de firma.');
    $path = rawurlencode($folio) . '/' . rawurlencode($archivo);
    $url = 'https://graph.microsoft.com/v1.0/drives/' . rawurlencode($driveId) . '/root:/' . $path . ':/content';
    svCurlJson($url, 'PUT', [
        'Authorization: Bearer ' . $token,
        'Accept: application/json',
        'Content-Type: application/json; charset=utf-8'
    ], $body);
}
