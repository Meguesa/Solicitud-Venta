from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
API = ROOT / "api" / "solicitud-venta"
UI = ROOT / "solicitud-venta"


def replace_between(path: Path, start_marker: str, end_marker: str, replacement: str) -> None:
    source = path.read_text(encoding="utf-8")
    start = source.find(start_marker)
    end = source.find(end_marker, start)
    if start < 0 or end < 0:
        raise RuntimeError(f"No se encontraron marcadores esperados en {path}")
    source = source[:start] + replacement + source[end:]
    path.write_text(source, encoding="utf-8")


def replace_once(path: Path, old: str, new: str, label: str) -> None:
    source = path.read_text(encoding="utf-8")
    if old not in source:
        if new in source:
            return
        raise RuntimeError(f"No se encontro el marcador de {label} en {path}")
    path.write_text(source.replace(old, new, 1), encoding="utf-8")


# -----------------------------------------------------------------------------
# Sesion unica del Portal para todos los endpoints privados de Solicitud.
# -----------------------------------------------------------------------------
common = API / "_common.php"
common_source = common.read_text(encoding="utf-8")
start = common_source.find("function svUsuarioAutenticado(string $tenantId, string $clientId): array")
end = common_source.find("function svAuthorizationHeader(): string", start)
if start < 0 or end < 0:
    raise RuntimeError("No se pudo localizar svUsuarioAutenticado en _common.php")

session_auth = r'''function svUsuarioAutenticado(string $tenantId, string $clientId): array
{
    $portalClaims = svUsuarioSesionPortal($tenantId);
    if ($portalClaims !== null) {
        return $portalClaims;
    }

    $authorization = svAuthorizationHeader();
    if (!preg_match('/^Bearer\s+(.+)$/i', $authorization, $matches)) {
        svResponderError(401, 'SESSION_REQUIRED', 'La sesion del Portal no esta activa. Inicia sesion nuevamente.');
    }

    try {
        $claims = svValidarAccessToken(trim($matches[1]), $tenantId, $clientId);
    } catch (Throwable $error) {
        error_log('Solicitud Venta token: ' . $error->getMessage());
        svResponderError(401, 'INVALID_TOKEN', 'La sesion o el access token no son validos.');
    }

    $scopes = preg_split('/\s+/', trim((string) ($claims['scp'] ?? ''))) ?: [];
    if (!in_array('SolicitudVenta.Access', $scopes, true)) {
        svResponderError(403, 'SCOPE_REQUIRED', 'El token no contiene el permiso requerido.');
    }

    return $claims;
}

/** @return array<string,mixed>|null */
function svUsuarioSesionPortal(string $tenantId): ?array
{
    $bootstrapPath = dirname(__DIR__, 2) . '/includes/bootstrap.php';
    if (!is_file($bootstrapPath)) {
        return null;
    }

    // bootstrap.php se incluye desde esta funcion. Sus variables de configuracion
    // deben enlazarse al scope global porque portal_config() y portal_provider()
    // las consultan mediante global.
    global $config, $provider;
    require_once $bootstrapPath;

    if (!function_exists('portal_is_authenticated') || !portal_is_authenticated()) {
        return null;
    }

    $user = function_exists('portal_user') ? portal_user() : [];
    $email = strtolower(trim((string) ($user['email'] ?? '')));
    if ($email === '') {
        return null;
    }

    return [
        'oid' => (string) ($user['id'] ?? ''),
        'name' => (string) ($user['name'] ?? $email),
        'preferred_username' => $email,
        'upn' => $email,
        'tid' => $tenantId,
        'scp' => 'SolicitudVenta.Access',
        'auth_mode' => 'PORTAL_SESSION',
    ];
}

'''
common_source = common_source[:start] + session_auth + common_source[end:]
common.write_text(common_source, encoding="utf-8")

replace_between(
    API / "archivos.php",
    "$authorization = obtenerAuthorizationHeader();",
    "$correoUsuario =",
    "require_once __DIR__ . '/_common.php';\n$claims = svUsuarioAutenticado($tenantId, $clientId);\n\n",
)

replace_between(
    API / "borrador.php",
    "$authorization = obtenerAuthorizationHeader();",
    "$body = file_get_contents('php://input');",
    "require_once __DIR__ . '/_common.php';\n$claims = svUsuarioAutenticado($tenantId, $backendClientId);\n$tenantClaim = (string) ($claims['tid'] ?? $tenantId);\n\n",
)


# -----------------------------------------------------------------------------
# Recuperacion: firma-remota no debe interceptar la lectura de estado-borrador.
# estado-solicitud.php queda reservado exclusivamente para seguimiento de firma.
# -----------------------------------------------------------------------------
firma_path = UI / "firma-remota.js"
firma_source = firma_path.read_text(encoding="utf-8")
firma_source = firma_source.replace("\n  instalarRedireccionCargaEstado();\n", "\n", 1)
redirect_start = firma_source.find("  function instalarRedireccionCargaEstado() {")
redirect_end = firma_source.find("  function insertarSelectorModalidad(section) {", redirect_start)
if redirect_start >= 0 and redirect_end >= 0:
    firma_source = firma_source[:redirect_start] + firma_source[redirect_end:]
elif "__solicitudFirmaRemotaFetchEstadoEnvuelto" in firma_source:
    raise RuntimeError("No se pudo retirar el interceptor global de estado-borrador en firma-remota.js")

firma_source = firma_source.replace(
    'if (["btnLogout", "btnCopiarFirmaRemota"].includes(control.id)) return;',
    'if (["btnLogout", "btnSolicitudInicio", "btnCopiarFirmaRemota"].includes(control.id)) return;',
)
firma_path.write_text(firma_source, encoding="utf-8")


# -----------------------------------------------------------------------------
# Recuperacion: cuando se abre /solicitud-venta/?folio=..., ese folio es la
# referencia autoritativa. Antes solo se leia localStorage y podia abrir otro
# borrador distinto al seleccionado en Mis solicitudes.
# -----------------------------------------------------------------------------
persistencia_path = UI / "persistencia.js"
persistencia_source = persistencia_path.read_text(encoding="utf-8")
if "const referencia = referenciaSolicitada();" not in persistencia_source:
    if "const referencia = leerBorradorActivoLocal();" not in persistencia_source:
        raise RuntimeError("No se encontro la lectura del borrador activo en persistencia.js")
    persistencia_source = persistencia_source.replace(
        "const referencia = leerBorradorActivoLocal();",
        "const referencia = referenciaSolicitada();",
        1,
    )

if "function referenciaSolicitada()" not in persistencia_source:
    marker = "  function leerBorradorActivoLocal() {"
    if marker not in persistencia_source:
        raise RuntimeError("No se encontro leerBorradorActivoLocal en persistencia.js")
    helper = r'''  function referenciaSolicitada() {
    try {
      const folio = String(new URLSearchParams(window.location.search).get('folio') || '').trim().toUpperCase();
      if (/^SV-\d{4}-\d+$/.test(folio)) {
        const itemId = String(Number(folio.split('-').pop() || 0) || '');
        if (/^\d+$/.test(itemId)) return { folio, itemId, origen: 'query' };
      }
    } catch (_) {}
    return leerBorradorActivoLocal();
  }

'''
    persistencia_source = persistencia_source.replace(marker, helper + marker, 1)

# Mantener el apuntador local alineado con el borrador realmente abierto.
needle = "      recalcularDespuesDeRestaurar();"
if "guardarBorradorActivoLocal(data.folio || referencia.folio" not in persistencia_source:
    if needle not in persistencia_source:
        raise RuntimeError("No se encontro el punto de finalizacion de restauracion en persistencia.js")
    persistencia_source = persistencia_source.replace(
        needle,
        "      guardarBorradorActivoLocal(data.folio || referencia.folio, data.itemId || referencia.itemId);\n" + needle,
        1,
    )
persistencia_path.write_text(persistencia_source, encoding="utf-8")


# -----------------------------------------------------------------------------
# Navegacion: boton visible para regresar a Mis solicitudes / Inicio.
# -----------------------------------------------------------------------------
index_path = UI / "index.html"
index_source = index_path.read_text(encoding="utf-8")
if 'id="btnSolicitudInicio"' not in index_source:
    logout_tag = '<button id="btnLogout" class="secondary-button" type="button">Cerrar sesión</button>'
    if logout_tag not in index_source:
        raise RuntimeError("No se encontro btnLogout en index.html")
    index_source = index_source.replace(
        logout_tag,
        '<button id="btnSolicitudInicio" class="secondary-button" type="button">Regresar a inicio</button>\n          ' + logout_tag,
        1,
    )
index_path.write_text(index_source, encoding="utf-8")

app_path = UI / "app.js"
app_source = app_path.read_text(encoding="utf-8")
if 'const homeButton = document.getElementById("btnSolicitudInicio");' not in app_source:
    marker = '  const logoutButton = document.getElementById("btnLogout");'
    if marker not in app_source:
        raise RuntimeError("No se encontro btnLogout en app.js")
    app_source = app_source.replace(
        marker,
        marker + '\n  const homeButton = document.getElementById("btnSolicitudInicio");',
        1,
    )

if 'window.location.assign("/solicitud-venta/inicio/")' not in app_source:
    marker = '  tipoSolicitud.addEventListener("change", actualizarFormularioDinamico);'
    if marker not in app_source:
        raise RuntimeError("No se encontro el inicio de listeners del formulario en app.js")
    listener = '''  homeButton?.addEventListener("click", () => {\n    window.location.assign("/solicitud-venta/inicio/");\n  });\n\n'''
    app_source = app_source.replace(marker, listener + marker, 1)
app_path.write_text(app_source, encoding="utf-8")


# -----------------------------------------------------------------------------
# Compatibilidad para borradores creados antes de que _ESTADO_BORRADOR.json
# pudiera guardarse. Si el archivo no existe, se reconstruye el principal con
# los campos ya persistidos en Solicitudes_Venta para evitar perder la captura.
# -----------------------------------------------------------------------------
estado_path = API / "estado-borrador.php"
estado_source = estado_path.read_text(encoding="utf-8")

estado_source = estado_source.replace(
    ". '?$expand=fields($select=Title,field_1,Vendedor_Correo,Solicitud_Grupo,field_8,field_9,field_48)';",
    ". '?$expand=fields';",
    1,
)

old_load = r'''    $documento = cargarEstadoGraph($graphToken, $driveId, $folio);
    if (strtoupper(trim((string) ($documento['folio'] ?? ''))) !== $folio) {
        throw new RuntimeException('El archivo de estado no corresponde al folio solicitado.');
    }
    $usuarioEstado = strtolower(trim((string) ($documento['usuario'] ?? '')));
    if ($usuarioEstado === '' || !hash_equals($usuarioEstado, $correoUsuario)) {
        throw new RuntimeException('El archivo de estado no pertenece al usuario autenticado.');
    }
    $estado = $documento['estado'] ?? null;
    if (!is_array($estado)) {
        throw new RuntimeException('El archivo de estado no contiene datos validos.');
    }

    http_response_code(200);
    echo json_encode([
        'ok' => true,
        'folio' => $folio,
        'itemId' => $itemId,
        'guardadoUtc' => (string) ($documento['guardadoUtc'] ?? ''),
        'estado' => $estado,
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
'''

new_load = r'''    try {
        $documento = cargarEstadoGraph($graphToken, $driveId, $folio);
        if (strtoupper(trim((string) ($documento['folio'] ?? ''))) !== $folio) {
            throw new RuntimeException('El archivo de estado no corresponde al folio solicitado.');
        }
        $usuarioEstado = strtolower(trim((string) ($documento['usuario'] ?? '')));
        if ($usuarioEstado === '' || !hash_equals($usuarioEstado, $correoUsuario)) {
            throw new RuntimeException('El archivo de estado no pertenece al usuario autenticado.');
        }
        $estado = $documento['estado'] ?? null;
        if (!is_array($estado)) {
            throw new RuntimeException('El archivo de estado no contiene datos validos.');
        }

        http_response_code(200);
        echo json_encode([
            'ok' => true,
            'folio' => $folio,
            'itemId' => $itemId,
            'guardadoUtc' => (string) ($documento['guardadoUtc'] ?? ''),
            'estado' => $estado,
            'recuperacion' => 'ESTADO_BORRADOR',
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    } catch (Throwable $estadoError) {
        // Borradores antiguos pudieron guardar su fila en SharePoint antes de que
        // existiera/funcionara _ESTADO_BORRADOR.json. Recuperamos el principal
        // directamente desde la lista para no dejar al usuario con una hoja vacia.
        error_log('Solicitud Venta estado borrador fallback SharePoint: ' . $estadoError->getMessage());
        $estado = reconstruirEstadoLegado($datosSolicitud);
        http_response_code(200);
        echo json_encode([
            'ok' => true,
            'folio' => $folio,
            'itemId' => $itemId,
            'guardadoUtc' => '',
            'estado' => $estado,
            'recuperacion' => 'SHAREPOINT_FALLBACK',
            'warning' => 'El borrador fue reconstruido desde SharePoint porque no existia un estado reanudable completo.',
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
'''

if "'recuperacion' => 'SHAREPOINT_FALLBACK'" not in estado_source:
    if old_load not in estado_source:
        raise RuntimeError("No se encontro el bloque de carga de estado-borrador.php")
    estado_source = estado_source.replace(old_load, new_load, 1)

if "function reconstruirEstadoLegado(array $fields): array" not in estado_source:
    insert_marker = "function obtenerDriveExpedientes(string $token, string $siteId): string"
    if insert_marker not in estado_source:
        raise RuntimeError("No se encontro el punto de insercion del fallback en estado-borrador.php")

    legacy_helpers = r'''/** @return array<string,mixed> */
function reconstruirEstadoLegado(array $fields): array
{
    $controles = [];

    $mapa = [
        'tipoSolicitud' => primerCampoLegado($fields, ['Tipo_Componente', 'Tipo_Solicitud', 'field_56']),
        'tipoOperacion' => primerCampoLegado($fields, ['field_47']),
        'tipoVentaProcap' => primerCampoLegado($fields, ['field_48']),
        'tipoContrato' => primerCampoLegado($fields, ['field_51', 'field_48']),
        'referencia' => primerCampoLegado($fields, ['field_4']),
        'origenVenta' => primerCampoLegado($fields, ['field_50']),
        'lugar' => primerCampoLegado($fields, ['field_3']),
        'clienteTipoId' => primerCampoLegado($fields, ['Cliente_Tipo_ID']),
        'clienteNumeroId' => primerCampoLegado($fields, ['field_5']),
        'clienteRfc' => primerCampoLegado($fields, ['field_6']),
        'clienteCurp' => primerCampoLegado($fields, ['field_7']),
        'clienteNombres' => primerCampoLegado($fields, ['field_8']),
        // La version historica guardaba ambos apellidos juntos en field_9.
        'clienteApellidoPaterno' => primerCampoLegado($fields, ['field_9']),
        'edadCliente' => primerCampoLegado($fields, ['field_11']),
        'clienteSexo' => primerCampoLegado($fields, ['field_12']),
        'clienteEstadoCivil' => primerCampoLegado($fields, ['field_13']),
        'clienteNacionalidad' => primerCampoLegado($fields, ['Cliente_Nacionalidad']),
        'clienteRegimenMatrimonial' => primerCampoLegado($fields, ['Cliente_Regimen_Matrimonial']),
        'clienteVivienda' => primerCampoLegado($fields, ['field_14']),
        'clienteEscolaridad' => primerCampoLegado($fields, ['Cliente_Escolaridad']),
        'clienteDomicilio' => primerCampoLegado($fields, ['field_15']),
        'clienteNumero' => primerCampoLegado($fields, ['field_16']),
        'clienteColonia' => primerCampoLegado($fields, ['field_17']),
        'clienteEstado' => primerCampoLegado($fields, ['field_19']),
        'clienteCp' => primerCampoLegado($fields, ['field_20']),
        'clienteCiudad' => primerCampoLegado($fields, ['Cliente_Ciudad']),
        'clienteMunicipio' => primerCampoLegado($fields, ['field_18']),
        'clienteTelefono' => primerCampoLegado($fields, ['Cliente_Telefono']),
        'clienteCelular' => primerCampoLegado($fields, ['field_21']),
        'clienteCorreo' => primerCampoLegado($fields, ['field_22']),
        'clienteDomicilioAnterior' => primerCampoLegado($fields, ['Cliente_Domicilio_Anterior']),
        'clienteAntiguedadDomicilioAnterior' => primerCampoLegado($fields, ['Cliente_Antiguedad_Domicilio_Anterior']),
        'clienteDependientes' => primerCampoLegado($fields, ['field_23']),
        'clienteEdadesDependientes' => primerCampoLegado($fields, ['field_24']),
        'clienteConyuge' => primerCampoLegado($fields, ['field_25']),
        'clienteConyugeEdad' => primerCampoLegado($fields, ['field_26']),
        'laboralEmpresa' => primerCampoLegado($fields, ['field_27']),
        'laboralOcupacion' => primerCampoLegado($fields, ['field_28']),
        'laboralDomicilio' => primerCampoLegado($fields, ['field_29']),
        'laboralNumero' => primerCampoLegado($fields, ['field_30']),
        'laboralColonia' => primerCampoLegado($fields, ['field_31']),
        'laboralCiudad' => primerCampoLegado($fields, ['field_32']),
        'laboralMunicipio' => primerCampoLegado($fields, ['field_33']),
        'laboralEstado' => primerCampoLegado($fields, ['field_34']),
        'laboralCp' => primerCampoLegado($fields, ['field_35']),
        'laboralTelefono' => primerCampoLegado($fields, ['field_36']),
        'laboralExtension' => primerCampoLegado($fields, ['field_37']),
        'laboralActividad' => primerCampoLegado($fields, ['field_38']),
        'laboralSector' => primerCampoLegado($fields, ['field_39']),
        'laboralAntiguedad' => primerCampoLegado($fields, ['field_40']),
        'laboralAntiguedadAnterior' => primerCampoLegado($fields, ['Laboral_Antiguedad_Anterior']),
        'sustitutoNombre' => primerCampoLegado($fields, ['field_41']),
        'sustitutoDomicilio' => primerCampoLegado($fields, ['field_42']),
        'sustitutoEdad' => primerCampoLegado($fields, ['field_43']),
        'sustitutoTelefono' => primerCampoLegado($fields, ['field_44']),
        'sustitutoParentesco' => primerCampoLegado($fields, ['field_45']),
        'sustitutoId' => primerCampoLegado($fields, ['field_46']),
        'referencia1Nombre' => primerCampoLegado($fields, ['Referencia1_Nombre', 'Referencia_1_Nombre', 'Referencia_Familiar1_Nombre']),
        'referencia1Telefono' => primerCampoLegado($fields, ['Referencia1_Telefono', 'Referencia_1_Telefono', 'Referencia_Familiar1_Telefono']),
        'referencia1Celular' => primerCampoLegado($fields, ['Referencia1_Celular', 'Referencia_1_Celular', 'Referencia_Familiar1_Celular']),
        'referencia2Nombre' => primerCampoLegado($fields, ['Referencia2_Nombre', 'Referencia_2_Nombre', 'Referencia_Familiar2_Nombre']),
        'referencia2Telefono' => primerCampoLegado($fields, ['Referencia2_Telefono', 'Referencia_2_Telefono', 'Referencia_Familiar2_Telefono']),
        'referencia2Celular' => primerCampoLegado($fields, ['Referencia2_Celular', 'Referencia_2_Celular', 'Referencia_Familiar2_Celular']),
        'banco1Nombre' => primerCampoLegado($fields, ['Banco1_Nombre', 'Banco_1_Nombre']),
        'banco1TipoCuenta' => primerCampoLegado($fields, ['Banco1_Tipo_Cuenta', 'Banco_1_Tipo_Cuenta']),
        'banco1NumeroCuenta' => primerCampoLegado($fields, ['Banco1_Numero_Cuenta', 'Banco_1_Numero_Cuenta']),
        'banco2Nombre' => primerCampoLegado($fields, ['Banco2_Nombre', 'Banco_2_Nombre']),
        'banco2TipoCuenta' => primerCampoLegado($fields, ['Banco2_Tipo_Cuenta', 'Banco_2_Tipo_Cuenta']),
        'banco2NumeroCuenta' => primerCampoLegado($fields, ['Banco2_Numero_Cuenta', 'Banco_2_Numero_Cuenta']),
        'paquete' => primerCampoLegado($fields, ['Paquete']),
        'descripcionVenta' => primerCampoLegado($fields, ['field_61']),
        'servicioTipo' => primerCampoLegado($fields, ['field_52']),
        'servicioAtaud' => primerCampoLegado($fields, ['field_53']),
        'servicioUrna' => primerCampoLegado($fields, ['field_54']),
        'servicioDuracion' => primerCampoLegado($fields, ['field_55']),
        'propiedadTipo' => primerCampoLegado($fields, ['Propiedad_Subtipo']),
        'propiedadSeccion' => primerCampoLegado($fields, ['field_57']),
        'propiedadManzana' => primerCampoLegado($fields, ['field_58']),
        'propiedadNumero' => primerCampoLegado($fields, ['field_59']),
        'propiedadClave' => primerCampoLegado($fields, ['field_60']),
        'formaPago' => primerCampoLegado($fields, ['field_62']),
        'precioTotal' => primerCampoLegado($fields, ['field_63']),
        'enganche' => primerCampoLegado($fields, ['field_64']),
        'saldo' => primerCampoLegado($fields, ['field_65']),
        'mensualidades' => primerCampoLegado($fields, ['field_66']),
        'importeMensual' => primerCampoLegado($fields, ['field_67']),
        'importeMensualidad' => primerCampoLegado($fields, ['field_67']),
        'diaPago' => primerCampoLegado($fields, ['field_68']),
        'metodoPago' => primerCampoLegado($fields, ['field_69']),
        'precioLista' => primerCampoLegado($fields, ['field_76', 'Precio_Base_Componente']),
        'bonificacion' => primerCampoLegado($fields, ['field_77']),
        'montoFinanciar' => primerCampoLegado($fields, ['field_78']),
        'interesFinanciamiento' => primerCampoLegado($fields, ['field_79']),
        'periodoPagos' => primerCampoLegado($fields, ['field_80']),
        'pagosAnuales' => primerCampoLegado($fields, ['field_81']),
        'totalPagar' => primerCampoLegado($fields, ['field_82']),
        'documentoOtros' => primerCampoLegado($fields, ['field_88']),
        'finadoNombres' => primerCampoLegado($fields, ['field_89']),
        'finadoApellidos' => primerCampoLegado($fields, ['field_90']),
        'finadoSexo' => primerCampoLegado($fields, ['field_91']),
        'finadoEstatura' => primerCampoLegado($fields, ['field_92']),
        'finadoPeso' => primerCampoLegado($fields, ['field_93']),
        'finadoParentescoTitular' => primerCampoLegado($fields, ['field_94']),
        'finadoCausaDefuncion' => primerCampoLegado($fields, ['field_95']),
        'finadoProcedencia' => primerCampoLegado($fields, ['field_96']),
        'uiCorresponsableNombres' => primerCampoLegado($fields, ['field_97']),
        'uiCorresponsableApellidos' => primerCampoLegado($fields, ['field_98']),
        'uiCorresponsableParentesco' => primerCampoLegado($fields, ['field_99']),
        'uiCorresponsableCelular' => primerCampoLegado($fields, ['field_100']),
        'uiObservaciones' => primerCampoLegado($fields, ['field_101']),
        'observacionesSolicitud' => primerCampoLegado($fields, ['Observaciones_Solicitud']),
        'vendedorNombre' => primerCampoLegado($fields, ['Vendedor_Nombre']),
        'vendedorCorreo' => primerCampoLegado($fields, ['Vendedor_Correo']),
    ];

    foreach ($mapa as $id => $value) {
        if ($value === null || $value === '') continue;
        $controles[$id] = ['tipo' => 'value', 'valor' => (string) $value];
    }

    foreach ([
        'fechaSolicitud' => primerCampoLegado($fields, ['field_2']),
        'fechaNacimiento' => primerCampoLegado($fields, ['field_10']),
        'clienteConyugeFechaNacimiento' => primerCampoLegado($fields, ['Conyuge_Fecha_Nacimiento']),
        'fechaPrimerVencimiento' => primerCampoLegado($fields, ['field_75']),
    ] as $id => $value) {
        $fecha = fechaLegado($value);
        if ($fecha !== '') $controles[$id] = ['tipo' => 'value', 'valor' => $fecha];
    }

    foreach ([
        'conformidadFinanciamiento' => primerCampoLegado($fields, ['Financiamiento_Conformidad']),
        'documentoIdTitular' => primerCampoLegado($fields, ['Documento_ID_Titular']),
        'documentoIdSustituto' => primerCampoLegado($fields, ['Documento_ID_Sustituto']),
        'documentoComprobanteDomicilio' => primerCampoLegado($fields, ['Documento_Comprobante_Domicilio']),
        'documentoComprobantePago' => primerCampoLegado($fields, ['Documento_Comprobante_Pago']),
    ] as $id => $value) {
        if ($value === '' || $value === null) continue;
        $controles[$id] = ['tipo' => 'checked', 'valor' => boolLegado($value)];
    }

    $tipo = strtoupper(trim((string) primerCampoLegado($fields, ['Tipo_Componente', 'Tipo_Solicitud', 'field_56'])));
    $operacion = strtoupper(trim((string) primerCampoLegado($fields, ['field_47'])));
    $claveServicio = strtoupper(trim((string) primerCampoLegado($fields, ['field_4'])));
    $numeroServicio = '';
    if ($tipo === 'SERVICIO' && substr_count($claveServicio, '-') >= 2) {
        $partes = explode('-', $claveServicio);
        $numeroServicio = trim((string) end($partes));
    }

    $sucursal = strtoupper(trim((string) primerCampoLegado($fields, ['field_49'])));
    if ($tipo === 'LOTE' || $tipo === 'NICHO') $sucursal = 'PARQUE';
    if ($tipo === 'SERVICIO' && !in_array($sucursal, ['CHURUBUSCO', 'AGUA FRIA'], true)) $sucursal = '';

    $componente = [
        'componenteNumero' => 1,
        'esPrincipal' => true,
        'tipoSolicitud' => $tipo,
        'tipoOperacion' => $operacion !== '' ? $operacion : 'PREVISION',
        'tipoVentaProcap' => (string) primerCampoLegado($fields, ['field_48']),
        'servicioTipo' => (string) primerCampoLegado($fields, ['field_52']),
        'servicioAtaud' => (string) primerCampoLegado($fields, ['field_53']),
        'servicioUrna' => (string) primerCampoLegado($fields, ['field_54']),
        'servicioDuracion' => (string) primerCampoLegado($fields, ['field_55']),
        'servicioNumero' => $numeroServicio,
        'servicioClave' => $numeroServicio !== '' ? $claveServicio : '',
        'sucursal' => $sucursal,
        'propiedadTipo' => (string) primerCampoLegado($fields, ['Propiedad_Subtipo']),
        'propiedadSeccion' => (string) primerCampoLegado($fields, ['field_57']),
        'propiedadManzana' => (string) primerCampoLegado($fields, ['field_58']),
        'propiedadNumero' => (string) primerCampoLegado($fields, ['field_59']),
        'propiedadClave' => (string) primerCampoLegado($fields, ['field_60']),
        'precioBaseComponente' => (float) (primerCampoLegado($fields, ['Precio_Base_Componente', 'field_76', 'field_63']) ?: 0),
        'montoComponente' => (float) (primerCampoLegado($fields, ['Monto_Componente', 'field_63']) ?: 0),
    ];

    return [
        'controles' => $controles,
        'componentes' => [$componente],
        'distribucion' => [
            'tipo' => (string) (primerCampoLegado($fields, ['Distribucion_Tipo']) ?: 'AUTOMATICA'),
            'promocionNombre' => (string) primerCampoLegado($fields, ['Promocion_Nombre']),
        ],
        'expediente' => [
            'version' => 1,
            'documentos' => [],
            'firmas' => [],
        ],
    ];
}

function primerCampoLegado(array $fields, array $nombres)
{
    foreach ($nombres as $nombre) {
        if (!array_key_exists($nombre, $fields)) continue;
        $value = $fields[$nombre];
        if ($value !== null && $value !== '') return $value;
    }
    return '';
}

function fechaLegado($value): string
{
    $texto = trim((string) $value);
    if ($texto === '') return '';
    if (preg_match('/^\d{4}-\d{2}-\d{2}/', $texto, $matches)) return $matches[0];
    return $texto;
}

function boolLegado($value): bool
{
    if (is_bool($value)) return $value;
    if (is_numeric($value)) return ((int) $value) !== 0;
    return in_array(strtoupper(trim((string) $value)), ['TRUE', 'SI', 'SÍ', 'YES', '1'], true);
}

'''
    estado_source = estado_source.replace(insert_marker, legacy_helpers + insert_marker, 1)

estado_path.write_text(estado_source, encoding="utf-8")


# -----------------------------------------------------------------------------
# Barreras de integridad del build.
# -----------------------------------------------------------------------------
for file_name in ["_common.php", "archivos.php", "borrador.php", "estado-borrador.php"]:
    text = (API / file_name).read_text(encoding="utf-8")
    if file_name != "estado-borrador.php" and "svUsuarioAutenticado" not in text:
        raise RuntimeError(f"La preparacion de sesion no quedo aplicada en {file_name}")

if "global $config, $provider;" not in common.read_text(encoding="utf-8"):
    raise RuntimeError("No quedo aplicado el enlace de variables globales del bootstrap.")
if "instalarRedireccionCargaEstado" in firma_path.read_text(encoding="utf-8"):
    raise RuntimeError("firma-remota.js aun contiene el interceptor que rompe la recuperacion.")
if "function referenciaSolicitada()" not in persistencia_path.read_text(encoding="utf-8"):
    raise RuntimeError("persistencia.js no reconoce el folio solicitado por URL.")
if 'id="btnSolicitudInicio"' not in index_path.read_text(encoding="utf-8"):
    raise RuntimeError("No quedo agregado el boton Regresar a inicio.")
if "function reconstruirEstadoLegado" not in estado_path.read_text(encoding="utf-8"):
    raise RuntimeError("No quedo habilitado el fallback de recuperacion desde SharePoint.")

print("Solicitud de Venta preparada: sesion unica, recuperacion corregida, fallback legado y regreso a inicio.")
