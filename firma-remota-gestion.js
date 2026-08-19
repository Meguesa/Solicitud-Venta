(() => {
  const ENDPOINT = "/api/solicitud-venta/gestionar-firma-remota.php";
  const STORAGE_PREFIX = "solicitudVenta:borradorActivo:v1:";
  let inicializado = false;
  let procesando = false;

  function iniciar() {
    if (inicializado) return;
    const section = document.getElementById("firmasSection");
    if (!section || !window.solicitudVentaAuth) {
      setTimeout(iniciar, 100);
      return;
    }

    inicializado = true;
    insertarPanel(section);
    actualizarPanel();
    setInterval(actualizarPanel, 900);
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
    const pendiente = estatus.includes("PENDIENTE FIRMA") || document.getElementById("btnValidate")?.textContent?.trim().toUpperCase() === "PENDIENTE DE FIRMA";

    panel.hidden = !pendiente;
    if (!pendiente) return;

    const regenerar = document.getElementById("btnRegenerarFirmaRemota");
    const cancelar = document.getElementById("btnCancelarFirmaRemota");
    if (regenerar) regenerar.disabled = procesando;
    if (cancelar) cancelar.disabled = procesando;
  }

  async function regenerarEnlace() {
    if (procesando) return;
    const folio = obtenerFolio();
    if (!folio) return mostrarMensaje("No fue posible identificar el folio de la solicitud.", "error");
    if (!window.confirm("Se invalidará inmediatamente el enlace anterior y se generará uno nuevo. ¿Deseas continuar?")) return;

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
      if (estado) estado.textContent = "Enlace regenerado. El enlace anterior quedó invalidado.";
      mostrarMensaje(`Nuevo enlace generado para ${folio}. El enlace anterior ya no es válido.`, "ok");
    } catch (error) {
      console.error("Regenerar enlace de firma:", error);
      mostrarMensaje(`No fue posible regenerar el enlace: ${error.message || error}`, "error");
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
      const nuevo = { ...actual, folio, itemId, actualizado: new Date().toISOString() };
      if (firmaUrl) nuevo.firmaUrl = firmaUrl;
      else delete nuevo.firmaUrl;
      localStorage.setItem(key, JSON.stringify(nuevo));
    } catch (error) {
      console.warn("No fue posible actualizar el enlace local de firma:", error);
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
