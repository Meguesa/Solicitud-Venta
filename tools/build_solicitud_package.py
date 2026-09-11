from __future__ import annotations

import re
import shutil
from collections import Counter
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


def basename_src(src: str) -> str:
    path_part = urlsplit(src).path.rstrip("/")
    return path_part.rsplit("/", 1)[-1] if path_part else ""


def prepare_index() -> None:
    path = DEPLOY / "solicitud-venta" / "index.html"
    source = path.read_text(encoding="utf-8")

    if "firma-remota.css" not in source:
        source = source.replace(
            '<link rel="stylesheet" href="styles.css">',
            '<link rel="stylesheet" href="styles.css">\n'
            '  <link rel="stylesheet" href="firma-remota.css?v=20260820-1">',
            1,
        )
    if "wizard.css" not in source:
        source = source.replace(
            "</head>",
            '  <link rel="stylesheet" href="wizard.css?v=20260821-1">\n</head>',
            1,
        )

    source = re.sub(
        r'\s*<script[^>]+(?:msal-browser|alcdn\.msauth|cdn\.jsdelivr\.net/npm/@azure/msal-browser)[^>]*></script>\s*',
        "\n",
        source,
        flags=re.I,
    )

    module_set = set(MODULES)
    script_pattern = re.compile(
        r'\s*<script\b[^>]*\bsrc=["\']([^"\']+)["\'][^>]*></script>\s*',
        flags=re.I,
    )

    def remove_managed_script(match: re.Match[str]) -> str:
        return "\n" if basename_src(match.group(1)) in module_set else match.group(0)

    source = script_pattern.sub(remove_managed_script, source)
    if "</body>" not in source:
        raise RuntimeError("No se encontro </body> para insertar los modulos de Solicitud de Venta.")

    tags = "\n".join(
        f'  <script src="{module}?v={CACHE_VERSION}"></script>'
        for module in MODULES
    )
    source = source.replace("</body>", f"{tags}\n</body>", 1)

    names = [basename_src(src) for src in script_pattern.findall(source)]
    counts = Counter(names)
    for module in MODULES:
        if counts[module] != 1:
            raise RuntimeError(f"{module} aparece {counts[module]} veces; se esperaba exactamente 1.")

    path.write_text(source, encoding="utf-8")


def prepare_componentes() -> None:
    path = DEPLOY / "solicitud-venta" / "componentes.js"
    source = path.read_text(encoding="utf-8")
    sentinel = "window.__solicitudComponentesModuloActivo"
    if sentinel in source:
        return

    marker = "(() => {"
    if marker not in source:
        raise RuntimeError("No se encontro el inicio esperado de componentes.js.")
    replacement = (
        "(() => {\n"
        "  if (window.__solicitudComponentesModuloActivo) return;\n"
        "  window.__solicitudComponentesModuloActivo = true;"
    )
    path.write_text(source.replace(marker, replacement, 1), encoding="utf-8")


def prepare_pdf() -> None:
    lib_path = DEPLOY / "api" / "solicitud-venta" / "pdf-final-lib.php"
    lib_source = lib_path.read_text(encoding="utf-8")
    lib_source = lib_source.replace(
        "$this->text(self::MARGIN + 10.0, 41.0, 'JARDINES DE JUAN PABLO'",
        "$this->text(self::MARGIN + 90.0, 41.0, 'JARDINES DE JUAN PABLO'",
    )
    lib_source = lib_source.replace(
        "$this->text(self::MARGIN + 10.0, 58.0, $back ? 'SOLICITUD DE VENTA - REVERSO' : 'SOLICITUD DE VENTA'",
        "$this->text(self::MARGIN + 90.0, 58.0, $back ? 'SOLICITUD DE VENTA - REVERSO' : 'SOLICITUD DE VENTA'",
    )
    lib_path.write_text(lib_source, encoding="utf-8")

    layout_path = DEPLOY / "api" / "solicitud-venta" / "pdf-final-layout-v3.php"
    layout_source = layout_path.read_text(encoding="utf-8")

    if "svPdfAgregarLogoJdjp($pdf);" not in layout_source:
        marker = "$pdf = new SvPdfDocumento($folio);"
        if marker not in layout_source:
            raise RuntimeError("No se encontro el constructor del PDF V3 para insertar branding.")
        layout_source = layout_source.replace(
            marker,
            marker + "\n    svPdfAgregarLogoJdjp($pdf);",
            1,
        )
        layout_source = re.sub(
            r"(?m)^(\s*)svPdfV3NuevaPagina\(\$pdf\);\s*$",
            lambda match: (
                f"{match.group(1)}svPdfV3NuevaPagina($pdf);\n"
                f"{match.group(1)}svPdfAgregarLogoJdjp($pdf);"
            ),
            layout_source,
        )

    old_consent = (
        "El cliente manifiesta su conformidad con la información capturada en esta Solicitud de Venta "
        "y con las condiciones, importes, componentes y servicios asentados en el expediente digital del folio."
    )
    new_consent = (
        old_consent
        + " Asimismo, declara haber leído el Aviso de Privacidad de MEGUESA, S.A. de C.V. y autoriza "
        "el tratamiento de sus datos personales y, cuando corresponda, datos patrimoniales o financieros "
        "para elaborar, evaluar, formalizar, administrar y dar seguimiento a esta Solicitud de Venta. "
        "Aviso de Privacidad versión 11/09/2026; consentimiento solicitud-venta-2026-09-11-v1."
    )
    if new_consent not in layout_source:
        if old_consent not in layout_source:
            raise RuntimeError("No se encontro la declaracion de conformidad para agregar privacidad.")
        layout_source = layout_source.replace(old_consent, new_consent, 1)

    layout_path.write_text(layout_source, encoding="utf-8")


def validate_package() -> None:
    checks = [
        (DEPLOY / "solicitud-venta/componentes.js", "__solicitudComponentesModuloActivo"),
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

    index_source = (DEPLOY / "solicitud-venta/index.html").read_text(encoding="utf-8")
    if "msal-browser" in index_source.lower():
        raise RuntimeError("La interfaz aun intenta cargar MSAL independiente.")

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

    prepare_index()
    prepare_componentes()
    prepare_pdf()
    validate_package()


if __name__ == "__main__":
    build()
