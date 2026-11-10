#!/usr/bin/env python3
"""Construye una COPIA aislada de Solicitud de Venta. Nunca publica produccion.

Salida: _deploy_qa/{solicitud-venta-qa,api/solicitud-venta-qa,firma-qa}
No contiene secretos. El servidor leera unicamente la configuracion privada
/home/juanpab1/portal-config/solicitud-venta-qa.php para datos de QA.
"""
from __future__ import annotations

import shutil
import json
from pathlib import Path
from build_solicitud_package import ROOT, DEPLOY, build

DEST = ROOT / "_deploy_qa"
QA_SECRET_PATH = "/home/juanpab1/portal-config/solicitud-venta-qa.php"
PROD_CONFIG_PATH = "/home/juanpab1/portal-config/config.php"

QA_GUARD = r"""<?php
declare(strict_types=1);

/* Configuracion y permisos de QA, independientes de produccion. */
function svQaLoadConfig(): array
{
    $path = '/home/juanpab1/portal-config/solicitud-venta-qa.php';
    if (!is_file($path) || !is_readable($path)) {
        throw new RuntimeException('QA_CONFIG_NOT_FOUND');
    }
    $data = require $path;
    if (!is_array($data)) throw new RuntimeException('QA_CONFIG_INVALID');

    $expected = [
        'solicitud_backend_tenant_id' => '888d54c0-f785-49d1-b967-54da8b0aed94',
        'solicitud_backend_client_id' => 'f03ffff5-fd4e-488f-93b6-bcddaedad9d8',
        'solicitud_sharepoint_site_id' => 'meguesajdjp.sharepoint.com,655df2aa-df51-4e2a-ade5-53bdcd9a3b0c,359048b1-c034-4b49-9cdc-c93beeccc008',
        'solicitud_sharepoint_list_id' => '8494b26b-5bdb-4d60-a993-6f53856f6b6f',
    ];
    foreach ($expected as $key => $value) {
        if (!isset($data[$key]) || !is_string($data[$key])
            || !hash_equals($value, trim($data[$key]))) {
            throw new RuntimeException('QA_CONFIG_RESOURCE_MISMATCH:' . $key);
        }
    }
    if (($data['solicitud_qa_mode'] ?? null) !== true
        || ($data['solicitud_qa_email_enabled'] ?? null) !== false) {
        throw new RuntimeException('QA_SAFETY_FLAGS_REQUIRED');
    }
    if (trim((string) ($data['solicitud_backend_client_secret'] ?? '')) === '') {
        throw new RuntimeException('QA_SECRET_MISSING');
    }
    return $data;
}

function svQaAllowedEmail(string $email): bool
{
    // Se habilitaran mas usuarios de prueba solo cuando se autorice su acceso.
    return hash_equals('sistemas@juanpablo.com.mx', strtolower(trim($email)));
}
"""

QA_DIAGNOSTIC = r"""<?php
declare(strict_types=1);
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, max-age=0');
header('X-Robots-Tag: noindex, nofollow');
header('X-Content-Type-Options: nosniff');

require_once rtrim((string) ($_SERVER['DOCUMENT_ROOT'] ?? ''), '/')
    . '/api/solicitud-venta-qa/_common.php';

function replyQa(int $status, array $body): void {
    http_response_code($status);
    echo json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'GET') {
    replyQa(405, ['ok' => false, 'error' => 'METHOD_NOT_ALLOWED']);
}
try {
    $cfg = svConfig();
    // Solo sesion compartida del Portal y cuenta autorizada.
    $claims = svUsuarioAutenticado($cfg['tenantId'], $cfg['clientId']);
    $token = svGraphToken($cfg['tenantId'], $cfg['clientId'], $cfg['clientSecret']);
    $site = svCurlJson(
        'https://graph.microsoft.com/v1.0/sites/' . rawurlencode($cfg['siteId']) . '?$select=id,webUrl',
        'GET',
        ['Authorization: Bearer ' . $token, 'Accept: application/json']
    );
    $list = svCurlJson(
        'https://graph.microsoft.com/v1.0/sites/' . rawurlencode($cfg['siteId'])
            . '/lists/' . rawurlencode($cfg['listId']) . '?$select=id,name',
        'GET',
        ['Authorization: Bearer ' . $token, 'Accept: application/json']
    );
    $drives = svCurlJson(
        'https://graph.microsoft.com/v1.0/sites/' . rawurlencode($cfg['siteId'])
            . '/drives?$select=id,name',
        'GET',
        ['Authorization: Bearer ' . $token, 'Accept: application/json']
    );
    $libraryFound = false;
    foreach (($drives['value'] ?? []) as $drive) {
        if (($drive['name'] ?? '') === 'Expedientes_Ventas') $libraryFound = true;
    }
    $ok = ($site['id'] ?? '') === $cfg['siteId']
        && ($list['id'] ?? '') === $cfg['listId']
        && $libraryFound;
    replyQa($ok ? 200 : 502, [
        'ok' => $ok,
        'entorno' => 'QA',
        'sitio' => 'SolicitudVentaQA',
        'lista' => 'Solicitudes_Venta',
        'biblioteca' => 'Expedientes_Ventas',
        'auth' => 'PORTAL_SESSION',
        'modo' => 'SOLO_LECTURA',
        'correos' => 'DESHABILITADOS'
    ]);
} catch (Throwable $e) {
    // El detalle tecnico solo en logs. No exponer IDs, tokens o secretos.
    error_log('Diagnostico Solicitud Venta QA: ' . $e->getMessage());
    replyQa(503, ['ok' => false, 'entorno' => 'QA', 'error' => 'QA_CHECK_FAILED']);
}
"""

def exact_replace(source: str, needle: str, replacement: str, label: str) -> str:
    count = source.count(needle)
    if count != 1:
        raise RuntimeError(f"QA_BUILD_FAILED {label}: {count} apariciones; esperado 1")
    return source.replace(needle, replacement)

def replace_config(path: Path, raw_variable: str) -> None:
    s = path.read_text(encoding="utf-8")
    s = exact_replace(s, PROD_CONFIG_PATH, QA_SECRET_PATH, str(path))
    s = exact_replace(s, f"{raw_variable} = require $configPath;",
                      f"{raw_variable} = svQaLoadConfig();", str(path) + " require")
    path.write_text(s, encoding="utf-8")

def build_qa() -> None:
    # Construct an ordinary bundle first, but NEVER run its deployment script.
    build()
    if DEST.exists():
        shutil.rmtree(DEST)
    shutil.copytree(DEPLOY, DEST)
    (DEST / "solicitud-venta").rename(DEST / "solicitud-venta-qa")
    (DEST / "api" / "solicitud-venta").rename(DEST / "api" / "solicitud-venta-qa")
    (DEST / "firma").rename(DEST / "firma-qa")

    for file in DEST.rglob("*"):
        if not file.is_file() or file.suffix.lower() not in {".php", ".html", ".js", ".css"}:
            continue
        s = file.read_text(encoding="utf-8")
        s = s.replace("/api/solicitud-venta/", "/api/solicitud-venta-qa/")
        s = s.replace("/solicitud-venta/", "/solicitud-venta-qa/")
        s = s.replace("/firma/", "/firma-qa/")
        # Separar estado guardado en navegador para no interferir con ventas reales.
        s = s.replace("solicitudVenta:", "solicitudVentaQA:")
        file.write_text(s, encoding="utf-8")

    api = DEST / "api" / "solicitud-venta-qa"
    (api / "_qa_guard.php").write_text(QA_GUARD, encoding="utf-8")

    common = api / "_common.php"
    content = common.read_text(encoding="utf-8")
    content = exact_replace(content, "declare(strict_types=1);",
                            "declare(strict_types=1);\nrequire_once __DIR__ . '/_qa_guard.php';",
                            "QA guard require")
    content = content.replace(PROD_CONFIG_PATH, QA_SECRET_PATH, 1)
    content = exact_replace(content, "$raw = require $configPath;", "$raw = svQaLoadConfig();",
                            "svConfig QA")
    original_auth = """    $portalClaims = svUsuarioSesionPortal($tenantId);
    if ($portalClaims !== null) {
        return $portalClaims;
    }"""
    safe_auth = """    $portalClaims = svUsuarioSesionPortal($tenantId);
    if ($portalClaims === null) {
        svResponderError(401, 'QA_SESSION_REQUIRED', 'Inicia sesion en el Portal para acceder a QA.');
    }
    $emailQa = strtolower(trim((string) ($portalClaims['preferred_username'] ?? $portalClaims['upn'] ?? '')));
    if (!svQaAllowedEmail($emailQa)) {
        svResponderError(403, 'QA_USER_FORBIDDEN', 'La cuenta no esta autorizada para el entorno QA.');
    }
    return $portalClaims;"""
    content = exact_replace(content, original_auth, safe_auth, "QA portal-only authentication")
    common.write_text(content, encoding="utf-8")

    for basename, variable in [
        ("borrador.php", "$config"),
        ("archivos.php", "$config"),
        ("notificaciones.php", "$raw"),
        ("sharepoint-grupos.php", "$raw"),
    ]:
        replace_config(api / basename, variable)

    auth = api / "autorizacion.php"
    content = auth.read_text(encoding="utf-8")
    content = exact_replace(
        content,
        "    if (!is_array($claims)) return [];",
        """    if (!is_array($claims)) return [];
    $emailQa = strtolower(trim((string) ($claims['preferred_username'] ?? $claims['upn'] ?? '')));
    if (!svQaAllowedEmail($emailQa)) return [];""",
        "QA allowed user"
    )
    original_context = """    if ($email === '') {
        $fallback['error'] = 'La sesion no contiene correo electronico.';
        return $fallback;
    }"""
    content = exact_replace(
        content, original_context,
        original_context + """
    // QA no consulta grupos del SharePoint de produccion.
    // Cuenta de sistemas: todos los roles funcionales, exclusivamente QA.
    if (svQaAllowedEmail($email)) {
        return [
            'resolved' => true,
            'groups' => [],
            'roles' => ['ADMINISTRADOR', 'VENDEDOR', 'GERENCIA_COMERCIAL', 'COBRANZA'],
            'functional_permissions' => [],
            'checked_at' => time(),
            'error' => '',
        ];
    }
    $fallback['error'] = 'QA_USER_FORBIDDEN';
    return $fallback;""",
        "QA roles"
    )
    auth.write_text(content, encoding="utf-8")

    mail = api / "notificaciones.php"
    content = mail.read_text(encoding="utf-8")
    needle = """    $sender = strtolower(trim($sender));"""
    replacement = """    // PROHIBIDO enviar correos de produccion desde el entorno QA.
    // No se ejecuta ninguna peticion Graph /sendMail.
    throw new RuntimeException('QA_EMAIL_DISABLED');
"""
    content = exact_replace(content, needle, replacement + needle, "QA mail block")
    mail.write_text(content, encoding="utf-8")

    # El expediente final utiliza Graph /users/.../messages directamente y NO
    # pasa por svEnviarCorreoGraph. Bloquear su endpoint completo en QA para que
    # ninguna prueba cree borradores ni envie mensajes por esos caminos.
    final_email = api / "notificar-expediente-final.php"
    content = final_email.read_text(encoding="utf-8")
    entry = "require_once __DIR__ . '/autorizacion.php';"
    deny = """// En QA el correo final es SOLO una prueba de bloqueo. No crear emails.
http_response_code(409);
echo json_encode([
    'ok' => false,
    'error' => 'QA_EMAIL_DISABLED',
    'message' => 'Entorno QA: no se envia el expediente final ni se crean mensajes en Microsoft 365.'
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
exit;

"""
    content = exact_replace(content, entry, deny + entry, "QA no final email")
    final_email.write_text(content, encoding="utf-8")

    # El endpoint de notificacion a Cobranza tampoco debe utilizarse para
    # probar entregas reales: mantener las transiciones pero negar el envio.
    cobranza_email = api / "notificar-cobranza.php"
    content = cobranza_email.read_text(encoding="utf-8")
    content = exact_replace(
        content, entry,
        deny.replace("expediente final", "aviso a Cobranza") + entry,
        "QA no cobranza email"
    )
    cobranza_email.write_text(content, encoding="utf-8")

    # Mostrar entorno QA y bloquear la interfaz a usuarios distintos de Sistemas.
    # Este filtro de interfaz complementa el bloqueo existente de endpoints;
    # las paginas NO deben aceptar a cualquier usuario del Portal.
    banner = (
        '<div role="status" style="padding:13px;text-align:center;'
        'background:#ffe5a1;color:#422800;font:700 15px system-ui">'
        'ENTORNO DE PRUEBAS QA - SOLO DATOS FICTICIOS - NO ENVIAR CLIENTES REALES'
        '</div>'
    )
    for page in [
        DEST / "solicitud-venta-qa" / "index.php",
        DEST / "solicitud-venta-qa" / "inicio" / "index.php",
        DEST / "solicitud-venta-qa" / "vobo" / "index.php",
    ]:
        content = page.read_text(encoding="utf-8")
        content = exact_replace(
            content, "$user = svSolicitudUsuario();",
            """$user = svSolicitudUsuario();
if (!svQaAllowedEmail((string) ($user['email'] ?? ''))) {
    http_response_code(403);
    exit('Acceso QA exclusivo para cuenta Sistemas.');
}""",
            "QA UI user guard " + str(page)
        )
        if page.parent == DEST / "solicitud-venta-qa":
            content = exact_replace(
                content,
                "echo $source;",
                "$source = str_replace('<body>', '<body>' . " + json.dumps(banner, ensure_ascii=False) + ", $source);\necho $source;",
                "QA main banner"
            )
        else:
            content = exact_replace(content, "<body>", "<body>\n" + banner, "QA page banner")
        page.write_text(content, encoding="utf-8")

    # La firma publica se mantiene como pagina de prueba, señalizada
    # e independiente. Sus endpoints solo usan credenciales y lista QA.
    public_signature = DEST / "firma-qa" / "index.html"
    content = public_signature.read_text(encoding="utf-8")
    content = exact_replace(content, "<body>", "<body>\n" + banner, "QA signature banner")
    public_signature.write_text(content, encoding="utf-8")

    # Garantias adicionales: el codigo final del endpoint nunca debe
    # alcanzar Graph Mail y la interfaz no debe aceptar otras cuentas.
    assert "QA_EMAIL_DISABLED" in final_email.read_text(encoding="utf-8")
    assert "QA_EMAIL_DISABLED" in cobranza_email.read_text(encoding="utf-8")
    assert "Acceso QA exclusivo" in (DEST / "solicitud-venta-qa" / "index.php").read_text(encoding="utf-8")

    (DEST / "solicitud-venta-qa" / "diagnostico.php").write_text(
        QA_DIAGNOSTIC, encoding="utf-8"
    )
    # Solo prueba manual de archivo temporal, sin incluir otras pantallas.
    qa_write_probe = ROOT / "qa" / "prueba-escritura.php"
    require_qa_probe = qa_write_probe.read_text(encoding="utf-8")
    if "svUsuarioAutenticado(" not in require_qa_probe or "svCurlJson(" not in require_qa_probe:
        raise RuntimeError("QA_WRITE_PROBE_INCOMPLETE")
    (DEST / "solicitud-venta-qa" / "prueba-escritura.php").write_text(
        require_qa_probe, encoding="utf-8"
    )
    qa_list_probe = (ROOT / "qa" / "prueba-lista.php").read_text(encoding="utf-8")
    if "svUsuarioAutenticado(" not in qa_list_probe or "svCurlJson(" not in qa_list_probe:
        raise RuntimeError("QA_LIST_PROBE_INCOMPLETE")
    (DEST / "solicitud-venta-qa" / "prueba-lista.php").write_text(
        qa_list_probe, encoding="utf-8"
    )
    qa_schema = (ROOT / "qa" / "diagnostico-esquema.php").read_text(encoding="utf-8")
    if "svUsuarioAutenticado(" not in qa_schema or "QA_SCHEMA_CHECK_FAILED" not in qa_schema:
        raise RuntimeError("QA_SCHEMA_DIAGNOSTIC_INCOMPLETE")
    (DEST / "solicitud-venta-qa" / "diagnostico-esquema.php").write_text(
        qa_schema, encoding="utf-8"
    )
    qa_schema_adjust = (ROOT / "qa" / "ajustar-esquema.php").read_text(encoding="utf-8")
    if "svUsuarioAutenticado(" not in qa_schema_adjust or "svCurlJson(" not in qa_schema_adjust:
        raise RuntimeError("QA_SCHEMA_ADJUST_INCOMPLETE")
    (DEST / "solicitud-venta-qa" / "ajustar-esquema.php").write_text(
        qa_schema_adjust, encoding="utf-8"
    )

    # Checks que fallan antes de generar un paquete publicable.
    assert QA_SECRET_PATH in common.read_text(encoding="utf-8")
    assert PROD_CONFIG_PATH in common.read_text(encoding="utf-8"), (
        "La sesion SSO debe conservar su config, sin acceder a Graph de produccion")
    for f in DEST.rglob("*"):
        if not f.is_file() or f.suffix not in {".php", ".html", ".js"}:
            continue
        s = f.read_text(encoding="utf-8")
        if "/api/solicitud-venta/" in s or "/solicitud-venta/" in s or "/firma/" in s:
            raise RuntimeError(f"QA_RUNTIME_ROUTE_PRODUCTION: {f}")
        if PROD_CONFIG_PATH in s and f != common:
            raise RuntimeError(f"QA_PROD_CONFIG_REFERENCE: {f}")
    print("QA paquete construido: rutas QA, config privada QA, sin correos, sin credenciales en GitHub.")
    print("Contenido:", *sorted(str(p.relative_to(DEST)) for p in DEST.iterdir()), sep="\n  ")

if __name__ == "__main__":
    build_qa()
