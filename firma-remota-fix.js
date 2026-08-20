(() => {
  const INICIAR_ENDPOINT = "/api/solicitud-venta/iniciar-firma-remota.php";
  const PREPARAR_ENDPOINT = "/api/solicitud-venta/preparar-firma-remota.php";
  let puenteInstalado = false;

  function esRemota() {
    return document.getElementById("modalidadFirma")?.value === "REMOTA";
  }

  function vendedorConfirmadoVisualmente() {
    return Array.from(document.querySelectorAll('[data-signature-status="FIRMA_VENDEDOR"]')).some((node) =>
      /firma ya guardada en el expediente/i.test(String(node?.textContent || ""))
    );
  }

  function instalarPuenteExpediente() {
    if (puenteInstalado) return;

    const extras = window.solicitudVentaExtras;
    if (!extras || typeof extras.capturarEstadoExpediente !== "function") {
      setTimeout(instalarPuenteExpediente, 100);
      return;
    }

    if (extras.__firmaRemotaFixActivo) {
      puenteInstalado = true;
      return;
    }

    const capturarOriginal = extras.capturarEstadoExpediente.bind(extras);
    extras.capturarEstadoExpediente = () => {
      const actual = capturarOriginal() || {};
      const firmas = {
        ...(actual?.firmas && typeof actual.firmas === "object" ? actual.firmas : {})
      };

      // La firma del vendedor ya fue verificada contra SharePoint por el modulo
      // de seguimiento. Si la UI muestra el mensaje controlado de firma guardada,
      // no debemos volver a bloquear el envio por un booleano historico desfasado.
      if (vendedorConfirmadoVisualmente()) firmas.FIRMA_VENDEDOR = true;

      // En modalidad REMOTA la firma del cliente solo puede considerarse valida
      // despues de que la pagina publica reciba y guarde la firma. Cualquier true
      // heredado de pruebas/presencial se ignora mientras se prepara el enlace.
      if (esRemota()) firmas.FIRMA_CLIENTE = false;

      return {
        ...actual,
        firmas
      };
    };

    extras.__firmaRemotaFixActivo = true;
    puenteInstalado = true;
  }

  function obtenerItemId() {
    const value = String(new URLSearchParams(location.search).get("itemId") || "").trim();
    return /^\d+$/.test(value) && Number(value) > 0 ? String(Number(value)) : "";
  }

  function instalarPreflight() {
    if (window.__solicitudFirmaRemotaPreflightActivo) return;
    const fetchAnterior = window.fetch.bind(window);

    window.fetch = async function (input, init = {}) {
      const url = typeof input === "string" ? input : String(input?.url || "");
      const method = String(init?.method || "GET").toUpperCase();

      if (!url.includes(INICIAR_ENDPOINT) || method !== "POST") {
        return fetchAnterior(input, init);
      }

      let body = null;
      try {
        body = typeof init?.body === "string" ? JSON.parse(init.body) : null;
      } catch (_) {}

      const folio = String(body?.folio || "").trim().toUpperCase();
      if (!/^SV-\d{4}-\d{6,}$/.test(folio)) {
        return fetchAnterior(input, init);
      }

      const headersOriginales = new Headers(init?.headers || {});
      const headersPreflight = new Headers();
      headersPreflight.set("Content-Type", "application/json");
      const authorization = headersOriginales.get("Authorization");
      if (authorization) headersPreflight.set("Authorization", authorization);

      const response = await fetchAnterior(PREPARAR_ENDPOINT, {
        method: "POST",
        cache: "no-store",
        credentials: "same-origin",
        headers: headersPreflight,
        body: JSON.stringify({
          folio,
          itemId: obtenerItemId()
        })
      });

      const resultado = await response.json().catch(() => null);
      if (!response.ok || !resultado?.ok) {
        throw new Error(resultado?.message || resultado?.error || `No fue posible preparar la firma remota (HTTP ${response.status}).`);
      }

      // La fuente de verdad queda en SharePoint: titular PENDIENTE y vendedor FIRMADO.
      // A partir de aqui el endpoint original puede crear el enlace con seguridad.
      return fetchAnterior(input, init);
    };

    window.__solicitudFirmaRemotaPreflightActivo = true;
  }

  function iniciar() {
    instalarPuenteExpediente();
    instalarPreflight();

    // Document capture ocurre antes del listener capture instalado directamente
    // sobre el boton por firma-remota.js. Reinstalamos el puente justo antes del
    // clic por si otro modulo reemplazo el objeto de extras durante la recuperacion.
    document.addEventListener("click", (event) => {
      const button = event.target instanceof Element ? event.target.closest("#btnValidate") : null;
      if (!button || !esRemota()) return;
      puenteInstalado = false;
      instalarPuenteExpediente();
    }, true);
  }

  if (document.readyState === "loading") {
    document.addEventListener("DOMContentLoaded", iniciar);
  } else {
    iniciar();
  }
})();
