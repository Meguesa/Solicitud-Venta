(() => {
  const token = new URLSearchParams(location.search).get("token") || "";
  const canvas = document.getElementById("firmaCanvas");
  const ctx = canvas.getContext("2d");
  let dibujando = false;
  let tieneFirma = false;
  let cargada = false;

  document.getElementById("btnLimpiar").addEventListener("click", limpiarFirma);
  document.getElementById("btnFirmar").addEventListener("click", firmar);
  prepararCanvas();
  cargar();

  function prepararCanvas() {
    ajustarCanvas();
    const punto = (event) => {
      const rect = canvas.getBoundingClientRect();
      return {
        x: (event.clientX - rect.left) * (canvas.width / rect.width),
        y: (event.clientY - rect.top) * (canvas.height / rect.height)
      };
    };

    canvas.addEventListener("pointerdown", (event) => {
      event.preventDefault();
      canvas.setPointerCapture(event.pointerId);
      const p = punto(event);
      dibujando = true;
      ctx.beginPath();
      ctx.moveTo(p.x, p.y);
    });
    canvas.addEventListener("pointermove", (event) => {
      if (!dibujando) return;
      event.preventDefault();
      const p = punto(event);
      ctx.lineTo(p.x, p.y);
      ctx.stroke();
      tieneFirma = true;
    });
    ["pointerup", "pointercancel", "pointerleave"].forEach((name) => {
      canvas.addEventListener(name, () => {
        if (!dibujando) return;
        dibujando = false;
        ctx.closePath();
      });
    });
  }

  function ajustarCanvas() {
    const rect = canvas.getBoundingClientRect();
    const ratio = Math.max(1, window.devicePixelRatio || 1);
    canvas.width = Math.max(640, Math.round((rect.width || 720) * ratio));
    canvas.height = Math.round(210 * ratio);
    ctx.lineCap = "round";
    ctx.lineJoin = "round";
    ctx.lineWidth = 2.4 * ratio;
    ctx.strokeStyle = "#1f1f1f";
    ctx.fillStyle = "#fff";
    ctx.fillRect(0, 0, canvas.width, canvas.height);
  }

  function limpiarFirma() {
    ajustarCanvas();
    tieneFirma = false;
    mostrarMensaje("");
  }

  async function cargar() {
    if (!token) return mostrarError("El enlace no contiene un token de firma válido.");
    try {
      const data = await llamarApi("cargar");
      document.getElementById("loadingPanel").hidden = true;
      if (data.firmado) {
        document.getElementById("signedPanel").hidden = false;
        document.getElementById("signedMessage").textContent = `La solicitud ${data.folio} ya fue firmada y enviada al siguiente paso.`;
        return;
      }
      render(data.snapshot || {});
      document.getElementById("signatureContent").hidden = false;
      cargada = true;
    } catch (error) {
      mostrarError(error.message || String(error));
    }
  }

  function render(snapshot) {
    const cliente = snapshot.cliente || {};
    const venta = snapshot.venta || {};
    texto("folio", snapshot.folio || "");
    texto("clienteNombre", cliente.nombre || "Cliente");
    texto("fechaSolicitud", formatearFecha(snapshot.fechaSolicitud));
    texto("tipoVenta", venta.tipoVentaProcap || "—");
    texto("formaPago", venta.formaPago || "—");
    texto("metodoPago", venta.metodoPago || "—");
    texto("precioTotal", moneda(venta.precioTotal));
    texto("enganche", moneda(venta.enganche));
    texto("saldo", moneda(venta.saldo));

    if (venta.descripcion) {
      document.getElementById("descripcionVentaWrap").hidden = false;
      texto("descripcionVenta", venta.descripcion);
    }

    renderDetalleCompleto(snapshot.detalleCompleto || []);

    const container = document.getElementById("componentes");
    container.textContent = "";
    (snapshot.componentes || []).forEach((item) => container.appendChild(crearComponente(item)));
  }

  function renderDetalleCompleto(secciones) {
    const section = document.getElementById("detalleCompletoSection");
    const container = document.getElementById("detalleCompleto");
    if (!section || !container) return;

    container.textContent = "";
    if (!Array.isArray(secciones) || !secciones.length) {
      section.hidden = true;
      return;
    }

    secciones.forEach((seccion) => {
      if (!Array.isArray(seccion?.campos) || !seccion.campos.length) return;
      const article = document.createElement("article");
      article.className = "detail-section";

      const title = document.createElement("h3");
      title.textContent = seccion.titulo || "Información";
      article.appendChild(title);

      const grid = document.createElement("div");
      grid.className = "detail-grid";
      seccion.campos.forEach((campo) => grid.appendChild(crearCampoDetalle(campo)));
      article.appendChild(grid);
      container.appendChild(article);
    });

    section.hidden = !container.children.length;
  }

  function crearCampoDetalle(campo) {
    const div = document.createElement("div");
    div.className = "detail-field";
    const label = document.createElement("span");
    label.textContent = campo?.etiqueta || "Dato";
    const strong = document.createElement("strong");
    strong.textContent = formatearValorDetalle(campo);
    if (String(campo?.valor || "").length > 90) div.classList.add("detail-field-long");
    div.append(label, strong);
    return div;
  }

  function formatearValorDetalle(campo) {
    const valor = campo?.valor == null ? "" : String(campo.valor);
    if (campo?.tipo === "fecha") return formatearFecha(valor);
    if (campo?.tipo === "moneda") return moneda(valor);
    return valor || "—";
  }

  function crearComponente(item) {
    const box = document.createElement("article");
    box.className = "component-item";

    const title = document.createElement("div");
    title.className = "component-title";
    const strong = document.createElement("strong");
    strong.textContent = `Componente ${item.numero || ""} · ${item.tipo || ""}`;
    const amount = document.createElement("strong");
    amount.textContent = moneda(item.monto);
    title.append(strong, amount);
    box.appendChild(title);

    const details = document.createElement("div");
    details.className = "component-details";
    agregarDato(details, "Operación", item.operacion);
    agregarDato(details, "Tipo de venta ProCaP", item.tipoVentaProcap);
    agregarDato(details, item.tipo === "SERVICIO" ? "Clave de servicio / Referencia" : "Clave de propiedad / Referencia", item.referencia || item.propiedadClave);
    agregarDato(details, "Precio base del componente", moneda(item.precioBase));
    agregarDato(details, "Monto asignado", moneda(item.monto));
    if (item.tipo === "SERVICIO") {
      agregarDato(details, "Servicio", item.servicioTipo);
      agregarDato(details, "Ataúd", item.servicioAtaud);
      agregarDato(details, "Urna", item.servicioUrna);
      agregarDato(details, "Duración", item.servicioDuracion);
    } else {
      agregarDato(details, "Subtipo", item.propiedadSubtipo);
      agregarDato(details, "Sección", item.propiedadSeccion);
      agregarDato(details, "Manzana", item.propiedadManzana);
      agregarDato(details, "Número", item.propiedadNumero);
      if (item.propiedadClave && item.propiedadClave !== item.referencia) agregarDato(details, "Clave de propiedad", item.propiedadClave);
    }
    box.appendChild(details);
    return box;
  }

  function agregarDato(parent, label, value) {
    if (value === null || value === undefined || value === "") return;
    const div = document.createElement("div");
    const span = document.createElement("span");
    span.textContent = label;
    const strong = document.createElement("strong");
    strong.textContent = String(value);
    div.append(span, strong);
    parent.appendChild(div);
  }

  async function firmar() {
    if (!cargada) return;
    if (!document.getElementById("consentimiento").checked) {
      return mostrarMensaje("Debes aceptar la solicitud antes de firmar.", "error");
    }
    if (!tieneFirma) return mostrarMensaje("Firma dentro del recuadro antes de continuar.", "error");

    const button = document.getElementById("btnFirmar");
    button.disabled = true;
    mostrarMensaje("Registrando firma...");
    try {
      const data = await llamarApi("firmar", {
        consentimiento: true,
        firmaDataUrl: canvas.toDataURL("image/png")
      });
      document.getElementById("signatureContent").hidden = true;
      document.getElementById("signedPanel").hidden = false;
      document.getElementById("signedMessage").textContent = `La firma de la solicitud ${data.folio} quedó registrada correctamente. La solicitud continuará al proceso de Vo.Bo.`;
    } catch (error) {
      button.disabled = false;
      mostrarMensaje(error.message || String(error), "error");
    }
  }

  async function llamarApi(accion, extra = {}) {
    const response = await fetch("/api/solicitud-venta/firma-remota-publica.php", {
      method: "POST",
      headers: { "Content-Type": "application/json" },
      body: JSON.stringify({ accion, token, ...extra })
    });
    const data = await response.json().catch(() => null);
    if (!response.ok || !data?.ok) throw new Error(data?.message || data?.error || `HTTP ${response.status}`);
    return data;
  }

  function mostrarError(message) {
    document.getElementById("loadingPanel").hidden = true;
    document.getElementById("signatureContent").hidden = true;
    document.getElementById("errorPanel").hidden = false;
    document.getElementById("errorMessage").textContent = message;
  }

  function mostrarMensaje(message, tipo = "") {
    const el = document.getElementById("formMessage");
    el.textContent = message;
    el.className = `form-message ${tipo}`.trim();
  }

  function texto(id, value) {
    const el = document.getElementById(id);
    if (el) el.textContent = value == null ? "" : String(value);
  }

  function formatearFecha(value) {
    const textoFecha = String(value || "").trim();
    const match = textoFecha.match(/^(\d{4})-(\d{2})-(\d{2})/);
    if (match) return `${match[3]}/${match[2]}/${match[1]}`;
    return textoFecha || "—";
  }

  function moneda(value) {
    return new Intl.NumberFormat("es-MX", { style: "currency", currency: "MXN" }).format(Number(value || 0));
  }
})();