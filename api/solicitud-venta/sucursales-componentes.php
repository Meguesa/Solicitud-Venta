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
    svResponderError(403, 'USER_EMAIL_REQUIRED', 'No fue posible identificar al vendedor.');
}

$raw = file_get_contents('php://input');
$payload = json_decode(is_string($raw) ? $raw : '', true);
if (!is_array($payload)) {
    svResponderError(400, 'INVALID_JSON', 'El cuerpo debe ser JSON valido.');
}

$accion = strtolower(trim((string) ($payload['accion'] ?? '')));
if (!in_array($accion, ['guardar', 'cargar'], true)) {
    svResponderError(400, 'INVALID_ACTION', 'La accion solicitada no es valida.');
}

$folio = strtoupper(trim((string) ($payload['folio'] ?? '')));
if (!preg_match('/^SV-(\d{4})-(\d{6,})$/', $folio, $match)) {
    svResponderError(400, 'INVALID_FOLIO', 'El folio no es valido.');
}
$itemIdPrincipal = (string) ((int) ltrim($match[2], '0'));
if ($itemIdPrincipal === '0') {
    svResponderError(400, 'INVALID_ITEM_ID', 'El folio no contiene un ID valido.');
}

try {
    $graphToken = svGraphToken($config['tenantId'], $config['clientId'], $config['clientSecret']);
    $principal = scObtenerPrincipal($graphToken, $config['siteId'], $config['listId'], $itemIdPrincipal);
    $principalFields = is_array($principal['fields'] ?? null) ? $principal['fields'] : [];
    scValidarPropietario($principalFields, $folio, $correoUsuario);

    $grupo = strtoupper(trim((string) ($principalFields['Solicitud_Grupo'] ?? '')));
    if ($grupo === '') $grupo = $folio;
    $items = scObtenerItemsGrupo($graphToken, $config['siteId'], $config['listId'], $grupo, $principal);

    if ($accion === 'cargar') {
        usort($items, static function (array $a, array $b): int {
            $fa = is_array($a['fields'] ?? null) ? $a['fields'] : [];
            $fb = is_array($b['fields'] ?? null) ? $b['fields'] : [];
            return ((int) ($fa['Componente_Numero'] ?? 0)) <=> ((int) ($fb['Componente_Numero'] ?? 0));
        });

        $componentes = [];
        foreach ($items as $index => $item) {
            $fields = is_array($item['fields'] ?? null) ? $item['fields'] : [];
            $numero = (int) ($fields['Componente_Numero'] ?? ($index + 1));
            $tipo = strtoupper(trim((string) ($fields['Tipo_Componente'] ?? $fields['Tipo_Solicitud'] ?? '')));
            $sucursal = strtoupper(trim((string) ($fields['field_49'] ?? '')));
            if ($tipo === 'LOTE' || $tipo === 'NICHO') $sucursal = 'PARQUE';
            if ($tipo === 'SERVICIO' && !in_array($sucursal, ['CHURUBUSCO', 'AGUA FRIA'], true)) $sucursal = '';
            $componentes[] = [
                'componenteNumero' => $numero,
                'tipoSolicitud' => $tipo,
                'sucursal' => $sucursal,
            ];
        }

        http_response_code(200);
        echo json_encode([
            'ok' => true,
            'folio' => $folio,
            'estatus' => strtoupper(trim((string) ($principalFields['field_1'] ?? ''))),
            'componentes' => $componentes,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }

    $estatus = strtoupper(trim((string) ($principalFields['field_1'] ?? '')));
    if ($estatus !== 'BORRADOR') {
        svResponderError(409, 'REQUEST_NOT_EDITABLE', 'La solicitud ya no se encuentra en BORRADOR.');
    }

    $entrada = $payload['componentes'] ?? null;
    if (!is_array($entrada) || count($entrada) < 1) {
        svResponderError(400, 'COMPONENTS_REQUIRED', 'La solicitud debe contener al menos un componente.');
    }

    $porNumero = [];
    foreach ($items as $index => $item) {
        $fields = is_array($item['fields'] ?? null) ? $item['fields'] : [];
        $correo = strtolower(trim((string) ($fields['Vendedor_Correo'] ?? '')));
        if ($correo === '' || !hash_equals($correo, $correoUsuario)) {
            svResponderError(403, 'GROUP_FORBIDDEN', 'Uno de los componentes no pertenece al vendedor autenticado.');
        }
        $numero = (int) ($fields['Componente_Numero'] ?? ($index + 1));
        if ($numero > 0) $porNumero[$numero] = $item;
    }

    $actualizados = 0;
    foreach (array_values($entrada) as $index => $componente) {
        if (!is_array($componente)) continue;
        $numero = $index + 1;
        $tipo = strtoupper(trim((string) ($componente['tipoSolicitud'] ?? '')));
        if (!in_array($tipo, ['SERVICIO', 'LOTE', 'NICHO'], true)) {
            svResponderError(400, 'INVALID_COMPONENT_TYPE', 'El componente ' . $numero . ' tiene un tipo no valido.');
        }

        $sucursal = strtoupper(trim((string) ($componente['sucursal'] ?? '')));
        if ($tipo === 'SERVICIO') {
            if ($sucursal === '') continue;
            if (!in_array($sucursal, ['CHURUBUSCO', 'AGUA FRIA'], true)) {
                svResponderError(400, 'INVALID_SERVICE_BRANCH', 'La sucursal del componente ' . $numero . ' no es valida.');
            }
        } else {
            $sucursal = 'PARQUE';
        }

        $item = $porNumero[$numero] ?? null;
        if (!is_array($item)) {
            svResponderError(409, 'COMPONENT_NOT_FOUND', 'No se encontro el componente ' . $numero . ' en SharePoint.');
        }
        $itemId = trim((string) ($item['id'] ?? ''));
        if ($itemId === '') throw new RuntimeException('Un componente no contiene ID.');

        scActualizarCampos($graphToken, $config['siteId'], $config['listId'], $itemId, ['field_49' => $sucursal]);
        $actualizados++;
    }

    http_response_code(200);
    echo json_encode([
        'ok' => true,
        'folio' => $folio,
        'componentesActualizados' => $actualizados,
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
} catch (Throwable $error) {
    error_log('Solicitud Venta sucursales componentes: ' . $error->getMessage());
    svResponderError(502, 'COMPONENT_BRANCH_SYNC_FAILED', 'No fue posible sincronizar las sucursales de los componentes.');
}

/** @return array<string,mixed> */
function scObtenerPrincipal(string $token, string $siteId, string $listId, string $itemId): array
{
    $campos = 'Title,field_1,Vendedor_Correo,Solicitud_Grupo,Componente_Numero,Tipo_Componente,Tipo_Solicitud,field_49';
    $url = 'https://graph.microsoft.com/v1.0/sites/' . rawurlencode($siteId)
        . '/lists/' . rawurlencode($listId)
        . '/items/' . rawurlencode($itemId)
        . '?$expand=fields($select=' . $campos . ')';
    return svCurlJson($url, 'GET', ['Authorization: Bearer ' . $token, 'Accept: application/json']);
}

/** @param array<string,mixed> $fields */
function scValidarPropietario(array $fields, string $folio, string $correoUsuario): void
{
    $correo = strtolower(trim((string) ($fields['Vendedor_Correo'] ?? '')));
    $title = strtoupper(trim((string) ($fields['Title'] ?? '')));
    $grupo = strtoupper(trim((string) ($fields['Solicitud_Grupo'] ?? '')));
    if ($correo === '' || !hash_equals($correo, $correoUsuario)) {
        svResponderError(403, 'REQUEST_FORBIDDEN', 'La solicitud no pertenece al vendedor autenticado.');
    }
    if ($title !== $folio && $grupo !== $folio) {
        svResponderError(409, 'REQUEST_MISMATCH', 'El folio no corresponde a la solicitud indicada.');
    }
}

/** @param array<string,mixed> $principal @return array<int,array<string,mixed>> */
function scObtenerItemsGrupo(string $token, string $siteId, string $listId, string $grupo, array $principal): array
{
    $campos = 'Title,field_1,Vendedor_Correo,Solicitud_Grupo,Componente_Numero,Tipo_Componente,Tipo_Solicitud,field_49';
    $items = [];
    $url = 'https://graph.microsoft.com/v1.0/sites/' . rawurlencode($siteId)
        . '/lists/' . rawurlencode($listId)
        . '/items?$expand=fields($select=' . $campos . ')&$top=200';

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

/** @param array<string,mixed> $fields */
function scActualizarCampos(string $token, string $siteId, string $listId, string $itemId, array $fields): void
{
    $body = json_encode($fields, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if (!is_string($body)) throw new RuntimeException('No fue posible serializar la sucursal del componente.');
    $url = 'https://graph.microsoft.com/v1.0/sites/' . rawurlencode($siteId)
        . '/lists/' . rawurlencode($listId)
        . '/items/' . rawurlencode($itemId)
        . '/fields';
    svCurlJson($url, 'PATCH', [
        'Authorization: Bearer ' . $token,
        'Accept: application/json',
        'Content-Type: application/json'
    ], $body);
}
