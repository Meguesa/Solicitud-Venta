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
        El enlace seguro se generará después de revisar el resumen final y pulsar <strong>Enviar a firma</strong>. La firma del vendedor debe quedar registrada antes de generar el enlace.
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
        status.textContent = "La firma del cliente se solicitará mediante el enlace que se generará al enviar la solicitud.";
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
    if (!token) throw new Error("No fue posible obtener autorización para cargar el expediente.");

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
    return data;
  }

  async function iniciarFirmaRemota(folio, detalleSolicitud) {
    const token = await window.solicitudVentaAuth.getBackendAccessToken();
    if (!token) throw new Error("No fue posible obtener autorización para generar el enlace de firma.");

    const response = await fetch("/api/solicitud-venta/iniciar-firma-remota.php", {
      method: "POST",
      headers: { "Content-Type": "application/json", Authorization: `Bearer ${token}` },
      body: JSON.stringify({ folio, detalleSolicitud })
    });
    const data = await response.json().catch(() => null);
    if (!response.ok || !data?.ok) throw new Error(data?.message || data?.error || `HTTP ${response.status}`);
    return data;
  }

  function finalizarEnvioRemoto(resultado) {
    const folio = String(resultado?.folio || obtenerFolioActual() || "");
    const firmaUrl = String(resultado?.firmaUrl || "");
    seguimientoFolio = folio;
    ultimoEstatus = "PENDIENTE FIRMA";

    const resultBox = document.getElementById("firmaRemotaResultado");
    const urlInput = document.getElementById("firmaRemotaUrl");
    if (urlInput) urlInput.value = firmaUrl;
    if (resultBox) resultBox.hidden = !firmaUrl;

    if (folio) guardarReferenciaLocal(folio);
    bloquearFormularioPendienteFirma();
    mostrarMensaje(`Solicitud ${folio} enviada a firma. Comparte el enlace seguro con el cliente.`, "ok");
    iniciarSeguimiento(folio);
  }

  async function copiarEnlace() {
    const input = document.getElementById("firmaRemotaUrl");
    const value = input?.value?.trim();
    if (!value) return;
    try {
      await navigator.clipboard.writeText(value);
      mostrarMensaje("Enlace de firma copiado al portapapeles.", "ok");
    } catch (_) {
      input.focus();
      input.select();
      document.execCommand("copy");
      mostrarMensaje("Enlace de firma copiado al portapapeles.", "ok");
    }
  }

  function capturarDetalleSolicitud() {
    const sections = [];
    document.querySelectorAll("#solicitudForm > section.form-section").forEach((section) => {
      if (section.hidden) return;
      const titulo = section.querySelector(".section-title h3")?.textContent?.trim() || "Sección";
      const campos = [];
      section.querySelectorAll("input, select, textarea").forEach((control) => {
        if (!(control instanceof HTMLInputElement || control instanceof HTMLSelectElement || control instanceof HTMLTextAreaElement)) return;
        if (!control.id || control.closest("[hidden]") || control.disabled) return;
        if (control instanceof HTMLInputElement && ["file", "button", "submit", "reset", "hidden"].includes(control.type)) return;

        const label = control.closest("label")?.childNodes?.[0]?.textContent?.trim() || control.id;
        let value = "";
        if (control instanceof HTMLInputElement && (control.type === "checkbox" || control.type === "radio")) {
          value = control.checked ? "Sí" : "No";
        } else if (control instanceof HTMLSelectElement) {
          value = control.selectedOptions[0]?.textContent?.trim() || control.value;
        } else {
          value = control.value;
        }
        if (String(value).trim() === "") return;
        campos.push({ etiqueta: label, valor: String(value).trim(), tipo: "texto" });
      });
      if (campos.length) sections.push({ titulo, campos });
    });
    return sections;
  }

  function obtenerFolioActual() {
    const folio = document.querySelector(".folio-box strong")?.textContent?.trim() || "";
    return /^SV-\d{4}-\d+$/.test(folio) ? folio : "";
  }

  function guardarReferenciaLocal(folio) {
    try {
      const usuario = window.solicitudVentaAuth?.getUser?.();
      const correo = String(usuario?.username || "").trim().toLowerCase();
      if (!correo) return;
      const key = `${STORAGE_PREFIX}${correo}`;
      const raw = localStorage.getItem(key);
      const data = raw ? JSON.parse(raw) : {};
      localStorage.setItem(key, JSON.stringify({ ...data, folio, estatus: "PENDIENTE FIRMA" }));
    } catch (_) {}
  }

  function iniciarSeguimientoDesdeStorage() {
    try {
      const usuario = window.solicitudVentaAuth?.getUser?.();
      const correo = String(usuario?.username || "").trim().toLowerCase();
      if (!correo) return;
      const raw = localStorage.getItem(`${STORAGE_PREFIX}${correo}`);
      const data = raw ? JSON.parse(raw) : null;
      const folio = String(data?.folio || "").trim();
      if (/^SV-\d{4}-\d+$/.test(folio) && String(data?.estatus || "").toUpperCase() === "PENDIENTE FIRMA") {
        iniciarSeguimiento(folio);
      }
    } catch (_) {}
  }

  function iniciarSeguimiento(folio) {
    detenerSeguimiento();
    if (!folio) return;
    seguimientoFolio = folio;
    seguimientoTimer = window.setInterval(() => consultarEstado(folio), 15000);
    consultarEstado(folio);
  }

  function detenerSeguimiento() {
    if (seguimientoTimer) window.clearInterval(seguimientoTimer);
    seguimientoTimer = null;
  }

  async function consultarEstado(folio) {
    try {
      const token = await window.solicitudVentaAuth.getBackendAccessToken();
      if (!token) return;
      const response = await fetch(ESTADO_ENDPOINT, {
        method: "POST",
        headers: { "Content-Type": "application/json", Authorization: `Bearer ${token}` },
        body: JSON.stringify({ accion: "cargar", folio })
      });
      const data = await response.json().catch(() => null);
      if (!response.ok || !data?.ok) return;
      const estatus = String(data.estatus || "").trim().toUpperCase();
      if (estatus) ultimoEstatus = estatus;
      if (estatus === "FIRMADA" || estatus === "PENDIENTE VOBO" || estatus === "APROBADA") {
        detenerSeguimiento();
        mostrarMensaje(`La firma remota de ${folio} fue recibida. Estatus actual: ${estatus}.`, "ok");
      }
    } catch (_) {}
  }

  function bloquearFormularioPendienteFirma() {
    const form = document.getElementById("solicitudForm");
    if (!form) return;
    form.querySelectorAll("input, select, textarea, button").forEach((control) => {
      if (["btnLogout", "btnCopiarFirmaRemota"].includes(control.id)) return;
      if (control.closest("#firmaRemotaResultado")) return;
      control.disabled = true;
    });
  }

  function restablecer() {
    detenerSeguimiento();
    seguimientoFolio = "";
    ultimoEstatus = "";
    const resultBox = document.getElementById("firmaRemotaResultado");
    const urlInput = document.getElementById("firmaRemotaUrl");
    if (resultBox) resultBox.hidden = true;
    if (urlInput) urlInput.value = "";
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
