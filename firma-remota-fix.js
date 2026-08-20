(() => {
  const INICIAR_ENDPOINT = "/api/solicitud-venta/iniciar-firma-remota.php";
  const PREPARAR_ENDPOINT = "/api/solicitud-venta/preparar-firma-remota.php";
  const GESTIONAR_ENDPOINT = "/api/solicitud-venta/gestionar-firma-remota.php";
  const STORAGE_PREFIX = "solicitudVenta:borradorActivo:v1:";
  let puenteInstalado = false;
  let recuperando = false;

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

  function obtenerFolio() {
    const visible = document.querySelector(".folio-box strong")?.textContent?.trim().toUpperCase() || "";
    if (/^SV-\d{4}-\d+$/.test(visible)) return visible;
    const query = String(new URLSearchParams(location.search).get("folio") || "").trim().toUpperCase();
    return /^SV-\d{4}-\d+$/.test(query) ? query : "";
  }

  function claveStorage() {
    const usuario = window.solicitudVentaAuth?.getUser?.();
    const correo = String(usuario?.username || document.getElementById("userEmail")?.textContent || "").trim().toLowerCase();
    return correo ? `${STORAGE_PREFIX}${correo}` : "";
  }

  function leerReferenciaLocal() {
    const key = claveStorage();
    if (!key) return null;
    try {
      const raw = localStorage.getItem(key);
      return raw ? JSON.parse(raw) : null;
    } catch (_) {
      return null;
    }
  }

  function guardarEnlaceLocal(folio, firmaUrl) {
    const key = claveStorage();
    if (!key || !folio || !firmaUrl) return;
    try {
      const raw = localStorage.getItem(key);
      const actual = raw ? JSON.parse(raw) : {};
      const itemId = actual?.itemId || obtenerItemId() || String(Number(folio.split("-").pop() || 0) || "");
      localStorage.setItem(key, JSON.stringify({
        ...actual,
        folio,
        itemId,
        firmaUrl,
        estatus: "PENDIENTE FIRMA",
        actualizado: new Date().toISOString()
      }));
    } catch (error) {
      console.warn("No fue posible conservar el enlace de firma remota:", error);
    }
  }

  function mostrarEnlace(firmaUrl) {
    const url = String(firmaUrl || "").trim();
    if (!url) return;
    const input = document.getElementById("firmaRemotaUrl");
    const resultBox = document.getElementById("firmaRemotaResultado");
    if (input) input.value = url;
    if (resultBox) resultBox.hidden = false;
  }

  function restaurarEnlaceLocal() {
    const folio = obtenerFolio();
    const referencia = leerReferenciaLocal();
    const folioGuardado = String(referencia?.folio || "").trim().toUpperCase();
    const firmaUrl = String(referencia?.firmaUrl || "").trim();
    if (!folio || folioGuardado !== folio || !firmaUrl) return;
    mostrarEnlace(firmaUrl);
  }

  function solicitudPendienteFirma() {
    const body = String(document.body.dataset.solicitudEstatus || "").trim().toUpperCase();
    const pill = String(document.querySelector(".status-pill")?.textContent || "").trim().toUpperCase();
    const boton = String(document.getElementById("btnValidate")?.textContent || "").trim().toUpperCase();
    const referencia = String(leerReferenciaLocal()?.estatus || "").trim().toUpperCase();
    return body.includes("PENDIENTE FIRMA")
      || pill.includes("PENDIENTE FIRMA")
      || boton.includes("PENDIENTE DE FIRMA")
      || referencia === "PENDIENTE FIRMA";
  }

  function asegurarPanelRecuperacion() {
    restaurarEnlaceLocal();
    if (!solicitudPendienteFirma()) return;

    const gestionExistente = document.getElementById("firmaRemotaGestion");
    if (gestionExistente) {
      gestionExistente.hidden = false;
      return;
    }

    if (document.getElementById("firmaRemotaRecuperacionFix")) return;
    const section = document.getElementById("firmasSection");
    if (!section) return;

    const panel = document.createElement("div");
    panel.id = "firmaRemotaRecuperacionFix";
    panel.className = "remote-signature-management";
    panel.innerHTML = `
      <div class="remote-signature-management-copy">
        <strong>Enlace de firma del cliente</strong>
        <small id="firmaRemotaRecuperacionEstado">Si el enlace no aparece, genera uno nuevo. El enlace anterior quedará invalidado.</small>
      </div>
      <div class="remote-signature-management-actions">
        <button id="btnRecuperarFirmaRemota" type="button">Generar nuevo enlace</button>
      </div>`;

    const modo = section.querySelector(".remote-signature-mode");
    if (modo?.nextSibling) section.insertBefore(panel, modo.nextSibling);
    else section.appendChild(panel);

    document.getElementById("btnRecuperarFirmaRemota")?.addEventListener("click", regenerarEnlacePerdido);
  }

  async function regenerarEnlacePerdido() {
    if (recuperando) return;
    const folio = obtenerFolio();
    if (!folio) return mostrarMensaje("No fue posible identificar el folio de la solicitud.", "error");

    if (!window.confirm("Se invalidará el enlace anterior y se generará uno nuevo para el cliente. ¿Deseas continuar?")) return;

    recuperando = true;
    const button = document.getElementById("btnRecuperarFirmaRemota");
    if (button) button.disabled = true;

    try {
      mostrarMensaje("Generando un nuevo enlace de firma...");
      const token = await window.solicitudVentaAuth.getBackendAccessToken();
      if (!token) throw new Error("No fue posible obtener autorización.");

      const response = await fetch(GESTIONAR_ENDPOINT, {
        method: "POST",
        cache: "no-store",
        headers: {
          "Content-Type": "application/json",
          Authorization: `Bearer ${token}`
        },
        body: JSON.stringify({ accion: "regenerar", folio })
      });
      const data = await response.json().catch(() => null);
      if (!response.ok || !data?.ok) throw new Error(data?.message || data?.error || `HTTP ${response.status}`);

      const firmaUrl = String(data?.firmaUrl || "").trim();
      if (!firmaUrl) throw new Error("El servidor no devolvió el nuevo enlace.");

      guardarEnlaceLocal(folio, firmaUrl);
      mostrarEnlace(firmaUrl);

      const estado = document.getElementById("firmaRemotaRecuperacionEstado");
      if (estado) estado.textContent = "Nuevo enlace generado. El enlace anterior quedó invalidado.";
      mostrarMensaje(`Nuevo enlace generado para ${folio}. Ya puedes copiarlo y compartirlo con el cliente.`, "ok");
    } catch (error) {
      console.error("Recuperar enlace de firma remota:", error);
      mostrarMensaje(`No fue posible generar el enlace: ${error.message || error}`, "error");
    } finally {
      recuperando = false;
      if (button) button.disabled = false;
    }
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

      // Ejecutamos el endpoint original y capturamos una copia de su respuesta
      // antes de que firma-remota.js la consuma. Asi la URL queda persistida en el
      // mismo instante en que el servidor la genera y no se pierde al bloquear o
      // repintar el formulario como PENDIENTE FIRMA.
      const finalResponse = await fetchAnterior(input, init);
      try {
        const copia = finalResponse.clone();
        const data = await copia.json().catch(() => null);
        const firmaUrl = String(data?.firmaUrl || "").trim();
        const folioRespuesta = String(data?.folio || folio).trim().toUpperCase();
        if (finalResponse.ok && data?.ok && firmaUrl && /^SV-\d{4}-\d+$/.test(folioRespuesta)) {
          guardarEnlaceLocal(folioRespuesta, firmaUrl);
          mostrarEnlace(firmaUrl);
        }
      } catch (error) {
        console.warn("No fue posible persistir inmediatamente el enlace remoto:", error);
      }
      return finalResponse;
    };

    window.__solicitudFirmaRemotaPreflightActivo = true;
  }

  function mostrarMensaje(texto, tipo = "") {
    if (typeof window.mostrarMensaje === "function") {
      window.mostrarMensaje(texto, tipo);
      return;
    }
    const mensaje = document.getElementById("formMessage");
    if (!mensaje) return;
    mensaje.textContent = texto;
    mensaje.className = `form-message ${tipo}`.trim();
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

    setTimeout(asegurarPanelRecuperacion, 500);
    setInterval(asegurarPanelRecuperacion, 900);
    window.addEventListener("focus", asegurarPanelRecuperacion);
  }

  if (document.readyState === "loading") {
    document.addEventListener("DOMContentLoaded", iniciar);
  } else {
    iniciar();
  }
})();