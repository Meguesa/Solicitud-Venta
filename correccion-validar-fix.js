(() => {
  const VALIDAR_ENDPOINT = "/api/solicitud-venta/validar.php";
  const STORAGE_PREFIX = "solicitudVenta:borradorActivo:v1:";
  let procesando = false;

  function esCorreccion() {
    return new URLSearchParams(window.location.search).get("correccion") === "1";
  }

  function obtenerFolio() {
    const visible = document.querySelector(".folio-box strong")?.textContent?.trim().toUpperCase() || "";
    if (/^SV-\d{4}-\d+$/.test(visible)) return visible;
    const query = String(new URLSearchParams(window.location.search).get("folio") || "").trim().toUpperCase();
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

  function validarFormulario(form) {
    if (typeof window.actualizarRequiredVisibles === "function") window.actualizarRequiredVisibles();
    if (typeof window.actualizarInformacionLaboral === "function") window.actualizarInformacionLaboral();

    const componentes = typeof window.solicitudVentaComponentesValidar === "function"
      ? window.solicitudVentaComponentesValidar()
      : { ok: true };
    if (!componentes?.ok) {
      mostrarMensaje(componentes?.message || "Revisa los componentes de la venta.", "error");
      document.getElementById("componentesSection")?.scrollIntoView({ behavior: "smooth", block: "start" });
      return false;
    }

    if (!form.checkValidity()) {
      form.reportValidity();
      mostrarMensaje("Faltan campos obligatorios por completar.", "error");
      return false;
    }

    if (document.getElementById("formaPago")?.value === "CREDITO" && !document.getElementById("conformidadFinanciamiento")?.checked) {
      mostrarMensaje("Debes confirmar la conformidad del financiamiento.", "error");
      return false;
    }

    return true;
  }

  function claveArchivo(file) {
    return `${file.name}:${file.size}:${file.lastModified}`;
  }

  function obtenerArchivosPendientes() {
    const pendientes = [];
    document.querySelectorAll("#documentosSection .upload-card").forEach((card) => {
      const uploaded = new Set((card.dataset.uploadedKeys || "").split("|").filter(Boolean));
      card.querySelectorAll("input[type=file]").forEach((input) => {
        Array.from(input.files || []).forEach((file) => {
          if (!uploaded.has(claveArchivo(file))) pendientes.push({ card, file });
        });
      });
    });
    return pendientes;
  }

  async function esperarArchivosGuardados(timeoutMs = 90000) {
    const inicio = Date.now();
    while (Date.now() - inicio < timeoutMs) {
      if (obtenerArchivosPendientes().length === 0) return true;
      await new Promise((resolve) => window.setTimeout(resolve, 250));
    }
    return false;
  }

  async function guardarCambios() {
    const pendientes = obtenerArchivosPendientes();
    if (pendientes.length > 0) {
      const guardar = document.getElementById("btnSaveDraft");
      if (!(guardar instanceof HTMLButtonElement) || guardar.disabled) {
        throw new Error("Hay documentos nuevos pendientes y no fue posible activar Guardar borrador.");
      }

      mostrarMensaje("Guardando los cambios y cargando la documentación corregida...");
      guardar.click();
      const completo = await esperarArchivosGuardados();
      if (!completo) throw new Error("La carga de la documentación corregida excedió el tiempo de espera.");
      return;
    }

    if (typeof window.guardarBorrador !== "function") {
      throw new Error("No fue posible localizar el guardado del borrador.");
    }
    mostrarMensaje("Guardando los cambios de la corrección...");
    await window.guardarBorrador();
  }

  async function enviarVoBo(folio) {
    const token = await window.solicitudVentaAuth?.getBackendAccessToken?.();
    if (!token) throw new Error("No fue posible obtener autorización para enviar la solicitud a Vo.Bo.");

    const response = await fetch(VALIDAR_ENDPOINT, {
      method: "POST",
      cache: "no-store",
      credentials: "same-origin",
      headers: {
        "Content-Type": "application/json",
        Authorization: `Bearer ${token}`
      },
      body: JSON.stringify({ folio })
    });

    const data = await response.json().catch(() => null);
    if (!response.ok || !data?.ok) {
      throw new Error(data?.message || data?.error || `HTTP ${response.status}`);
    }
    return data;
  }

  function limpiarBorradorActivoLocal() {
    try {
      const usuario = window.solicitudVentaAuth?.getUser?.();
      const correo = String(usuario?.username || document.getElementById("userEmail")?.textContent || "")
        .trim()
        .toLowerCase();
      if (correo) localStorage.removeItem(`${STORAGE_PREFIX}${correo}`);
    } catch (error) {
      console.warn("No fue posible limpiar el apuntador local de la corrección validada:", error);
    }
  }

  function finalizar(resultado) {
    const folio = String(resultado?.folio || obtenerFolio()).trim();
    const estatus = String(resultado?.estatus || "PENDIENTE VOBO").trim();
    const total = Number(resultado?.componentesActualizados || 0);

    limpiarBorradorActivoLocal();
    document.body.dataset.solicitudEstatus = estatus;

    const pill = document.querySelector(".status-pill");
    if (pill) pill.textContent = estatus;

    const guardar = document.getElementById("btnSaveDraft");
    const validar = document.getElementById("btnValidate");
    if (guardar instanceof HTMLButtonElement) guardar.disabled = true;
    if (validar instanceof HTMLButtonElement) {
      validar.disabled = true;
      validar.textContent = "En Vo.Bo.";
    }

    mostrarMensaje(
      `Solicitud ${folio} corregida y enviada nuevamente a Vo.Bo.${total ? ` ${total} componente(s) actualizado(s).` : ""}`,
      "ok"
    );
  }

  document.addEventListener("click", async (event) => {
    if (!esCorreccion()) return;
    const element = event.target instanceof Element ? event.target : null;
    const button = element?.closest("#btnValidate");
    if (!(button instanceof HTMLButtonElement)) return;

    // Este listener se carga antes que firma-remota.js, extras.js y correccion-fix.js.
    // En CORRECCION las firmas persistidas en SharePoint son la fuente de verdad;
    // no se deben volver a validar canvases vacios ni generar otra liga remota.
    event.preventDefault();
    event.stopImmediatePropagation();
    if (procesando) return;

    const form = document.getElementById("solicitudForm");
    if (!(form instanceof HTMLFormElement)) {
      mostrarMensaje("No fue posible localizar el formulario de la solicitud.", "error");
      return;
    }
    if (!validarFormulario(form)) return;

    const folio = obtenerFolio();
    if (!folio) {
      mostrarMensaje("No fue posible identificar el folio de la corrección.", "error");
      return;
    }

    procesando = true;
    button.disabled = true;
    try {
      await guardarCambios();
      mostrarMensaje("Verificando las firmas guardadas y regresando la solicitud a Vo.Bo...");
      const resultado = await enviarVoBo(folio);
      finalizar(resultado);
    } catch (error) {
      console.error("No fue posible validar la corrección:", error);
      button.disabled = false;
      mostrarMensaje(`No fue posible validar la corrección: ${error.message || error}`, "error");
    } finally {
      procesando = false;
    }
  }, true);
})();