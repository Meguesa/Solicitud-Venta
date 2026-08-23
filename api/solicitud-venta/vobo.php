<?php

declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');

require_once dirname(__DIR__, 2) . '/includes/bootstrap.php';
require_once __DIR__ . '/_common.php';

function voboError(int $status, string $code, string $message): void
{
    http_response_code($status);
    echo json_encode(['ok' => false, 'error' => $code, 'message' => $message], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    voboError(405, 'METHOD_NOT_ALLOWED', 'Metodo no permitido.');
}
if (!portal_is_authenticated()) {
    voboError(401, 'AUTH_REQUIRED', 'La sesion del Portal Interno no esta activa.');
}

$etapa = strtolower(trim((string) ($_GET['etapa'] ?? 'comercial')));
if (!in_array($etapa, ['comercial', 'cobranza'], true)) {
    voboError(400, 'INVALID_STAGE', 'La etapa de Vo.Bo. solicitada no es valida.');
}
if ($etapa === 'cobranza') {
    if (!portal_user_can_cobranza_vobo()) {
        voboError(403, 'COBRANZA_FORBIDDEN', 'Tu cuenta no tiene autorizacion para revisar solicitudes en Vo.Bo. de Cobranza.');
    }
} elseif (!portal_user_can_vobo()) {
    voboError(403, 'VOBO_FORBIDDEN', 'Tu cuenta no tiene autorizacion para revisar solicitudes en Vo.Bo. Comercial.');
}

$estatusPendiente = $etapa === 'cobranza' ? 'PENDIENTE COBRANZA' : 'PENDIENTE VOBO';
$rolEtapa = $etapa === 'cobranza' ? 'COBRANZA' : portal_vobo_role();

$raw = file_get_contents('php://input');
$payload = json_decode(is_string($raw) ? $raw : '', true);
if (!is_array($payload)) $payload = [];
$accion = strtolower(trim((string) ($payload['accion'] ?? 'listar')));
if (!in_array($accion, ['listar', 'detalle', 'aprobar', 'correccion'], true)) {
    voboError(400, 'INVALID_ACTION', 'La accion solicitada no es valida.');
}

$config = svConfig();
try {
    $graphToken = svGraphToken($config['tenantId'], $config['clientId'], $config['clientSecret']);
    $items = voboObtenerItems($graphToken, $config['siteId'], $config['listId']);
} catch (Throwable $error) {
    error_log('Solicitud Venta VoBo lectura ' . $etapa . ': ' . $error->getMessage());
    voboError(502, 'VOBO_READ_FAILED', 'No fue posible consultar las solicitudes pendientes de Vo.Bo.');
}

if ($accion === 'listar') {
    $solicitudes = [];
    foreach ($items as $item) {
        $fields = is_array($item['fields'] ?? null) ? $item['fields'] : [];
        $estatus = strtoupper(trim((string) ($fields['field_1'] ?? '')));
        if ($estatus !== $estatusPendiente) continue;

        $numero = (int) ($fields['Componente_Numero'] ?? 0);
        $principal = voboBool($fields['Es_Principal'] ?? false) || $numero === 1;
        if (!$principal) continue;

        $folio = strtoupper(trim((string) ($fields['Solicitud_Grupo'] ?? $fields['Title'] ?? '')));
        if ($folio === '') continue;

        $solicitudes[] = [
            'folio' => $folio,
            'fecha' => (string) ($fields['field_2'] ?? ''),
            'cliente' => voboNombreCliente($fields),
            'vendedor' => trim((string) ($fields['Vendedor_Nombre'] ?? '')),
            'vendedorCorreo' => trim((string) ($fields['Vendedor_Correo'] ?? '')),
            'tipoVenta' => trim((string) ($fields['field_48'] ?? '')),
            'componentes' => max(1, (int) ($fields['Componente_Total'] ?? 1)),
            'precioTotal' => voboNumero($fields['field_63'] ?? 0),
            'lugar' => trim((string) ($fields['field_3'] ?? '')),
            'estatus' => $estatus,
        ];
    }

    usort($solicitudes, static function (array $a, array $b): int {
        $fechaA = strtotime((string) ($a['fecha'] ?? '')) ?: 0;
        $fechaB = strtotime((string) ($b['fecha'] ?? '')) ?: 0;
        if ($fechaA === $fechaB) return strcmp((string) ($b['folio'] ?? ''), (string) ($a['folio'] ?? ''));
        return $fechaB <=> $fechaA;
    });

    $user = portal_user();
    http_response_code(200);
    echo json_encode([
        'ok' => true,
        'etapa' => $etapa,
        'rol' => $rolEtapa,
        'usuario' => [
            'nombre' => (string) ($user['name'] ?? ''),
            'correo' => (string) ($user['email'] ?? ''),
        ],
        'total' => count($solicitudes),
        'solicitudes' => $solicitudes,
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

$folio = strtoupper(trim((string) ($payload['folio'] ?? '')));
if (!preg_match('/^SV-\d{4}-\d{6,}$/', $folio)) {
    voboError(400, 'INVALID_FOLIO', 'El folio indicado no es valido.');
}

$grupo = [];
foreach ($items as $item) {
    $fields = is_array($item['fields'] ?? null) ? $item['fields'] : [];
    $grupoItem = strtoupper(trim((string) ($fields['Solicitud_Grupo'] ?? '')));
    $title = strtoupper(trim((string) ($fields['Title'] ?? '')));
    if ($grupoItem === $folio || $title === $folio) $grupo[] = $item;
}
if (!$grupo) voboError(404, 'REQUEST_NOT_FOUND', 'No se encontro la solicitud indicada.');

usort($grupo, static function (array $a, array $b): int {
    $fa = is_array($a['fields'] ?? null) ? $a['fields'] : [];
    $fb = is_array($b['fields'] ?? null) ? $b['fields'] : [];
    return ((int) ($fa['Componente_Numero'] ?? 0)) <=> ((int) ($fb['Componente_Numero'] ?? 0));
});

$principalFields = is_array($grupo[0]['fields'] ?? null) ? $grupo[0]['fields'] : [];
foreach ($grupo as $item) {
    $fields = is_array($item['fields'] ?? null) ? $item['fields'] : [];
    if (voboBool($fields['Es_Principal'] ?? false) || (int) ($fields['Componente_Numero'] ?? 0) === 1) {
        $principalFields = $fields;
        break;
    }
}

$estatusPrincipal = strtoupper(trim((string) ($principalFields['field_1'] ?? '')));
if ($estatusPrincipal !== $estatusPendiente) {
    voboError(409, 'INVALID_STATUS', 'La solicitud ya no se encuentra pendiente en esta etapa. Actualiza la bandeja antes de continuar.');
}

try {
    $columnas = voboObtenerColumnas($graphToken, $config['siteId'], $config['listId']);
} catch (Throwable $error) {
    error_log('Solicitud Venta VoBo columnas: ' . $error->getMessage());
    $columnas = [];
}
$labels = voboMapaEtiquetas($columnas);

if ($accion === 'aprobar' || $accion === 'correccion') {
    voboProcesarDecision($accion, $etapa, $folio, $grupo, $payload, $graphToken, $config, $columnas);
}

$componentes = [];
foreach ($grupo as $index => $item) {
    $f = is_array($item['fields'] ?? null) ? $item['fields'] : [];
    $componentes[] = [
        'numero' => (int) ($f['Componente_Numero'] ?? ($index + 1)),
        'tipo' => trim((string) ($f['Tipo_Componente'] ?? $f['Tipo_Solicitud'] ?? '')),
        'operacion' => trim((string) ($f['field_47'] ?? '')),
        'tipoVentaProcap' => trim((string) ($f['field_48'] ?? '')),
        'sucursal' => trim((string) ($f['field_49'] ?? '')),
        'referencia' => trim((string) ($f['field_4'] ?? '')),
        'servicioTipo' => trim((string) ($f['field_52'] ?? '')),
        'servicioAtaud' => trim((string) ($f['field_53'] ?? '')),
        'servicioUrna' => trim((string) ($f['field_54'] ?? '')),
        'servicioDuracion' => trim((string) ($f['field_55'] ?? '')),
        'propiedadTipo' => trim((string) ($f['Propiedad_Subtipo'] ?? $f['field_56'] ?? '')),
        'propiedadSeccion' => trim((string) ($f['field_57'] ?? '')),
        'propiedadManzana' => trim((string) ($f['field_58'] ?? '')),
        'propiedadNumero' => trim((string) ($f['field_59'] ?? '')),
        'propiedadClave' => trim((string) ($f['field_60'] ?? '')),
        'precioBase' => voboNumero($f['Precio_Base_Componente'] ?? 0),
        'monto' => voboNumero($f['Monto_Componente'] ?? 0),
        'estatus' => strtoupper(trim((string) ($f['field_1'] ?? ''))),
    ];
}

$detalle = voboDetalleComun($principalFields, $labels);
$documentos = [
    ['nombre' => 'Identificacion titular', 'estado' => voboEstadoDocumento($principalFields['Documento_ID_Titular'] ?? null)],
    ['nombre' => 'Identificacion titular substituto', 'estado' => voboEstadoDocumento($principalFields['Documento_ID_Sustituto'] ?? null)],
    ['nombre' => 'Comprobante de domicilio', 'estado' => voboEstadoDocumento($principalFields['Documento_Comprobante_Domicilio'] ?? null)],
    ['nombre' => 'Comprobante de pago', 'estado' => voboEstadoDocumento($principalFields['Documento_Comprobante_Pago'] ?? null)],
    ['nombre' => 'Firma del cliente', 'estado' => strtoupper(trim((string) ($principalFields['field_102'] ?? 'PENDIENTE')))],
    ['nombre' => 'Firma del vendedor', 'estado' => strtoupper(trim((string) ($principalFields['field_103'] ?? 'PENDIENTE')))],
];

http_response_code(200);
echo json_encode([
    'ok' => true,
    'folio' => $folio,
    'etapa' => $etapa,
    'rol' => $rolEtapa,
    'resumen' => [
        'estatus' => strtoupper(trim((string) ($principalFields['field_1'] ?? ''))),
        'fecha' => (string) ($principalFields['field_2'] ?? ''),
        'cliente' => voboNombreCliente($principalFields),
        'vendedor' => trim((string) ($principalFields['Vendedor_Nombre'] ?? '')),
        'vendedorCorreo' => trim((string) ($principalFields['Vendedor_Correo'] ?? '')),
        'tipoVenta' => trim((string) ($principalFields['field_48'] ?? '')),
        'precioTotal' => voboNumero($principalFields['field_63'] ?? 0),
        'formaPago' => trim((string) ($principalFields['field_62'] ?? '')),
        'metodoPago' => trim((string) ($principalFields['field_69'] ?? '')),
        'lugar' => trim((string) ($principalFields['field_3'] ?? '')),
    ],
    'detalle' => $detalle,
    'componentes' => $componentes,
    'documentos' => $documentos,
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

/** @return array<int,array<string,mixed>> */
function voboObtenerItems(string $token, string $siteId, string $listId): array
{
    $items = [];
    $url = 'https://graph.microsoft.com/v1.0/sites/' . rawurlencode($siteId)
        . '/lists/' . rawurlencode($listId)
        . '/items?$expand=fields&$top=200';
    $paginas = 0;
    while ($url !== '' && $paginas < 50) {
        $data = svCurlJson($url, 'GET', ['Authorization: Bearer ' . $token, 'Accept: application/json']);
        foreach (($data['value'] ?? []) as $item) if (is_array($item)) $items[] = $item;
        $url = trim((string) ($data['@odata.nextLink'] ?? ''));
        $paginas++;
    }
    return $items;
}

/** @return array<int,array<string,mixed>> */
function voboObtenerColumnas(string $token, string $siteId, string $listId): array
{
    $url = 'https://graph.microsoft.com/v1.0/sites/' . rawurlencode($siteId)
        . '/lists/' . rawurlencode($listId)
        . '/columns?$select=name,displayName,readOnly&$top=300';
    $data = svCurlJson($url, 'GET', ['Authorization: Bearer ' . $token, 'Accept: application/json']);
    return is_array($data['value'] ?? null) ? $data['value'] : [];
}

/** @return array<string,string> */
function voboMapaEtiquetas(array $columnas): array
{
    $map = [];
    foreach ($columnas as $col) {
        if (!is_array($col)) continue;
        $name = trim((string) ($col['name'] ?? ''));
        $display = trim((string) ($col['displayName'] ?? ''));
        if ($name !== '') $map[$name] = $display !== '' ? $display : $name;
    }
    return $map;
}

/** @return string[] */
function voboNombresColumnas(array $columnas): array
{
    $names = [];
    foreach ($columnas as $col) {
        if (!is_array($col)) continue;
        $name = trim((string) ($col['name'] ?? ''));
        if ($name !== '') $names[] = $name;
    }
    return $names;
}

/** @param array<string,mixed> $payload */
function voboProcesarDecision(string $accion, string $etapa, string $folio, array $grupo, array $payload, string $graphToken, array $config, array $columnas): void
{
    $estatusEsperado = $etapa === 'cobranza' ? 'PENDIENTE COBRANZA' : 'PENDIENTE VOBO';
    foreach ($grupo as $item) {
        $fields = is_array($item['fields'] ?? null) ? $item['fields'] : [];
        $estatus = strtoupper(trim((string) ($fields['field_1'] ?? '')));
        if ($estatus !== $estatusEsperado) {
            voboError(409, 'INVALID_STATUS', 'La solicitud ya no esta pendiente en esta etapa. Actualiza la bandeja antes de continuar.');
        }
    }

    $motivo = trim((string) ($payload['motivo'] ?? ''));
    if ($accion === 'correccion') {
        if (mb_strlen($motivo) < 5) {
            voboError(400, 'CORRECTION_REASON_REQUIRED', 'Escribe el motivo de la correccion antes de enviarla al vendedor.');
        }
        if (mb_strlen($motivo) > 2000) {
            voboError(400, 'CORRECTION_REASON_TOO_LONG', 'El motivo de correccion no puede exceder 2000 caracteres.');
        }
    }

    $columnNames = voboNombresColumnas($columnas);
    $motivoColumn = '';
    $motivoCandidates = $etapa === 'cobranza'
        ? ['Cobranza_Motivo_Correccion', 'VoBo_Motivo_Correccion', 'VoBo_Observaciones', 'Motivo_Correccion']
        : ['VoBo_Motivo_Correccion', 'VoBo_Observaciones', 'Motivo_Correccion'];
    foreach ($motivoCandidates as $candidate) {
        if (in_array($candidate, $columnNames, true)) {
            $motivoColumn = $candidate;
            break;
        }
    }
    if ($accion === 'correccion' && $motivoColumn === '') {
        voboError(409, 'CORRECTION_COLUMN_REQUIRED', 'Falta una columna de texto en SharePoint para guardar el motivo de correccion.');
    }

    $user = portal_user();
    $nombre = trim((string) ($user['name'] ?? ''));
    $correo = strtolower(trim((string) ($user['email'] ?? '')));
    $rol = $etapa === 'cobranza' ? 'COBRANZA' : portal_vobo_role();
    $revisor = trim($nombre . ($correo !== '' ? ' <' . $correo . '>' : '') . ($rol !== '' ? ' · ' . $rol : ''));
    $fecha = gmdate('Y-m-d\TH:i:s\Z');

    if ($etapa === 'cobranza') {
        $estatusNuevo = $accion === 'aprobar' ? 'APROBADA' : 'CORRECCION';
        $voboEstado = $accion === 'aprobar' ? 'APROBADO' : 'CORRECCION';
    } else {
        $estatusNuevo = $accion === 'aprobar' ? 'PENDIENTE COBRANZA' : 'CORRECCION';
        $voboEstado = $accion === 'aprobar' ? 'APROBADO' : 'CORRECCION';
    }

    try {
        foreach ($grupo as $item) {
            $itemId = trim((string) ($item['id'] ?? ''));
            if ($itemId === '') throw new RuntimeException('Componente sin ID de SharePoint.');

            if ($etapa === 'cobranza') {
                $fieldsPatch = ['field_1' => $estatusNuevo];
                if ($accion === 'correccion') {
                    // Se marca tambien el Vo.Bo. como CORRECCION para reutilizar el flujo
                    // de reapertura existente. Al corregirse, volvera a revision Comercial
                    // antes de regresar nuevamente a Cobranza.
                    $fieldsPatch['VoBo_Estatus'] = 'CORRECCION';
                }
            } else {
                $fieldsPatch = [
                    'field_1' => $estatusNuevo,
                    'VoBo_Estatus' => $voboEstado,
                    'VoBo_Por' => $revisor,
                    'VoBo_Fecha' => $fecha,
                ];
            }

            if ($motivoColumn !== '') {
                $fieldsPatch[$motivoColumn] = $accion === 'correccion' ? $motivo : '';
            }

            // Si las columnas de auditoria de Cobranza existen, se aprovechan sin
            // convertirlas en requisito para que la transicion principal funcione.
            if ($etapa === 'cobranza') {
                if (in_array('Cobranza_Estatus', $columnNames, true)) {
                    $fieldsPatch['Cobranza_Estatus'] = $accion === 'aprobar' ? 'APROBADO' : 'CORRECCION';
                }
                if (in_array('Cobranza_Por', $columnNames, true)) $fieldsPatch['Cobranza_Por'] = $revisor;
                if (in_array('Cobranza_Fecha', $columnNames, true)) $fieldsPatch['Cobranza_Fecha'] = $fecha;
            }

            voboActualizarCampos($graphToken, $config['siteId'], $config['listId'], $itemId, $fieldsPatch);
        }
    } catch (Throwable $error) {
        error_log('Solicitud Venta VoBo decision ' . $etapa . ' ' . $folio . ': ' . $error->getMessage());
        voboError(502, 'VOBO_UPDATE_FAILED', 'No fue posible actualizar todos los componentes de la solicitud. No repitas la accion hasta revisar el estatus en SharePoint.');
    }

    if ($accion === 'aprobar') {
        $message = $etapa === 'cobranza'
            ? 'Vo.Bo. de Cobranza aprobado correctamente. La solicitud ' . $folio . ' quedo APROBADA.'
            : 'Vo.Bo. Comercial aprobado correctamente. La solicitud ' . $folio . ' fue enviada a Vo.Bo. de Cobranza.';
    } else {
        $message = 'Correccion solicitada correctamente para ' . $folio . ' desde ' . ($etapa === 'cobranza' ? 'Cobranza' : 'Vo.Bo. Comercial') . '.';
    }

    http_response_code(200);
    echo json_encode([
        'ok' => true,
        'folio' => $folio,
        'etapa' => $etapa,
        'accion' => $accion,
        'estatus' => $estatusNuevo,
        'voboEstatus' => $voboEstado,
        'componentes' => count($grupo),
        'revisor' => $revisor,
        'fecha' => $fecha,
        'motivo' => $accion === 'correccion' ? $motivo : '',
        'message' => $message,
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

/** @param array<string,mixed> $fields */
function voboActualizarCampos(string $token, string $siteId, string $listId, string $itemId, array $fields): void
{
    $url = 'https://graph.microsoft.com/v1.0/sites/' . rawurlencode($siteId)
        . '/lists/' . rawurlencode($listId)
        . '/items/' . rawurlencode($itemId)
        . '/fields';
    svCurlJson(
        $url,
        'PATCH',
        ['Authorization: Bearer ' . $token, 'Accept: application/json', 'Content-Type: application/json'],
        json_encode($fields, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
    );
}

/** @return array<int,array{etiqueta:string,valor:string}> */
function voboDetalleComun(array $fields, array $labels): array
{
    $excluir = array_fill_keys([
        'Title', 'field_1', 'field_4', 'field_47', 'field_48', 'field_49', 'field_51',
        'field_52', 'field_53', 'field_54', 'field_55', 'field_56', 'Propiedad_Subtipo',
        'field_57', 'field_58', 'field_59', 'field_60', 'Monto_Componente', 'Precio_Base_Componente',
        'Distribucion_Tipo', 'Promocion_Nombre', 'Solicitud_Grupo', 'Componente_Numero', 'Componente_Total',
        'Tipo_Componente', 'Tipo_Solicitud', 'Es_Principal', 'Vendedor_Correo',
        'field_102', 'field_103', 'field_104', 'Documento_ID_Titular', 'Documento_ID_Sustituto',
        'Documento_Comprobante_Domicilio', 'Documento_Comprobante_Pago',
        'VoBo_Estatus', 'VoBo_Por', 'VoBo_Fecha', 'VoBo_Motivo_Correccion', 'VoBo_Observaciones', 'Motivo_Correccion',
        'Cobranza_Estatus', 'Cobranza_Por', 'Cobranza_Fecha', 'Cobranza_Motivo_Correccion',
        'ProcaP_Numero', 'ProcaP_Estatus', 'ProcaP_Fecha', 'ProcaP_Capturado_Por'
    ], true);

    $detalle = [];
    foreach ($fields as $name => $value) {
        if (!is_string($name) || isset($excluir[$name])) continue;
        if (str_starts_with($name, '@') || str_starts_with($name, '_') || str_starts_with($name, 'ContentType')) continue;
        if (str_starts_with($name, 'ProcaP_')) continue;
        if (is_array($value) || is_object($value) || $value === null) continue;

        $texto = is_bool($value) ? ($value ? 'SI' : 'NO') : trim((string) $value);
        if ($texto === '' || $texto === '0' && !in_array($name, ['field_23', 'field_63', 'field_64', 'field_65'], true)) continue;
        $etiqueta = trim((string) ($labels[$name] ?? $name));
        if ($etiqueta === '') continue;
        $detalle[] = ['etiqueta' => $etiqueta, 'valor' => $texto];
    }
    return $detalle;
}

function voboNombreCliente(array $fields): string
{
    $nombres = trim((string) ($fields['field_8'] ?? ''));
    $apellidos = trim((string) ($fields['field_9'] ?? ''));
    $nombre = trim($nombres . ' ' . $apellidos);
    return $nombre !== '' ? preg_replace('/\s+/', ' ', $nombre) ?: $nombre : 'SIN NOMBRE';
}

function voboEstadoDocumento($value): string
{
    return voboBool($value) ? 'RECIBIDO' : 'PENDIENTE';
}

function voboBool($value): bool
{
    if (is_bool($value)) return $value;
    if (is_numeric($value)) return (int) $value !== 0;
    return in_array(strtoupper(trim((string) $value)), ['TRUE', 'SI', 'YES', '1'], true);
}

function voboNumero($value): float
{
    return is_numeric($value) ? round((float) $value, 2) : 0.0;
}
