<?php

declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');

require_once __DIR__ . '/_common.php';
require_once __DIR__ . '/notificaciones-flujo.php';

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

$folio = strtoupper(trim((string) ($payload['folio'] ?? '')));
if (!preg_match('/^SV-(\d{4})-(\d{6,})$/', $folio, $folioMatch)) {
    svResponderError(400, 'INVALID_FOLIO', 'El folio de la solicitud no es valido.');
}

$itemIdPrincipal = (string) ((int) ltrim($folioMatch[2], '0'));
if ($itemIdPrincipal === '0') {
    svResponderError(400, 'INVALID_ITEM_ID', 'El folio no contiene un ID valido.');
}

$estatusDestino = 'PENDIENTE VOBO';

try {
    $graphToken = svGraphToken($config['tenantId'], $config['clientId'], $config['clientSecret']);
    $principal = obtenerItemPrincipal(
        $graphToken,
        $config['siteId'],
        $config['listId'],
        $itemIdPrincipal
    );

    $principalFields = is_array($principal['fields'] ?? null) ? $principal['fields'] : [];
    validarPrincipal($principalFields, $folio, $correoUsuario);

    $solicitudGrupo = strtoupper(trim((string) ($principalFields['Solicitud_Grupo'] ?? '')));
    if ($solicitudGrupo === '') $solicitudGrupo = $folio;

    $firmaCliente = strtoupper(trim((string) ($principalFields['field_102'] ?? '')));
    $firmaVendedor = strtoupper(trim((string) ($principalFields['field_103'] ?? '')));
    if ($firmaCliente !== 'FIRMADO' || $firmaVendedor !== 'FIRMADO') {
        svResponderError(409, 'SIGNATURES_REQUIRED', 'La firma del cliente y la firma del vendedor deben estar guardadas antes de enviar a Vo.Bo.');
    }

    $items = obtenerItemsGrupo(
        $graphToken,
        $config['siteId'],
        $config['listId'],
        $solicitudGrupo,
        $principal
    );

    if (!$items) {
        svResponderError(409, 'GROUP_EMPTY', 'No se encontraron componentes para la solicitud.');
    }

    $totalEsperado = (int) ($principalFields['Componente_Total'] ?? 0);
    if ($totalEsperado > 0 && count($items) !== $totalEsperado) {
        svResponderError(
            409,
            'COMPONENT_COUNT_MISMATCH',
            'La cantidad de componentes en SharePoint no coincide con la solicitud. Vuelve a guardar el borrador antes de validar.'
        );
    }

    foreach ($items as $item) {
        $fields = is_array($item['fields'] ?? null) ? $item['fields'] : [];
        $estado = strtoupper(trim((string) ($fields['field_1'] ?? '')));
        $correo = strtolower(trim((string) ($fields['Vendedor_Correo'] ?? '')));
        $grupo = strtoupper(trim((string) ($fields['Solicitud_Grupo'] ?? '')));

        if ($estado !== 'BORRADOR') {
            svResponderError(409, 'GROUP_NOT_DRAFT', 'Todos los componentes deben permanecer en BORRADOR antes de enviar a Vo.Bo.');
        }
        if ($correo === '' || !hash_equals($correo, $correoUsuario)) {
            svResponderError(403, 'GROUP_FORBIDDEN', 'Uno de los componentes no pertenece al vendedor autenticado.');
        }
        if ($grupo !== '' && $grupo !== $solicitudGrupo) {
            svResponderError(409, 'GROUP_MISMATCH', 'Uno de los componentes no pertenece al mismo grupo de solicitud.');
        }
    }

    usort($items, static function (array $a, array $b): int {
        $fa = is_array($a['fields'] ?? null) ? $a['fields'] : [];
        $fb = is_array($b['fields'] ?? null) ? $b['fields'] : [];
        return ((int) ($fa['Componente_Numero'] ?? 0)) <=> ((int) ($fb['Componente_Numero'] ?? 0));
    });

    $actualizados = [];
    try {
        foreach ($items as $item) {
            $id = trim((string) ($item['id'] ?? ''));
            if ($id === '') throw new RuntimeException('Un componente no contiene ID de SharePoint.');
            actualizarEstatus(
                $graphToken,
                $config['siteId'],
                $config['listId'],
                $id,
                $estatusDestino
            );
            $actualizados[] = $id;
        }
    } catch (Throwable $updateError) {
        foreach ($actualizados as $idActualizado) {
            try {
                actualizarEstatus(
                    $graphToken,
                    $config['siteId'],
                    $config['listId'],
                    $idActualizado,
                    'BORRADOR'
                );
            } catch (Throwable $rollbackError) {
                error_log('Solicitud Venta rollback VoBo: ' . $rollbackError->getMessage());
            }
        }
        throw $updateError;
    }

    $componentes = [];
    foreach ($items as $item) {
        $fields = is_array($item['fields'] ?? null) ? $item['fields'] : [];
        $componentes[] = [
            'itemId' => (string) ($item['id'] ?? ''),
            'title' => (string) ($fields['Title'] ?? ''),
            'componenteNumero' => (int) ($fields['Componente_Numero'] ?? 0),
        ];
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
        error_log('Solicitud Venta notificacion VoBo Comercial ' . $folio . ': ' . $notificationError->getMessage());
    }

    http_response_code(200);
    echo json_encode([
        'ok' => true,
        'folio' => $folio,
        'solicitudGrupo' => $solicitudGrupo,
        'estatus' => $estatusDestino,
        'componentesActualizados' => count($componentes),
        'componentes' => $componentes,
        'notificacionComercial' => $notificacionComercial,
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
} catch (Throwable $error) {
    error_log('Solicitud Venta validar VoBo: ' . $error->getMessage());
    svResponderError(502, 'VALIDATION_TRANSITION_FAILED', 'No fue posible enviar la solicitud al flujo de Vo.Bo.');
}

/** @return array<string,mixed> */
function obtenerItemPrincipal(string $token, string $siteId, string $listId, string $itemId): array
{
    $campos = implode(',', [
        'Title', 'field_1', 'field_2', 'field_8', 'field_9', 'field_48', 'field_63',
        'Vendedor_Nombre', 'Vendedor_Correo', 'Solicitud_Grupo', 'Componente_Numero',
        'Componente_Total', 'field_102', 'field_103'
    ]);
    $url = 'https://graph.microsoft.com/v1.0/sites/' . rawurlencode($siteId)
        . '/lists/' . rawurlencode($listId)
        . '/items/' . rawurlencode($itemId)
        . '?$expand=fields($select=' . $campos . ')';

    return svCurlJson($url, 'GET', [
        'Authorization: Bearer ' . $token,
        'Accept: application/json',
    ]);
}

/** @param array<string,mixed> $fields */
function validarPrincipal(array $fields, string $folio, string $correoUsuario): void
{
    $title = strtoupper(trim((string) ($fields['Title'] ?? '')));
    $grupo = strtoupper(trim((string) ($fields['Solicitud_Grupo'] ?? '')));
    $estado = strtoupper(trim((string) ($fields['field_1'] ?? '')));
    $correo = strtolower(trim((string) ($fields['Vendedor_Correo'] ?? '')));

    if ($title !== $folio && $grupo !== $folio) {
        svResponderError(409, 'DRAFT_MISMATCH', 'El registro principal no corresponde al folio solicitado.');
    }
    if ($estado !== 'BORRADOR') {
        svResponderError(409, 'DRAFT_NOT_EDITABLE', 'La solicitud ya no se encuentra en estado BORRADOR.');
    }
    if ($correo === '' || !hash_equals($correo, $correoUsuario)) {
        svResponderError(403, 'DRAFT_FORBIDDEN', 'La solicitud no pertenece al vendedor autenticado.');
    }
}

/** @param array<string,mixed> $principal
 *  @return array<int,array<string,mixed>>
 */
function obtenerItemsGrupo(
    string $token,
    string $siteId,
    string $listId,
    string $grupo,
    array $principal
): array {
    $campos = 'Title,field_1,Vendedor_Correo,Solicitud_Grupo,Componente_Numero,Componente_Total';
    $filtro = "fields/Solicitud_Grupo eq '" . str_replace("'", "''", $grupo) . "'";
    $query = http_build_query([
        '$expand' => 'fields($select=' . $campos . ')',
        '$filter' => $filtro,
        '$top' => '200',
    ], '', '&', PHP_QUERY_RFC3986);

    $url = 'https://graph.microsoft.com/v1.0/sites/' . rawurlencode($siteId)
        . '/lists/' . rawurlencode($listId) . '/items?' . $query;

    try {
        $respuesta = svCurlJson($url, 'GET', [
            'Authorization: Bearer ' . $token,
            'Accept: application/json',
            'Prefer: HonorNonIndexedQueriesWarningMayFailRandomly',
        ]);
        $items = array_values(array_filter($respuesta['value'] ?? [], 'is_array'));
        if ($items) return $items;
    } catch (Throwable $filterError) {
        error_log('Solicitud Venta filtro grupo VoBo: ' . $filterError->getMessage());
    }

    $items = [];
    $url = 'https://graph.microsoft.com/v1.0/sites/' . rawurlencode($siteId)
        . '/lists/' . rawurlencode($listId)
        . '/items?$expand=fields($select=' . $campos . ')&$top=200';

    while ($url !== '') {
        $respuesta = svCurlJson($url, 'GET', [
            'Authorization: Bearer ' . $token,
            'Accept: application/json',
        ]);
        foreach (($respuesta['value'] ?? []) as $item) {
            if (!is_array($item)) continue;
            $fields = is_array($item['fields'] ?? null) ? $item['fields'] : [];
            $itemGrupo = strtoupper(trim((string) ($fields['Solicitud_Grupo'] ?? '')));
            if ($itemGrupo === $grupo) $items[] = $item;
        }
        $url = trim((string) ($respuesta['@odata.nextLink'] ?? ''));
    }

    if ($items) return $items;

    $principalFields = is_array($principal['fields'] ?? null) ? $principal['fields'] : [];
    $principalGrupo = strtoupper(trim((string) ($principalFields['Solicitud_Grupo'] ?? '')));
    if ($principalGrupo === '' || $principalGrupo === $grupo) return [$principal];

    return [];
}

function actualizarEstatus(
    string $token,
    string $siteId,
    string $listId,
    string $itemId,
    string $estatus
): void {
    $url = 'https://graph.microsoft.com/v1.0/sites/' . rawurlencode($siteId)
        . '/lists/' . rawurlencode($listId)
        . '/items/' . rawurlencode($itemId) . '/fields';
    $body = json_encode(['field_1' => $estatus], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if (!is_string($body)) throw new RuntimeException('No fue posible serializar el cambio de estatus.');

    svCurlJson($url, 'PATCH', [
        'Authorization: Bearer ' . $token,
        'Accept: application/json',
        'Content-Type: application/json',
    ], $body);
}
