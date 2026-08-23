<?php

declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');
header('X-Robots-Tag: noindex, nofollow, noarchive');

require_once __DIR__ . '/_common.php';
require_once __DIR__ . '/notificaciones-flujo.php';

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    svResponderError(405, 'METHOD_NOT_ALLOWED', 'Metodo no permitido.');
}

$config = svConfig();
$raw = file_get_contents('php://input');
$payload = json_decode(is_string($raw) ? $raw : '', true);
if (!is_array($payload)) svResponderError(400, 'INVALID_JSON', 'El cuerpo debe ser JSON valido.');

$accion = strtolower(trim((string) ($payload['accion'] ?? '')));
if (!in_array($accion, ['cargar', 'firmar'], true)) svResponderError(400, 'INVALID_ACTION', 'Accion no valida.');

$tokenPlano = trim((string) ($payload['token'] ?? ''));
if (!preg_match('/^(SV-\d{4}-\d{6,})\.([a-fA-F0-9]{64})$/', $tokenPlano, $match)) {
    svResponderError(400, 'INVALID_TOKEN', 'El enlace de firma no es valido.');
}
$folio = strtoupper($match[1]);
$itemIdPrincipal = (string) ((int) ltrim(explode('-', $folio)[2] ?? '0', '0'));
if ($itemIdPrincipal === '0') svResponderError(400, 'INVALID_FOLIO', 'El folio del enlace no es valido.');

try {
    $graphToken = svGraphToken($config['tenantId'], $config['clientId'], $config['clientSecret']);
    $driveId = frpObtenerDriveExpedientes($graphToken, $config['siteId']);
    $estado = frpCargarJson($graphToken, $driveId, $folio, '_FIRMA_REMOTA.json');
    frpValidarEstado($estado, $folio, $tokenPlano);

    $snapshot = is_array($estado['snapshot'] ?? null) ? $estado['snapshot'] : [];
    $principal = frpObtenerPrincipal($graphToken, $config['siteId'], $config['listId'], $itemIdPrincipal);
    $principalFields = is_array($principal['fields'] ?? null) ? $principal['fields'] : [];
    $estatusActual = strtoupper(trim((string) ($principalFields['field_1'] ?? '')));

    if ($accion === 'cargar') {
        $firmado = (bool) ($estado['firmado'] ?? false) || $estatusActual === 'PENDIENTE VOBO';
        http_response_code(200);
        echo json_encode([
            'ok' => true,
            'folio' => $folio,
            'expiraUtc' => (string) ($estado['expiraUtc'] ?? ''),
            'firmado' => $firmado,
            'estatus' => $estatusActual,
            'snapshot' => $snapshot,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }

    if ((bool) ($estado['firmado'] ?? false) || $estatusActual === 'PENDIENTE VOBO') {
        svResponderError(409, 'ALREADY_SIGNED', 'Esta solicitud ya fue firmada.');
    }
    if ($estatusActual !== 'PENDIENTE FIRMA') {
        svResponderError(409, 'NOT_PENDING_SIGNATURE', 'La solicitud ya no se encuentra pendiente de firma.');
    }
    if (!(bool) ($payload['consentimiento'] ?? false)) {
        svResponderError(400, 'CONSENT_REQUIRED', 'Debes aceptar la solicitud antes de firmar.');
    }

    $firmaDataUrl = trim((string) ($payload['firmaDataUrl'] ?? ''));
    if (!preg_match('/^data:image\/png;base64,(.+)$/s', $firmaDataUrl, $firmaMatch)) {
        svResponderError(400, 'INVALID_SIGNATURE', 'La firma no tiene un formato valido.');
    }
    $firmaBinaria = base64_decode($firmaMatch[1], true);
    if ($firmaBinaria === false || strlen($firmaBinaria) < 500) svResponderError(400, 'EMPTY_SIGNATURE', 'La firma esta vacia.');
    if (strlen($firmaBinaria) > 3 * 1024 * 1024) svResponderError(413, 'SIGNATURE_TOO_LARGE', 'La firma supera el limite permitido.');

    if (strtoupper(trim((string) ($principalFields['field_103'] ?? ''))) !== 'FIRMADO') {
        svResponderError(409, 'SELLER_SIGNATURE_REQUIRED', 'La firma del vendedor ya no se encuentra disponible.');
    }

    $grupo = strtoupper(trim((string) ($principalFields['Solicitud_Grupo'] ?? '')));
    if ($grupo === '') $grupo = $folio;
    $items = frpObtenerItemsGrupo($graphToken, $config['siteId'], $config['listId'], $grupo, $principal);
    if (!$items) svResponderError(409, 'GROUP_EMPTY', 'No se encontraron componentes de la solicitud.');
    foreach ($items as $item) {
        $fields = is_array($item['fields'] ?? null) ? $item['fields'] : [];
        if (strtoupper(trim((string) ($fields['field_1'] ?? ''))) !== 'PENDIENTE FIRMA') {
            svResponderError(409, 'GROUP_NOT_PENDING_SIGNATURE', 'Uno de los componentes ya no se encuentra pendiente de firma.');
        }
    }

    $firmaItem = frpSubirArchivo($graphToken, $driveId, $folio, 'FIRMA_CLIENTE.png', 'image/png', $firmaBinaria);
    frpActualizarMetadataFirma($graphToken, $firmaItem, $folio, $snapshot);

    $firmadoUtc = gmdate('c');
    $consentimientoTexto = 'Declaro que revise la informacion de la solicitud, que los datos y condiciones mostrados corresponden a lo acordado y que manifiesto mi consentimiento mediante esta firma electronica.';
    $evidencia = [
        'version' => 1,
        'folio' => $folio,
        'solicitudGrupo' => $grupo,
        'firmadoUtc' => $firmadoUtc,
        'snapshotSha256' => (string) ($estado['snapshotSha256'] ?? ''),
        'firmaSha256' => hash('sha256', $firmaBinaria),
        'consentimiento' => $consentimientoTexto,
        'ip' => (string) ($_SERVER['REMOTE_ADDR'] ?? ''),
        'userAgent' => substr((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 1000),
    ];
    frpGuardarJson($graphToken, $driveId, $folio, '_EVIDENCIA_FIRMA_CLIENTE.json', $evidencia);

    frpActualizarCampos($graphToken, $config['siteId'], $config['listId'], $itemIdPrincipal, ['field_102' => 'FIRMADO']);

    $actualizados = [];
    try {
        foreach ($items as $item) {
            $id = trim((string) ($item['id'] ?? ''));
            if ($id === '') throw new RuntimeException('Un componente no contiene ID.');
            frpActualizarCampos($graphToken, $config['siteId'], $config['listId'], $id, ['field_1' => 'PENDIENTE VOBO']);
            $actualizados[] = $id;
        }
    } catch (Throwable $error) {
        foreach ($actualizados as $id) {
            try { frpActualizarCampos($graphToken, $config['siteId'], $config['listId'], $id, ['field_1' => 'PENDIENTE FIRMA']); } catch (Throwable $rollback) {}
        }
        throw $error;
    }

    $estado['firmado'] = true;
    $estado['firmadoUtc'] = $firmadoUtc;
    $estado['evidenciaSha256'] = hash('sha256', json_encode($evidencia, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '');
    try { frpGuardarJson($graphToken, $driveId, $folio, '_FIRMA_REMOTA.json', $estado); } catch (Throwable $stateError) {
        error_log('Solicitud Venta actualizar estado firma remota: ' . $stateError->getMessage());
    }

    $notificacionComercial = [
        'enviado' => false,
        'grupo' => 'Solicitud Venta - Notificaciones Comercial',
        'destinatarios' => 0,
    ];
    try {
        $resultadoNotificacion = svNotificarEntradaVoboComercial(
            $graphToken,
            $config,
            $folio,
            $principalFields,
            count($items)
        );
        $notificacionComercial['enviado'] = (bool) ($resultadoNotificacion['enviado'] ?? false);
        $notificacionComercial['grupo'] = (string) ($resultadoNotificacion['grupo'] ?? $notificacionComercial['grupo']);
        $notificacionComercial['destinatarios'] = count($resultadoNotificacion['destinatarios'] ?? []);
    } catch (Throwable $notificationError) {
        error_log('Solicitud Venta notificacion VoBo Comercial firma remota ' . $folio . ': ' . $notificationError->getMessage());
    }

    http_response_code(200);
    echo json_encode([
        'ok' => true,
        'folio' => $folio,
        'firmadoUtc' => $firmadoUtc,
        'estatus' => 'PENDIENTE VOBO',
        'componentesActualizados' => count($items),
        'notificacionComercial' => $notificacionComercial,
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
} catch (Throwable $error) {
    error_log('Solicitud Venta firma remota publica: ' . $error->getMessage());
    svResponderError(502, 'REMOTE_SIGNATURE_FAILED', 'No fue posible procesar la firma remota.');
}

/** @param array<string,mixed> $estado */
function frpValidarEstado(array $estado, string $folio, string $tokenPlano): void
{
    if (strtoupper(trim((string) ($estado['folio'] ?? ''))) !== $folio) svResponderError(409, 'TOKEN_MISMATCH', 'El enlace no corresponde a esta solicitud.');
    $hash = trim((string) ($estado['tokenHash'] ?? ''));
    if ($hash === '' || !hash_equals($hash, hash('sha256', $tokenPlano))) svResponderError(403, 'TOKEN_FORBIDDEN', 'El enlace de firma no es valido.');
    $expira = strtotime((string) ($estado['expiraUtc'] ?? ''));
    if ($expira !== false && time() > $expira) svResponderError(410, 'TOKEN_EXPIRED', 'El enlace de firma ha expirado. Solicita uno nuevo a tu asesor.');
}

/** @return array<string,mixed> */
function frpObtenerPrincipal(string $token, string $siteId, string $listId, string $itemId): array
{
    $campos = implode(',', [
        'Title', 'field_1', 'field_2', 'field_8', 'field_9', 'field_48', 'field_63',
        'Vendedor_Nombre', 'Vendedor_Correo', 'Solicitud_Grupo', 'Componente_Total',
        'field_102', 'field_103'
    ]);
    $url = 'https://graph.microsoft.com/v1.0/sites/' . rawurlencode($siteId) . '/lists/' . rawurlencode($listId)
        . '/items/' . rawurlencode($itemId) . '?$expand=fields($select=' . $campos . ')';
    return svCurlJson($url, 'GET', ['Authorization: Bearer ' . $token, 'Accept: application/json']);
}

/** @param array<string,mixed> $principal @return array<int,array<string,mixed>> */
function frpObtenerItemsGrupo(string $token, string $siteId, string $listId, string $grupo, array $principal): array
{
    $campos = 'Title,field_1,Solicitud_Grupo,Componente_Numero,Componente_Total';
    $items = [];
    $url = 'https://graph.microsoft.com/v1.0/sites/' . rawurlencode($siteId) . '/lists/' . rawurlencode($listId)
        . '/items?$expand=fields($select=' . $campos . ')&$top=200';
    while ($url !== '') {
        $respuesta = svCurlJson($url, 'GET', ['Authorization: Bearer ' . $token, 'Accept: application/json']);
        foreach (($respuesta['value'] ?? []) as $item) {
            if (!is_array($item)) continue;
            $f = is_array($item['fields'] ?? null) ? $item['fields'] : [];
            if (strtoupper(trim((string) ($f['Solicitud_Grupo'] ?? ''))) === $grupo) $items[] = $item;
        }
        $url = trim((string) ($respuesta['@odata.nextLink'] ?? ''));
    }
    return $items ?: [$principal];
}

function frpObtenerDriveExpedientes(string $token, string $siteId): string
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
function frpCargarJson(string $token, string $driveId, string $folio, string $archivo): array
{
    $path = rawurlencode($folio) . '/' . rawurlencode($archivo);
    $url = 'https://graph.microsoft.com/v1.0/drives/' . rawurlencode($driveId) . '/root:/' . $path . ':/content';
    return svCurlJson($url, 'GET', ['Authorization: Bearer ' . $token, 'Accept: application/json']);
}

/** @param array<string,mixed> $data */
function frpGuardarJson(string $token, string $driveId, string $folio, string $archivo, array $data): void
{
    $body = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if (!is_string($body)) throw new RuntimeException('No fue posible serializar evidencia.');
    $path = rawurlencode($folio) . '/' . rawurlencode($archivo);
    $url = 'https://graph.microsoft.com/v1.0/drives/' . rawurlencode($driveId) . '/root:/' . $path . ':/content';
    svCurlJson($url, 'PUT', ['Authorization: Bearer ' . $token, 'Accept: application/json', 'Content-Type: application/json; charset=utf-8'], $body);
}

/** @return array<string,mixed> */
function frpSubirArchivo(string $token, string $driveId, string $folio, string $archivo, string $mime, string $contenido): array
{
    $path = rawurlencode($folio) . '/' . rawurlencode($archivo);
    $url = 'https://graph.microsoft.com/v1.0/drives/' . rawurlencode($driveId) . '/root:/' . $path . ':/content';
    return svCurlJson($url, 'PUT', ['Authorization: Bearer ' . $token, 'Accept: application/json', 'Content-Type: ' . $mime], $contenido);
}

/** @param array<string,mixed> $driveItem @param array<string,mixed> $snapshot */
function frpActualizarMetadataFirma(string $token, array $driveItem, string $folio, array $snapshot): void
{
    $ids = is_array($driveItem['sharepointIds'] ?? null) ? $driveItem['sharepointIds'] : [];
    $listId = trim((string) ($ids['listId'] ?? ''));
    $listItemId = trim((string) ($ids['listItemId'] ?? ''));
    if ($listId === '' || $listItemId === '') return;

    $cliente = is_array($snapshot['cliente'] ?? null) ? $snapshot['cliente'] : [];
    $venta = is_array($snapshot['venta'] ?? null) ? $snapshot['venta'] : [];
    $fields = [
        'Folio_Solicitud' => $folio,
        'Tipo_Documento' => 'FIRMA_CLIENTE',
        'Cliente_Nombre' => trim((string) ($cliente['nombre'] ?? '')) ?: 'PENDIENTE',
        'Tipo_Venta' => trim((string) ($venta['tipoVentaProcap'] ?? '')) ?: 'PENDIENTE',
        'Estatus_Documento' => 'RECIBIDO',
    ];
    $body = json_encode($fields, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    $url = 'https://graph.microsoft.com/v1.0/sites/root/lists/' . rawurlencode($listId) . '/items/' . rawurlencode($listItemId) . '/fields';
    // Si el driveItem no expone siteId, omitimos metadata; el archivo y la evidencia ya quedaron guardados.
    try { svCurlJson($url, 'PATCH', ['Authorization: Bearer ' . $token, 'Accept: application/json', 'Content-Type: application/json'], (string) $body); } catch (Throwable $error) {}
}

/** @param array<string,mixed> $fields */
function frpActualizarCampos(string $token, string $siteId, string $listId, string $itemId, array $fields): void
{
    $body = json_encode($fields, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    $url = 'https://graph.microsoft.com/v1.0/sites/' . rawurlencode($siteId) . '/lists/' . rawurlencode($listId)
        . '/items/' . rawurlencode($itemId) . '/fields';
    svCurlJson($url, 'PATCH', ['Authorization: Bearer ' . $token, 'Accept: application/json', 'Content-Type: application/json'], (string) $body);
}
