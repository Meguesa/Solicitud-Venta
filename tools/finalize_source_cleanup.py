from __future__ import annotations

from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
API = ROOT / "api" / "solicitud-venta"


def replace_between(path: Path, start_marker: str, end_marker: str, replacement: str, label: str) -> None:
    source = path.read_text(encoding="utf-8")
    if replacement in source:
        return
    start = source.find(start_marker)
    end = source.find(end_marker, start)
    if start < 0 or end < 0:
        raise RuntimeError(f"{path}: no se encontraron marcadores para {label}")
    path.write_text(source[:start] + replacement + source[end:], encoding="utf-8")


def migrate_archivos_auth() -> None:
    path = API / "archivos.php"
    source = path.read_text(encoding="utf-8")
    require = "require_once __DIR__ . '/_common.php';"
    if require not in source:
        marker = "header('X-Content-Type-Options: nosniff');"
        if marker not in source:
            raise RuntimeError("archivos.php: no se encontro encabezado para cargar _common.php")
        source = source.replace(marker, marker + "\n\n" + require, 1)
        path.write_text(source, encoding="utf-8")

    replace_between(
        path,
        "$authorization = obtenerAuthorizationHeader();",
        "$correoUsuario =",
        "$claims = svUsuarioAutenticado($tenantId, $clientId);\n\n",
        "autenticacion compartida",
    )


def migrate_borrador_auth() -> None:
    path = API / "borrador.php"
    source = path.read_text(encoding="utf-8")
    require = "require_once __DIR__ . '/_common.php';"
    if require not in source:
        marker = "header('X-Content-Type-Options: nosniff');"
        if marker not in source:
            raise RuntimeError("borrador.php: no se encontro encabezado para cargar _common.php")
        source = source.replace(marker, marker + "\n\n" + require, 1)
        path.write_text(source, encoding="utf-8")

    replace_between(
        path,
        "$authorization = obtenerAuthorizationHeader();",
        "$body = file_get_contents('php://input');",
        "$claims = svUsuarioAutenticado($tenantId, $backendClientId);\n"
        "$tenantClaim = (string) ($claims['tid'] ?? $tenantId);\n\n"
        "$body = file_get_contents('php://input');",
        "autenticacion compartida",
    )


def migrate_remote_signature() -> None:
    path = ROOT / "firma-remota.js"
    source = path.read_text(encoding="utf-8")
    source = source.replace("\n  instalarRedireccionCargaEstado();\n", "\n", 1)

    start = source.find("  function instalarRedireccionCargaEstado() {")
    end = source.find("  function insertarSelectorModalidad(section) {", start)
    if start >= 0 and end >= 0:
        source = source[:start] + source[end:]
    elif "__solicitudFirmaRemotaFetchEstadoEnvuelto" in source:
        raise RuntimeError("firma-remota.js: no se pudo retirar el interceptor global de fetch")

    source = source.replace(
        'if (["btnLogout", "btnCopiarFirmaRemota"].includes(control.id)) return;',
        'if (["btnLogout", "btnVolverMisSolicitudes", "btnCopiarFirmaRemota"].includes(control.id)) return;',
    )
    path.write_text(source, encoding="utf-8")


def simplify_builder() -> None:
    path = ROOT / "tools" / "build_solicitud_package.py"
    source = path.read_text(encoding="utf-8")

    source = source.replace("import subprocess\n", "")
    source = source.replace("import sys\n", "")

    start = source.find("\ndef normalize_runtime() -> None:\n")
    end = source.find("\ndef validate_package() -> None:\n", start)
    if start >= 0 and end >= 0:
        source = source[:start] + "\n" + source[end:]
    elif "def normalize_runtime()" in source:
        raise RuntimeError("build_solicitud_package.py: no se pudo retirar normalize_runtime()")

    source = source.replace('        (DEPLOY / "solicitud-venta/index.html", "btnSolicitudInicio"),\n', "")
    validation_marker = '        (DEPLOY / "api/solicitud-venta/archivos.php", "CORRIDA_FINANCIERA"),\n'
    validation_extra = (
        validation_marker
        + '        (DEPLOY / "api/solicitud-venta/archivos.php", "svUsuarioAutenticado($tenantId, $clientId)"),\n'
        + '        (DEPLOY / "api/solicitud-venta/borrador.php", "svUsuarioAutenticado($tenantId, $backendClientId)"),\n'
        + '        (DEPLOY / "solicitud-venta/index.php", "btnVolverMisSolicitudes"),\n'
    )
    if "svUsuarioAutenticado($tenantId, $clientId)" not in source:
        if validation_marker not in source:
            raise RuntimeError("build_solicitud_package.py: no se encontro punto para validaciones de autenticacion")
        source = source.replace(validation_marker, validation_extra, 1)

    msal_marker = '    if "msal-browser" in index_source.lower():\n        raise RuntimeError("La interfaz aun intenta cargar MSAL independiente.")\n'
    remote_validation = (
        msal_marker
        + '\n    firma_source = (DEPLOY / "solicitud-venta/firma-remota.js").read_text(encoding="utf-8")\n'
        + '    if "instalarRedireccionCargaEstado" in firma_source or "__solicitudFirmaRemotaFetchEstadoEnvuelto" in firma_source:\n'
        + '        raise RuntimeError("firma-remota.js aun contiene el interceptor historico de fetch.")\n'
    )
    if "interceptor historico de fetch" not in source:
        if msal_marker not in source:
            raise RuntimeError("build_solicitud_package.py: no se encontro validacion MSAL")
        source = source.replace(msal_marker, remote_validation, 1)

    source = source.replace('    (DEPLOY / "scripts").mkdir(parents=True, exist_ok=True)\n', "")

    normalizer_block = (
        '    normalizer = ROOT / "tools" / "normalize_runtime.py"\n'
        '    require_file(normalizer)\n'
        '    shutil.copy2(normalizer, DEPLOY / "scripts" / "normalize_runtime.py")\n\n'
    )
    source = source.replace(normalizer_block, "")
    source = source.replace("    normalize_runtime()\n", "")

    forbidden = ["normalize_runtime.py", "normalize_runtime()", "subprocess.run([sys.executable"]
    for marker in forbidden:
        if marker in source:
            raise RuntimeError(f"build_solicitud_package.py conserva dependencia historica: {marker}")

    path.write_text(source, encoding="utf-8")


def update_readme() -> None:
    path = ROOT / "README.md"
    source = path.read_text(encoding="utf-8")
    source = source.replace(
        "├── tools/                  # Build, normalizacion y despliegue FTPS",
        "├── tools/                  # Build y despliegue FTPS",
    )
    source = source.replace(
        "Este script crea `_deploy/`, prepara la plantilla, aplica compatibilidad historica, valida marcadores criticos y deja listo el paquete para publicacion. La compatibilidad que aun debe migrarse gradualmente a codigo fuente definitivo esta aislada en:\n\n`tools/normalize_runtime.py`\n\nEl workflow ya no contiene bloques extensos de transformacion de codigo: valida la estructura, ejecuta el builder, valida PHP y publica por FTPS mediante `tools/deploy_solicitud_ftps.sh`.",
        "Este script crea `_deploy/`, prepara los assets de produccion, valida marcadores criticos y deja listo el paquete para publicacion. La autenticacion compartida del Portal, la recuperacion de borradores y la firma remota ya viven directamente en el codigo fuente; no existe una capa de normalizacion de runtime.\n\nEl workflow valida la estructura, ejecuta el builder, valida PHP y publica por FTPS mediante `tools/deploy_solicitud_ftps.sh`.",
    )
    source = source.replace(
        "5. retirar del normalizador cualquier parche que ya haya sido incorporado de forma definitiva al codigo fuente.",
        "5. mantener toda la logica funcional en el codigo fuente y evitar parches de runtime durante el despliegue.",
    )
    path.write_text(source, encoding="utf-8")


def validate_sources() -> None:
    checks = {
        API / "archivos.php": ["require_once __DIR__ . '/_common.php';", "svUsuarioAutenticado($tenantId, $clientId)"],
        API / "borrador.php": ["require_once __DIR__ . '/_common.php';", "svUsuarioAutenticado($tenantId, $backendClientId)"],
        ROOT / "firma-remota.js": ["btnVolverMisSolicitudes"],
        ROOT / "tools" / "build_solicitud_package.py": ["interceptor historico de fetch"],
    }
    for path, markers in checks.items():
        source = path.read_text(encoding="utf-8")
        for marker in markers:
            if marker not in source:
                raise RuntimeError(f"{path}: falta marcador final {marker}")

    firma = (ROOT / "firma-remota.js").read_text(encoding="utf-8")
    if "instalarRedireccionCargaEstado" in firma or "__solicitudFirmaRemotaFetchEstadoEnvuelto" in firma:
        raise RuntimeError("firma-remota.js conserva el interceptor historico")

    builder = (ROOT / "tools" / "build_solicitud_package.py").read_text(encoding="utf-8")
    if "normalize_runtime" in builder:
        raise RuntimeError("El builder aun depende del normalizador historico")


def cleanup_migration_files() -> None:
    normalizer = ROOT / "tools" / "normalize_runtime.py"
    if normalizer.exists():
        normalizer.unlink()

    workflow = ROOT / ".github" / "workflows" / "finalizar-limpieza-fuentes.yml"
    if workflow.exists():
        workflow.unlink()

    me = ROOT / "tools" / "finalize_source_cleanup.py"
    if me.exists():
        me.unlink()


def main() -> None:
    migrate_archivos_auth()
    migrate_borrador_auth()
    migrate_remote_signature()
    simplify_builder()
    update_readme()
    validate_sources()
    cleanup_migration_files()
    print("Migracion final a codigo fuente aplicada; normalize_runtime.py retirado.")


if __name__ == "__main__":
    main()
