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
if ($correoUsuario === '') svResponderError(403, 'USER_EMAIL_REQUIRED', 'No fue posible identificar el correo del usuario.');

$rawBody = file_get_contents('php://input');
$payload = json_decode(is_string($rawBody) ? $rawBody : '', true);
if (!is_array($payload)) svResponderError(400, 'INVALID_JSON', 'El cuerpo debe ser JSON valido.');

$folio = strtoupper(trim((string) ($payload['folio'] ?? '')));
if (!preg_match('/^SV-(\d{4})-(\d{6,})$/', $folio, $folioMatch)) {
    svResponderError(400, 'INVALID_FOLIO', 'El folio de la solicitud no es valido.');
}

$entrada = $payload['componentes'] ?? null;
if (!is_array($entrada) || count($entrada) < 1) {
    svResponderError(400, 'COMPONENTS_REQUIRED', 'La solicitud debe contener al menos un componente.');
}
if (count($entrada) > 25) svResponderError(400, 'COMPONENT_LIMIT', 'La solicitud no puede contener mas de 25 componentes.');

$distribucionTipo = strtoupper(trim((string) ($payload['distribucionTipo'] ?? 'AUTOMATICA')));
if (!in_array($distribucionTipo, ['AUTOMATICA', 'MANUAL_PROMOCION'], true)) {
    svResponderError(400, 'INVALID_DISTRIBUTION', 'El tipo de distribucion no es valido.');
}
$promocionNombre = trim((string) ($payload['promocionNombre'] ?? ''));

$componentes = [];
foreach (array_values($entrada) as $index => $item) {
    if (!is_array($item)) svResponderError(400, 'INVALID_COMPONENT', 'Uno de los componentes no tiene un formato valido.');

    $numero = $index + 1;
    $tipo = strtoupper(trim((string) ($item['tipoSolicitud'] ?? '')));
    $operacion = strtoupper(trim((string) ($item['tipoOperacion'] ?? '')));
    if (!in_array($tipo, ['SERVICIO', 'LOTE', 'NICHO'], true)) {
        svResponderError(400, 'INVALID_COMPONENT_TYPE', 'El componente ' . $numero . ' tiene un tipo no valido.');
    }
    if (!in_array($operacion, ['PREVISION', 'USO INMEDIATO'], true)) {
        svResponderError(400, 'INVALID_COMPONENT_OPERATION', 'El componente ' . $numero . ' tiene una operacion no valida.');
    }

    $componentes[] = [
        'numero' => $numero,
        'tipo' => $tipo,
        'operacion' => $operacion,
        'tipoVentaProcap' => tipoVentaProcap($tipo, $operacion),
        'servicioTipo' => trim((string) ($item['servicioTipo'] ?? '')),
        'servicioAtaud' => trim((string) ($item['servicioAtaud'] ?? '')),
        'servicioUrna' => trim((string) ($item['servicioUrna'] ?? '')),
        'servicioDuracion' => trim((string) ($item['servicioDuracion'] ?? '')),
        'servicioNumero' => strtoupper(trim((string) ($item['servicioNumero'] ?? ''))),
        'servicioClave' => strtoupper(trim((string) ($item['servicioClave'] ?? ''))),
        'propiedadTipo' => trim((string) ($item['propiedadTipo'] ?? '')),
        'propiedadSeccion' => trim((string) ($item['propiedadSeccion'] ?? '')),
        'propiedadManzana' => trim((string) ($item['propiedadManzana'] ?? '')),
        'propiedadNumero' => trim((string) ($item['propiedadNumero'] ?? '')),
        'propiedadClave' => trim((string) ($item['propiedadClave'] ?? '')),
        'precioBaseComponente' => numeroNoNegativo($item['precioBaseComponente'] ?? 0, $numero, 'precio base'),
        'montoComponente' => numeroNoNegativo($item['montoComponente'] ?? 0, $numero, 'monto asignado'),
    ];
}

$itemIdPrincipal = (string) ((int) ltrim($folioMatch[2], '0'));
if ($itemIdPrincipal === '0') svResponderError(400, 'INVALID_ITEM_ID', 'El folio no contiene un ID valido.');

try {
    $graphToken = svGraphToken($config['tenantId'], $config['clientId'], $config['clientSecret']);
    $principal = obtenerItem($graphToken, $config['siteId'], $config['listId'], $itemIdPrincipal);
    $principalFields = is_array($principal['fields'] ?? null) ? $principal['fields'] : [];
    verificarPrincipal($principalFields, $folio, $correoUsuario);

    $columnas = obtenerColumnas($graphToken, $config['siteId'], $config['listId']);
    $camposComunes = construirCamposComunes($principalFields, $columnas);
    $itemsGrupo = obtenerItemsGrupo($graphToken, $config['siteId'], $config['listId'], $folio);

    $existentes = [];
    $duplicados = [];
    foreach ($itemsGrupo as $registro) {
        $id = trim((string) ($registro['id'] ?? ''));
        if ($id === '' || $id === $itemIdPrincipal) continue;
        $fields = is_array($registro['fields'] ?? null) ? $registro['fields'] : [];
        $numero = (int) ($fields['Componente_Numero'] ?? 0);
        if ($numero < 2 || isset($existentes[$numero])) {
            $duplicados[] = $registro;
            continue;
        }
        $existentes[$numero] = $registro;
    }

    $total = count($componentes);
    actualizarCampos($graphToken, $config['siteId'], $config['listId'], $itemIdPrincipal,
        construirCamposComponente($componentes[0], $folio, $total, true, $distribucionTipo, $promocionNombre)
    );

    $registros = [[
        'itemId' => $itemIdPrincipal,
        'title' => $folio,
        'componenteNumero' => 1,
        'esPrincipal' => true,
    ]];
    $idsUsados = [];

    for ($index = 1; $index < $total; $index++) {
        $numero = $index + 1;
        $title = $folio . '-' . str_pad((string) $numero, 2, '0', STR_PAD_LEFT);
        $campos = array_merge(
            $camposComunes,
            construirCamposComponente($componentes[$index], $folio, $total, false, $distribucionTipo, $promocionNombre),
            ['Title' => $title]
        );

        if (isset($existentes[$numero])) {
            $registro = $existentes[$numero];
            $itemId = (string) ($registro['id'] ?? '');
            $fields = is_array($registro['fields'] ?? null) ? $registro['fields'] : [];
            verificarSecundario($fields, $folio, $correoUsuario);
            actualizarCampos($graphToken, $config['siteId'], $config['listId'], $itemId, $campos);
        } else {
            $creado = crearItem($graphToken, $config['siteId'], $config['listId'], $campos);
            $itemId = (string) ($creado['id'] ?? '');
            if ($itemId === '') throw new RuntimeException('SharePoint creo un componente sin devolver su ID.');
        }

        $idsUsados[$itemId] = true;
        $registros[] = [
            'itemId' => $itemId,
            'title' => $title,
            'componenteNumero' => $numero,
            'esPrincipal' => false,
        ];
    }

    foreach ($existentes as $registro) {
        $itemId = (string) ($registro['id'] ?? '');
        if ($itemId === '' || isset($idsUsados[$itemId])) continue;
        $fields = is_array($registro['fields'] ?? null) ? $registro['fields'] : [];
        verificarSecundario($fields, $folio, $correoUsuario);
        eliminarItem($graphToken, $config['siteId'], $config['listId'], $itemId);
    }
    foreach ($duplicados as $registro) {
        $itemId = (string) ($registro['id'] ?? '');
        if ($itemId === '') continue;
        $fields = is_array($registro['fields'] ?? null) ? $registro['fields'] : [];
        verificarSecundario($fields, $folio, $correoUsuario);
        eliminarItem($graphToken, $config['siteId'], $config['listId'], $itemId);
    }
} catch (Throwable $error) {
    error_log('Solicitud Venta componentes: ' . $error->getMessage());
    svResponderError(502, 'COMPONENT_SYNC_FAILED', 'No fue posible sincronizar los componentes: ' . $error->getMessage());
}

http_response_code(200);
echo json_encode([
    'ok' => true,
    'folio' => $folio,
    'solicitudGrupo' => $folio,
    'componenteTotal' => count($componentes),
    'distribucionTipo' => $distribucionTipo,
    'promocionNombre' => $promocionNombre,
    'registros' => $registros,
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

function numeroNoNegativo($value, int $componente, string $nombre): float
{
    if ($value === null || $value === '') return 0.0;
    if (!is_numeric($value)) svResponderError(400, 'INVALID_COMPONENT_AMOUNT', 'El ' . $nombre . ' del componente ' . $componente . ' no es numerico.');
    $numero = (float) $value;
    if ($numero < 0) svResponderError(400, 'INVALID_COMPONENT_AMOUNT', 'El ' . $nombre . ' del componente ' . $componente . ' no puede ser negativo.');
    return round($numero, 2);
}

function tipoVentaProcap(string $tipo, string $operacion): string
{
    if ($tipo === 'SERVICIO' && $operacion === 'PREVISION') return 'SERVICIO PREVISION';
    if ($tipo === 'SERVICIO' && $operacion === 'USO INMEDIATO') return 'SERVICIO UI';
    if ($tipo === 'LOTE' && $operacion === 'PREVISION') return 'CEMENTERIO PREVISION';
    if ($tipo === 'LOTE' && $operacion === 'USO INMEDIATO') return 'CEMENTERIO UI';
    if ($tipo === 'NICHO' && $operacion === 'PREVISION') return 'NICHO PREVISION';
    if ($tipo === 'NICHO' && $operacion === 'USO INMEDIATO') return 'NICHO UI';
    throw new RuntimeException('No fue posible determinar el tipo de venta ProcaP.');
}

function claveServicio(string $servicioTipo, string $ataud, string $numero, string $operacion): string
{
    $servicios = [
        'VELACION E INHUMACION' => 'VI',
        'VELACION Y CREMACION' => 'VC',
        'CREMACION DIRECTA' => 'CD',
        'INHUMACION DIRECTA' => 'ID',
        'RENTA DE CAPILLA' => 'RC',
        'TRASLADO' => 'TR',
        'OTRO' => 'OT',
    ];
    $ataudes = [
        'ATAUD MADERA BASICO' => 'ATMADBA',
        'ATAUD MADERA EXCLUSIVO' => 'ATMADEX',
        'ATAUD MADERA DE LUJO' => 'ATMADLU',
        'ATAUD METALICO BASICO' => 'ATMETBA',
        'ATAUD METALICO EXCLUSIVO' => 'ATMETEX',
        'OTRO' => 'ATOTRO',
    ];
    $cremacionDirecta = [
        'PREVISION' => 'OPATMET',
        'USO INMEDIATO' => 'URNABAS',
    ];

    $tipo = strtoupper(trim($servicioTipo));
    $tipoAtaud = strtoupper(trim($ataud));
    $numero = strtoupper(trim($numero));
    $operacion = strtoupper(trim($operacion));
    if ($numero === '') return '';

    $prefijoServicio = $servicios[$tipo] ?? '';
    if ($prefijoServicio === '') return '';

    if ($tipo === 'CREMACION DIRECTA') {
        $complemento = $cremacionDirecta[$operacion] ?? '';
        if ($complemento === '') return '';
        return $prefijoServicio . '-' . $complemento . '-' . $numero;
    }

    $prefijoAtaud = $ataudes[$tipoAtaud] ?? '';
    if ($prefijoAtaud === '') return '';
    return $prefijoServicio . '-' . $prefijoAtaud . '-' . $numero;
}

function ataudNormalizado(string $servicioTipo, string $ataud): string
{
    if (strtoupper(trim($servicioTipo)) === 'CREMACION DIRECTA') return 'NO APLICA';
    return trim($ataud);
}

function urnaNormalizada(string $servicioTipo, string $urna): string
{
    if (str_contains(strtoupper(trim($servicioTipo)), 'INHUMACION')) return 'NO APLICA';
    return trim($urna);
}

/** @return array<string,mixed> */
function construirCamposComponente(array $componente, string $folio, int $total, bool $principal, string $distribucionTipo, string $promocionNombre): array
{
    $tipo = (string) $componente['tipo'];
    $campos = [
        'field_1' => 'BORRADOR',
        'field_4' => null,
        'Solicitud_Grupo' => $folio,
        'Componente_Numero' => (int) $componente['numero'],
        'Componente_Total' => $total,
        'Tipo_Componente' => $tipo,
        'Es_Principal' => $principal,
        'Tipo_Solicitud' => $tipo,
        'field_47' => (string) $componente['operacion'],
        'field_48' => (string) $componente['tipoVentaProcap'],
        'field_51' => (string) $componente['tipoVentaProcap'],
        'Monto_Componente' => (float) $componente['montoComponente'],
        'Precio_Base_Componente' => (float) $componente['precioBaseComponente'],
        'Distribucion_Tipo' => $distribucionTipo,
        'Promocion_Nombre' => $promocionNombre,
        'field_52' => null, 'field_53' => null, 'field_54' => null, 'field_55' => null,
        'field_56' => null, 'Propiedad_Subtipo' => null, 'field_57' => null,
        'field_58' => null, 'field_59' => null, 'field_60' => null,
    ];

    if ($tipo === 'SERVICIO') {
        $clave = claveServicio(
            (string) $componente['servicioTipo'],
            (string) $componente['servicioAtaud'],
            (string) $componente['servicioNumero'],
            (string) $componente['operacion']
        );
        if ($clave === '') {
            throw new RuntimeException('No fue posible construir la clave del componente de servicio ' . (string) $componente['numero'] . '.');
        }
        $campos['field_4'] = $clave;
        $campos['field_52'] = (string) $componente['servicioTipo'];
        $campos['field_53'] = ataudNormalizado((string) $componente['servicioTipo'], (string) $componente['servicioAtaud']);
        $campos['field_54'] = urnaNormalizada((string) $componente['servicioTipo'], (string) $componente['servicioUrna']);
        $campos['field_55'] = (string) $componente['servicioDuracion'];
    } else {
        $campos['field_56'] = $tipo;
        $campos['Propiedad_Subtipo'] = (string) $componente['propiedadTipo'];
        $campos['field_57'] = (string) $componente['propiedadSeccion'];
        $campos['field_58'] = (string) $componente['propiedadManzana'];
        $campos['field_59'] = (string) $componente['propiedadNumero'];
        $campos['field_60'] = (string) $componente['propiedadClave'];
    }
    return $campos;
}

/** @return array<string,mixed> */
function construirCamposComunes(array $principalFields, array $columnas): array
{
    $escribibles = [];
    foreach (($columnas['value'] ?? []) as $columna) {
        if (!is_array($columna)) continue;
        $name = trim((string) ($columna['name'] ?? ''));
        if ($name !== '' && empty($columna['readOnly'])) $escribibles[$name] = true;
    }

    $excluir = array_fill_keys([
        'Title', 'field_4', 'Tipo_Solicitud', 'field_47', 'field_48', 'field_51',
        'field_52', 'field_53', 'field_54', 'field_55', 'field_56', 'Propiedad_Subtipo',
        'field_57', 'field_58', 'field_59', 'field_60',
        'Solicitud_Grupo', 'Componente_Numero', 'Componente_Total', 'Tipo_Componente', 'Es_Principal',
        'Monto_Componente', 'Precio_Base_Componente', 'Distribucion_Tipo', 'Promocion_Nombre',
        'field_63', 'field_64', 'field_65', 'field_66', 'field_67', 'field_68',
        'field_70', 'field_71', 'field_72', 'field_73', 'field_74', 'field_75', 'field_76',
        'field_77', 'field_78', 'field_79', 'field_80', 'field_81', 'field_82'
    ], true);

    $campos = [];
    foreach ($principalFields as $name => $value) {
        if (!is_string($name) || !isset($escribibles[$name]) || isset($excluir[$name])) continue;
        if (str_starts_with($name, 'ProcaP_')) continue;
        $campos[$name] = $value;
    }
    return $campos;
}

function verificarPrincipal(array $fields, string $folio, string $correoUsuario): void
{
    $estado = strtoupper(trim((string) ($fields['field_1'] ?? '')));
    $correo = strtolower(trim((string) ($fields['Vendedor_Correo'] ?? '')));
    $title = strtoupper(trim((string) ($fields['Title'] ?? '')));
    $grupo = strtoupper(trim((string) ($fields['Solicitud_Grupo'] ?? '')));
    if ($estado !== 'BORRADOR') throw new RuntimeException('La solicitud ya no esta en estado BORRADOR.');
    if ($correo === '' || !hash_equals($correo, $correoUsuario)) throw new RuntimeException('La solicitud no pertenece al usuario autenticado.');
    if ($title !== $folio && $grupo !== $folio) throw new RuntimeException('El registro principal no corresponde al folio indicado.');
}

function verificarSecundario(array $fields, string $folio, string $correoUsuario): void
{
    $estado = strtoupper(trim((string) ($fields['field_1'] ?? '')));
    $correo = strtolower(trim((string) ($fields['Vendedor_Correo'] ?? '')));
    $grupo = strtoupper(trim((string) ($fields['Solicitud_Grupo'] ?? '')));
    if ($estado !== 'BORRADOR') throw new RuntimeException('Un componente ya no esta en estado BORRADOR.');
    if ($correo === '' || !hash_equals($correo, $correoUsuario)) throw new RuntimeException('Un componente no pertenece al usuario autenticado.');
    if ($grupo !== $folio) throw new RuntimeException('Un componente no pertenece al grupo indicado.');
}

/** @return array<string,mixed> */
function obtenerItem(string $token, string $siteId, string $listId, string $itemId): array
{
    $url = 'https://graph.microsoft.com/v1.0/sites/' . rawurlencode($siteId) . '/lists/' . rawurlencode($listId) . '/items/' . rawurlencode($itemId) . '?$expand=fields';
    return svCurlJson($url, 'GET', ['Authorization: Bearer ' . $token, 'Accept: application/json']);
}

/** @return array<string,mixed> */
function obtenerColumnas(string $token, string $siteId, string $listId): array
{
    $url = 'https://graph.microsoft.com/v1.0/sites/' . rawurlencode($siteId) . '/lists/' . rawurlencode($listId) . '/columns?$select=name,readOnly';
    return svCurlJson($url, 'GET', ['Authorization: Bearer ' . $token, 'Accept: application/json']);
}

/** @return array<int,array<string,mixed>> */
function obtenerItemsGrupo(string $token, string $siteId, string $listId, string $folio): array
{
    $base = 'https://graph.microsoft.com/v1.0/sites/' . rawurlencode($siteId) . '/lists/' . rawurlencode($listId) . '/items';
    $query = http_build_query([
        '$expand' => 'fields($select=Title,field_1,Vendedor_Correo,Solicitud_Grupo,Componente_Numero)',
        '$filter' => "fields/Solicitud_Grupo eq '" . str_replace("'", "''", $folio) . "'",
        '$top' => 100,
    ], '', '&', PHP_QUERY_RFC3986);

    try {
        $resultado = svCurlJson($base . '?' . $query, 'GET', [
            'Authorization: Bearer ' . $token,
            'Accept: application/json',
            'Prefer: HonorNonIndexedQueriesWarningMayFailRandomly',
        ]);
        return is_array($resultado['value'] ?? null) ? $resultado['value'] : [];
    } catch (Throwable $error) {
        error_log('Solicitud Venta componentes filtro por grupo: ' . $error->getMessage());
    }

    $items = [];
    $query = http_build_query([
        '$expand' => 'fields($select=Title,field_1,Vendedor_Correo,Solicitud_Grupo,Componente_Numero)',
        '$top' => 200,
    ], '', '&', PHP_QUERY_RFC3986);
    $url = $base . '?' . $query;
    $paginas = 0;
    while ($url !== '' && $paginas < 50) {
        $resultado = svCurlJson($url, 'GET', ['Authorization: Bearer ' . $token, 'Accept: application/json']);
        foreach (($resultado['value'] ?? []) as $registro) {
            if (!is_array($registro)) continue;
            $fields = is_array($registro['fields'] ?? null) ? $registro['fields'] : [];
            if (strtoupper(trim((string) ($fields['Solicitud_Grupo'] ?? ''))) === $folio) $items[] = $registro;
        }
        $url = trim((string) ($resultado['@odata.nextLink'] ?? ''));
        $paginas++;
    }
    return $items;
}

/** @return array<string,mixed> */
function crearItem(string $token, string $siteId, string $listId, array $fields): array
{
    $url = 'https://graph.microsoft.com/v1.0/sites/' . rawurlencode($siteId) . '/lists/' . rawurlencode($listId) . '/items';
    $body = json_encode(['fields' => $fields], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if (!is_string($body)) throw new RuntimeException('No fue posible serializar el componente.');
    return svCurlJson($url, 'POST', ['Authorization: Bearer ' . $token, 'Accept: application/json', 'Content-Type: application/json'], $body);
}

function actualizarCampos(string $token, string $siteId, string $listId, string $itemId, array $fields): void
{
    $url = 'https://graph.microsoft.com/v1.0/sites/' . rawurlencode($siteId) . '/lists/' . rawurlencode($listId) . '/items/' . rawurlencode($itemId) . '/fields';
    $body = json_encode($fields, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if (!is_string($body)) throw new RuntimeException('No fue posible serializar la actualizacion.');
    svCurlJson($url, 'PATCH', ['Authorization: Bearer ' . $token, 'Accept: application/json', 'Content-Type: application/json'], $body);
}

function eliminarItem(string $token, string $siteId, string $listId, string $itemId): void
{
    $url = 'https://graph.microsoft.com/v1.0/sites/' . rawurlencode($siteId) . '/lists/' . rawurlencode($listId) . '/items/' . rawurlencode($itemId);
    svCurlJson($url, 'DELETE', ['Authorization: Bearer ' . $token, 'Accept: application/json']);
}
