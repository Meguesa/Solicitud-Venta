<?php

declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    http_response_code(405);
    header('Allow: POST');
    echo json_encode(['ok' => false, 'error' => 'METHOD_NOT_ALLOWED']);
    exit;
}

$configPath = '/home/juanpab1/portal-config/config.php';
if (!is_file($configPath)) {
    responderError(500, 'CONFIG_NOT_FOUND', 'No se encontro la configuracion privada del portal.');
}

/** @var array<string, mixed> $config */
$config = require $configPath;

$tenantId = (string) ($config['solicitud_backend_tenant_id'] ?? '');
$backendClientId = (string) ($config['solicitud_backend_client_id'] ?? '');
$backendClientSecret = (string) ($config['solicitud_backend_client_secret'] ?? '');
$sharePointSiteId = (string) ($config['solicitud_sharepoint_site_id'] ?? '');
$sharePointListId = (string) ($config['solicitud_sharepoint_list_id'] ?? '');

if ($tenantId === '' || $backendClientId === '' || $backendClientSecret === '' || $sharePointSiteId === '' || $sharePointListId === '') {
    responderError(500, 'CONFIG_INCOMPLETE', 'La configuracion del backend de Solicitud de Venta esta incompleta.');
}

$authorization = obtenerAuthorizationHeader();
if (!preg_match('/^Bearer\s+(.+)$/i', $authorization, $matches)) {
    responderError(401, 'TOKEN_REQUIRED', 'Se requiere un access token Bearer.');
}

$token = trim($matches[1]);

try {
    $claims = validarAccessTokenEntra($token, $tenantId, $backendClientId);
} catch (Throwable $error) {
    error_log('Solicitud Venta token invalido: ' . $error->getMessage());
    responderError(401, 'INVALID_TOKEN', 'El access token no es valido: ' . $error->getMessage());
}

$scopes = preg_split('/\s+/', trim((string) ($claims['scp'] ?? ''))) ?: [];
if (!in_array('SolicitudVenta.Access', $scopes, true)) {
    responderError(403, 'SCOPE_REQUIRED', 'El token no contiene el permiso SolicitudVenta.Access.');
}

$tenantClaim = (string) ($claims['tid'] ?? '');
if (!hash_equals(strtolower($tenantId), strtolower($tenantClaim))) {
    responderError(403, 'TENANT_NOT_ALLOWED', 'El tenant del usuario no esta autorizado.');
}

$body = file_get_contents('php://input');
$payload = [];
if (is_string($body) && trim($body) !== '') {
    $decoded = json_decode($body, true);
    if (!is_array($decoded)) {
        responderError(400, 'INVALID_JSON', 'El cuerpo de la solicitud debe ser JSON valido.');
    }
    $payload = $decoded;
}

$usuario = [
    'oid' => (string) ($claims['oid'] ?? ''),
    'nombre' => (string) ($claims['name'] ?? ''),
    'correo' => (string) ($claims['preferred_username'] ?? $claims['upn'] ?? ''),
    'tenantId' => $tenantClaim,
];

try {
    $graphToken = obtenerGraphAppToken($tenantId, $backendClientId, $backendClientSecret);
    $lista = obtenerListaSharePoint($graphToken, $sharePointSiteId, $sharePointListId);
} catch (Throwable $error) {
    error_log('Solicitud Venta Graph/SharePoint: ' . $error->getMessage());
    responderError(502, 'SHAREPOINT_CONNECTION_FAILED', 'No fue posible validar la conexion con SharePoint: ' . $error->getMessage());
}

if (($payload['accion'] ?? '') === 'guardar_borrador') {
    $tipoSolicitud = strtoupper(trim((string) ($payload['tipoSolicitud'] ?? '')));
    $tipoOperacion = strtoupper(trim((string) ($payload['tipoOperacion'] ?? '')));
    $tipoVentaProcap = strtoupper(trim((string) ($payload['tipoVentaProcap'] ?? '')));
    $fechaSolicitud = trim((string) ($payload['fechaSolicitud'] ?? ''));
    $itemId = trim((string) ($payload['itemId'] ?? ''));
    $folio = trim((string) ($payload['folio'] ?? ''));
    $solicitudGrupo = trim((string) ($payload['solicitudGrupo'] ?? ''));

    if ($tipoSolicitud === '' || $tipoOperacion === '' || $tipoVentaProcap === '' || $fechaSolicitud === '') {
        responderError(400, 'DRAFT_MINIMUM_REQUIRED', 'Para guardar el borrador se requieren Tipo de solicitud, Tipo de operacion, Tipo de venta ProcaP y Fecha.');
    }
    if ($itemId !== '' && !ctype_digit($itemId)) {
        responderError(400, 'INVALID_ITEM_ID', 'El identificador del borrador no es valido.');
    }

    try {
        $columnas = obtenerColumnasListaSharePoint($graphToken, $sharePointSiteId, $sharePointListId);
        $mapaColumnas = crearMapaColumnas($columnas);
    } catch (Throwable $error) {
        error_log('Solicitud Venta columnas SharePoint: ' . $error->getMessage());
        responderError(502, 'SHAREPOINT_COLUMNS_FAILED', 'No fue posible leer el esquema actual de Solicitudes_Venta: ' . $error->getMessage());
    }

    $fields = [
        'field_1' => 'BORRADOR',
        'field_2' => $fechaSolicitud,
        'Tipo_Solicitud' => $tipoSolicitud,
        'field_47' => $tipoOperacion,
        'field_48' => $tipoVentaProcap,
        'Vendedor_Nombre' => $usuario['nombre'],
        'Vendedor_Correo' => $usuario['correo'],
        'Componente_Numero' => 1,
        'Componente_Total' => 1,
        'Tipo_Componente' => $tipoSolicitud,
        'Es_Principal' => true,
    ];

    if ($solicitudGrupo !== '') $fields['Solicitud_Grupo'] = $solicitudGrupo;

    agregarTexto($fields, 'field_4', $payload, 'referencia');
    agregarTexto($fields, 'field_50', $payload, 'origenVenta');
    agregarTexto($fields, 'field_51', $payload, 'tipoContrato');

    $lugar = textoPayload($payload, 'lugar');
    if ($lugar !== '') {
        $fields['field_3'] = $lugar;
        $fields['field_49'] = $lugar;
    }

    // Informacion del cliente.
    agregarTexto($fields, 'Cliente_Tipo_ID', $payload, 'clienteTipoId');
    agregarTexto($fields, 'field_5', $payload, 'clienteNumeroId');
    agregarTexto($fields, 'field_6', $payload, 'clienteRfc');
    agregarTexto($fields, 'field_7', $payload, 'clienteCurp');
    agregarTexto($fields, 'field_8', $payload, 'clienteNombres');

    $apellidos = trim(textoPayload($payload, 'clienteApellidoPaterno') . ' ' . textoPayload($payload, 'clienteApellidoMaterno'));
    if ($apellidos !== '') $fields['field_9'] = preg_replace('/\s+/', ' ', $apellidos) ?: $apellidos;

    agregarFecha($fields, 'field_10', $payload, 'clienteFechaNacimiento');
    agregarNumero($fields, 'field_11', $payload, 'clienteEdad');
    agregarTexto($fields, 'field_12', $payload, 'clienteSexo');
    agregarTexto($fields, 'field_13', $payload, 'clienteEstadoCivil');
    agregarTexto($fields, 'field_14', $payload, 'clienteVivienda');
    agregarTexto($fields, 'field_15', $payload, 'clienteDomicilio');
    agregarTexto($fields, 'field_16', $payload, 'clienteNumero');
    agregarTexto($fields, 'field_17', $payload, 'clienteColonia');
    agregarTexto($fields, 'field_18', $payload, 'clienteMunicipio');
    agregarTexto($fields, 'field_19', $payload, 'clienteEstado');
    agregarTexto($fields, 'field_20', $payload, 'clienteCp');
    agregarTexto($fields, 'field_21', $payload, 'clienteCelular');
    agregarTexto($fields, 'field_22', $payload, 'clienteCorreo');
    agregarNumero($fields, 'field_23', $payload, 'clienteDependientes');
    agregarTexto($fields, 'field_24', $payload, 'clienteEdadesDependientes');
    agregarTexto($fields, 'field_25', $payload, 'clienteConyuge');
    agregarNumero($fields, 'field_26', $payload, 'clienteConyugeEdad');

    agregarTextoResuelto($fields, $mapaColumnas, ['Cliente_Nacionalidad'], $payload, 'clienteNacionalidad');
    agregarTextoResuelto($fields, $mapaColumnas, ['Cliente_Regimen_Matrimonial'], $payload, 'clienteRegimenMatrimonial');
    agregarTextoResuelto($fields, $mapaColumnas, ['Cliente_Ciudad'], $payload, 'clienteCiudad');
    agregarTextoResuelto($fields, $mapaColumnas, ['Cliente_Escolaridad'], $payload, 'clienteEscolaridad');
    agregarTextoResuelto($fields, $mapaColumnas, ['Cliente_Domicilio_Anterior'], $payload, 'clienteDomicilioAnterior');
    agregarTextoResuelto($fields, $mapaColumnas, ['Cliente_Antiguedad_Domicilio_Anterior'], $payload, 'clienteAntiguedadDomicilioAnterior');
    agregarTextoResuelto($fields, $mapaColumnas, ['Cliente_Telefono'], $payload, 'clienteTelefono');
    agregarFechaResuelta($fields, $mapaColumnas, ['Conyuge_Fecha_Nacimiento'], $payload, 'clienteConyugeFechaNacimiento');

    // Informacion laboral.
    agregarTexto($fields, 'field_27', $payload, 'laboralEmpresa');
    agregarTexto($fields, 'field_28', $payload, 'laboralOcupacion');
    agregarTexto($fields, 'field_29', $payload, 'laboralDomicilio');
    agregarTexto($fields, 'field_30', $payload, 'laboralNumero');
    agregarTexto($fields, 'field_31', $payload, 'laboralColonia');
    agregarTexto($fields, 'field_32', $payload, 'laboralCiudad');
    agregarTexto($fields, 'field_33', $payload, 'laboralMunicipio');
    agregarTexto($fields, 'field_34', $payload, 'laboralEstado');
    agregarTexto($fields, 'field_35', $payload, 'laboralCp');
    agregarTexto($fields, 'field_36', $payload, 'laboralTelefono');
    agregarTexto($fields, 'field_37', $payload, 'laboralExtension');
    agregarTexto($fields, 'field_38', $payload, 'laboralActividad');
    agregarTexto($fields, 'field_39', $payload, 'laboralSector');
    agregarTexto($fields, 'field_40', $payload, 'laboralAntiguedad');
    agregarTextoResuelto($fields, $mapaColumnas, ['Laboral_Antiguedad_Anterior'], $payload, 'laboralAntiguedadAnterior');

    // Titular substituto, obligatorio para todos los tipos de solicitud.
    agregarTexto($fields, 'field_41', $payload, 'sustitutoNombre');
    agregarTexto($fields, 'field_42', $payload, 'sustitutoDomicilio');
    agregarNumero($fields, 'field_43', $payload, 'sustitutoEdad');
    agregarTexto($fields, 'field_44', $payload, 'sustitutoTelefono');
    agregarTexto($fields, 'field_45', $payload, 'sustitutoParentesco');
    agregarTexto($fields, 'field_46', $payload, 'sustitutoId');

    // Referencias familiares y bancarias, aplicables a lote/nicho.
    if ($tipoSolicitud === 'LOTE' || $tipoSolicitud === 'NICHO') {
        agregarTextoResuelto($fields, $mapaColumnas, ['Referencia1_Nombre', 'Referencia_1_Nombre', 'Referencia_Familiar1_Nombre'], $payload, 'referencia1Nombre');
        agregarTextoResuelto($fields, $mapaColumnas, ['Referencia1_Telefono', 'Referencia_1_Telefono', 'Referencia_Familiar1_Telefono'], $payload, 'referencia1Telefono');
        agregarTextoResuelto($fields, $mapaColumnas, ['Referencia1_Celular', 'Referencia_1_Celular', 'Referencia_Familiar1_Celular'], $payload, 'referencia1Celular');
        agregarTextoResuelto($fields, $mapaColumnas, ['Referencia2_Nombre', 'Referencia_2_Nombre', 'Referencia_Familiar2_Nombre'], $payload, 'referencia2Nombre');
        agregarTextoResuelto($fields, $mapaColumnas, ['Referencia2_Telefono', 'Referencia_2_Telefono', 'Referencia_Familiar2_Telefono'], $payload, 'referencia2Telefono');
        agregarTextoResuelto($fields, $mapaColumnas, ['Referencia2_Celular', 'Referencia_2_Celular', 'Referencia_Familiar2_Celular'], $payload, 'referencia2Celular');

        agregarTextoResuelto($fields, $mapaColumnas, ['Banco1_Nombre', 'Banco_1_Nombre'], $payload, 'banco1Nombre');
        agregarTextoResuelto($fields, $mapaColumnas, ['Banco1_Tipo_Cuenta', 'Banco_1_Tipo_Cuenta'], $payload, 'banco1TipoCuenta');
        agregarTextoResuelto($fields, $mapaColumnas, ['Banco1_Numero_Cuenta', 'Banco_1_Numero_Cuenta'], $payload, 'banco1NumeroCuenta');
        agregarTextoResuelto($fields, $mapaColumnas, ['Banco2_Nombre', 'Banco_2_Nombre'], $payload, 'banco2Nombre');
        agregarTextoResuelto($fields, $mapaColumnas, ['Banco2_Tipo_Cuenta', 'Banco_2_Tipo_Cuenta'], $payload, 'banco2TipoCuenta');
        agregarTextoResuelto($fields, $mapaColumnas, ['Banco2_Numero_Cuenta', 'Banco_2_Numero_Cuenta'], $payload, 'banco2NumeroCuenta');
    }

    // Informacion de la venta.
    agregarTexto($fields, 'Paquete', $payload, 'paquete');
    agregarTexto($fields, 'field_61', $payload, 'descripcionVenta');

    if ($tipoSolicitud === 'SERVICIO') {
        agregarTexto($fields, 'field_52', $payload, 'servicioTipo');
        agregarTexto($fields, 'field_53', $payload, 'servicioAtaud');
        agregarTexto($fields, 'field_54', $payload, 'servicioUrna');
        agregarTexto($fields, 'field_55', $payload, 'servicioDuracion');
    }

    if ($tipoSolicitud === 'LOTE' || $tipoSolicitud === 'NICHO') {
        $fields['field_56'] = $tipoSolicitud;
        agregarTextoResuelto($fields, $mapaColumnas, ['Propiedad_Subtipo'], $payload, 'propiedadTipo');
        agregarTexto($fields, 'field_57', $payload, 'propiedadSeccion');
        agregarTexto($fields, 'field_58', $payload, 'propiedadManzana');
        agregarTexto($fields, 'field_59', $payload, 'propiedadNumero');
        agregarTexto($fields, 'field_60', $payload, 'propiedadClave');
    }

    // Importe y forma de pago.
    agregarTexto($fields, 'field_62', $payload, 'formaPago');
    agregarNumero($fields, 'field_63', $payload, 'precioTotal');
    agregarNumero($fields, 'field_64', $payload, 'enganche');
    agregarNumero($fields, 'field_65', $payload, 'saldo');
    agregarNumero($fields, 'field_66', $payload, 'mensualidades');
    agregarNumero($fields, 'field_67', $payload, 'importeMensual');
    agregarNumero($fields, 'field_68', $payload, 'diaPago');
    agregarTexto($fields, 'field_69', $payload, 'metodoPago');
    agregarBooleanoResuelto($fields, $mapaColumnas, ['Financiamiento_Conformidad'], $payload, 'conformidadFinanciamiento');

    // Distribucion economica para la etapa actual de un solo componente.
    // Al habilitar paquetes multiples, estos valores se calcularan por cada componente.
    agregarNumero($fields, 'Monto_Componente', $payload, 'precioTotal');
    agregarNumero($fields, 'Precio_Base_Componente', $payload, 'precioLista');
    $fields['Distribucion_Tipo'] = 'AUTOMATICA';
    agregarTexto($fields, 'Promocion_Nombre', $payload, 'promocionNombre');

    if (strtoupper(textoPayload($payload, 'formaPago')) === 'CREDITO') {
        agregarNumero($fields, 'field_70', $payload, 'precioTotal');
        agregarNumero($fields, 'field_71', $payload, 'enganche');
        agregarNumero($fields, 'field_72', $payload, 'saldo');
        agregarNumero($fields, 'field_73', $payload, 'mensualidades');
        agregarNumero($fields, 'field_74', $payload, 'importeMensual');
        agregarFecha($fields, 'field_75', $payload, 'fechaPrimerVencimiento');
        agregarNumero($fields, 'field_76', $payload, 'precioLista');
        agregarNumero($fields, 'field_77', $payload, 'bonificacion');
        agregarNumero($fields, 'field_78', $payload, 'montoFinanciar');
        agregarNumero($fields, 'field_79', $payload, 'interesFinanciamiento');
        agregarTexto($fields, 'field_80', $payload, 'periodoPagos');
        agregarNumero($fields, 'field_81', $payload, 'pagosAnuales');
        agregarNumero($fields, 'field_82', $payload, 'totalPagar');
    }

    agregarBooleanoResuelto($fields, $mapaColumnas, ['Documento_ID_Titular'], $payload, 'documentoIdTitular');
    agregarBooleanoResuelto($fields, $mapaColumnas, ['Documento_ID_Sustituto'], $payload, 'documentoIdSustituto');
    agregarBooleanoResuelto($fields, $mapaColumnas, ['Documento_Comprobante_Domicilio'], $payload, 'documentoComprobanteDomicilio');
    agregarBooleanoResuelto($fields, $mapaColumnas, ['Documento_Comprobante_Pago'], $payload, 'documentoComprobantePago');
    agregarTexto($fields, 'field_88', $payload, 'documentoOtros');

    if ($tipoOperacion === 'USO INMEDIATO') {
        agregarTexto($fields, 'field_89', $payload, 'finadoNombres');
        agregarTexto($fields, 'field_90', $payload, 'finadoApellidos');
        agregarTexto($fields, 'field_91', $payload, 'finadoSexo');
        agregarNumero($fields, 'field_92', $payload, 'finadoEstatura');
        agregarNumero($fields, 'field_93', $payload, 'finadoPeso');
        agregarTexto($fields, 'field_94', $payload, 'finadoParentescoTitular');
        agregarTexto($fields, 'field_95', $payload, 'finadoCausaDefuncion');
        agregarTexto($fields, 'field_96', $payload, 'finadoProcedencia');
        agregarTexto($fields, 'field_97', $payload, 'uiCorresponsableNombres');
        agregarTexto($fields, 'field_98', $payload, 'uiCorresponsableApellidos');
        agregarTexto($fields, 'field_99', $payload, 'uiCorresponsableParentesco');
        agregarTexto($fields, 'field_100', $payload, 'uiCorresponsableCelular');
        agregarTexto($fields, 'field_101', $payload, 'uiObservaciones');
    }

    agregarTextoResuelto($fields, $mapaColumnas, ['Observaciones_Solicitud'], $payload, 'observacionesSolicitud');

    agregarValorSiColumnaExiste($fields, $mapaColumnas, ['Cobranza_Pago_Inicial_Validado'], false);
    agregarValorSiColumnaExiste($fields, $mapaColumnas, ['Cobranza_Informacion_Validada'], false);

    try {
        if ($itemId === '') {
            $temporal = 'SV-PENDIENTE-' . gmdate('YmdHis') . '-' . substr(bin2hex(random_bytes(3)), 0, 6);
            $fieldsCrear = array_merge(['Title' => $temporal], $fields);
            $fieldsCrear['field_102'] = 'PENDIENTE';
            $fieldsCrear['field_103'] = 'PENDIENTE';
            $fieldsCrear['field_104'] = strtoupper(textoPayload($payload, 'formaPago')) === 'CREDITO' ? 'PENDIENTE' : 'NO APLICA';

            $item = crearItemSharePoint($graphToken, $sharePointSiteId, $sharePointListId, $fieldsCrear);
            $itemId = (string) ($item['id'] ?? '');
            if ($itemId === '') throw new RuntimeException('SharePoint creo el registro pero no devolvio el ID del item.');

            $folio = 'SV-' . gmdate('Y') . '-' . str_pad($itemId, 6, '0', STR_PAD_LEFT);
            $solicitudGrupo = $solicitudGrupo !== '' ? $solicitudGrupo : $folio;
            actualizarCamposItemSharePoint($graphToken, $sharePointSiteId, $sharePointListId, $itemId, [
                'Title' => $folio,
                'Solicitud_Grupo' => $solicitudGrupo,
            ]);
            $status = 201;
            $mensaje = 'Borrador creado correctamente.';
        } else {
            verificarBorradorUsuario($graphToken, $sharePointSiteId, $sharePointListId, $itemId, $usuario['correo']);
            if ($solicitudGrupo === '' && $folio !== '') $fields['Solicitud_Grupo'] = $folio;
            actualizarCamposItemSharePoint($graphToken, $sharePointSiteId, $sharePointListId, $itemId, $fields);
            if ($folio === '') $folio = 'SV-' . gmdate('Y') . '-' . str_pad($itemId, 6, '0', STR_PAD_LEFT);
            $status = 200;
            $mensaje = 'Borrador actualizado correctamente.';
        }
    } catch (Throwable $error) {
        error_log('Solicitud Venta guardar borrador: ' . $error->getMessage());
        responderError(502, 'DRAFT_SAVE_FAILED', 'No fue posible guardar el borrador en SharePoint: ' . $error->getMessage());
    }

    http_response_code($status);
    echo json_encode([
        'ok' => true,
        'message' => $mensaje,
        'folio' => $folio,
        'solicitudGrupo' => $solicitudGrupo !== '' ? $solicitudGrupo : $folio,
        'itemId' => $itemId,
        'usuario' => $usuario,
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

http_response_code(200);
echo json_encode([
    'ok' => true,
    'message' => 'Token de usuario y conexion app-only con SharePoint validados correctamente.',
    'usuario' => $usuario,
    'sharePoint' => [
        'id' => (string) ($lista['id'] ?? ''),
        'nombre' => (string) ($lista['displayName'] ?? $lista['name'] ?? ''),
    ],
    'payloadRecibido' => $payload,
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
exit;

function responderError(int $status, string $code, string $message): void
{
    http_response_code($status);
    echo json_encode(['ok' => false, 'error' => $code, 'message' => $message], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function textoPayload(array $payload, string $key): string
{
    return trim((string) ($payload[$key] ?? ''));
}

function agregarTexto(array &$fields, string $sharePointField, array $payload, string $payloadKey): void
{
    $value = textoPayload($payload, $payloadKey);
    if ($value !== '') $fields[$sharePointField] = $value;
}

function agregarFecha(array &$fields, string $sharePointField, array $payload, string $payloadKey): void
{
    $value = textoPayload($payload, $payloadKey);
    if ($value !== '') $fields[$sharePointField] = $value;
}

function agregarNumero(array &$fields, string $sharePointField, array $payload, string $payloadKey): void
{
    if (!array_key_exists($payloadKey, $payload) || $payload[$payloadKey] === null || $payload[$payloadKey] === '' || !is_numeric($payload[$payloadKey])) return;
    $fields[$sharePointField] = (float) $payload[$payloadKey];
}

function agregarBooleano(array &$fields, string $sharePointField, array $payload, string $payloadKey): void
{
    if (!array_key_exists($payloadKey, $payload)) return;
    $fields[$sharePointField] = (bool) $payload[$payloadKey];
}

function normalizarNombreColumna(string $value): string
{
    $normal = strtolower($value);
    $normal = preg_replace('/[^a-z0-9]+/', '', $normal);
    return is_string($normal) ? $normal : strtolower($value);
}

function crearMapaColumnas(array $columnas): array
{
    $mapa = [];
    foreach (($columnas['value'] ?? []) as $columna) {
        if (!is_array($columna)) continue;
        $name = trim((string) ($columna['name'] ?? ''));
        $display = trim((string) ($columna['displayName'] ?? ''));
        if ($name === '') continue;
        $mapa[normalizarNombreColumna($name)] = $name;
        if ($display !== '') $mapa[normalizarNombreColumna($display)] = $name;
    }
    return $mapa;
}

function resolverColumna(array $mapa, array $candidatos): string
{
    foreach ($candidatos as $candidato) {
        $key = normalizarNombreColumna((string) $candidato);
        if (isset($mapa[$key])) return (string) $mapa[$key];
    }
    return '';
}

function agregarTextoResuelto(array &$fields, array $mapa, array $candidatos, array $payload, string $payloadKey): void
{
    $value = textoPayload($payload, $payloadKey);
    if ($value === '') return;
    $columna = resolverColumna($mapa, $candidatos);
    if ($columna !== '') $fields[$columna] = $value;
}

function agregarFechaResuelta(array &$fields, array $mapa, array $candidatos, array $payload, string $payloadKey): void
{
    agregarTextoResuelto($fields, $mapa, $candidatos, $payload, $payloadKey);
}

function agregarBooleanoResuelto(array &$fields, array $mapa, array $candidatos, array $payload, string $payloadKey): void
{
    if (!array_key_exists($payloadKey, $payload)) return;
    $columna = resolverColumna($mapa, $candidatos);
    if ($columna !== '') $fields[$columna] = (bool) $payload[$payloadKey];
}

function agregarValorSiColumnaExiste(array &$fields, array $mapa, array $candidatos, mixed $valor): void
{
    $columna = resolverColumna($mapa, $candidatos);
    if ($columna !== '') $fields[$columna] = $valor;
}

function obtenerAuthorizationHeader(): string
{
    if (!empty($_SERVER['HTTP_AUTHORIZATION'])) return trim((string) $_SERVER['HTTP_AUTHORIZATION']);
    if (!empty($_SERVER['REDIRECT_HTTP_AUTHORIZATION'])) return trim((string) $_SERVER['REDIRECT_HTTP_AUTHORIZATION']);
    if (function_exists('getallheaders')) {
        $headers = getallheaders();
        foreach ($headers as $name => $value) {
            if (strcasecmp((string) $name, 'Authorization') === 0) return trim((string) $value);
        }
    }
    return '';
}

function validarAccessTokenEntra(string $jwt, string $tenantId, string $backendClientId): array
{
    $parts = explode('.', $jwt);
    if (count($parts) !== 3) throw new RuntimeException('JWT con formato invalido.');

    [$encodedHeader, $encodedPayload, $encodedSignature] = $parts;
    $header = json_decode(base64UrlDecode($encodedHeader), true);
    $claims = json_decode(base64UrlDecode($encodedPayload), true);
    if (!is_array($header) || !is_array($claims)) throw new RuntimeException('JWT no decodificable.');
    if (($header['alg'] ?? '') !== 'RS256') throw new RuntimeException('Algoritmo JWT no permitido.');

    $kid = (string) ($header['kid'] ?? '');
    if ($kid === '') throw new RuntimeException('JWT sin kid.');
    if ((string) ($claims['ver'] ?? '') !== '2.0') throw new RuntimeException('Version de token no soportada.');

    $metadataUrl = 'https://login.microsoftonline.com/' . rawurlencode($tenantId) . '/v2.0/.well-known/openid-configuration';
    $metadata = obtenerJsonRemoto($metadataUrl);

    $issuerEsperado = (string) ($metadata['issuer'] ?? '');
    $issuerToken = (string) ($claims['iss'] ?? '');
    if ($issuerEsperado === '' || !hash_equals($issuerEsperado, $issuerToken)) throw new RuntimeException('Issuer invalido.');

    $audience = (string) ($claims['aud'] ?? '');
    if (!hash_equals(strtolower($backendClientId), strtolower($audience))) throw new RuntimeException('Audience invalido.');

    $tenantClaim = (string) ($claims['tid'] ?? '');
    if ($tenantClaim === '' || !hash_equals(strtolower($tenantId), strtolower($tenantClaim))) throw new RuntimeException('Tenant invalido.');

    $now = time();
    $expires = (int) ($claims['exp'] ?? 0);
    $notBefore = (int) ($claims['nbf'] ?? 0);
    if ($expires <= 0 || $now >= $expires) throw new RuntimeException('Token expirado.');
    if ($notBefore > 0 && ($now + 60) < $notBefore) throw new RuntimeException('Token aun no valido.');

    $jwksUri = (string) ($metadata['jwks_uri'] ?? '');
    if ($jwksUri === '') throw new RuntimeException('Metadata sin jwks_uri.');

    $jwks = obtenerJsonRemoto($jwksUri);
    $signingKey = null;
    foreach (($jwks['keys'] ?? []) as $key) {
        if (is_array($key) && (string) ($key['kid'] ?? '') === $kid) {
            $signingKey = $key;
            break;
        }
    }
    if (!is_array($signingKey)) throw new RuntimeException('Signing key no encontrada.');

    $certificates = $signingKey['x5c'] ?? null;
    if (!is_array($certificates) || empty($certificates[0])) throw new RuntimeException('Signing key sin certificado x5c.');

    $certificateBody = preg_replace('/\s+/', '', (string) $certificates[0]);
    if ($certificateBody === null || $certificateBody === '') throw new RuntimeException('Certificado invalido.');

    $certificatePem = "-----BEGIN CERTIFICATE-----\n" . chunk_split($certificateBody, 64, "\n") . "-----END CERTIFICATE-----\n";
    $publicKey = openssl_pkey_get_public($certificatePem);
    if ($publicKey === false) throw new RuntimeException('No fue posible obtener la clave publica.');

    $verified = openssl_verify($encodedHeader . '.' . $encodedPayload, base64UrlDecode($encodedSignature), $publicKey, OPENSSL_ALGO_SHA256);
    if ($verified !== 1) throw new RuntimeException('Firma JWT invalida.');
    return $claims;
}

function obtenerGraphAppToken(string $tenantId, string $clientId, string $clientSecret): string
{
    $url = 'https://login.microsoftonline.com/' . rawurlencode($tenantId) . '/oauth2/v2.0/token';
    $body = http_build_query([
        'client_id' => $clientId,
        'client_secret' => $clientSecret,
        'scope' => 'https://graph.microsoft.com/.default',
        'grant_type' => 'client_credentials',
    ], '', '&', PHP_QUERY_RFC3986);

    $response = ejecutarCurlJson($url, 'POST', ['Content-Type: application/x-www-form-urlencoded', 'Accept: application/json'], $body);
    $accessToken = (string) ($response['access_token'] ?? '');
    if ($accessToken === '') throw new RuntimeException('Microsoft Entra ID no devolvio access_token para Microsoft Graph.');
    return $accessToken;
}

function obtenerListaSharePoint(string $graphToken, string $siteId, string $listId): array
{
    $url = 'https://graph.microsoft.com/v1.0/sites/' . rawurlencode($siteId) . '/lists/' . rawurlencode($listId) . '?$select=id,name,displayName';
    return ejecutarCurlJson($url, 'GET', ['Authorization: Bearer ' . $graphToken, 'Accept: application/json']);
}

function obtenerColumnasListaSharePoint(string $graphToken, string $siteId, string $listId): array
{
    $url = 'https://graph.microsoft.com/v1.0/sites/' . rawurlencode($siteId) . '/lists/' . rawurlencode($listId) . '/columns?$select=name,displayName';
    return ejecutarCurlJson($url, 'GET', ['Authorization: Bearer ' . $graphToken, 'Accept: application/json']);
}

function crearItemSharePoint(string $graphToken, string $siteId, string $listId, array $fields): array
{
    $url = 'https://graph.microsoft.com/v1.0/sites/' . rawurlencode($siteId) . '/lists/' . rawurlencode($listId) . '/items';
    $body = json_encode(['fields' => $fields], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if (!is_string($body)) throw new RuntimeException('No fue posible serializar el borrador.');

    return ejecutarCurlJson($url, 'POST', [
        'Authorization: Bearer ' . $graphToken,
        'Accept: application/json',
        'Content-Type: application/json',
    ], $body);
}

function actualizarCamposItemSharePoint(string $graphToken, string $siteId, string $listId, string $itemId, array $fields): void
{
    $url = 'https://graph.microsoft.com/v1.0/sites/' . rawurlencode($siteId) . '/lists/' . rawurlencode($listId) . '/items/' . rawurlencode($itemId) . '/fields';
    $body = json_encode($fields, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if (!is_string($body)) throw new RuntimeException('No fue posible serializar la actualizacion del borrador.');

    ejecutarCurlJson($url, 'PATCH', [
        'Authorization: Bearer ' . $graphToken,
        'Accept: application/json',
        'Content-Type: application/json',
    ], $body);
}

function verificarBorradorUsuario(string $graphToken, string $siteId, string $listId, string $itemId, string $correoUsuario): void
{
    $url = 'https://graph.microsoft.com/v1.0/sites/' . rawurlencode($siteId)
        . '/lists/' . rawurlencode($listId)
        . '/items/' . rawurlencode($itemId)
        . '?$expand=fields($select=Title,field_1,Vendedor_Correo)';

    $item = ejecutarCurlJson($url, 'GET', ['Authorization: Bearer ' . $graphToken, 'Accept: application/json']);
    $fields = is_array($item['fields'] ?? null) ? $item['fields'] : [];
    $estado = strtoupper(trim((string) ($fields['field_1'] ?? '')));
    $correoBorrador = strtolower(trim((string) ($fields['Vendedor_Correo'] ?? '')));
    $correoUsuario = strtolower(trim($correoUsuario));

    if ($estado !== 'BORRADOR') throw new RuntimeException('La solicitud ya no esta en estado BORRADOR y no puede editarse con esta accion.');
    if ($correoBorrador === '' || $correoUsuario === '' || !hash_equals($correoBorrador, $correoUsuario)) {
        throw new RuntimeException('El borrador no pertenece al usuario autenticado.');
    }
}

function ejecutarCurlJson(string $url, string $method, array $headers, ?string $body = null): array
{
    $curl = curl_init($url);
    if ($curl === false) throw new RuntimeException('No fue posible inicializar cURL.');

    $options = [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_CONNECTTIMEOUT => 8,
        CURLOPT_TIMEOUT => 15,
        CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
    ];
    if ($body !== null) $options[CURLOPT_POSTFIELDS] = $body;
    curl_setopt_array($curl, $options);

    $response = curl_exec($curl);
    $httpCode = (int) curl_getinfo($curl, CURLINFO_HTTP_CODE);
    $curlError = curl_error($curl);
    curl_close($curl);

    if ($response === false || ($response === '' && $httpCode !== 204)) throw new RuntimeException('La solicitud remota fallo: ' . $curlError);
    if ($httpCode === 204) return [];

    $decoded = json_decode((string) $response, true);
    if ($httpCode < 200 || $httpCode >= 300) {
        $detalle = '';
        if (is_array($decoded)) $detalle = (string) ($decoded['error']['message'] ?? $decoded['error_description'] ?? '');
        throw new RuntimeException('Servicio remoto respondio HTTP ' . $httpCode . ($detalle !== '' ? ': ' . $detalle : '.'));
    }
    if (!is_array($decoded)) throw new RuntimeException('El servicio remoto no devolvio JSON valido.');
    return $decoded;
}

function obtenerJsonRemoto(string $url): array
{
    return ejecutarCurlJson($url, 'GET', ['Accept: application/json', 'User-Agent: Portal-JJP-Solicitud-Venta/1.0']);
}

function base64UrlDecode(string $value): string
{
    $remainder = strlen($value) % 4;
    if ($remainder > 0) $value .= str_repeat('=', 4 - $remainder);
    $decoded = base64_decode(strtr($value, '-_', '+/'), true);
    if ($decoded === false) throw new RuntimeException('Base64Url invalido.');
    return $decoded;
}
