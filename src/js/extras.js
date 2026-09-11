(() => {
  const MAX_FILE_BYTES = 12 * 1024 * 1024;
  const ALLOWED_TYPES = new Set(["image/jpeg", "image/png", "image/webp", "application/pdf"]);
  const STORAGE_PREFIX = "solicitudVenta:borradorActivo:v1:";
  const firmas = {};
  let inicializado = false;

  function iniciarCuandoEsteListo() {
    if (inicializado) return;
    if (!document.getElementById("solicitudForm") || typeof window.guardarBorrador !== "function") {
      setTimeout(iniciarCuandoEsteListo, 50);
      return;
    }

    inicializado = true;
    configurarDocumentos();
    configurarFirmas();
    configurarReinicioFlujo();
    interceptarGuardado();
    interceptarValidacion();
  }

  if (document.readyState === "loading") {
    document.addEventListener("DOMContentLoaded", () => setTimeout(iniciarCuandoEsteListo, 0));
  } else {
    setTimeout(iniciarCuandoEsteListo, 0);
  }

  function configurarDocumentos() {
    const form = document.getElementById("solicitudForm");
    const actions = form?.querySelector(".form-actions");
    let section = document.getElementById("documentosSection");
    if (!form || !actions || !section) return;

    section.innerHTML = `
      <div class="section-title">
        <span>D</span>
        <div>
          <h3>Documentación</h3>
          <p>Carga fotografías o archivos del expediente. En celular puedes tomar la foto directamente con la cámara.</p>
        </div>
      </div>
      <div class="upload-grid">
        ${crearCeldaDocumento("Identificación oficial del titular", "docTitular", "ID_TITULAR", false)}
        ${crearCeldaDocumento("Identificación del titular substituto", "docSustituto", "ID_SUSTITUTO", false)}
        ${crearCeldaDocumento("Comprobante de domicilio", "docDomicilio", "COMPROBANTE_DOMICILIO", false)}
        ${crearCeldaDocumento("Comprobante de pago", "docPago", "COMPROBANTE_PAGO", false)}
        ${crearCeldaDocumento("Otros documentos", "docOtros", "OTRO", true)}
      </div>
      <label class="document-description">Descripción de otros documentos
        <textarea id="documentoOtros" rows="2" placeholder="Ej. ACTA / DOCUMENTO ADICIONAL / NO APLICA"></textarea>
      </label>
      <p class="upload-help">Formatos permitidos: JPG, PNG, WEBP y PDF. Máximo 12 MB por archivo.</p>
    `;

    form.insertBefore(section, actions);
    section.querySelectorAll("input[type=file]").forEach((input) => {
      input.addEventListener("change", () => actualizarArchivoSeleccionado(input));
    });
  }

  function crearCeldaDocumento(titulo, baseId, tipo, multiple) {
    const multipleAttr = multiple ? " multiple" : "";
    return `
      <div class="upload-card" data-document-type="${tipo}" data-base-id="${baseId}" data-archivo-cargado="0">
        <strong>${titulo}</strong>
        <div class="upload-actions">
          <label class="file-button">
            <span>Elegir foto / archivo</span>
            <input id="${baseId}Archivo" type="file" accept="image/jpeg,image/png,image/webp,application/pdf"${multipleAttr}>
          </label>
          <label class="file-button camera-button">
            <span>Tomar foto</span>
            <input id="${baseId}Camara" type="file" accept="image/*" capture="environment">
          </label>
        </div>
        <small class="file-status" id="${baseId}Estado">Sin archivo seleccionado</small>
      </div>`;
  }

  function actualizarArchivoSeleccionado(input) {
    const card = input.closest(".upload-card");
    if (!card) return;

    const baseId = card.dataset.baseId;
    const otroId = input.id.endsWith("Archivo") ? `${baseId}Camara` : `${baseId}Archivo`;
    const otro = document.getElementById(otroId);
    if (input.files?.length && otro && !input.multiple) otro.value = "";

    const archivos = obtenerArchivosCard(card);
    const estado = card.querySelector(".file-status");
    card.dataset.uploadedKeys = "";

    if (!archivos.length) {
      if (card.dataset.archivoCargado === "1") {
        if (estado) estado.textContent = "Documento ya cargado en el expediente";
      } else if (estado) {
        estado.textContent = "Sin archivo seleccionado";
      }
      return;
    }

    if (estado) estado.textContent = archivos.map((file) => file.name).join(", ");
  }

  function obtenerArchivosCard(card) {
    const archivos = [];
    card.querySelectorAll("input[type=file]").forEach((input) => {
      if (input.files) archivos.push(...Array.from(input.files));
    });
    return archivos;
  }

  function configurarFirmas() {
    const form = document.getElementById("solicitudForm");
    const actions = form?.querySelector(".form-actions");
    if (!form || !actions || document.getElementById("firmasSection")) return;

    const section = document.createElement("section");
    section.id = "firmasSection";
    section.className = "form-section";
    section.innerHTML = `
      <div class="section-title">
        <span>F</span>
        <div>
          <h3>Firmas</h3>
          <p>El cliente y el vendedor deben firmar antes de validar y enviar la solicitud.</p>
        </div>
      </div>
      <div class="signature-grid">
        ${crearCeldaFirma("Firma del cliente", "firmaCliente", "FIRMA_CLIENTE")}
        ${crearCeldaFirma("Firma del vendedor", "firmaVendedor", "FIRMA_VENDEDOR")}
      </div>`;

    form.insertBefore(section, actions);
    prepararFirma("firmaCliente", "FIRMA_CLIENTE");
    prepararFirma("firmaVendedor", "FIRMA_VENDEDOR");
  }

  function crearCeldaFirma(titulo, id, tipo) {
    return `
      <div class="signature-card" data-signature-type="${tipo}">
        <div class="signature-header">
          <strong>${titulo}</strong>
          <button type="button" class="signature-clear" data-clear-signature="${id}">Limpiar firma</button>
        </div>
        <canvas id="${id}" class="signature-canvas" aria-label="${titulo}"></canvas>
        <small data-signature-status="${tipo}">Firma dentro del recuadro usando dedo, mouse o lápiz digital.</small>
      </div>`;
  }

  function prepararFirma(id, tipo) {
    const canvas = document.getElementById(id);
    if (!canvas) return;

    const ctx = canvas.getContext("2d");
    const state = {
      canvas,
      ctx,
      tipo,
      dibujando: false,
      tieneFirma: false,
      yaCargada: false,
      version: 0,
      uploadedVersion: -1
    };
    firmas[id] = state;

    ajustarCanvas(state);

    const punto = (event) => {
      const rect = canvas.getBoundingClientRect();
      const scaleX = canvas.width / rect.width;
      const scaleY = canvas.height / rect.height;
      return {
        x: (event.clientX - rect.left) * scaleX,
        y: (event.clientY - rect.top) * scaleY
      };
    };

    canvas.addEventListener("pointerdown", (event) => {
      event.preventDefault();
      canvas.setPointerCapture(event.pointerId);
      const p = punto(event);
      state.dibujando = true;
      state.yaCargada = false;
      actualizarEstadoFirmaVisual(state, "Firma modificada; se actualizará al guardar.");
      ctx.beginPath();
      ctx.moveTo(p.x, p.y);
    });

    canvas.addEventListener("pointermove", (event) => {
      if (!state.dibujando) return;
      event.preventDefault();
      const p = punto(event);
      ctx.lineTo(p.x, p.y);
      ctx.stroke();
      state.tieneFirma = true;
      state.version += 1;
    });

    const terminar = () => {
      if (!state.dibujando) return;
      state.dibujando = false;
      ctx.closePath();
    };
    canvas.addEventListener("pointerup", terminar);
    canvas.addEventListener("pointercancel", terminar);
    canvas.addEventListener("pointerleave", terminar);

    document.querySelector(`[data-clear-signature="${id}"]`)?.addEventListener("click", () => limpiarFirma(state));
  }

  function ajustarCanvas(state) {
    const rect = state.canvas.getBoundingClientRect();
    const ratio = Math.max(1, window.devicePixelRatio || 1);
    state.canvas.width = Math.max(600, Math.round((rect.width || 520) * ratio));
    state.canvas.height = Math.round(180 * ratio);
    state.ctx.lineCap = "round";
    state.ctx.lineJoin = "round";
    state.ctx.lineWidth = 2.2 * ratio;
    state.ctx.strokeStyle = "#1d1d1d";
    state.ctx.fillStyle = "#ffffff";
    state.ctx.fillRect(0, 0, state.canvas.width, state.canvas.height);
  }

  function limpiarFirma(state) {
    state.ctx.clearRect(0, 0, state.canvas.width, state.canvas.height);
    state.ctx.fillStyle = "#ffffff";
    state.ctx.fillRect(0, 0, state.canvas.width, state.canvas.height);
    state.tieneFirma = false;
    state.yaCargada = false;
    state.version += 1;
    state.uploadedVersion = -1;
    actualizarEstadoFirmaVisual(state, "Firma eliminada. Debe firmarse nuevamente antes de validar.");
  }

  function firmaDisponible(state) {
    return Boolean(state?.tieneFirma || state?.yaCargada);
  }

  function actualizarEstadoFirmaVisual(state, texto = "") {
    const status = document.querySelector(`[data-signature-status="${state.tipo}"]`);
    if (!status) return;
    if (texto) {
      status.textContent = texto;
    } else if (state.yaCargada) {
      status.textContent = "Firma ya guardada en el expediente. No es necesario volver a firmar.";
    } else {
      status.textContent = "Firma dentro del recuadro usando dedo, mouse o lápiz digital.";
    }
  }

  function configurarReinicioFlujo() {
    document.getElementById("btnReset")?.addEventListener("click", () => {
      const guardar = document.getElementById("btnSaveDraft");
      const validar = document.getElementById("btnValidate");
      if (guardar) guardar.disabled = false;
      if (validar) validar.disabled = false;
      const pill = document.querySelector(".status-pill");
      if (pill) pill.textContent = "Borrador";
    });
  }

  function interceptarGuardado() {
    const button = document.getElementById("btnSaveDraft");
    if (!button || button.dataset.extrasIntercepted === "1") return;
    button.dataset.extrasIntercepted = "1";

    button.addEventListener("click", async (event) => {
      event.preventDefault();
      event.stopImmediatePropagation();

      await window.guardarBorrador();
      const folio = obtenerFolioActual();
      if (!folio) return;

      try {
        await subirPendientes(folio, false);
        await refrescarEstadoPersistente();
      } catch (error) {
        console.error("Error al guardar archivos del expediente:", error);
        mostrarMensajeExtra(`El borrador se guardó, pero faltó cargar un archivo: ${error.message || error}`, "error");
      }
    }, true);
  }

  function interceptarValidacion() {
    const form = document.getElementById("solicitudForm");
    if (!form || form.dataset.extrasSubmitIntercepted === "1") return;
    form.dataset.extrasSubmitIntercepted = "1";

    form.addEventListener("submit", async (event) => {
      event.preventDefault();
      event.stopImmediatePropagation();

      if (typeof window.actualizarRequiredVisibles === "function") window.actualizarRequiredVisibles();
      if (typeof window.actualizarInformacionLaboral === "function") window.actualizarInformacionLaboral();

      const validacionComponentes = typeof window.solicitudVentaComponentesValidar === "function"
        ? window.solicitudVentaComponentesValidar()
        : { ok: true };
      if (!validacionComponentes?.ok) {
        mostrarMensajeExtra(validacionComponentes?.message || "Revisa los componentes de la venta.", "error");
        document.getElementById("componentesSection")?.scrollIntoView({ behavior: "smooth", block: "start" });
        return;
      }

      if (!form.checkValidity()) {
        form.reportValidity();
        mostrarMensajeExtra("Faltan campos obligatorios por completar.", "error");
        return;
      }

      if (document.getElementById("formaPago")?.value === "CREDITO" && !document.getElementById("conformidadFinanciamiento")?.checked) {
        mostrarMensajeExtra("Debes confirmar la conformidad del financiamiento.", "error");
        return;
      }

      if (!firmaDisponible(firmas.firmaCliente) || !firmaDisponible(firmas.firmaVendedor)) {
        mostrarMensajeExtra("La firma del cliente y la firma del vendedor son obligatorias antes de validar la solicitud.", "error");
        document.getElementById("firmasSection")?.scrollIntoView({ behavior: "smooth", block: "center" });
        return;
      }

      let folio = obtenerFolioActual();
      if (!folio) {
        mostrarMensajeExtra("Guardando borrador antes de registrar las firmas...");
        await window.guardarBorrador();
        folio = obtenerFolioActual();
      }

      if (!folio) {
        mostrarMensajeExtra("No fue posible obtener un folio para guardar las firmas.", "error");
        return;
      }

      const validarButton = document.getElementById("btnValidate");
      if (validarButton) validarButton.disabled = true;

      try {
        mostrarMensajeExtra("Guardando documentación y firmas...");
        await subirPendientes(folio, true);
        await refrescarEstadoPersistente();

        mostrarMensajeExtra("Enviando solicitud al flujo de Vo.Bo...");
        const resultado = await enviarSolicitudVoBo(folio);
        finalizarEnvioVoBo(resultado);
      } catch (error) {
        console.error("Error al preparar la solicitud:", error);
        if (validarButton) validarButton.disabled = false;
        mostrarMensajeExtra(`No fue posible completar la validación: ${error.message || error}`, "error");
      }
    }, true);
  }

  function obtenerFolioActual() {
    const folio = document.querySelector(".folio-box strong")?.textContent?.trim() || "";
    if (!/^SV-\d{4}-\d+$/.test(folio)) return "";
    return folio;
  }

  async function subirPendientes(folio, incluirFirmas) {
    const section = document.getElementById("documentosSection");
    const cards = section ? Array.from(section.querySelectorAll(".upload-card")) : [];

    for (const card of cards) {
      const tipo = card.dataset.documentType;
      const archivos = obtenerArchivosCard(card);
      const uploaded = new Set((card.dataset.uploadedKeys || "").split("|").filter(Boolean));

      for (const file of archivos) {
        validarArchivo(file);
        const key = `${file.name}:${file.size}:${file.lastModified}`;
        if (uploaded.has(key)) continue;
        await subirArchivo(folio, tipo, file);
        uploaded.add(key);
        card.dataset.archivoCargado = "1";
      }

      card.dataset.uploadedKeys = Array.from(uploaded).join("|");
      if (archivos.length && uploaded.size) {
        const estado = card.querySelector(".file-status");
        if (estado) estado.textContent = `${archivos.length} archivo(s) cargado(s) en el expediente`;
      } else if (card.dataset.archivoCargado === "1") {
        const estado = card.querySelector(".file-status");
        if (estado) estado.textContent = "Documento ya cargado en el expediente";
      }
    }

    if (!incluirFirmas) {
      for (const state of Object.values(firmas)) {
        if (state.tieneFirma && state.uploadedVersion !== state.version) {
          await subirFirma(folio, state);
        }
      }
      return;
    }

    for (const state of Object.values(firmas)) {
      if (!firmaDisponible(state)) throw new Error("Falta una firma obligatoria.");
      if (state.tieneFirma && state.uploadedVersion !== state.version) await subirFirma(folio, state);
    }
  }

  function validarArchivo(file) {
    if (file.size > MAX_FILE_BYTES) throw new Error(`${file.name} supera el límite de 12 MB.`);
    if (file.type && !ALLOWED_TYPES.has(file.type) && !file.type.startsWith("image/")) {
      throw new Error(`${file.name} no tiene un formato permitido.`);
    }
  }

  async function subirFirma(folio, state) {
    const blob = await new Promise((resolve, reject) => {
      state.canvas.toBlob((value) => value ? resolve(value) : reject(new Error("No fue posible generar la imagen de la firma.")), "image/png", 0.95);
    });
    const file = new File([blob], `${state.tipo}.png`, { type: "image/png", lastModified: Date.now() });
    await subirArchivo(folio, state.tipo, file);
    state.uploadedVersion = state.version;
    state.yaCargada = true;
    actualizarEstadoFirmaVisual(state);
  }

  async function subirArchivo(folio, tipoDocumento, file) {
    const token = await window.solicitudVentaAuth.getBackendAccessToken();
    if (!token) throw new Error("No fue posible obtener autorización para cargar el archivo.");

    const formData = new FormData();
    formData.append("folio", folio);
    formData.append("tipoDocumento", tipoDocumento);
    formData.append("archivo", file, file.name);

    const response = await fetch("/api/solicitud-venta/archivos.php", {
      method: "POST",
      headers: { "Authorization": `Bearer ${token}` },
      body: formData
    });

    const resultado = await response.json().catch(() => null);
    if (!response.ok || !resultado?.ok) {
      throw new Error(resultado?.message || resultado?.error || `HTTP ${response.status}`);
    }
    return resultado;
  }

  async function enviarSolicitudVoBo(folio) {
    const token = await window.solicitudVentaAuth.getBackendAccessToken();
    if (!token) throw new Error("No fue posible obtener autorización para enviar la solicitud a Vo.Bo.");

    const response = await fetch("/api/solicitud-venta/validar.php", {
      method: "POST",
      headers: {
        "Content-Type": "application/json",
        "Authorization": `Bearer ${token}`
      },
      body: JSON.stringify({ folio })
    });

    const resultado = await response.json().catch(() => null);
    if (!response.ok || !resultado?.ok) {
      throw new Error(resultado?.message || resultado?.error || `HTTP ${response.status}`);
    }
    return resultado;
  }

  function finalizarEnvioVoBo(resultado) {
    const folio = resultado?.folio || obtenerFolioActual();
    const total = Number(resultado?.componentesActualizados || 0);
    const estatus = resultado?.estatus || "PENDIENTE VOBO";

    limpiarBorradorActivoLocal();

    const pill = document.querySelector(".status-pill");
    if (pill) pill.textContent = estatus;

    const guardar = document.getElementById("btnSaveDraft");
    const validar = document.getElementById("btnValidate");
    if (guardar) guardar.disabled = true;
    if (validar) validar.disabled = true;

    mostrarMensajeExtra(
      `Solicitud ${folio} enviada a Vo.Bo. correctamente${total ? ` con ${total} componente(s)` : ""}. Estatus: ${estatus}.`,
      "ok"
    );
  }

  function limpiarBorradorActivoLocal() {
    const usuario = window.solicitudVentaAuth?.getUser?.();
    const correo = String(usuario?.username || document.getElementById("userEmail")?.textContent || "").trim().toLowerCase();
    if (!correo) return;
    try {
      localStorage.removeItem(`${STORAGE_PREFIX}${correo}`);
    } catch (error) {
      console.warn("No fue posible limpiar el apuntador local del borrador validado:", error);
    }
  }

  function capturarEstadoExpediente() {
    const documentos = {};
    document.querySelectorAll("#documentosSection .upload-card").forEach((card) => {
      const tipo = card.dataset.documentType || "";
      if (!tipo) return;
      const tieneCarga = card.dataset.archivoCargado === "1" || Boolean((card.dataset.uploadedKeys || "").trim());
      documentos[tipo] = tieneCarga;
    });

    const firmasEstado = {};
    Object.values(firmas).forEach((state) => {
      firmasEstado[state.tipo] = firmaDisponible(state);
    });

    return {
      version: 1,
      documentos,
      firmas: firmasEstado
    };
  }

  function restaurarEstadoExpediente(estado = {}) {
    const documentos = estado?.documentos && typeof estado.documentos === "object" ? estado.documentos : {};
    document.querySelectorAll("#documentosSection .upload-card").forEach((card) => {
      const tipo = card.dataset.documentType || "";
      const cargado = Boolean(documentos[tipo]);
      card.dataset.archivoCargado = cargado ? "1" : "0";
      card.dataset.uploadedKeys = "";
      const status = card.querySelector(".file-status");
      if (status) status.textContent = cargado ? "Documento ya cargado en el expediente" : "Sin archivo seleccionado";
    });

    const firmasGuardadas = estado?.firmas && typeof estado.firmas === "object" ? estado.firmas : {};
    Object.values(firmas).forEach((state) => {
      const cargada = Boolean(firmasGuardadas[state.tipo]);
      state.yaCargada = cargada;
      state.tieneFirma = false;
      state.uploadedVersion = cargada ? state.version : -1;
      ajustarCanvas(state);
      actualizarEstadoFirmaVisual(state);
    });
  }

  async function refrescarEstadoPersistente() {
    const api = window.solicitudVentaPersistencia;
    if (!api || typeof api.guardarEstadoActual !== "function") return;
    try {
      await api.guardarEstadoActual();
    } catch (error) {
      console.warn("No fue posible actualizar el estado reanudable del expediente:", error);
    }
  }

  function mostrarMensajeExtra(texto, tipo = "") {
    if (typeof window.mostrarMensaje === "function") {
      window.mostrarMensaje(texto, tipo);
      return;
    }
    const mensaje = document.getElementById("formMessage");
    if (!mensaje) return;
    mensaje.textContent = texto;
    mensaje.className = `form-message ${tipo}`.trim();
  }

  window.solicitudVentaExtras = {
    capturarEstadoExpediente,
    restaurarEstadoExpediente
  };
})();