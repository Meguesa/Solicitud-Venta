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

$folio = strtoupper(trim((string) ($payload['folio'] ?? '')));
if (!preg_match('/^SV-(\d{4})-(\d{6,})$/', $folio, $match)) {
    svResponderError(400, 'INVALID_FOLIO', 'El folio no es valido.');
}
$itemIdPrincipal = (string) ((int) ltrim($match[2], '0'));
if ($itemIdPrincipal === '0') svResponderError(400, 'INVALID_ITEM_ID', 'El folio no contiene un ID valido.');
$detalleSolicitud = frNormalizarDetalleSolicitud($payload['detalleSolicitud'] ?? []);

try {
    $token = svGraphToken($config['tenantId'], $config['clientId'], $config['clientSecret']);
    $principal = frObtenerPrincipal($token, $config['siteId'], $config['listId'], $itemIdPrincipal);
    $principalFields = is_array($principal['fields'] ?? null) ? $principal['fields'] : [];

    frValidarPrincipal($principalFields, $folio, $correoUsuario);
    if (strtoupper(trim((string) ($principalFields['field_103'] ?? ''))) !== 'FIRMADO') {
        svResponderError(409, 'SELLER_SIGNATURE_REQUIRED', 'La firma del vendedor debe estar guardada antes de enviar al cliente.');
    }

    $grupo = strtoupper(trim((string) ($principalFields['Solicitud_Grupo'] ?? '')));
    if ($grupo === '') $grupo = $folio;

    $items = frObtenerItemsGrupo($token, $config['siteId'], $config['listId'], $grupo, $principal);
    if (!$items) svResponderError(409, 'GROUP_EMPTY', 'No se encontraron componentes de la solicitud.');

    $totalEsperado = (int) ($principalFields['Componente_Total'] ?? 0);
    if ($totalEsperado > 0 && count($items) !== $totalEsperado) {
        svResponderError(409, 'COMPONENT_COUNT_MISMATCH', 'La cantidad de componentes no coincide. Guarda nuevamente el borrador.');
    }

    foreach ($items as $item) {
        $fields = is_array($item['fields'] ?? null) ? $item['fields'] : [];
        $estado = strtoupper(trim((string) ($fields['field_1'] ?? '')));
        $correo = strtolower(trim((string) ($fields['Vendedor_Correo'] ?? '')));
        if ($estado !== 'BORRADOR') svResponderError(409, 'GROUP_NOT_DRAFT', 'Todos los componentes deben estar en BORRADOR.');
        if ($correo === '' || !hash_equals($correo, $correoUsuario)) svResponderError(403, 'GROUP_FORBIDDEN', 'La solicitud no pertenece al vendedor autenticado.');
    }

    usort($items, static function (array $a, array $b): int {
        $fa = is_array($a['fields'] ?? null) ? $a['fields'] : [];
        $fb = is_array($b['fields'] ?? null) ? $b['fields'] : [];
        return ((int) ($fa['Componente_Numero'] ?? 0)) <=> ((int) ($fb['Componente_Numero'] ?? 0));
    });

    $snapshot = frCrearSnapshot($folio, $grupo, $principalFields, $items);
    if ($detalleSolicitud) $snapshot['detalleCompleto'] = $detalleSolicitud;
    $snapshotJson = json_encode($snapshot, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if (!is_string($snapshotJson)) throw new RuntimeException('No fue posible serializar la solicitud para firma.');

    $tokenPlano = $folio . '.' . bin2hex(random_bytes(32));
    $ahora = gmdate('c');
    $expira = gmdate('c', time() + (7 * 24 * 60 * 60));
    $estadoFirma = [
        'version' => 1,
        'folio' => $folio,
        'solicitudGrupo' => $grupo,
        'tokenHash' => hash('sha256', $tokenPlano),
        'creadoUtc' => $ahora,
        'expiraUtc' => $expira,
        'firmado' => false,
        'snapshotSha256' => hash('sha256', $snapshotJson),
        'snapshot' => $snapshot,
    ];

    $driveId = frObtenerDriveExpedientes($token, $config['siteId']);
    frAsegurarCarpeta($token, $driveId, $folio);
    frGuardarJson($token, $driveId, $folio, '_FIRMA_REMOTA.json', $estadoFirma);

    $actualizados = [];
    try {
        foreach ($items as $item) {
            $id = trim((string) ($item['id'] ?? ''));
            if ($id === '') throw new RuntimeException('Un componente no contiene ID.');
            frActualizarCampos($token, $config['siteId'], $config['listId'], $id, ['field_1' => 'PENDIENTE FIRMA']);
            $actualizados[] = $id;
        }
    } catch (Throwable $error) {
        foreach ($actualizados as $id) {
            try { frActualizarCampos($token, $config['siteId'], $config['listId'], $id, ['field_1' => 'BORRADOR']); } catch (Throwable $rollback) {}
        }
        throw $error;
    }

    http_response_code(200);
    echo json_encode([
        'ok' => true,
        'folio' => $folio,
        'solicitudGrupo' => $grupo,
        'estatus' => 'PENDIENTE FIRMA',
        'expiraUtc' => $expira,
        'firmaUrl' => 'https://portal.juanpablo.com.mx/firma/?token=' . rawurlencode($tokenPlano),
        'componentesActualizados' => count($items),
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
} catch (Throwable $error) {
    error_log('Solicitud Venta iniciar firma remota: ' . $error->getMessage());
    svResponderError(502, 'REMOTE_SIGNATURE_START_FAILED', 'No fue posible preparar la firma remota.');
}

/** @return array<string,mixed> */
function frObtenerPrincipal(string $token, string $siteId, string $listId, string $itemId): array
{
    $campos = implode(',', [
        'Title','field_1','field_2','Vendedor_Correo','Solicitud_Grupo','Componente_Total','field_103',
        'field_8','field_9','field_21','field_22','field_48','field_61','field_62','field_63','field_64','field_65','field_69','Paquete'
    ]);
    $url = 'https://graph.microsoft.com/v1.0/sites/' . rawurlencode($siteId)
        . '/lists/' . rawurlencode($listId) . '/items/' . rawurlencode($itemId)
        . '?$expand=fields($select=' . $campos . ')';
    return svCurlJson($url, 'GET', ['Authorization: Bearer ' . $token, 'Accept: application/json']);
}

/** @param array<string,mixed> $fields */
function frValidarPrincipal(array $fields, string $folio, string $correoUsuario): void
{
    $title = strtoupper(trim((string) ($fields['Title'] ?? '')));
    $grupo = strtoupper(trim((string) ($fields['Solicitud_Grupo'] ?? '')));
    $estado = strtoupper(trim((string) ($fields['field_1'] ?? '')));
    $correo = strtolower(trim((string) ($fields['Vendedor_Correo'] ?? '')));
    if ($title !== $folio && $grupo !== $folio) svResponderError(409, 'DRAFT_MISMATCH', 'El folio no corresponde al registro principal.');
    if ($estado !== 'BORRADOR') svResponderError(409, 'DRAFT_NOT_EDITABLE', 'La solicitud ya no se encuentra en BORRADOR.');
    if ($correo === '' || !hash_equals($correo, $correoUsuario)) svResponderError(403, 'DRAFT_FORBIDDEN', 'La solicitud no pertenece al vendedor autenticado.');
}

/** @param array<string,mixed> $principal @return array<int,array<string,mixed>> */
function frObtenerItemsGrupo(string $token, string $siteId, string $listId, string $grupo, array $principal): array
{
    $campos = implode(',', [
        'Title','field_1','Vendedor_Correo','Solicitud_Grupo','Componente_Numero','Componente_Total','Tipo_Componente',
        'field_47','field_48','field_4','field_52','field_53','field_54','field_55','field_56','Propiedad_Subtipo','field_57','field_58','field_59','field_60',
        'Precio_Base_Componente','Monto_Componente'
    ]);
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
    if ($items) return $items;
    return [$principal];
}

/** @param array<string,mixed> $principalFields @param array<int,array<string,mixed>> $items @return array<string,mixed> */
function frCrearSnapshot(string $folio, string $grupo, array $principalFields, array $items): array
{
    $cliente = trim((string) ($principalFields['field_8'] ?? '') . ' ' . (string) ($principalFields['field_9'] ?? ''));
    $cliente = preg_replace('/\s+/', ' ', $cliente) ?: $cliente;
    $componentes = [];
    foreach ($items as $item) {
        $f = is_array($item['fields'] ?? null) ? $item['fields'] : [];
        $componentes[] = [
            'numero' => (int) ($f['Componente_Numero'] ?? 0),
            'tipo' => (string) ($f['Tipo_Componente'] ?? ''),
            'operacion' => (string) ($f['field_47'] ?? ''),
            'tipoVentaProcap' => (string) ($f['field_48'] ?? ''),
            'referencia' => (string) ($f['field_4'] ?? ''),
            'servicioTipo' => (string) ($f['field_52'] ?? ''),
            'servicioAtaud' => (string) ($f['field_53'] ?? ''),
            'servicioUrna' => (string) ($f['field_54'] ?? ''),
            'servicioDuracion' => (string) ($f['field_55'] ?? ''),
            'propiedadSubtipo' => (string) ($f['Propiedad_Subtipo'] ?? ''),
            'propiedadSeccion' => (string) ($f['field_57'] ?? ''),
            'propiedadManzana' => (string) ($f['field_58'] ?? ''),
            'propiedadNumero' => (string) ($f['field_59'] ?? ''),
            'propiedadClave' => (string) ($f['field_60'] ?? ''),
            'precioBase' => (float) ($f['Precio_Base_Componente'] ?? 0),
            'monto' => (float) ($f['Monto_Componente'] ?? 0),
        ];
    }

    return [
        'folio' => $folio,
        'solicitudGrupo' => $grupo,
        'fechaSolicitud' => (string) ($principalFields['field_2'] ?? ''),
        'cliente' => [
            'nombre' => $cliente,
            'celular' => (string) ($principalFields['field_21'] ?? ''),
            'correo' => (string) ($principalFields['field_22'] ?? ''),
        ],
        'venta' => [
            'tipoVentaProcap' => (string) ($principalFields['field_48'] ?? ''),
            'paquete' => (string) ($principalFields['Paquete'] ?? ''),
            'descripcion' => (string) ($principalFields['field_61'] ?? ''),
            'formaPago' => (string) ($principalFields['field_62'] ?? ''),
            'precioTotal' => (float) ($principalFields['field_63'] ?? 0),
            'enganche' => (float) ($principalFields['field_64'] ?? 0),
            'saldo' => (float) ($principalFields['field_65'] ?? 0),
            'metodoPago' => (string) ($principalFields['field_69'] ?? ''),
        ],
        'componentes' => $componentes,
    ];
}

/** @return array<int,array{titulo:string,campos:array<int,array{etiqueta:string,valor:string,tipo:string}>}> */
function frNormalizarDetalleSolicitud(mixed $raw): array
{
    if (!is_array($raw)) return [];
    $resultado = [];
    foreach (array_slice($raw, 0, 40) as $seccion) {
        if (!is_array($seccion)) continue;
        $titulo = frLimitarTexto($seccion['titulo'] ?? '', 140);
        if ($titulo === '') $titulo = 'Informacion';
        $camposRaw = is_array($seccion['campos'] ?? null) ? $seccion['campos'] : [];
        $campos = [];
        foreach (array_slice($camposRaw, 0, 120) as $campo) {
            if (!is_array($campo)) continue;
            $etiqueta = frLimitarTexto($campo['etiqueta'] ?? '', 180);
            $valor = frLimitarTexto($campo['valor'] ?? '', 4000);
            if ($etiqueta === '' || $valor === '') continue;
            $tipo = strtolower(frLimitarTexto($campo['tipo'] ?? 'texto', 30));
            if (!in_array($tipo, ['texto', 'fecha', 'moneda', 'numero', 'booleano'], true)) $tipo = 'texto';
            $campos[] = ['etiqueta' => $etiqueta, 'valor' => $valor, 'tipo' => $tipo];
        }
        if ($campos) $resultado[] = ['titulo' => $titulo, 'campos' => $campos];
    }
    return $resultado;
}

function frLimitarTexto(mixed $valor, int $max): string
{
    $texto = trim((string) $valor);
    if ($texto === '') return '';
    return mb_substr($texto, 0, $max, 'UTF-8');
}

function frObtenerDriveExpedientes(string $token, string $siteId): string
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

function frAsegurarCarpeta(string $token, string $driveId, string $folio): void
{
    $url = 'https://graph.microsoft.com/v1.0/drives/' . rawurlencode($driveId) . '/root:/' . rawurlencode($folio);
    try {
        svCurlJson($url, 'GET', ['Authorization: Bearer ' . $token, 'Accept: application/json']);
        return;
    } catch (Throwable $error) {}
    $body = json_encode(['name' => $folio, 'folder' => new stdClass(), '@microsoft.graph.conflictBehavior' => 'fail']);
    svCurlJson('https://graph.microsoft.com/v1.0/drives/' . rawurlencode($driveId) . '/root/children', 'POST', [
        'Authorization: Bearer ' . $token, 'Accept: application/json', 'Content-Type: application/json'
    ], (string) $body);
}

/** @param array<string,mixed> $data */
function frGuardarJson(string $token, string $driveId, string $folio, string $archivo, array $data): void
{
    $body = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if (!is_string($body)) throw new RuntimeException('No fue posible serializar el estado de firma.');
    $path = rawurlencode($folio) . '/' . rawurlencode($archivo);
    $url = 'https://graph.microsoft.com/v1.0/drives/' . rawurlencode($driveId) . '/root:/' . $path . ':/content';
    svCurlJson($url, 'PUT', ['Authorization: Bearer ' . $token, 'Accept: application/json', 'Content-Type: application/json; charset=utf-8'], $body);
}

/** @param array<string,mixed> $fields */
function frActualizarCampos(string $token, string $siteId, string $listId, string $itemId, array $fields): void
{
    $body = json_encode($fields, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if (!is_string($body)) throw new RuntimeException('No fue posible serializar campos.');
    $url = 'https://graph.microsoft.com/v1.0/sites/' . rawurlencode($siteId) . '/lists/' . rawurlencode($listId)
        . '/items/' . rawurlencode($itemId) . '/fields';
    svCurlJson($url, 'PATCH', ['Authorization: Bearer ' . $token, 'Accept: application/json', 'Content-Type: application/json'], $body);
}
