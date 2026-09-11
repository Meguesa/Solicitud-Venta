(() => {
  const ENDPOINT = "/api/solicitud-venta/gestionar-firma-remota.php";
  const ESTADO_ENDPOINT = "/api/solicitud-venta/estado-solicitud.php";
  const STORAGE_PREFIX = "solicitudVenta:borradorActivo:v1:";
  let inicializado = false;
  let procesando = false;
  let lugarRestaurado = false;

  function iniciar() {
    if (inicializado) return;
    const section = document.getElementById("firmasSection");
    if (!section || !window.solicitudVentaAuth) {
      setTimeout(iniciar, 100);
      return;
    }

    inicializado = true;
    insertarPanel(section);
    restaurarEnlaceLocal();
    actualizarPanel();
    setTimeout(restaurarLugarDesdeServidor, 250);
    setInterval(() => {
      restaurarEnlaceLocal();
      actualizarPanel();
    }, 900);
  }

  if (document.readyState === "loading") {
    document.addEventListener("DOMContentLoaded", iniciar);
  } else {
    iniciar();
  }

  function insertarPanel(section) {
    if (document.getElementById("firmaRemotaGestion")) return;

    const panel = document.createElement("div");
    panel.id = "firmaRemotaGestion";
    panel.className = "remote-signature-management";
    panel.hidden = true;
    panel.innerHTML = `
      <div class="remote-signature-management-copy">
        <strong>Gestionar enlace de firma</strong>
        <small id="firmaRemotaGestionEstado">Puedes invalidar el enlace actual o generar uno nuevo.</small>
      </div>
      <div class="remote-signature-management-actions">
        <button id="btnRegenerarFirmaRemota" type="button">Regenerar enlace</button>
        <button id="btnCancelarFirmaRemota" type="button" class="remote-signature-cancel">Cancelar enlace</button>
      </div>`;

    const modo = section.querySelector(".remote-signature-mode");
    if (modo?.nextSibling) section.insertBefore(panel, modo.nextSibling);
    else section.appendChild(panel);

    document.getElementById("btnRegenerarFirmaRemota")?.addEventListener("click", regenerarEnlace);
    document.getElementById("btnCancelarFirmaRemota")?.addEventListener("click", cancelarEnlace);
  }

  function actualizarPanel() {
    const panel = document.getElementById("firmaRemotaGestion");
    if (!panel) return;

    const estatus = String(document.body.dataset.solicitudEstatus || document.querySelector(".status-pill")?.textContent || "")
      .trim().toUpperCase();
    const textoBoton = document.getElementById("btnValidate")?.textContent?.trim().toUpperCase() || "";
    const textoMensaje = document.getElementById("formMessage")?.textContent?.trim().toUpperCase() || "";
    const referencia = leerReferenciaLocal();
    const estatusLocal = String(referencia?.estatus || "").trim().toUpperCase();
    const pendiente = estatus.includes("PENDIENTE FIRMA")
      || textoBoton === "PENDIENTE DE FIRMA"
      || textoBoton === "PENDIENTE FIRMA"
      || textoMensaje.includes("PENDIENTE DE FIRMA")
      || textoMensaje.includes("PENDIENTE FIRMA")
      || estatusLocal === "PENDIENTE FIRMA";

    panel.hidden = !pendiente;
    if (!pendiente) return;

    const url = String(document.getElementById("firmaRemotaUrl")?.value || referencia?.firmaUrl || "").trim();
    const regenerar = document.getElementById("btnRegenerarFirmaRemota");
    const cancelar = document.getElementById("btnCancelarFirmaRemota");
    const estado = document.getElementById("firmaRemotaGestionEstado");

    if (regenerar) {
      regenerar.disabled = procesando;
      regenerar.textContent = url ? "Regenerar enlace" : "Generar enlace";
    }
    if (cancelar) cancelar.disabled = procesando;
    if (estado && !procesando) {
      estado.textContent = url
        ? "El enlace de firma está disponible. Puedes copiarlo o regenerarlo si necesitas invalidar el anterior."
        : "La solicitud está pendiente de firma, pero el enlace no está disponible en este navegador. Genera uno nuevo para continuar.";
    }
  }

  function restaurarEnlaceLocal() {
    const referencia = leerReferenciaLocal();
    const folio = obtenerFolio();
    const folioGuardado = String(referencia?.folio || "").trim().toUpperCase();
    const url = String(referencia?.firmaUrl || "").trim();
    if (!folio || folioGuardado !== folio || !url) return;

    const input = document.getElementById("firmaRemotaUrl");
    const resultBox = document.getElementById("firmaRemotaResultado");
    if (input && input.value !== url) input.value = url;
    if (resultBox) resultBox.hidden = false;
  }

  async function restaurarLugarDesdeServidor() {
    if (lugarRestaurado) return;
    const lugar = document.getElementById("lugar");
    if (!(lugar instanceof HTMLSelectElement)) return;
    if (String(lugar.value || "").trim() !== "") {
      lugarRestaurado = true;
      return;
    }

    const folio = obtenerFolio();
    if (!folio) return;

    try {
      const token = await window.solicitudVentaAuth.getBackendAccessToken();
      if (!token) return;

      const response = await fetch(ESTADO_ENDPOINT, {
        method: "POST",
        cache: "no-store",
        headers: {
          "Content-Type": "application/json",
          Authorization: `Bearer ${token}`
        },
        body: JSON.stringify({ accion: "cargar", folio })
      });
      const data = await response.json().catch(() => null);
      if (!response.ok || !data?.ok) return;

      const valorLugar = String(data?.estado?.controles?.lugar?.valor || "").trim();
      if (!valorLugar || String(lugar.value || "").trim() !== "") {
        lugarRestaurado = true;
        return;
      }

      let opcion = Array.from(lugar.options).find((item) =>
        item.value.trim().toUpperCase() === valorLugar.toUpperCase()
        || item.textContent.trim().toUpperCase() === valorLugar.toUpperCase()
      );
      if (!opcion) {
        opcion = document.createElement("option");
        opcion.value = valorLugar;
        opcion.textContent = valorLugar;
        lugar.appendChild(opcion);
      }

      lugar.value = opcion.value;
      lugar.dispatchEvent(new Event("change", { bubbles: true }));
      lugarRestaurado = true;
    } catch (error) {
      console.warn("No fue posible restaurar Lugar desde el estado del servidor:", error);
    }
  }

  async function regenerarEnlace() {
    if (procesando) return;
    const folio = obtenerFolio();
    if (!folio) return mostrarMensaje("No fue posible identificar el folio de la solicitud.", "error");

    const urlActual = String(document.getElementById("firmaRemotaUrl")?.value || leerReferenciaLocal()?.firmaUrl || "").trim();
    const confirmacion = urlActual
      ? "Se invalidará inmediatamente el enlace anterior y se generará uno nuevo. ¿Deseas continuar?"
      : "Se generará un nuevo enlace de firma para el cliente. ¿Deseas continuar?";
    if (!window.confirm(confirmacion)) return;

    procesando = true;
    actualizarPanel();
    try {
      mostrarMensaje("Generando un nuevo enlace de firma...");
      const data = await gestionar("regenerar", folio);
      const url = String(data?.firmaUrl || "");
      if (!url) throw new Error("El servidor no devolvió el nuevo enlace.");

      const input = document.getElementById("firmaRemotaUrl");
      const resultBox = document.getElementById("firmaRemotaResultado");
      if (input) input.value = url;
      if (resultBox) resultBox.hidden = false;
      guardarUrlLocal(folio, url);

      const estado = document.getElementById("firmaRemotaGestionEstado");
      if (estado) estado.textContent = "Enlace generado correctamente. Si existía uno anterior, quedó invalidado.";
      mostrarMensaje(`Enlace de firma generado para ${folio}. Ya puedes copiarlo y compartirlo con el cliente.`, "ok");
    } catch (error) {
      console.error("Regenerar enlace de firma:", error);
      mostrarMensaje(`No fue posible generar el enlace: ${error.message || error}`, "error");
    } finally {
      procesando = false;
      actualizarPanel();
    }
  }

  async function cancelarEnlace() {
    if (procesando) return;
    const folio = obtenerFolio();
    if (!folio) return mostrarMensaje("No fue posible identificar el folio de la solicitud.", "error");
    if (!window.confirm("El enlace actual dejará de funcionar. La solicitud seguirá en PENDIENTE FIRMA hasta que generes un enlace nuevo. ¿Deseas continuar?")) return;

    procesando = true;
    actualizarPanel();
    try {
      mostrarMensaje("Cancelando el enlace de firma...");
      await gestionar("cancelar", folio);

      const input = document.getElementById("firmaRemotaUrl");
      const resultBox = document.getElementById("firmaRemotaResultado");
      if (input) input.value = "";
      if (resultBox) resultBox.hidden = true;
      guardarUrlLocal(folio, "");

      const estado = document.getElementById("firmaRemotaGestionEstado");
      if (estado) estado.textContent = "Enlace cancelado. Genera uno nuevo cuando quieras volver a enviarlo al cliente.";
      mostrarMensaje(`Enlace de firma de ${folio} cancelado. La solicitud continúa en PENDIENTE FIRMA.`, "ok");
    } catch (error) {
      console.error("Cancelar enlace de firma:", error);
      mostrarMensaje(`No fue posible cancelar el enlace: ${error.message || error}`, "error");
    } finally {
      procesando = false;
      actualizarPanel();
    }
  }

  async function gestionar(accion, folio) {
    const token = await window.solicitudVentaAuth.getBackendAccessToken();
    if (!token) throw new Error("No fue posible obtener autorización.");

    const response = await fetch(ENDPOINT, {
      method: "POST",
      cache: "no-store",
      headers: {
        "Content-Type": "application/json",
        Authorization: `Bearer ${token}`
      },
      body: JSON.stringify({ accion, folio })
    });

    const data = await response.json().catch(() => null);
    if (!response.ok || !data?.ok) throw new Error(data?.message || data?.error || `HTTP ${response.status}`);
    return data;
  }

  function guardarUrlLocal(folio, firmaUrl) {
    const key = claveStorage();
    if (!key) return;
    try {
      const raw = localStorage.getItem(key);
      const actual = raw ? JSON.parse(raw) : {};
      const itemId = actual?.itemId || String(Number(folio.split("-").pop() || 0) || "");
      const nuevo = {
        ...actual,
        folio,
        itemId,
        estatus: "PENDIENTE FIRMA",
        actualizado: new Date().toISOString()
      };
      if (firmaUrl) nuevo.firmaUrl = firmaUrl;
      else delete nuevo.firmaUrl;
      localStorage.setItem(key, JSON.stringify(nuevo));
    } catch (error) {
      console.warn("No fue posible actualizar el enlace local de firma:", error);
    }
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

  function claveStorage() {
    const usuario = window.solicitudVentaAuth?.getUser?.();
    const correo = String(usuario?.username || document.getElementById("userEmail")?.textContent || "").trim().toLowerCase();
    return correo ? `${STORAGE_PREFIX}${correo}` : "";
  }

  function obtenerFolio() {
    const folio = document.querySelector(".folio-box strong")?.textContent?.trim() || "";
    if (/^SV-\d{4}-\d+$/.test(folio)) return folio;
    const query = new URLSearchParams(location.search).get("folio") || "";
    return /^SV-\d{4}-\d+$/.test(query) ? query : "";
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
})();