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


# Este normalizador conserva exclusivamente compatibilidad que aun no vive en
# el codigo fuente definitivo. Persistencia, fallback de borradores y sesion
# comun ya estan implementados directamente en sus modulos base.

# -----------------------------------------------------------------------------
# Endpoints heredados: reutilizar la autenticacion comun de Solicitud de Venta.
# -----------------------------------------------------------------------------
archivos = API / "archivos.php"
archivos_source = archivos.read_text(encoding="utf-8")
if "svUsuarioAutenticado($tenantId, $clientId)" not in archivos_source:
    replace_between(
        archivos,
        "$authorization = obtenerAuthorizationHeader();",
        "$correoUsuario =",
        "require_once __DIR__ . '/_common.php';\n"
        "$claims = svUsuarioAutenticado($tenantId, $clientId);\n\n",
    )

borrador = API / "borrador.php"
borrador_source = borrador.read_text(encoding="utf-8")
if "svUsuarioAutenticado($tenantId, $backendClientId)" not in borrador_source:
    replace_between(
        borrador,
        "$authorization = obtenerAuthorizationHeader();",
        "$body = file_get_contents('php://input');",
        "require_once __DIR__ . '/_common.php';\n"
        "$claims = svUsuarioAutenticado($tenantId, $backendClientId);\n"
        "$tenantClaim = (string) ($claims['tid'] ?? $tenantId);\n\n",
    )


# -----------------------------------------------------------------------------
# Firma remota: estado-borrador.php debe ser la unica fuente para recuperar el
# borrador. El interceptor historico redirigia esa lectura a estado-solicitud.
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
# Navegacion heredada: mientras index.html conserve el encabezado autonomo,
# agregar el regreso a Mis solicitudes. index.php oculta ese encabezado en la
# experiencia del Portal, pero algunos flujos aun dependen del control.
# -----------------------------------------------------------------------------
index_path = UI / "index.html"
index_source = index_path.read_text(encoding="utf-8")
if 'id="btnSolicitudInicio"' not in index_source:
    logout_tag = '<button id="btnLogout" class="secondary-button" type="button">Cerrar sesión</button>'
    if logout_tag not in index_source:
        raise RuntimeError("No se encontro btnLogout en index.html")
    index_source = index_source.replace(
        logout_tag,
        '<button id="btnSolicitudInicio" class="secondary-button" type="button">Regresar a inicio</button>\n          '
        + logout_tag,
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
    listener = (
        '  homeButton?.addEventListener("click", () => {\n'
        '    window.location.assign("/solicitud-venta/inicio/");\n'
        '  });\n\n'
    )
    app_source = app_source.replace(marker, listener + marker, 1)
app_path.write_text(app_source, encoding="utf-8")


# -----------------------------------------------------------------------------
# Barreras de integridad del normalizador temporal.
# -----------------------------------------------------------------------------
if "svUsuarioAutenticado($tenantId, $clientId)" not in archivos.read_text(encoding="utf-8"):
    raise RuntimeError("archivos.php no reutiliza la autenticacion comun.")
if "svUsuarioAutenticado($tenantId, $backendClientId)" not in borrador.read_text(encoding="utf-8"):
    raise RuntimeError("borrador.php no reutiliza la autenticacion comun.")
if "instalarRedireccionCargaEstado" in firma_path.read_text(encoding="utf-8"):
    raise RuntimeError("firma-remota.js aun contiene el interceptor de recuperacion.")
if 'id="btnSolicitudInicio"' not in index_path.read_text(encoding="utf-8"):
    raise RuntimeError("index.html no contiene el regreso a inicio.")
if 'window.location.assign("/solicitud-venta/inicio/")' not in app_path.read_text(encoding="utf-8"):
    raise RuntimeError("app.js no contiene la navegacion a Mis solicitudes.")

print("Normalizacion temporal aplicada: autenticacion heredada, firma remota y navegacion.")
