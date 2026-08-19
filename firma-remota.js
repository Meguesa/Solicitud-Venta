(() => {
  const STORAGE_PREFIX = "solicitudVenta:borradorActivo:v1:";
  const ESTADO_ENDPOINT = "/api/solicitud-venta/estado-solicitud.php";
  const CAMPOS_MONEDA = new Set([
    "precioTotal", "enganche", "saldo", "importeMensualidad", "precioLista",
    "bonificacion", "montoFinanciar", "totalPagar"
  ]);
  let inicializado = false;
  let enviando = false;
  let seguimientoTimer = null;
  let seguimientoFolio = "";
  let ultimoEstatus = "";

  instalarRedireccionCargaEstado();

  function iniciar() {
    if (inicializado) return;
    const form = document.getElementById("solicitudForm");
    const firmasSection = document.getElementById("firmasSection");
    const btnValidate = document.getElementById("btnValidate");
    if (!form || !firmasSection || !btnValidate || !window.solicitudVentaAuth) {
      setTimeout(iniciar, 80);
      return;
    }

    inicializado = true;
    insertarSelectorModalidad(firmasSection);
    btnValidate.addEventListener("click", interceptarFirmaRemota, true);
    document.getElementById("btnReset")?.addEventListener("click", () => {
      detenerSeguimiento();
      setTimeout(restablecer, 0);
    });
    actualizarModalidad();
    setTimeout(iniciarSeguimientoDesdeStorage, 1200);
  }

  if (document.readyState === "loading") {
    document.addEventListener("DOMContentLoaded", () => setTimeout(iniciar, 0));
  } else {
    setTimeout(iniciar, 0);
  }

  function instalarRedireccionCargaEstado() {
    if (window.__solicitudFirmaRemotaFetchEstadoEnvuelto) return;
    const fetchAnterior = window.fetch.bind(window);

    window.fetch = function (input, init = {}) {
      try {
        const url = typeof input === "string" ? input : String(input?.url || "");
        if (url.includes("/api/solicitud-venta/estado-borrador.php") && String(init?.method || "GET").toUpperCase() === "POST") {
          const body = typeof init?.body === "string" ? JSON.parse(init.body) : null;
          if (body?.accion === "cargar") return fetchAnterior(ESTADO_ENDPOINT, init);
        }
      } catch (_) {}
      return fetchAnterior(input, init);
    };

    window.__solicitudFirmaRemotaFetchEstadoEnvuelto = true;
  }

  function insertarSelectorModalidad(section) {
    if (document.getElementById("modalidadFirma")) return;
    const title = section.querySelector(".section-title");
    const bloque = document.createElement("div");
    bloque.className = "remote-signature-mode";
    bloque.innerHTML = `
      <label>Modalidad de firma
        <select id="modalidadFirma" required>
          <option value="PRESENCIAL">PRESENCIAL</option>
          <option value="REMOTA">REMOTA</option>
        </select>
      </label>
      <div id="firmaRemotaAyuda" class="remote-signature-help" hidden>
        El cliente firmará desde un enlace seguro. La firma del vendedor sí debe quedar registrada antes de enviar la solicitud.
      </div>
      <div id="firmaRemotaResultado" class="remote-signature-result" hidden>
        <strong>Enlace de firma del cliente</strong>
        <div class="remote-signature-link-row">
          <input id="firmaRemotaUrl" type="text" readonly>
          <button id="btnCopiarFirmaRemota" type="button">Copiar enlace</button>
        </div>
        <small>Comparte este enlace únicamente con el titular de la solicitud.</small>
      </div>`;

    if (title?.nextSibling) section.insertBefore(bloque, title.nextSibling);
    else section.appendChild(bloque);

    document.getElementById("modalidadFirma")?.addEventListener("change", actualizarModalidad);
    document.getElementById("btnCopiarFirmaRemota")?.addEventListener("click", copiarEnlace);
  }

  function actualizarModalidad() {
    const remota = document.getElementById("modalidadFirma")?.value === "REMOTA";
    const ayuda = document.getElementById("firmaRemotaAyuda");
    if (ayuda) ayuda.hidden = !remota;

    const cardCliente = document.querySelector('[data-signature-type="FIRMA_CLIENTE"]');
    if (cardCliente) {
      cardCliente.classList.toggle("remote-signature-disabled", remota);
      const canvas = cardCliente.querySelector("canvas");
      const limpiar = cardCliente.querySelector(".signature-clear");
      if (canvas) canvas.style.pointerEvents = remota ? "none" : "auto";
      if (limpiar) limpiar.disabled = remota;
      const status = cardCliente.querySelector('[data-signature-status="FIRMA_CLIENTE"]');
      if (status && remota) {
        status.textContent = "La firma del cliente se solicitará mediante un enlace remoto.";
      } else if (status && !status.textContent.includes("ya guardada")) {
        status.textContent = "Firma dentro del recuadro usando dedo, mouse o lápiz digital.";
      }
    }

    const btnValidate = document.getElementById("btnValidate");
    if (btnValidate && !btnValidate.disabled) {
      btnValidate.textContent = remota ? "Enviar a firma" : "Validar solicitud";
    }
  }

  async function interceptarFirmaRemota(event) {
    if (document.getElementById("modalidadFirma")?.value !== "REMOTA") return;
    event.preventDefault();
    event.stopImmediatePropagation();
    if (enviando) return;
    enviando = true;

    const btnValidate = document.getElementById("btnValidate");
    if (btnValidate) btnValidate.disabled = true;

    try {
      validarFormularioRemoto();
      mostrarMensaje("Guardando la solicitud antes de generar el enlace de firma...");
      await window.guardarBorrador();

      const folio = obtenerFolioActual();
      if (!folio) throw new Error("No fue posible obtener el folio de la solicitud.");

      await subirDocumentosSeleccionados(folio);
      await asegurarFirmaVendedor(folio);

      if (window.solicitudVentaPersistencia?.guardarEstadoActual) {
        await window.solicitudVentaPersistencia.guardarEstadoActual();
      }

      mostrarMensaje("Generando enlace seguro para firma del cliente...");
      const resultado = await iniciarFirmaRemota(folio, capturarDetalleSolicitud());
      finalizarEnvioRemoto(resultado);
    } catch (error) {
      console.error("Error al iniciar firma remota:", error);
      if (btnValidate) btnValidate.disabled = false;
      actualizarModalidad();
      mostrarMensaje(`No fue posible enviar a firma: ${error.message || error}`, "error");
    } finally {
      enviando = false;
    }
  }

  function validarFormularioRemoto() {
    if (typeof window.actualizarRequiredVisibles === "function") window.actualizarRequiredVisibles();
    if (typeof window.actualizarInformacionLaboral === "function") window.actualizarInformacionLaboral();

    const validacionComponentes = typeof window.solicitudVentaComponentesValidar === "function"
      ? window.solicitudVentaComponentesValidar()
      : { ok: true };
    if (!validacionComponentes?.ok) {
      document.getElementById("componentesSection")?.scrollIntoView({ behavior: "smooth", block: "start" });
      throw new Error(validacionComponentes?.message || "Revisa los componentes de la venta.");
    }

    const form = document.getElementById("solicitudForm");
    if (!form.checkValidity()) {
      form.reportValidity();
      throw new Error("Faltan campos obligatorios por completar.");
    }

    if (document.getElementById("formaPago")?.value === "CREDITO" && !document.getElementById("conformidadFinanciamiento")?.checked) {
      throw new Error("Debes confirmar la conformidad del financiamiento.");
    }

    const expediente = window.solicitudVentaExtras?.capturarEstadoExpediente?.() || {};
    if (!expediente?.firmas?.FIRMA_VENDEDOR) {
      document.getElementById("firmasSection")?.scrollIntoView({ behavior: "smooth", block: "center" });
      throw new Error("La firma del vendedor es obligatoria antes de enviar la solicitud al cliente.");
    }
  }

  async function subirDocumentosSeleccionados(folio) {
    const cards = Array.from(document.querySelectorAll("#documentosSection .upload-card"));
    for (const card of cards) {
      const tipo = card.dataset.documentType || "OTRO";
      const uploaded = new Set((card.dataset.uploadedKeys || "").split("|").filter(Boolean));
      const files = [];
      card.querySelectorAll('input[type="file"]').forEach((input) => {
        if (input.files) files.push(...Array.from(input.files));
      });

      for (const file of files) {
        const key = `${file.name}:${file.size}:${file.lastModified}`;
        if (uploaded.has(key)) continue;
        await subirArchivo(folio, tipo, file);
        uploaded.add(key);
        card.dataset.archivoCargado = "1";
      }
      card.dataset.uploadedKeys = Array.from(uploaded).join("|");
    }
  }

  async function asegurarFirmaVendedor(folio) {
    const status = document.querySelector('[data-signature-status="FIRMA_VENDEDOR"]')?.textContent || "";
    if (/ya guardada en el expediente/i.test(status)) return;

    const canvas = document.getElementById("firmaVendedor");
    if (!canvas) throw new Error("No fue posible localizar la firma del vendedor.");
    const blob = await new Promise((resolve, reject) => {
      canvas.toBlob((value) => value ? resolve(value) : reject(new Error("No fue posible generar la firma del vendedor.")), "image/png", 0.95);
    });
    const file = new File([blob], "FIRMA_VENDEDOR.png", { type: "image/png", lastModified: Date.now() });
    await subirArchivo(folio, "FIRMA_VENDEDOR", file);
  }

  async function subirArchivo(folio, tipoDocumento, file) {
    const token = await window.solicitudVentaAuth.getBackendAccessToken();
    if (!token) throw new Error("No fue posible obtener autorización para cargar archivos.");

    const formData = new FormData();
    formData.append("folio", folio);
    formData.append("tipoDocumento", tipoDocumento);
    formData.append("archivo", file, file.name);

    const response = await fetch("/api/solicitud-venta/archivos.php", {
      method: "POST",
      headers: { Authorization: `Bearer ${token}` },
      body: formData
    });
    const data = await response.json().catch(() => null);
    if (!response.ok || !data?.ok) throw new Error(data?.message || data?.error || `HTTP ${response.status}`);
  }

  async function iniciarFirmaRemota(folio, detalleSolicitud) {
    const token = await window.solicitudVentaAuth.getBackendAccessToken();
    if (!token) throw new Error("No fue posible obtener autorización para iniciar la firma remota.");

    const response = await fetch("/api/solicitud-venta/iniciar-firma-remota.php", {
      method: "POST",
      headers: {
        "Content-Type": "application/json",
        Authorization: `Bearer ${token}`
      },
      body: JSON.stringify({ folio, detalleSolicitud })
    });
    const data = await response.json().catch(() => null);
    if (!response.ok || !data?.ok) throw new Error(data?.message || data?.error || `HTTP ${response.status}`);
    return data;
  }

  function capturarDetalleSolicitud() {
    const form = document.getElementById("solicitudForm");
    if (!form) return [];

    const omitidas = new Set(["componentesSection", "documentosSection", "firmasSection"]);
    const secciones = [];

    form.querySelectorAll("section.form-section").forEach((section) => {
      if (omitidas.has(section.id) || !esVisible(section)) return;
      const titulo = section.querySelector(".section-title h3")?.textContent?.trim() || "Información";
      const campos = [];

      section.querySelectorAll("input, select, textarea").forEach((control) => {
        if (!control.id || !esVisible(control)) return;
        if (control instanceof HTMLInputElement && ["file", "button", "submit", "reset", "hidden"].includes(control.type)) return;
        if (control.id === "modalidadFirma") return;

        const etiqueta = obtenerEtiqueta(control);
        if (!etiqueta) return;
        const dato = obtenerValorControl(control);
        if (!dato) return;
        campos.push({ id: control.id, etiqueta, valor: dato.valor, tipo: dato.tipo });
      });

      if (campos.length) secciones.push({ titulo, campos });
    });

    const documentos = capturarDocumentacion();
    if (documentos.campos.length) secciones.push(documentos);

    return secciones;
  }

  function capturarDocumentacion() {
    const campos = [];
    document.querySelectorAll("#documentosSection .upload-card").forEach((card) => {
      const titulo = card.querySelector("strong")?.textContent?.trim() || card.dataset.documentType || "Documento";
      const archivosSeleccionados = Array.from(card.querySelectorAll('input[type="file"]')).some((input) => Boolean(input.files?.length));
      const recibido = card.dataset.archivoCargado === "1" || archivosSeleccionados;
      if (recibido) campos.push({ etiqueta: titulo, valor: "RECIBIDO", tipo: "texto" });
    });
    const otros = document.getElementById("documentoOtros")?.value?.trim();
    if (otros) campos.push({ etiqueta: "Descripción de otros documentos", valor: otros, tipo: "texto" });
    return { titulo: "Documentación", campos };
  }

  function esVisible(elemento) {
    if (!elemento || elemento.closest("[hidden]")) return false;
    const estilo = window.getComputedStyle(elemento);
    return estilo.display !== "none" && estilo.visibility !== "hidden";
  }

  function obtenerEtiqueta(control) {
    const label = control.closest("label");
    if (label) {
      const copia = label.cloneNode(true);
      copia.querySelectorAll("input, select, textarea, button, small").forEach((node) => node.remove());
      const texto = copia.textContent.replace(/\s+/g, " ").trim();
      if (texto) return texto;
    }
    return control.getAttribute("aria-label")?.trim() || control.id;
  }

  function obtenerValorControl(control) {
    if (control instanceof HTMLInputElement && (control.type === "checkbox" || control.type === "radio")) {
      return { valor: control.checked ? "SI" : "NO", tipo: "booleano" };
    }

    let valor = "";
    if (control instanceof HTMLSelectElement) {
      valor = control.selectedOptions?.[0]?.textContent?.trim() || control.value.trim();
      if (!control.value) valor = "";
    } else {
      valor = String(control.value || "").trim();
    }
    if (!valor) return null;

    if (control instanceof HTMLInputElement && control.type === "date") return { valor, tipo: "fecha" };
    if (CAMPOS_MONEDA.has(control.id)) return { valor, tipo: "moneda" };
    if (control instanceof HTMLInputElement && control.type === "number") return { valor, tipo: "numero" };
    return { valor, tipo: "texto" };
  }

  function finalizarEnvioRemoto(resultado) {
    const folio = resultado?.folio || obtenerFolioActual();
    const url = resultado?.firmaUrl || "";
    const resultBox = document.getElementById("firmaRemotaResultado");
    const input = document.getElementById("firmaRemotaUrl");
    if (input) input.value = url;
    if (resultBox) resultBox.hidden = false;

    guardarReferenciaLocal(folio);
    aplicarEstadoVisual("PENDIENTE FIRMA", { folio }, false);
    iniciarSeguimiento(folio);

    mostrarMensaje(
      `Solicitud ${folio} enviada a firma remota. Comparte el enlace seguro con el cliente. Estatus: PENDIENTE FIRMA.`,
      "ok"
    );
    resultBox?.scrollIntoView({ behavior: "smooth", block: "center" });
  }

  async function iniciarSeguimientoDesdeStorage() {
    const referencia = leerReferenciaLocal();
    if (!referencia?.folio) return;

    try {
      const data = await consultarEstadoSolicitud(referencia.folio);
      aplicarRespuestaSeguimiento(data, true);
      if (String(data.estatus || "").toUpperCase() === "PENDIENTE FIRMA") iniciarSeguimiento(referencia.folio);
    } catch (error) {
      console.warn("No fue posible consultar el estado de firma remota:", error);
    }
  }

  function iniciarSeguimiento(folio) {
    if (!folio) return;
    seguimientoFolio = folio;
    if (seguimientoTimer) clearInterval(seguimientoTimer);

    seguimientoTimer = setInterval(async () => {
      try {
        const data = await consultarEstadoSolicitud(seguimientoFolio);
        aplicarRespuestaSeguimiento(data, false);
      } catch (error) {
        console.warn("Seguimiento de firma remota:", error);
      }
    }, 8000);
  }

  function detenerSeguimiento() {
    if (seguimientoTimer) clearInterval(seguimientoTimer);
    seguimientoTimer = null;
    seguimientoFolio = "";
    ultimoEstatus = "";
  }

  async function consultarEstadoSolicitud(folio) {
    const token = await window.solicitudVentaAuth.getBackendAccessToken();
    if (!token) throw new Error("No fue posible obtener autorización para consultar el estado.");

    const response = await fetch(ESTADO_ENDPOINT, {
      method: "POST",
      headers: {
        "Content-Type": "application/json",
        Authorization: `Bearer ${token}`
      },
      body: JSON.stringify({ accion: "cargar", folio })
    });
    const data = await response.json().catch(() => null);
    if (!response.ok || !data?.ok) throw new Error(data?.message || data?.error || `HTTP ${response.status}`);
    return data;
  }

  function aplicarRespuestaSeguimiento(data, desdeCarga) {
    const estatus = String(data?.estatus || "BORRADOR").trim().toUpperCase();
    const expediente = data?.estado?.expediente;
    if (expediente && window.solicitudVentaExtras?.restaurarEstadoExpediente) {
      window.solicitudVentaExtras.restaurarEstadoExpediente(expediente);
    }

    aplicarEstadoVisual(estatus, data, desdeCarga);
    if (estatus !== "PENDIENTE FIRMA") detenerSeguimiento();
  }

  function aplicarEstadoVisual(estatus, data = null, desdeCarga = false) {
    const estado = String(estatus || "BORRADOR").trim().toUpperCase();
    if (estado === "BORRADOR") return;

    bloquearFormulario(estado);

    const pill = document.querySelector(".status-pill");
    if (pill) pill.textContent = estado;

    const validar = document.getElementById("btnValidate");
    if (validar) {
      validar.disabled = true;
      validar.textContent = estado === "PENDIENTE FIRMA" ? "Pendiente de firma" : "En Vo.Bo.";
    }

    const cambio = ultimoEstatus !== estado;
    ultimoEstatus = estado;
    const folio = data?.folio || obtenerFolioActual() || seguimientoFolio;

    if (estado === "PENDIENTE FIRMA") {
      if (cambio || desdeCarga) {
        mostrarMensaje(`Solicitud ${folio} pendiente de firma del cliente. La información queda en modo solo lectura.`, "ok");
      }
      return;
    }

    if (estado === "PENDIENTE VOBO") {
      const cardCliente = document.querySelector('[data-signature-type="FIRMA_CLIENTE"]');
      cardCliente?.classList.remove("remote-signature-disabled");
      const statusCliente = cardCliente?.querySelector('[data-signature-status="FIRMA_CLIENTE"]');
      if (statusCliente) statusCliente.textContent = "Firma remota recibida y guardada en el expediente.";
      if (cambio || desdeCarga) {
        mostrarMensaje(`Firma remota recibida para ${folio}. La solicitud fue enviada a Vo.Bo. y permanece disponible en modo solo lectura.`, "ok");
      }
      return;
    }

    if (cambio || desdeCarga) {
      mostrarMensaje(`Solicitud ${folio} en estatus ${estado}. La información se mantiene en modo solo lectura.`, "ok");
    }
  }

  function bloquearFormulario(estatus) {
    const form = document.getElementById("solicitudForm");
    if (!form) return;

    form.querySelectorAll("input, select, textarea, button").forEach((control) => {
      if (control.id === "btnReset" || control.id === "btnCopiarFirmaRemota") return;
      control.disabled = true;
    });

    const reset = document.getElementById("btnReset");
    if (reset) {
      reset.disabled = false;
      reset.textContent = "Nueva solicitud";
    }

    const guardar = document.getElementById("btnSaveDraft");
    if (guardar) guardar.disabled = true;

    const validar = document.getElementById("btnValidate");
    if (validar) validar.disabled = true;

    const copiar = document.getElementById("btnCopiarFirmaRemota");
    if (copiar) copiar.disabled = false;

    document.body.dataset.solicitudEstatus = estatus;
  }

  function guardarReferenciaLocal(folio) {
    if (!/^SV-\d{4}-\d+$/.test(String(folio || ""))) return;
    const key = claveStorage();
    if (!key) return;
    const itemId = String(Number(String(folio).split("-").pop() || 0) || "");
    if (!itemId) return;

    try {
      localStorage.setItem(key, JSON.stringify({ folio, itemId, actualizado: new Date().toISOString() }));
    } catch (error) {
      console.warn("No fue posible conservar la solicitud en seguimiento:", error);
    }
  }

  function leerReferenciaLocal() {
    const key = claveStorage();
    if (!key) return null;
    try {
      const raw = localStorage.getItem(key);
      if (!raw) return null;
      const data = JSON.parse(raw);
      if (!/^SV-\d{4}-\d+$/.test(String(data?.folio || ""))) return null;
      return data;
    } catch (_) {
      return null;
    }
  }

  function claveStorage() {
    const usuario = window.solicitudVentaAuth?.getUser?.();
    const correo = String(usuario?.username || document.getElementById("userEmail")?.textContent || "").trim().toLowerCase();
    return correo ? `${STORAGE_PREFIX}${correo}` : "";
  }

  async function copiarEnlace() {
    const input = document.getElementById("firmaRemotaUrl");
    const value = input?.value?.trim() || "";
    if (!value) return;
    try {
      await navigator.clipboard.writeText(value);
      mostrarMensaje("Enlace de firma copiado. Compártelo únicamente con el cliente.", "ok");
    } catch (_) {
      input.select();
      document.execCommand("copy");
      mostrarMensaje("Enlace de firma copiado.", "ok");
    }
  }

  function restablecer() {
    const modalidad = document.getElementById("modalidadFirma");
    if (modalidad) {
      modalidad.disabled = false;
      modalidad.value = "PRESENCIAL";
    }
    const resultBox = document.getElementById("firmaRemotaResultado");
    if (resultBox) resultBox.hidden = true;
    const url = document.getElementById("firmaRemotaUrl");
    if (url) url.value = "";

    const form = document.getElementById("solicitudForm");
    form?.querySelectorAll("input, select, textarea, button").forEach((control) => {
      control.disabled = false;
    });

    const reset = document.getElementById("btnReset");
    if (reset) reset.textContent = "Limpiar";
    delete document.body.dataset.solicitudEstatus;
    actualizarModalidad();
  }

  function obtenerFolioActual() {
    const folio = document.querySelector(".folio-box strong")?.textContent?.trim() || "";
    return /^SV-\d{4}-\d+$/.test(folio) ? folio : "";
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