(() => {
  const MAX_FILE_BYTES = 12 * 1024 * 1024;
  const ALLOWED_TYPES = new Set(["image/jpeg", "image/png", "image/webp", "application/pdf"]);
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
      <div class="upload-card" data-document-type="${tipo}" data-base-id="${baseId}">
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
      estado.textContent = "Sin archivo seleccionado";
      return;
    }

    estado.textContent = archivos.map((file) => file.name).join(", ");
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
        <small>Firma dentro del recuadro usando dedo, mouse o lápiz digital.</small>
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
    state.version += 1;
    state.uploadedVersion = -1;
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

      if (!form.checkValidity()) {
        form.reportValidity();
        mostrarMensajeExtra("Faltan campos obligatorios por completar.", "error");
        return;
      }

      if (document.getElementById("formaPago")?.value === "CREDITO" && !document.getElementById("conformidadFinanciamiento")?.checked) {
        mostrarMensajeExtra("Debes confirmar la conformidad del financiamiento.", "error");
        return;
      }

      if (!firmas.firmaCliente?.tieneFirma || !firmas.firmaVendedor?.tieneFirma) {
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

      try {
        mostrarMensajeExtra("Guardando documentación y firmas...");
        await subirPendientes(folio, true);
        mostrarMensajeExtra("Validación correcta. Documentación y firmas guardadas en el expediente. La solicitud está lista para continuar al flujo de Vo.Bo.", "ok");
      } catch (error) {
        console.error("Error al preparar la solicitud:", error);
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
      }

      card.dataset.uploadedKeys = Array.from(uploaded).join("|");
      if (archivos.length && uploaded.size) {
        const estado = card.querySelector(".file-status");
        if (estado) estado.textContent = `${archivos.length} archivo(s) cargado(s) en el expediente`;
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
      if (!state.tieneFirma) throw new Error("Falta una firma obligatoria.");
      if (state.uploadedVersion !== state.version) await subirFirma(folio, state);
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
})();
