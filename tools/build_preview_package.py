from __future__ import annotations

import shutil
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
SOURCE = ROOT / "_deploy" / "solicitud-venta"
OUT_ROOT = ROOT / "_preview"
OUT = OUT_ROOT / "solicitud-venta-preview"

ALLOWED_EMAILS = {
    "gabriel.guerra@juanpablo.com.mx",
}

GUARD_JS = r"""(() => {
  'use strict';

  window.SOLICITUD_VENTA_PREVIEW = true;

  const nativeFetch = window.fetch.bind(window);

  function requestInfo(input, init) {
    const request = input instanceof Request ? input : null;
    const url = new URL(request ? request.url : String(input), window.location.origin);
    const method = String(init?.method || request?.method || 'GET').toUpperCase();
    const body = init?.body ?? null;
    return { url, method, body };
  }

  function accionSegura(body) {
    if (typeof body !== 'string' || !body.trim().startsWith('{')) return false;
    try {
      const data = JSON.parse(body);
      const accion = String(data?.accion || '').toLowerCase();
      return ['cargar', 'listar', 'detalle'].includes(accion);
    } catch (_) {
      return false;
    }
  }

  window.fetch = async (input, init = {}) => {
    const info = requestInfo(input, init);
    const esApiSolicitud = info.url.pathname.startsWith('/api/solicitud-venta/');
    const metodoLectura = ['GET', 'HEAD', 'OPTIONS'].includes(info.method);
    const postLectura = info.method === 'POST' && accionSegura(info.body);

    if (esApiSolicitud && !metodoLectura && !postLectura) {
      console.warn('[PREVIEW] Escritura bloqueada:', info.method, info.url.pathname);
      return new Response(JSON.stringify({
        ok: false,
        preview: true,
        error: 'PREVIEW_WRITE_BLOCKED',
        message: 'Entorno de pruebas: las escrituras a producción están deshabilitadas.'
      }), {
        status: 423,
        headers: { 'Content-Type': 'application/json; charset=utf-8' }
      });
    }

    return nativeFetch(input, init);
  };

  function bloquearAcciones() {
    const patrones = /guardar|enviar|validar solicitud|aprobar|solicitar correcci[oó]n|firmar|subir|adjuntar/i;

    document.querySelectorAll('button, input[type="submit"]').forEach((control) => {
      const texto = String(control.textContent || control.value || '').trim();
      if (!patrones.test(texto)) return;
      control.disabled = true;
      control.title = 'Deshabilitado en el entorno Preview para proteger producción.';
      control.dataset.previewBlocked = '1';
    });
  }

  function instalarBanner() {
    if (document.getElementById('solicitudPreviewBanner')) return;
    const banner = document.createElement('div');
    banner.id = 'solicitudPreviewBanner';
    banner.innerHTML = '<strong>ENTORNO DE PRUEBAS</strong><span>No guarda, no envía correos y no modifica producción.</span>';
    document.body.prepend(banner);

    const style = document.createElement('style');
    style.textContent = `
      #solicitudPreviewBanner {
        position: sticky; top: 0; z-index: 100000;
        display: flex; align-items: center; justify-content: center; gap: 12px;
        padding: 9px 16px; background: #fff3cd; border-bottom: 1px solid #d6b656;
        color: #5b4300; font: 600 13px/1.3 Arial, sans-serif;
      }
      #solicitudPreviewBanner strong { letter-spacing: .04em; }
      [data-preview-blocked="1"] { opacity: .48 !important; cursor: not-allowed !important; }
    `;
    document.head.appendChild(style);
  }

  function iniciar() {
    document.title = '[PRUEBAS] ' + document.title;
    instalarBanner();
    bloquearAcciones();

    const observer = new MutationObserver(() => bloquearAcciones());
    observer.observe(document.body, { subtree: true, childList: true });
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', iniciar, { once: true });
  } else {
    iniciar();
  }
})();
"""


def main() -> None:
    if not SOURCE.is_dir():
        raise RuntimeError("Primero debe ejecutarse tools/build_solicitud_package.py")

    if OUT_ROOT.exists():
        shutil.rmtree(OUT_ROOT)
    shutil.copytree(SOURCE, OUT)

    # El Preview solo expone la captura. No publicamos bandejas ni Vo.Bo. para
    # evitar que una URL de pruebas pueda ejecutar flujos operativos.
    for operational_dir in ("inicio", "vobo"):
        target = OUT / operational_dir
        if target.exists():
            shutil.rmtree(target)

    index_php = OUT / "index.php"
    source = index_php.read_text(encoding="utf-8")

    marker = "$user = svSolicitudUsuario();"
    if marker not in source:
        raise RuntimeError("No se encontro el punto de control de usuario en index.php")

    allowed = ", ".join(repr(v) for v in sorted(ALLOWED_EMAILS))
    guard_php = marker + f"""
$previewAllowedEmails = [{allowed}];
$previewEmail = strtolower(trim((string) ($user['email'] ?? '')));
if (!in_array($previewEmail, $previewAllowedEmails, true)) {{
    http_response_code(403);
    exit('Entorno de pruebas restringido.');
}}
"""
    source = source.replace(marker, guard_php, 1)
    source = source.replace("/solicitud-venta/inicio/", "/solicitud-venta-preview/")
    index_php.write_text(source, encoding="utf-8")

    index_html = OUT / "index.html"
    html = index_html.read_text(encoding="utf-8")
    script_marker = '<script src="config.js"></script>'
    if script_marker not in html:
        raise RuntimeError("No se encontro config.js en index.html")
    html = html.replace(
        script_marker,
        '<script src="preview-guard.js?v=20261002-1"></script>\n  ' + script_marker,
        1,
    )
    index_html.write_text(html, encoding="utf-8")

    (OUT / "preview-guard.js").write_text(GUARD_JS, encoding="utf-8")

    print("Preview construido en _preview/solicitud-venta-preview")
    print("Acceso permitido:", ", ".join(sorted(ALLOWED_EMAILS)))


if __name__ == "__main__":
    main()
