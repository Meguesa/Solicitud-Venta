from __future__ import annotations

import re
import shutil
from pathlib import Path
from urllib.parse import urlsplit

ROOT = Path(__file__).resolve().parents[1]
DEPLOY = ROOT / "_deploy"

UI_FILE_MAP = {
    "index.php": "index.php",
    "index.html": "index.html",
    "src/css/styles.css": "styles.css",
    "src/css/wizard.css": "wizard.css",
    "src/css/firma-remota.css": "firma-remota.css",
    "src/js/config.js": "config.js",
    "src/js/auth.js": "auth.js",
    "src/js/app.js": "app.js",
    "src/js/componentes.js": "componentes.js",
    "src/js/componentes-sync.js": "componentes-sync.js",
    "src/js/correccion-validacion.js": "correccion-validacion.js",
    "src/js/consentimiento-privacidad.js": "consentimiento-privacidad.js",
    "src/js/firma-remota.js": "firma-remota.js",
    "src/js/firma-remota-seguimiento.js": "firma-remota-seguimiento.js",
    "src/js/firma-remota-gestion.js": "firma-remota-gestion.js",
    "src/js/firma-remota-preflight.js": "firma-remota-preflight.js",
    "src/js/correccion.js": "correccion.js",
    "src/js/extras.js": "extras.js",
    "src/js/documentos-identificacion-doble.js": "documentos-identificacion-doble.js",
    "src/js/documentacion.js": "documentacion.js",
    "src/js/persistencia.js": "persistencia.js",
    "src/js/financiamiento-integracion.js": "financiamiento-integracion.js",
    "src/js/financiamiento-bridge.js": "financiamiento-bridge.js",
    "src/js/sucursales-componentes.js": "sucursales-componentes.js",
    "src/js/wizard.js": "wizard.js",
    "src/js/resumen-directo.js": "resumen-directo.js",
}

MODULES = [
    "componentes.js",
    "componentes-sync.js",
    "correccion-validacion.js",
    "consentimiento-privacidad.js",
    "firma-remota.js",
    "firma-remota-seguimiento.js",
    "firma-remota-gestion.js",
    "extras.js",
    "documentos-identificacion-doble.js",
    "persistencia.js",
    "financiamiento-bridge.js",
    "financiamiento-integracion.js",
    "sucursales-componentes.js",
    "wizard.js",
    "resumen-directo.js",
    "firma-remota-preflight.js",
    "correccion.js",
    "documentacion.js",
]

CACHE_VERSION = "20260911-cleanup-3"


def require_file(path: Path) -> None:
    if not path.is_file() or path.stat().st_size <= 0:
        raise RuntimeError(f"Falta archivo requerido: {path.relative_to(ROOT)}")


def copy_tree(source: Path, target: Path) -> None:
    if not source.is_dir():
        raise RuntimeError(f"Falta directorio requerido: {source.relative_to(ROOT)}")
    shutil.copytree(source, target, dirs_exist_ok=True)


def script_basename(src: str) -> str:
    path = urlsplit(src).path.rstrip("/")
    return path.rsplit("/", 1)[-1] if path else ""


def validate_index_source() -> None:
    path = DEPLOY / "solicitud-venta" / "index.html"
    source = path.read_text(encoding="utf-8")

    if "msal-browser" in source.lower() or "alcdn.msauth" in source.lower():
        raise RuntimeError("index.html aun contiene la autenticacion MSAL historica.")

    required_styles = ["styles.css", "firma-remota.css", "wizard.css"]
    for style in required_styles:
        if style not in source:
            raise RuntimeError(f"index.html no carga {style}.")

    script_sources = re.findall(
        r'<script\b[^>]*\bsrc=["\']([^"\']+)["\'][^>]*></script>',
        source,
        flags=re.I,
    )
    script_names = [script_basename(src) for src in script_sources]

    required_scripts = ["config.js", "auth.js", "app.js", *MODULES]
    for script in required_scripts:
        count = script_names.count(script)
        if count != 1:
            raise RuntimeError(
                f"index.html debe cargar {script} exactamente una vez; se encontraron {count} referencias exactas."
            )

    if f"?v={CACHE_VERSION}" not in source:
        raise RuntimeError(
            f"index.html no utiliza la version de cache esperada {CACHE_VERSION} para los modulos administrados."
        )



def validate_package() -> None:
    checks = [
        (DEPLOY / "solicitud-venta/componentes.js", "__solicitudComponentesModuloActivo"),
        (DEPLOY / "api/solicitud-venta/pdf-final-lib.php", "self::MARGIN + 90.0, 41.0"),
        (DEPLOY / "api/solicitud-venta/pdf-final-lib.php", "self::MARGIN + 90.0, 58.0"),
        (DEPLOY / "solicitud-venta/firma-remota-preflight.js", "__solicitudFirmaRemotaPreflightActivo"),
        (DEPLOY / "solicitud-venta/financiamiento-integracion.js", "__solicitudFinanciamientoIntegracionActiva"),
        (DEPLOY / "solicitud-venta/financiamiento-bridge.js", "__solicitudFinanciamientoBridgeActivo"),
        (DEPLOY / "solicitud-venta/consentimiento-privacidad.js", "__solicitudConsentimientoFetchEnvuelto"),
        (DEPLOY / "api/solicitud-venta/consentimiento-privacidad.php", "CONSENTIMIENTO_PRIVACIDAD_SOLICITUD_VENTA"),
        (DEPLOY / "firma/consentimiento.js", "consentimiento-remoto.php"),
        (DEPLOY / "firma/index.html", "solicitud-venta-2026-09-11-v1"),
        (DEPLOY / "api/solicitud-venta/pdf-final-layout-v3.php", "Aviso de Privacidad versión 11/09/2026"),
        (DEPLOY / "api/solicitud-venta/archivos.php", "CORRIDA_FINANCIERA"),
        (DEPLOY / "api/solicitud-venta/archivos.php", "svUsuarioAutenticado($tenantId, $clientId)"),
        (DEPLOY / "api/solicitud-venta/borrador.php", "svUsuarioAutenticado($tenantId, $backendClientId)"),
        (DEPLOY / "solicitud-venta/index.php", "btnVolverMisSolicitudes"),
        (DEPLOY / "solicitud-venta/correccion-validacion.js", "VALIDAR_ENDPOINT"),
        (DEPLOY / "solicitud-venta/persistencia.js", "referenciaSolicitada"),
        (DEPLOY / "api/solicitud-venta/estado-borrador.php", "SHAREPOINT_FALLBACK"),
        (DEPLOY / "api/solicitud-venta/_common.php", "PORTAL_SESSION"),
        (DEPLOY / "api/solicitud-venta/_common.php", "session_name"),
        (DEPLOY / "api/solicitud-venta/pdf-final-layout-v3.php", "svPdfAgregarLogoJdjp"),
        (DEPLOY / "api/solicitud-venta/identificaciones-pdf.php", "/Count 1"),
    ]

    for path, marker in checks:
        require_file(path)
        if marker not in path.read_text(encoding="utf-8"):
            raise RuntimeError(f"Falta marcador {marker!r} en {path.relative_to(ROOT)}")

    validate_index_source()

    firma_source = (DEPLOY / "solicitud-venta/firma-remota.js").read_text(encoding="utf-8")
    if "instalarRedireccionCargaEstado" in firma_source or "__solicitudFirmaRemotaFetchEstadoEnvuelto" in firma_source:
        raise RuntimeError("firma-remota.js aun contiene el interceptor historico de fetch.")

    print("Paquete de produccion construido y validado exclusivamente desde Solicitud-Venta.")


def build() -> None:
    if DEPLOY.exists():
        shutil.rmtree(DEPLOY)

    (DEPLOY / "solicitud-venta" / "inicio").mkdir(parents=True, exist_ok=True)
    (DEPLOY / "solicitud-venta" / "vobo").mkdir(parents=True, exist_ok=True)
    (DEPLOY / "api").mkdir(parents=True, exist_ok=True)
    (DEPLOY / "firma").mkdir(parents=True, exist_ok=True)

    for source_name, target_name in UI_FILE_MAP.items():
        source = ROOT / source_name
        require_file(source)
        shutil.copy2(source, DEPLOY / "solicitud-venta" / target_name)

    copy_tree(ROOT / "inicio", DEPLOY / "solicitud-venta" / "inicio")
    copy_tree(ROOT / "vobo", DEPLOY / "solicitud-venta" / "vobo")
    copy_tree(ROOT / "api" / "solicitud-venta", DEPLOY / "api" / "solicitud-venta")
    copy_tree(ROOT / "firma", DEPLOY / "firma")

    validate_package()


if __name__ == "__main__":
    build()
