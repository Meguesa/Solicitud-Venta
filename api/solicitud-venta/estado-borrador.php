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

$accion = strtolower(trim((string) ($payload['accion'] ?? '')));
if (!in_array($accion, ['guardar', 'cargar'], true)) {
    svResponderError(400, 'INVALID_ACTION', 'La accion solicitada no es valida.');
}

$folio = strtoupper(trim((string) ($payload['folio'] ?? '')));
if (!preg_match('/^SV-(\d{4})-(\d{6,})$/', $folio, $folioMatch)) {
    svResponderError(400, 'INVALID_FOLIO', 'El folio del borrador no es valido.');
}

$itemId = resolverItemId($payload, $folioMatch);
$etapa = 'GRAPH_TOKEN';

try {
    $graphToken = svGraphToken($config['tenantId'], $config['clientId'], $config['clientSecret']);

    $etapa = 'CARGAR_ITEM';
    $registro = cargarPrincipal($graphToken, $config['siteId'], $config['listId'], $folio, $itemId, $correoUsuario);
    $itemId = (string) ($registro['id'] ?? $itemId);
    $fields = is_array($registro['fields'] ?? null) ? $registro['fields'] : [];

    $etapa = 'VALIDAR_PROPIETARIO';
    validarBorrador($fields, $correoUsuario);

    if ($accion === 'guardar') {
        $etapa = 'VALIDAR_ESTADO';
        $estado = $payload['estado'] ?? null;
        if (!is_array($estado)) {
            svResponderError(400, 'STATE_REQUIRED', 'No se recibio el estado del borrador.');
        }

        $documento = [
            'version' => 2,
            'folio' => $folio,
            'itemId' => $itemId,
            'usuario' => $correoUsuario,
            'guardadoUtc' => gmdate('c'),
            'estado' => $estado,
        ];
        $contenido = json_encode($documento, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if (!is_string($contenido)) {
            throw new RuntimeException('No fue posible serializar el estado del borrador.');
        }
        if (strlen($contenido) > 1024 * 1024) {
            svResponderError(413, 'STATE_TOO_LARGE', 'El estado del borrador supera el limite permitido.');
        }

        $etapa = 'LOCALIZAR_EXPEDIENTES';
        $driveId = obtenerDriveExpedientes($graphToken, $config['siteId']);
        $etapa = 'CREAR_CARPETA';
        asegurarCarpetaFolio($graphToken, $driveId, $folio);
        $etapa = 'GUARDAR_ESTADO';
        guardarEstadoGraph($graphToken, $driveId, $folio, $contenido);

        http_response_code(200);
        echo json_encode([
            'ok' => true,
            'folio' => $folio,
            'itemId' => $itemId,
            'guardadoUtc' => $documento['guardadoUtc'],
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }

    try {
        $etapa = 'LOCALIZAR_EXPEDIENTES';
        $driveId = obtenerDriveExpedientes($graphToken, $config['siteId']);
        $etapa = 'CARGAR_ESTADO';
        $documento = cargarEstadoGraph($graphToken, $driveId, $folio);
        $usuarioEstado = strtolower(trim((string) ($documento['usuario'] ?? '')));
        $folioEstado = strtoupper(trim((string) ($documento['folio'] ?? '')));
        $estado = $documento['estado'] ?? null;

        if ($folioEstado !== $folio) throw new RuntimeException('El estado guardado corresponde a otro folio.');
        if ($usuarioEstado === '' || !hash_equals($usuarioEstado, $correoUsuario)) throw new RuntimeException('El estado guardado pertenece a otro usuario.');
        if (!is_array($estado)) throw new RuntimeException('El estado guardado no contiene datos validos.');

        http_response_code(200);
        echo json_encode([
            'ok' => true,
            'folio' => $folio,
            'itemId' => $itemId,
            'guardadoUtc' => (string) ($documento['guardadoUtc'] ?? ''),
            'estado' => $estado,
            'recuperacion' => 'ESTADO_BORRADOR',
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    } catch (Throwable $estadoError) {
        error_log('Solicitud Venta fallback borrador ' . $folio . ': ' . $estadoError->getMessage());
    }

    $etapa = 'RECONSTRUIR_SHAREPOINT';
    $estado = reconstruirEstadoDesdeFields($fields);

    http_response_code(200);
    echo json_encode([
        'ok' => true,
        'folio' => $folio,
        'itemId' => $itemId,
        'guardadoUtc' => '',
        'estado' => $estado,
        'recuperacion' => 'SHAREPOINT_FALLBACK',
        'warning' => 'El borrador fue reconstruido desde SharePoint porque no habia un estado reanudable completo. Los campos que nunca fueron persistidos deben completarse nuevamente.',
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
} catch (Throwable $error) {
    error_log('Solicitud Venta estado borrador [' . $etapa . ']: ' . $error->getMessage());
    svResponderError(502, 'DRAFT_STATE_FAILED', 'No fue posible recuperar el borrador. Etapa: ' . $etapa . '.');
}

/** @param array<string,mixed> $payload @param array<int,string> $folioMatch */
function resolverItemId(array $payload, array $folioMatch): string
{
    $candidatos = [];
    $payloadId = trim((string) ($payload['itemId'] ?? ''));
    if ($payloadId !== '') $candidatos[] = $payloadId;

    $referer = trim((string) ($_SERVER['HTTP_REFERER'] ?? ''));
    if ($referer !== '') {
        $query = parse_url($referer, PHP_URL_QUERY);
        if (is_string($query) && $query !== '') {
            parse_str($query, $params);
            $refererId = trim((string) ($params['itemId'] ?? ''));
            if ($refererId !== '') $candidatos[] = $refererId;
        }
    }

    $derivado = (string) ((int) ltrim((string) ($folioMatch[2] ?? ''), '0'));
    if ($derivado !== '' && $derivado !== '0') $candidatos[] = $derivado;

    foreach ($candidatos as $id) {
        if (ctype_digit($id) && (int) $id > 0) return (string) ((int) $id);
    }
    return '';
}

/** @return array<string,mixed> */
function cargarPrincipal(string $token, string $siteId, string $listId, string $folio, string $itemId, string $correoUsuario): array
{
    if ($itemId !== '') {
        try {
            $url = 'https://graph.microsoft.com/v1.0/sites/' . rawurlencode($siteId)
                . '/lists/' . rawurlencode($listId)
                . '/items/' . rawurlencode($itemId)
                . '?$select=id&$expand=fields';
            $item = svCurlJson($url, 'GET', [
                'Authorization: Bearer ' . $token,
                'Accept: application/json',
            ]);
            $fields = is_array($item['fields'] ?? null) ? $item['fields'] : [];
            if (registroCoincide($fields, $folio, $correoUsuario)) return $item;
        } catch (Throwable $error) {
            error_log('Solicitud Venta item directo no disponible ' . $itemId . ': ' . $error->getMessage());
        }
    }

    $url = 'https://graph.microsoft.com/v1.0/sites/' . rawurlencode($siteId)
        . '/lists/' . rawurlencode($listId)
        . '/items?$select=id,lastModifiedDateTime&$expand=fields&$top=200';

    $candidato = null;
    while ($url !== '') {
        $respuesta = svCurlJson($url, 'GET', [
            'Authorization: Bearer ' . $token,
            'Accept: application/json',
        ]);
        foreach (($respuesta['value'] ?? []) as $item) {
            if (!is_array($item)) continue;
            $fields = is_array($item['fields'] ?? null) ? $item['fields'] : [];
            if (!registroCoincide($fields, $folio, $correoUsuario)) continue;

            $numero = max(1, (int) ($fields['Componente_Numero'] ?? 1));
            $principal = (bool) ($fields['Es_Principal'] ?? false) || $numero === 1;
            if ($principal) return $item;
            if ($candidato === null) $candidato = $item;
        }
        $url = trim((string) ($respuesta['@odata.nextLink'] ?? ''));
    }

    if (is_array($candidato)) return $candidato;
    throw new RuntimeException('No se encontro el registro principal del folio en SharePoint.');
}

/** @param array<string,mixed> $fields */
function registroCoincide(array $fields, string $folio, string $correoUsuario): bool
{
    $correo = strtolower(trim((string) ($fields['Vendedor_Correo'] ?? '')));
    if ($correo === '' || !hash_equals($correo, $correoUsuario)) return false;

    $title = strtoupper(trim((string) ($fields['Title'] ?? '')));
    $grupo = strtoupper(trim((string) ($fields['Solicitud_Grupo'] ?? '')));
    if ($title === $folio || $grupo === $folio) return true;
    return str_starts_with($title, $folio . '-');
}

/** @param array<string,mixed> $fields */
function validarBorrador(array $fields, string $correoUsuario): void
{
    $estado = strtoupper(trim((string) ($fields['field_1'] ?? '')));
    $correo = strtolower(trim((string) ($fields['Vendedor_Correo'] ?? '')));
    if ($estado !== 'BORRADOR') {
        svResponderError(409, 'DRAFT_NOT_EDITABLE', 'La solicitud ya no se encuentra en estado BORRADOR.');
    }
    if ($correo === '' || !hash_equals($correo, $correoUsuario)) {
        svResponderError(403, 'DRAFT_FORBIDDEN', 'El borrador no pertenece al usuario autenticado.');
    }
}

/** @return array<string,mixed> */
function reconstruirEstadoDesdeFields(array $fields): array
{
    $controles = [];
    $mapa = [
        'tipoSolicitud' => ['Tipo_Componente', 'Tipo_Solicitud', 'field_56'],
        'tipoOperacion' => ['field_47'],
        'tipoVentaProcap' => ['field_48'],
        'tipoContrato' => ['field_51', 'field_48'],
        'referencia' => ['Referencia_Venta', 'field_4'],
        'origenVenta' => ['field_50'],
        'lugar' => ['field_3'],
        'clienteTipoId' => ['Cliente_Tipo_ID'],
        'clienteNumeroId' => ['field_5'],
        'clienteRfc' => ['field_6'],
        'clienteCurp' => ['field_7'],
        'clienteNombres' => ['field_8'],
        'clienteApellidoPaterno' => ['Cliente_Apellido_Paterno', 'field_9'],
        'clienteApellidoMaterno' => ['Cliente_Apellido_Materno'],
        'edadCliente' => ['field_11'],
        'clienteSexo' => ['field_12'],
        'clienteEstadoCivil' => ['field_13'],
        'clienteNacionalidad' => ['Cliente_Nacionalidad'],
        'clienteRegimenMatrimonial' => ['Cliente_Regimen_Matrimonial'],
        'clienteVivienda' => ['field_14'],
        'clienteEscolaridad' => ['Cliente_Escolaridad'],
        'clienteDomicilio' => ['field_15'],
        'clienteNumero' => ['field_16'],
        'clienteColonia' => ['field_17'],
        'clienteMunicipio' => ['field_18'],
        'clienteEstado' => ['field_19'],
        'clienteCp' => ['field_20'],
        'clienteCiudad' => ['Cliente_Ciudad'],
        'clienteTelefono' => ['Cliente_Telefono'],
        'clienteCelular' => ['field_21'],
        'clienteCorreo' => ['field_22'],
        'clienteDomicilioAnterior' => ['Cliente_Domicilio_Anterior'],
        'clienteAntiguedadDomicilioAnterior' => ['Cliente_Antiguedad_Domicilio_Anterior'],
        'clienteDependientes' => ['field_23'],
        'clienteEdadesDependientes' => ['field_24'],
        'clienteConyuge' => ['field_25'],
        'clienteConyugeEdad' => ['field_26'],
        'laboralEmpresa' => ['field_27'],
        'laboralOcupacion' => ['field_28'],
        'laboralDomicilio' => ['field_29'],
        'laboralNumero' => ['field_30'],
        'laboralColonia' => ['field_31'],
        'laboralCiudad' => ['field_32'],
        'laboralMunicipio' => ['field_33'],
        'laboralEstado' => ['field_34'],
        'laboralCp' => ['field_35'],
        'laboralTelefono' => ['field_36'],
        'laboralExtension' => ['field_37'],
        'laboralActividad' => ['field_38'],
        'laboralSector' => ['field_39'],
        'laboralAntiguedad' => ['field_40'],
        'laboralAntiguedadAnterior' => ['Laboral_Antiguedad_Anterior'],
        'sustitutoNombre' => ['field_41'],
        'sustitutoDomicilio' => ['field_42'],
        'sustitutoEdad' => ['field_43'],
        'sustitutoTelefono' => ['field_44'],
        'sustitutoParentesco' => ['field_45'],
        'sustitutoId' => ['field_46'],
        'referencia1Nombre' => ['Referencia1_Nombre', 'Referencia_1_Nombre', 'Referencia_Familiar1_Nombre'],
        'referencia1Telefono' => ['Referencia1_Telefono', 'Referencia_1_Telefono', 'Referencia_Familiar1_Telefono'],
        'referencia1Celular' => ['Referencia1_Celular', 'Referencia_1_Celular', 'Referencia_Familiar1_Celular'],
        'referencia2Nombre' => ['Referencia2_Nombre', 'Referencia_2_Nombre', 'Referencia_Familiar2_Nombre'],
        'referencia2Telefono' => ['Referencia2_Telefono', 'Referencia_2_Telefono', 'Referencia_Familiar2_Telefono'],
        'referencia2Celular' => ['Referencia2_Celular', 'Referencia_2_Celular', 'Referencia_Familiar2_Celular'],
        'banco1Nombre' => ['Banco1_Nombre', 'Banco_1_Nombre'],
        'banco1TipoCuenta' => ['Banco1_Tipo_Cuenta', 'Banco_1_Tipo_Cuenta'],
        'banco1NumeroCuenta' => ['Banco1_Numero_Cuenta', 'Banco_1_Numero_Cuenta'],
        'banco2Nombre' => ['Banco2_Nombre', 'Banco_2_Nombre'],
        'banco2TipoCuenta' => ['Banco2_Tipo_Cuenta', 'Banco_2_Tipo_Cuenta'],
        'banco2NumeroCuenta' => ['Banco2_Numero_Cuenta', 'Banco_2_Numero_Cuenta'],
        'paquete' => ['Paquete'],
        'servicioTipo' => ['field_52'],
        'servicioAtaud' => ['field_53'],
        'servicioUrna' => ['field_54'],
        'servicioDuracion' => ['field_55'],
        'propiedadTipo' => ['Propiedad_Subtipo'],
        'propiedadSeccion' => ['field_57'],
        'propiedadManzana' => ['field_58'],
        'propiedadNumero' => ['field_59'],
        'propiedadClave' => ['field_60'],
        'descripcionVenta' => ['field_61'],
        'formaPago' => ['field_62'],
        'precioTotal' => ['field_63'],
        'enganche' => ['field_64'],
        'saldo' => ['field_65'],
        'mensualidades' => ['field_66'],
        'importeMensual' => ['field_67'],
        'diaPago' => ['field_68'],
        'metodoPago' => ['field_69'],
        'precioLista' => ['field_76', 'Precio_Base_Componente'],
        'bonificacion' => ['field_77'],
        'montoFinanciar' => ['field_78'],
        'interesFinanciamiento' => ['field_79'],
        'periodoPagos' => ['field_80'],
        'pagosAnuales' => ['field_81'],
        'totalPagar' => ['field_82'],
        'documentoOtros' => ['field_88'],
        'finadoNombres' => ['field_89'],
        'finadoApellidos' => ['field_90'],
        'finadoSexo' => ['field_91'],
        'finadoEstatura' => ['field_92'],
        'finadoPeso' => ['field_93'],
        'finadoParentescoTitular' => ['field_94'],
        'finadoCausaDefuncion' => ['field_95'],
        'finadoProcedencia' => ['field_96'],
        'uiCorresponsableNombres' => ['field_97'],
        'uiCorresponsableApellidos' => ['field_98'],
        'uiCorresponsableParentesco' => ['field_99'],
        'uiCorresponsableCelular' => ['field_100'],
        'uiObservaciones' => ['field_101'],
        'observacionesSolicitud' => ['Observaciones_Solicitud'],
        'vendedorNombre' => ['Vendedor_Nombre'],
        'vendedorCorreo' => ['Vendedor_Correo'],
    ];

    foreach ($mapa as $id => $columnas) {
        $valor = primerValor($fields, $columnas);
        if ($valor === '' || $valor === null) continue;
        $controles[$id] = ['tipo' => 'value', 'valor' => (string) $valor];
    }

    foreach ([
        'fechaSolicitud' => ['field_2'],
        'fechaNacimiento' => ['field_10'],
        'clienteConyugeFechaNacimiento' => ['Conyuge_Fecha_Nacimiento'],
        'fechaPrimerVencimiento' => ['field_75'],
    ] as $id => $columnas) {
        $valor = normalizarFecha(primerValor($fields, $columnas));
        if ($valor !== '') $controles[$id] = ['tipo' => 'value', 'valor' => $valor];
    }

    foreach ([
        'conformidadFinanciamiento' => ['Financiamiento_Conformidad'],
        'documentoIdTitular' => ['Documento_ID_Titular'],
        'documentoIdSustituto' => ['Documento_ID_Sustituto'],
        'documentoComprobanteDomicilio' => ['Documento_Comprobante_Domicilio'],
        'documentoComprobantePago' => ['Documento_Comprobante_Pago'],
    ] as $id => $columnas) {
        $valor = primerValor($fields, $columnas);
        if ($valor === '' || $valor === null) continue;
        $controles[$id] = ['tipo' => 'checked', 'valor' => valorBooleano($valor)];
    }

    $tipo = strtoupper(trim((string) primerValor($fields, ['Tipo_Componente', 'Tipo_Solicitud', 'field_56'])));
    $operacion = strtoupper(trim((string) primerValor($fields, ['field_47'])));
    $claveComponente = strtoupper(trim((string) primerValor($fields, ['field_4'])));
    $numeroServicio = '';
    if ($tipo === 'SERVICIO' && substr_count($claveComponente, '-') >= 2) {
        $partes = explode('-', $claveComponente);
        $numeroServicio = trim((string) end($partes));
    }

    $sucursal = strtoupper(trim((string) primerValor($fields, ['field_49'])));
    if ($tipo === 'LOTE' || $tipo === 'NICHO') $sucursal = 'PARQUE';
    if ($tipo === 'SERVICIO' && !in_array($sucursal, ['CHURUBUSCO', 'AGUA FRIA'], true)) $sucursal = '';

    $componente = [
        'componenteNumero' => 1,
        'esPrincipal' => true,
        'tipoSolicitud' => $tipo,
        'tipoOperacion' => $operacion !== '' ? $operacion : 'PREVISION',
        'tipoVentaProcap' => (string) primerValor($fields, ['field_48']),
        'servicioTipo' => (string) primerValor($fields, ['field_52']),
        'servicioAtaud' => (string) primerValor($fields, ['field_53']),
        'servicioUrna' => (string) primerValor($fields, ['field_54']),
        'servicioDuracion' => (string) primerValor($fields, ['field_55']),
        'servicioNumero' => $numeroServicio,
        'servicioClave' => $tipo === 'SERVICIO' ? $claveComponente : '',
        'sucursal' => $sucursal,
        'propiedadTipo' => (string) primerValor($fields, ['Propiedad_Subtipo']),
        'propiedadSeccion' => (string) primerValor($fields, ['field_57']),
        'propiedadManzana' => (string) primerValor($fields, ['field_58']),
        'propiedadNumero' => (string) primerValor($fields, ['field_59']),
        'propiedadClave' => $tipo === 'LOTE' || $tipo === 'NICHO'
            ? (string) (primerValor($fields, ['field_4', 'field_60']) ?: '')
            : (string) primerValor($fields, ['field_60']),
        'precioBaseComponente' => (float) (primerValor($fields, ['Precio_Base_Componente', 'field_76', 'field_63']) ?: 0),
        'montoComponente' => (float) (primerValor($fields, ['Monto_Componente', 'field_63']) ?: 0),
    ];

    return [
        'controles' => $controles,
        'componentes' => [$componente],
        'distribucion' => [
            'tipo' => (string) (primerValor($fields, ['Distribucion_Tipo']) ?: 'AUTOMATICA'),
            'promocionNombre' => (string) primerValor($fields, ['Promocion_Nombre']),
        ],
        'expediente' => [
            'version' => 1,
            'documentos' => [],
            'firmas' => [],
        ],
    ];
}

function primerValor(array $fields, array $columnas)
{
    foreach ($columnas as $columna) {
        if (!array_key_exists($columna, $fields)) continue;
        $valor = $fields[$columna];
        if ($valor !== null && $valor !== '') return $valor;
    }
    return '';
}

function normalizarFecha($valor): string
{
    $texto = trim((string) $valor);
    if ($texto === '') return '';
    if (preg_match('/^\d{4}-\d{2}-\d{2}/', $texto, $m)) return $m[0];
    return $texto;
}

function valorBooleano($valor): bool
{
    if (is_bool($valor)) return $valor;
    if (is_numeric($valor)) return ((int) $valor) !== 0;
    return in_array(strtoupper(trim((string) $valor)), ['TRUE', 'SI', 'SÍ', 'YES', '1'], true);
}

function obtenerDriveExpedientes(string $token, string $siteId): string
{
    $url = 'https://graph.microsoft.com/v1.0/sites/' . rawurlencode($siteId) . '/drives?$select=id,name';
    $drives = svCurlJson($url, 'GET', [
        'Authorization: Bearer ' . $token,
        'Accept: application/json',
    ]);

    foreach (($drives['value'] ?? []) as $drive) {
        if (!is_array($drive)) continue;
        $nombre = strtolower(trim((string) ($drive['name'] ?? '')));
        if (in_array($nombre, ['expedientes_ventas', 'expedientes ventas'], true)) {
            $id = trim((string) ($drive['id'] ?? ''));
            if ($id !== '') return $id;
        }
    }
    throw new RuntimeException('No se encontro la biblioteca Expedientes_Ventas.');
}

function asegurarCarpetaFolio(string $token, string $driveId, string $folio): void
{
    $folderUrl = 'https://graph.microsoft.com/v1.0/drives/' . rawurlencode($driveId)
        . '/root:/' . rawurlencode($folio);
    try {
        svCurlJson($folderUrl, 'GET', [
            'Authorization: Bearer ' . $token,
            'Accept: application/json',
        ]);
        return;
    } catch (Throwable $error) {
        // Se crea a continuacion.
    }

    $url = 'https://graph.microsoft.com/v1.0/drives/' . rawurlencode($driveId) . '/root/children';
    $body = json_encode([
        'name' => $folio,
        'folder' => new stdClass(),
        '@microsoft.graph.conflictBehavior' => 'replace',
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if (!is_string($body)) throw new RuntimeException('No fue posible serializar la carpeta del borrador.');

    svCurlJson($url, 'POST', [
        'Authorization: Bearer ' . $token,
        'Accept: application/json',
        'Content-Type: application/json',
    ], $body);
}

function guardarEstadoGraph(string $token, string $driveId, string $folio, string $contenido): void
{
    $path = rawurlencode($folio) . '/_ESTADO_BORRADOR.json';
    $url = 'https://graph.microsoft.com/v1.0/drives/' . rawurlencode($driveId)
        . '/root:/' . $path . ':/content';
    svCurlJson($url, 'PUT', [
        'Authorization: Bearer ' . $token,
        'Accept: application/json',
        'Content-Type: application/json; charset=utf-8',
    ], $contenido);
}

/** @return array<string,mixed> */
function cargarEstadoGraph(string $token, string $driveId, string $folio): array
{
    $path = rawurlencode($folio) . '/_ESTADO_BORRADOR.json';
    $url = 'https://graph.microsoft.com/v1.0/drives/' . rawurlencode($driveId)
        . '/root:/' . $path . ':/content';
    return svCurlJson($url, 'GET', [
        'Authorization: Bearer ' . $token,
        'Accept: application/json',
    ]);
}
