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
if ($correoUsuario === '') {
    svResponderError(403, 'USER_EMAIL_REQUIRED', 'No fue posible identificar el correo del usuario.');
}

$rawBody = file_get_contents('php://input');
$payload = json_decode(is_string($rawBody) ? $rawBody : '', true);
if (!is_array($payload)) {
    svResponderError(400, 'INVALID_JSON', 'El cuerpo debe ser JSON valido.');
}

$accion = strtolower(trim((string) ($payload['accion'] ?? 'cargar')));
if ($accion !== 'cargar') {
    svResponderError(400, 'INVALID_ACTION', 'Este endpoint solo permite consultar el estado de una solicitud.');
}

$folio = strtoupper(trim((string) ($payload['folio'] ?? '')));
if (!preg_match('/^SV-(\d{4})-(\d{6,})$/', $folio, $folioMatch)) {
    svResponderError(400, 'INVALID_FOLIO', 'El folio de la solicitud no es valido.');
}

$itemId = (string) ((int) ltrim($folioMatch[2], '0'));
if ($itemId === '0') {
    svResponderError(400, 'INVALID_ITEM_ID', 'El folio no contiene un ID valido.');
}

try {
    $graphToken = svGraphToken($config['tenantId'], $config['clientId'], $config['clientSecret']);
    $fields = esObtenerPrincipal(
        $graphToken,
        $config['siteId'],
        $config['listId'],
        $itemId
    );

    esValidarPropietario($fields, $folio, $correoUsuario);

    $estatus = strtoupper(trim((string) ($fields['field_1'] ?? '')));
    if ($estatus === '') $estatus = 'BORRADOR';

    $driveId = esObtenerDriveExpedientes($graphToken, $config['siteId']);
    $documento = esCargarEstado($graphToken, $driveId, $folio);

    if (strtoupper(trim((string) ($documento['folio'] ?? ''))) !== $folio) {
        throw new RuntimeException('El archivo de estado no corresponde al folio solicitado.');
    }

    $usuarioEstado = strtolower(trim((string) ($documento['usuario'] ?? '')));
    if ($usuarioEstado === '' || !hash_equals($usuarioEstado, $correoUsuario)) {
        svResponderError(403, 'STATE_FORBIDDEN', 'El estado guardado no pertenece al usuario autenticado.');
    }

    $estado = $documento['estado'] ?? null;
    if (!is_array($estado)) {
        throw new RuntimeException('El archivo de estado no contiene datos validos.');
    }

    if (!isset($estado['controles']) || !is_array($estado['controles'])) {
        $estado['controles'] = [];
    }
    $lugarEstado = trim((string) ($estado['controles']['lugar']['valor'] ?? ''));
    $lugarSharePoint = trim((string) ($fields['field_3'] ?? ''));
    if ($lugarSharePoint === '') {
        $lugarSharePoint = trim((string) ($fields['field_49'] ?? ''));
    }
    if ($lugarEstado === '' && $lugarSharePoint !== '') {
        $estado['controles']['lugar'] = [
            'tipo' => 'value',
            'valor' => $lugarSharePoint,
        ];
    }

    if (!isset($estado['expediente']) || !is_array($estado['expediente'])) {
        $estado['expediente'] = ['version' => 1, 'documentos' => [], 'firmas' => []];
    }
    if (!isset($estado['expediente']['firmas']) || !is_array($estado['expediente']['firmas'])) {
        $estado['expediente']['firmas'] = [];
    }

    $firmaCliente = strtoupper(trim((string) ($fields['field_102'] ?? '')));
    $firmaVendedor = strtoupper(trim((string) ($fields['field_103'] ?? '')));
    if ($firmaCliente === 'FIRMADO') $estado['expediente']['firmas']['FIRMA_CLIENTE'] = true;
    if ($firmaVendedor === 'FIRMADO') $estado['expediente']['firmas']['FIRMA_VENDEDOR'] = true;

    $metadataActualizada = true;
    $metadataArchivosActualizados = 0;
    try {
        $metadataArchivosActualizados = esActualizarMetadataExpediente(
            $graphToken,
            $config['siteId'],
            $driveId,
            $folio,
            $fields
        );
    } catch (Throwable $metadataError) {
        $metadataActualizada = false;
        error_log('Solicitud Venta metadata seguimiento: ' . $metadataError->getMessage());
    }

    http_response_code(200);
    echo json_encode([
        'ok' => true,
        'folio' => $folio,
        'itemId' => $itemId,
        'guardadoUtc' => (string) ($documento['guardadoUtc'] ?? ''),
        'estatus' => $estatus,
        'soloLectura' => $estatus !== 'BORRADOR',
        'firmaCliente' => $firmaCliente,
        'firmaVendedor' => $firmaVendedor,
        'metadataExpedienteActualizada' => $metadataActualizada,
        'metadataArchivosActualizados' => $metadataArchivosActualizados,
        'estado' => $estado,
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
} catch (Throwable $error) {
    error_log('Solicitud Venta consulta estado: ' . $error->getMessage());
    svResponderError(502, 'REQUEST_STATE_FAILED', 'No fue posible consultar el estado de la solicitud.');
}

/** @return array<string,mixed> */
function esObtenerPrincipal(string $token, string $siteId, string $listId, string $itemId): array
{
    $url = 'https://graph.microsoft.com/v1.0/sites/' . rawurlencode($siteId)
        . '/lists/' . rawurlencode($listId)
        . '/items/' . rawurlencode($itemId)
        . '?$expand=fields($select=Title,field_1,Vendedor_Correo,Solicitud_Grupo,field_102,field_103,field_8,field_9,field_48,field_3,field_49)';

    $item = svCurlJson($url, 'GET', [
        'Authorization: Bearer ' . $token,
        'Accept: application/json',
    ]);

    return is_array($item['fields'] ?? null) ? $item['fields'] : [];
}

/** @param array<string,mixed> $fields */
function esValidarPropietario(array $fields, string $folio, string $correoUsuario): void
{
    $correo = strtolower(trim((string) ($fields['Vendedor_Correo'] ?? '')));
    $title = strtoupper(trim((string) ($fields['Title'] ?? '')));
    $grupo = strtoupper(trim((string) ($fields['Solicitud_Grupo'] ?? '')));

    if ($correo === '' || !hash_equals($correo, $correoUsuario)) {
        svResponderError(403, 'REQUEST_FORBIDDEN', 'La solicitud no pertenece al usuario autenticado.');
    }
    if ($title !== $folio && $grupo !== $folio) {
        svResponderError(409, 'REQUEST_MISMATCH', 'El registro no corresponde al folio solicitado.');
    }
}

function esObtenerDriveExpedientes(string $token, string $siteId): string
{
    $url = 'https://graph.microsoft.com/v1.0/sites/' . rawurlencode($siteId) . '/drives?$select=id,name';
    $drives = svCurlJson($url, 'GET', [
        'Authorization: Bearer ' . $token,
        'Accept: application/json',
    ]);

    foreach (($drives['value'] ?? []) as $drive) {
        if (!is_array($drive)) continue;
        $nombre = strtolower(trim((string) ($drive['name'] ?? '')));
        if (!in_array($nombre, ['expedientes_ventas', 'expedientes ventas'], true)) continue;
        $id = trim((string) ($drive['id'] ?? ''));
        if ($id !== '') return $id;
    }

    throw new RuntimeException('No se encontro la biblioteca Expedientes_Ventas.');
}

/** @return array<string,mixed> */
function esCargarEstado(string $token, string $driveId, string $folio): array
{
    $path = rawurlencode($folio) . '/_ESTADO_BORRADOR.json';
    $url = 'https://graph.microsoft.com/v1.0/drives/' . rawurlencode($driveId)
        . '/root:/' . $path . ':/content';

    return svCurlJson($url, 'GET', [
        'Authorization: Bearer ' . $token,
        'Accept: application/json',
    ]);
}

/**
 * Completa o repara los metadatos de todos los archivos del expediente. Esto
 * incluye archivos generados por el flujo de firma remota, que no pasan por
 * archivos.php ni por el guardado normal del borrador.
 *
 * @param array<string,mixed> $datosSolicitud
 */
function esActualizarMetadataExpediente(
    string $token,
    string $siteId,
    string $driveId,
    string $folio,
    array $datosSolicitud
): int {
    $nombres = trim((string) ($datosSolicitud['field_8'] ?? ''));
    $apellidos = trim((string) ($datosSolicitud['field_9'] ?? ''));
    $clienteNombre = trim($nombres . ' ' . $apellidos);
    $clienteNombre = preg_replace('/\s+/', ' ', $clienteNombre) ?: $clienteNombre;
    if ($clienteNombre === '') $clienteNombre = 'PENDIENTE';

    $tipoVenta = strtoupper(trim((string) ($datosSolicitud['field_48'] ?? '')));
    if ($tipoVenta === '') $tipoVenta = 'PENDIENTE';

    $childrenUrl = 'https://graph.microsoft.com/v1.0/drives/' . rawurlencode($driveId)
        . '/root:/' . rawurlencode($folio) . ':/children?$select=id,name,file,sharepointIds';
    $children = svCurlJson($childrenUrl, 'GET', [
        'Authorization: Bearer ' . $token,
        'Accept: application/json',
    ]);

    $actualizados = 0;
    foreach (($children['value'] ?? []) as $driveItem) {
        if (!is_array($driveItem) || !isset($driveItem['file'])) continue;

        $nombreArchivo = trim((string) ($driveItem['name'] ?? ''));
        $sharepointIds = is_array($driveItem['sharepointIds'] ?? null) ? $driveItem['sharepointIds'] : [];
        $libraryListId = trim((string) ($sharepointIds['listId'] ?? ''));
        $libraryItemId = trim((string) ($sharepointIds['listItemId'] ?? ''));
        if ($nombreArchivo === '' || $libraryListId === '' || $libraryItemId === '') continue;

        $fields = [
            'Folio_Solicitud' => $folio,
            'Tipo_Documento' => esTipoDocumentoDesdeNombre($nombreArchivo),
            'Cliente_Nombre' => $clienteNombre,
            'Tipo_Venta' => $tipoVenta,
            'Estatus_Documento' => 'RECIBIDO',
        ];
        $body = json_encode($fields, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if (!is_string($body)) throw new RuntimeException('No fue posible serializar metadata del expediente.');

        $fieldsUrl = 'https://graph.microsoft.com/v1.0/sites/' . rawurlencode($siteId)
            . '/lists/' . rawurlencode($libraryListId)
            . '/items/' . rawurlencode($libraryItemId)
            . '/fields';

        svCurlJson($fieldsUrl, 'PATCH', [
            'Authorization: Bearer ' . $token,
            'Accept: application/json',
            'Content-Type: application/json',
        ], $body);
        $actualizados++;
    }

    return $actualizados;
}

function esTipoDocumentoDesdeNombre(string $nombreArchivo): string
{
    $nombre = strtoupper($nombreArchivo);
    if (str_starts_with($nombre, 'ID_TITULAR_')) return 'ID_TITULAR';
    if (str_starts_with($nombre, 'ID_SUSTITUTO_')) return 'ID_SUSTITUTO';
    if (str_starts_with($nombre, 'COMPROBANTE_DOMICILIO_')) return 'COMPROBANTE_DOMICILIO';
    if (str_starts_with($nombre, 'COMPROBANTE_PAGO_')) return 'COMPROBANTE_PAGO';
    if (str_starts_with($nombre, 'FIRMA_CLIENTE')) return 'FIRMA_CLIENTE';
    if (str_starts_with($nombre, 'FIRMA_VENDEDOR')) return 'FIRMA_VENDEDOR';
    return 'OTRO';
}
