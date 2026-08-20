(() => {
  const STORAGE_PREFIX = "solicitudVenta:borradorActivo:v1:";
  const PUBLIC_ENDPOINT = "/api/solicitud-venta/firma-remota-publica.php";
  const PRIVATE_ENDPOINT = "/api/solicitud-venta/estado-solicitud.php";
  let timer = null;
  let consultando = false;
  let ultimoEstatus = "";
  let puenteExpedienteInstalado = false;
  const firmasPersistidas = {
    FIRMA_CLIENTE: false,
    FIRMA_VENDEDOR: false
  };

  function iniciar() {
    if (!window.solicitudVentaAuth) {
      setTimeout(iniciar, 100);
      return;
    }

    asegurarPuenteExpediente();
    sembrarFolioDesdeQuery();
    capturarUrlActual();
    setTimeout(consultar, 900);
    timer = setInterval(consultar, 5000);
    window.addEventListener("focus", consultar);
    document.addEventListener("visibilitychange", () => {
      if (!document.hidden) consultar();
    });
  }

  if (document.readyState === "loading") {
    document.addEventListener("DOMContentLoaded", iniciar);
  } else {
    iniciar();
  }

  function asegurarPuenteExpediente() {
    if (puenteExpedienteInstalado) return;
    const extras = window.solicitudVentaExtras;
    if (!extras || typeof extras.capturarEstadoExpediente !== "function") {
      setTimeout(asegurarPuenteExpediente, 100);
      return;
    }

    const capturarOriginal = extras.capturarEstadoExpediente.bind(extras);
    extras.capturarEstadoExpediente = () => {
      const actual = capturarOriginal() || {};
      const firmas = {
        ...(actual?.firmas && typeof actual.firmas === "object" ? actual.firmas : {})
      };

      if (firmasPersistidas.FIRMA_CLIENTE) firmas.FIRMA_CLIENTE = true;
      if (firmasPersistidas.FIRMA_VENDEDOR) firmas.FIRMA_VENDEDOR = true;

      return {
        ...actual,
        firmas
      };
    };

    puenteExpedienteInstalado = true;
  }

  async function consultar() {
    if (consultando) return;

    const referencia = leerReferencia();
    const folio = obtenerFolioActual() || referencia?.folio || "";
    if (!/^SV-\d{4}-\d+$/.test(folio)) return;

    capturarUrlActual();
    consultando = true;
    try {
      const referenciaActual = leerReferencia();
      let data = null;
      const tokenFirma = extraerToken(referenciaActual?.firmaUrl || document.getElementById("firmaRemotaUrl")?.value || "");

      if (tokenFirma) {
        try {
          data = await consultarPublico(tokenFirma);
        } catch (error) {
          console.warn("Seguimiento firma remota por token:", error);
        }
      }

      if (!data || !data.ok) {
        data = await consultarPrivado(folio);
      }

      aplicarEstado(data, folio);
    } catch (error) {
      console.warn("No fue posible actualizar el estado de la firma remota:", error);
    } finally {
      consultando = false;
    }
  }

  async function consultarPublico(token) {
    const response = await fetch(PUBLIC_ENDPOINT, {
      method: "POST",
      cache: "no-store",
      headers: { "Content-Type": "application/json" },
      body: JSON.stringify({ accion: "cargar", token })
    });
    const data = await response.json().catch(() => null);
    if (!response.ok || !data?.ok) throw new Error(data?.message || data?.error || `HTTP ${response.status}`);
    return data;
  }

  async function consultarPrivado(folio) {
    const accessToken = await window.solicitudVentaAuth.getBackendAccessToken();
    if (!accessToken) throw new Error("No fue posible obtener autorización para consultar la solicitud.");

    const response = await fetch(PRIVATE_ENDPOINT, {
      method: "POST",
      cache: "no-store",
      headers: {
        "Content-Type": "application/json",
        Authorization: `Bearer ${accessToken}`
      },
      body: JSON.stringify({ accion: "cargar", folio })
    });
    const data = await response.json().catch(() => null);
    if (!response.ok || !data?.ok) throw new Error(data?.message || data?.error || `HTTP ${response.status}`);
    return data;
  }

  function aplicarEstado(data, folio) {
    sincronizarFirmasPersistidas(data);

    let estatus = String(data?.estatus || "").trim().toUpperCase();
    if (!estatus && data?.firmado) estatus = "PENDIENTE VOBO";
    if (!estatus) return;

    const cambio = ultimoEstatus !== estatus;
    ultimoEstatus = estatus;

    // BORRADOR permanece editable. El seguimiento solo debe bloquear una
    // solicitud cuando ya salió formalmente de la captura del vendedor.
    if (estatus === "BORRADOR") {
      restaurarBorradorSiFueBloqueado();
      return;
    }

    if (estatus === "PENDIENTE FIRMA") {
      bloquear("Pendiente de firma", estatus);
      const reset = document.getElementById("btnReset");
      if (reset) reset.textContent = "Nueva solicitud";
      mostrarMensaje(`Solicitud ${folio} pendiente de firma del cliente.`, "ok");
      return;
    }

    if (estatus === "PENDIENTE VOBO") {
      marcarFirmaClienteRecibida();
      bloquear("En Vo.Bo.", estatus);

      const reset = document.getElementById("btnReset");
      if (reset) reset.textContent = "Nueva solicitud";

      mostrarMensaje(
        `Firma remota recibida para ${folio}. La solicitud fue enviada a Vo.Bo. y permanece disponible en modo solo lectura.`,
        "ok"
      );
      return;
    }

    if (estatus === "APROBADA") {
      marcarFirmaClienteRecibida();
      bloquear("Aprobada", estatus);

      const reset = document.getElementById("btnReset");
      if (reset) reset.textContent = "Nueva solicitud";

      mostrarMensaje(
        `Solicitud ${folio} aprobada en Vo.Bo. Permanece disponible en modo solo lectura.`,
        "ok"
      );
      return;
    }

    if (estatus === "CORRECCION") {
      bloquear("En corrección", estatus);
      const reset = document.getElementById("btnReset");
      if (reset) reset.textContent = "Nueva solicitud";
      if (cambio) mostrarMensaje(`Solicitud ${folio} enviada a corrección.`, "ok");
      return;
    }

    bloquear(estatus, estatus);
    const reset = document.getElementById("btnReset");
    if (reset) reset.textContent = "Nueva solicitud";
    if (cambio) mostrarMensaje(`Solicitud ${folio}. Estatus: ${estatus}.`, "ok");
  }

  function sincronizarFirmasPersistidas(data) {
    asegurarPuenteExpediente();

    const expedienteFirmas = data?.estado?.expediente?.firmas && typeof data.estado.expediente.firmas === "object"
      ? data.estado.expediente.firmas
      : {};

    const firmaCliente = Boolean(expedienteFirmas.FIRMA_CLIENTE)
      || String(data?.firmaCliente || "").trim().toUpperCase() === "FIRMADO";
    const firmaVendedor = Boolean(expedienteFirmas.FIRMA_VENDEDOR)
      || String(data?.firmaVendedor || "").trim().toUpperCase() === "FIRMADO";

    // Solo promovemos estados positivos. Nunca reemplazamos una firma local
    // recién dibujada por un false remoto durante el polling.
    if (firmaCliente) firmasPersistidas.FIRMA_CLIENTE = true;
    if (firmaVendedor) firmasPersistidas.FIRMA_VENDEDOR = true;

    if (firmasPersistidas.FIRMA_CLIENTE) {
      const statusCliente = document.querySelector('[data-signature-status="FIRMA_CLIENTE"]');
      if (statusCliente && !statusCliente.textContent.includes("firma del cliente se solicitará")) {
        statusCliente.textContent = "Firma ya guardada en el expediente. No es necesario volver a firmar.";
      }
    }

    if (firmasPersistidas.FIRMA_VENDEDOR) {
      const statusVendedor = document.querySelector('[data-signature-status="FIRMA_VENDEDOR"]');
      if (statusVendedor) {
        statusVendedor.textContent = "Firma ya guardada en el expediente. No es necesario volver a firmar.";
      }
    }
  }

  function marcarFirmaClienteRecibida() {
    firmasPersistidas.FIRMA_CLIENTE = true;
    const cardCliente = document.querySelector('[data-signature-type="FIRMA_CLIENTE"]');
    cardCliente?.classList.remove("remote-signature-disabled");
    const statusCliente = cardCliente?.querySelector('[data-signature-status="FIRMA_CLIENTE"]');
    if (statusCliente) statusCliente.textContent = "Firma remota recibida y guardada en el expediente.";
  }

  function restaurarBorradorSiFueBloqueado() {
    const validar = document.getElementById("btnValidate");
    const textoActual = validar?.textContent?.trim().toUpperCase() || "";
    const bloqueadoPorMonitor = document.body.dataset.solicitudEstatus === "BORRADOR" || textoActual === "BORRADOR";
    if (!bloqueadoPorMonitor) return;

    delete document.body.dataset.solicitudEstatus;

    const pill = document.querySelector(".status-pill");
    if (pill) pill.textContent = "BORRADOR";

    const guardar = document.getElementById("btnSaveDraft");
    if (guardar) guardar.disabled = false;

    if (validar) {
      validar.disabled = false;
      const remota = document.getElementById("modalidadFirma")?.value === "REMOTA";
      validar.textContent = remota ? "Enviar a firma" : "Validar solicitud";
    }

    const reset = document.getElementById("btnReset");
    if (reset) {
      reset.disabled = false;
      reset.textContent = "Limpiar";
    }
  }

  function bloquear(textoBoton, estatus) {
    const pill = document.querySelector(".status-pill");
    if (pill) pill.textContent = estatus;

    const guardar = document.getElementById("btnSaveDraft");
    if (guardar) guardar.disabled = true;

    const validar = document.getElementById("btnValidate");
    if (validar) {
      validar.disabled = true;
      validar.textContent = textoBoton;
    }

    document.body.dataset.solicitudEstatus = estatus;
  }

  function sembrarFolioDesdeQuery() {
    const params = new URLSearchParams(location.search);
    const folio = String(params.get("folio") || "").trim().toUpperCase();
    if (!/^SV-\d{4}-\d+$/.test(folio)) return;

    const key = claveStorage();
    if (!key) return;
    const itemIdQuery = String(params.get("itemId") || "").trim();
    const itemId = /^\d+$/.test(itemIdQuery) && Number(itemIdQuery) > 0
      ? String(Number(itemIdQuery))
      : String(Number(folio.split("-").pop() || 0) || "");
    if (!itemId) return;

    try {
      const raw = localStorage.getItem(key);
      const actual = raw ? JSON.parse(raw) : {};
      localStorage.setItem(key, JSON.stringify({
        ...actual,
        folio,
        itemId,
        actualizado: new Date().toISOString()
      }));
    } catch (error) {
      console.warn("No fue posible preparar la recuperación por folio:", error);
    }
  }

  function capturarUrlActual() {
    const url = document.getElementById("firmaRemotaUrl")?.value?.trim() || "";
    if (!url || !extraerToken(url)) return;

    const key = claveStorage();
    if (!key) return;
    try {
      const raw = localStorage.getItem(key);
      const actual = raw ? JSON.parse(raw) : {};
      const folio = obtenerFolioActual() || actual?.folio || "";
      if (!/^SV-\d{4}-\d+$/.test(folio)) return;
      const params = new URLSearchParams(location.search);
      const itemIdQuery = String(params.get("itemId") || "").trim();
      const itemId = actual?.itemId
        || (/^\d+$/.test(itemIdQuery) && Number(itemIdQuery) > 0 ? String(Number(itemIdQuery)) : "")
        || String(Number(folio.split("-").pop() || 0) || "");
      localStorage.setItem(key, JSON.stringify({
        ...actual,
        folio,
        itemId,
        firmaUrl: url,
        actualizado: new Date().toISOString()
      }));
    } catch (error) {
      console.warn("No fue posible conservar el enlace de seguimiento:", error);
    }
  }

  function extraerToken(url) {
    try {
      return new URL(url, location.origin).searchParams.get("token") || "";
    } catch (_) {
      return "";
    }
  }

  function leerReferencia() {
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
