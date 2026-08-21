(() => {
  const INICIAR_ENDPOINT = "/api/solicitud-venta/iniciar-firma-remota.php";
  const PREPARAR_ENDPOINT = "/api/solicitud-venta/preparar-firma-remota.php";
  const GESTIONAR_ENDPOINT = "/api/solicitud-venta/gestionar-firma-remota.php";
  const BORRADOR_ENDPOINT = "/api/solicitud-venta/estado-borrador.php";
  const ESTADO_ENDPOINT = "/api/solicitud-venta/estado-solicitud.php";
  const CORRECCION_ENDPOINT = "/api/solicitud-venta/reabrir-correccion.php";
  const STORAGE_PREFIX = "solicitudVenta:borradorActivo:v1:";
  let puenteInstalado = false;
  let recuperando = false;
  let preparandoCorreccion = false;
  let correccionPreparada = false;

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

      if (vendedorConfirmadoVisualmente()) firmas.FIRMA_VENDEDOR = true;
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

  function marcarReferenciaCorreccion(folio, itemId) {
    const key = claveStorage();
    if (!key || !folio) return;
    try {
      const raw = localStorage.getItem(key);
      const actual = raw ? JSON.parse(raw) : {};
      localStorage.setItem(key, JSON.stringify({
        ...actual,
        folio,
        itemId: itemId || actual?.itemId || obtenerItemId(),
        firmaUrl: "",
        estatus: "BORRADOR",
        voboEstatus: "CORRECCION",
        actualizado: new Date().toISOString()
      }));
    } catch (error) {
      console.warn("No fue posible actualizar la referencia de correccion:", error);
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
      const url = String(document.getElementById("firmaRemotaUrl")?.value || "").trim();
      const estado = document.getElementById("firmaRemotaGestionEstado");
      if (estado && !url) {
        estado.textContent = "La liga anterior no esta disponible en este navegador. Genera una nueva para invalidar la anterior y compartirla con el cliente.";
      }
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
        <small id="firmaRemotaRecuperacionEstado">Si el enlace no aparece, genera uno nuevo. El enlace anterior quedara invalidado.</small>
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

    if (!window.confirm("Se invalidara el enlace anterior y se generara uno nuevo para el cliente. ¿Deseas continuar?")) return;

    recuperando = true;
    const button = document.getElementById("btnRecuperarFirmaRemota");
    if (button) button.disabled = true;

    try {
      mostrarMensaje("Generando un nuevo enlace de firma...");
      const token = await window.solicitudVentaAuth.getBackendAccessToken();
      if (!token) throw new Error("No fue posible obtener autorizacion.");

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
      if (!firmaUrl) throw new Error("El servidor no devolvio el nuevo enlace.");

      guardarEnlaceLocal(folio, firmaUrl);
      mostrarEnlace(firmaUrl);

      const estado = document.getElementById("firmaRemotaRecuperacionEstado");
      if (estado) estado.textContent = "Nuevo enlace generado. El enlace anterior quedo invalidado.";
      mostrarMensaje(`Nuevo enlace generado para ${folio}. Ya puedes copiarlo y compartirlo con el cliente.`, "ok");
    } catch (error) {
      console.error("Recuperar enlace de firma remota:", error);
      mostrarMensaje(`No fue posible generar el enlace: ${error.message || error}`, "error");
    } finally {
      recuperando = false;
      if (button) button.disabled = false;
    }
  }

  function correccionSolicitadaPorQuery() {
    return new URLSearchParams(location.search).get("correccion") === "1";
  }

  async function prepararCorreccion() {
    if (!correccionSolicitadaPorQuery() || preparandoCorreccion || correccionPreparada) return;
    if (!window.solicitudVentaAuth?.getBackendAccessToken) {
      setTimeout(prepararCorreccion, 120);
      return;
    }

    const folio = obtenerFolio();
    if (!folio) {
      setTimeout(prepararCorreccion, 120);
      return;
    }

    preparandoCorreccion = true;
    try {
      const token = await window.solicitudVentaAuth.getBackendAccessToken();
      if (!token) throw new Error("No fue posible obtener autorizacion para abrir la correccion.");

      const response = await fetch(CORRECCION_ENDPOINT, {
        method: "POST",
        cache: "no-store",
        credentials: "same-origin",
        headers: {
          "Content-Type": "application/json",
          Authorization: `Bearer ${token}`
        },
        body: JSON.stringify({
          folio,
          itemId: obtenerItemId()
        })
      });
      const data = await response.json().catch(() => null);
      if (!response.ok || !data?.ok) {
        throw new Error(data?.message || data?.error || `HTTP ${response.status}`);
      }

      correccionPreparada = true;
      marcarReferenciaCorreccion(folio, String(data?.itemId || obtenerItemId()));
      habilitarEdicionCorreccion(String(data?.motivo || ""));
    } catch (error) {
      console.error("Preparar correccion del vendedor:", error);
      mostrarMensaje(`No fue posible habilitar la correccion: ${error.message || error}`, "error");
    } finally {
      preparandoCorreccion = false;
    }
  }

  function habilitarEdicionCorreccion(motivo) {
    const pill = document.querySelector(".status-pill");
    if (pill) pill.textContent = "CORRECCION";

    delete document.body.dataset.solicitudEstatus;
    document.body.dataset.correccionActiva = "1";

    const guardar = document.getElementById("btnSaveDraft");
    if (guardar) guardar.disabled = false;

    const validar = document.getElementById("btnValidate");
    if (validar) {
      validar.disabled = false;
      validar.textContent = esRemota() ? "Enviar a firma" : "Validar solicitud";
    }

    const reset = document.getElementById("btnReset");
    if (reset) reset.disabled = false;

    mostrarAvisoCorreccion(motivo);
    const detalle = motivo ? ` Motivo: ${motivo}` : "";
    mostrarMensaje(`Solicitud devuelta a correccion. Atiende los cambios solicitados y vuelve a enviarla.${detalle}`, "ok");
  }

  function mostrarAvisoCorreccion(motivo) {
    const form = document.getElementById("solicitudForm");
    const banner = form?.querySelector(".form-banner");
    if (!form || !banner) return;

    let aviso = document.getElementById("solicitudCorreccionAviso");
    if (!aviso) {
      aviso = document.createElement("div");
      aviso.id = "solicitudCorreccionAviso";
      aviso.setAttribute("role", "status");
      aviso.style.cssText = "margin:14px 0;padding:16px 18px;border:1px solid #e0ad54;border-left:5px solid #b76b00;border-radius:12px;background:#fff8e8;color:#5a3300;line-height:1.45";
      banner.insertAdjacentElement("afterend", aviso);
    }

    const texto = String(motivo || "").trim();
    aviso.innerHTML = "";
    const titulo = document.createElement("strong");
    titulo.textContent = "Correccion solicitada por Vo.Bo.";
    const detalle = document.createElement("div");
    detalle.style.marginTop = "5px";
    detalle.textContent = texto || "Revisa la solicitud y atiende los cambios solicitados antes de volver a enviarla.";
    aviso.append(titulo, detalle);
  }

  function instalarPreflight() {
    if (window.__solicitudFirmaRemotaPreflightActivo) return;
    const fetchAnterior = window.fetch.bind(window);

    window.fetch = async function (input, init = {}) {
      const url = typeof input === "string" ? input : String(input?.url || "");
      const method = String(init?.method || "GET").toUpperCase();

      if (url.includes(BORRADOR_ENDPOINT) && method === "POST") {
        let body = null;
        try {
          body = typeof init?.body === "string" ? JSON.parse(init.body) : null;
        } catch (_) {}

        if (body?.accion === "cargar") {
          const response = await fetchAnterior(input, init);
          if (response.ok) return response;

          const errorData = await response.clone().json().catch(() => null);
          if (response.status === 409 && String(errorData?.error || "").trim().toUpperCase() === "DRAFT_NOT_EDITABLE") {
            const headers = new Headers(init?.headers || {});
            return fetchAnterior(ESTADO_ENDPOINT, {
              method: "POST",
              cache: "no-store",
              credentials: "same-origin",
              headers,
              body: JSON.stringify({
                accion: "cargar",
                folio: String(body?.folio || obtenerFolio()).trim().toUpperCase(),
                itemId: String(body?.itemId || obtenerItemId()).trim()
              })
            });
          }
          return response;
        }
      }

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
    prepararCorreccion();

    document.addEventListener("click", (event) => {
      const button = event.target instanceof Element ? event.target.closest("#btnValidate") : null;
      if (!button || !esRemota()) return;
      puenteInstalado = false;
      instalarPuenteExpediente();
    }, true);

    setTimeout(asegurarPanelRecuperacion, 500);
    setInterval(asegurarPanelRecuperacion, 900);
    window.addEventListener("focus", () => {
      asegurarPanelRecuperacion();
      prepararCorreccion();
    });
  }

  if (document.readyState === "loading") {
    document.addEventListener("DOMContentLoaded", iniciar);
  } else {
    iniciar();
  }
})();