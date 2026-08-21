(() => {
  const ENDPOINT = "/api/solicitud-venta/reabrir-correccion.php";
  let contexto = null;
  let consultando = false;
  let reenviando = false;

  function esCorreccion() {
    return new URLSearchParams(window.location.search).get("correccion") === "1";
  }

  function normalizar(valor) {
    return String(valor || "")
      .trim()
      .toUpperCase()
      .normalize("NFD")
      .replace(/[\u0300-\u036f]/g, "")
      .replace(/\s+/g, " ");
  }

  function obtenerFolio() {
    const visible = document.querySelector(".folio-box strong")?.textContent?.trim().toUpperCase() || "";
    if (/^SV-\d{4}-\d+$/.test(visible)) return visible;
    const query = String(new URLSearchParams(window.location.search).get("folio") || "").trim().toUpperCase();
    return /^SV-\d{4}-\d+$/.test(query) ? query : "";
  }

  function obtenerItemId() {
    const query = String(new URLSearchParams(window.location.search).get("itemId") || "").trim();
    return /^\d+$/.test(query) && Number(query) > 0 ? String(Number(query)) : "";
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

  function asegurarBotonRegresarInicio() {
    if (document.getElementById("btnRegresarInicioInferior")) return;
    const actions = document.querySelector("#solicitudForm .form-actions > div");
    if (!(actions instanceof HTMLElement)) return;

    const button = document.createElement("button");
    button.id = "btnRegresarInicioInferior";
    button.type = "button";
    button.className = "secondary-button inline-button";
    button.textContent = "Regresar a inicio";
    button.addEventListener("click", () => {
      window.location.href = "/solicitud-venta/inicio/";
    });
    actions.insertBefore(button, actions.firstChild);
  }

  function firmaRegistrada(tipo) {
    try {
      const expediente = window.solicitudVentaExtras?.capturarEstadoExpediente?.() || {};
      if (Boolean(expediente?.firmas?.[tipo])) return true;
    } catch (_) {}

    const status = document.querySelector(`[data-signature-status="${tipo}"]`)?.textContent || "";
    return /ya guardada en el expediente|firma remota recibida|firma registrada/i.test(status);
  }

  function ambasFirmasRegistradas() {
    return firmaRegistrada("FIRMA_CLIENTE") && firmaRegistrada("FIRMA_VENDEDOR");
  }

  async function reenviarCorreccionFirmada(event, button) {
    if (!esCorreccion() || reenviando || !ambasFirmasRegistradas()) return false;

    event.preventDefault();
    event.stopImmediatePropagation();
    reenviando = true;
    button.disabled = true;

    const modalidad = document.getElementById("modalidadFirma");
    const modalidadAnterior = modalidad instanceof HTMLSelectElement ? modalidad.value : "";

    try {
      mostrarMensaje("Guardando los cambios de la corrección antes de regresar a Vo.Bo...");
      if (typeof window.guardarBorrador !== "function") {
        throw new Error("No fue posible localizar el guardado del borrador.");
      }
      await window.guardarBorrador();

      // firma-remota.js intercepta el click cuando la modalidad es REMOTA. En una
      // correccion con ambas firmas ya existentes no debemos crear otra liga. Al
      // enviar el formulario temporalmente como PRESENCIAL reutilizamos la ruta
      // estable de extras.js: carga documentos pendientes y llama validar.php,
      // que conserva ambas firmas y cambia BORRADOR -> PENDIENTE VOBO.
      if (modalidad instanceof HTMLSelectElement) modalidad.value = "PRESENCIAL";

      const form = document.getElementById("solicitudForm");
      if (!(form instanceof HTMLFormElement)) throw new Error("No fue posible localizar el formulario.");
      form.requestSubmit(button);

      window.setTimeout(() => {
        if (modalidad instanceof HTMLSelectElement && modalidadAnterior) modalidad.value = modalidadAnterior;
      }, 0);
    } catch (error) {
      console.error("No fue posible reenviar la corrección a Vo.Bo.:", error);
      if (modalidad instanceof HTMLSelectElement && modalidadAnterior) modalidad.value = modalidadAnterior;
      button.disabled = false;
      mostrarMensaje(`No fue posible reenviar la corrección: ${error.message || error}`, "error");
    } finally {
      window.setTimeout(() => {
        reenviando = false;
      }, 500);
    }
    return true;
  }

  function asegurarCatalogoLugar() {
    const select = document.getElementById("lugar");
    if (!(select instanceof HTMLSelectElement)) return false;

    const existe = Array.from(select.options).some((option) =>
      normalizar(option.value || option.textContent) === "PUNTO DE VENTA"
    );

    if (!existe) {
      const option = document.createElement("option");
      option.value = "PUNTO DE VENTA";
      option.textContent = "PUNTO DE VENTA";
      const primeraReal = select.options.length > 1 ? select.options[1] : null;
      if (primeraReal) select.insertBefore(option, primeraReal);
      else select.appendChild(option);
    }
    return true;
  }

  function restaurarLugar() {
    if (!contexto?.lugar) return;
    const select = document.getElementById("lugar");
    if (!(select instanceof HTMLSelectElement)) return;

    asegurarCatalogoLugar();
    if (String(select.value || "").trim() !== "") return;

    const esperado = normalizar(contexto.lugar);
    let option = Array.from(select.options).find((item) =>
      normalizar(item.value || item.textContent) === esperado
    );

    if (!option) {
      option = document.createElement("option");
      option.value = String(contexto.lugar).trim().toUpperCase();
      option.textContent = String(contexto.lugar).trim().toUpperCase();
      select.appendChild(option);
    }

    select.value = option.value;
    select.dispatchEvent(new Event("input", { bubbles: true }));
    select.dispatchEvent(new Event("change", { bubbles: true }));
  }

  function asegurarPaginaFirmas() {
    if (!esCorreccion()) return;
    const tituloPaso = normalizar(document.getElementById("wizardStepTitle")?.textContent || "");
    if (tituloPaso !== "FIRMAS") return;

    const section = document.getElementById("firmasSection");
    if (!(section instanceof HTMLElement)) return;

    section.hidden = false;
    section.classList.remove("wizard-page-hidden");
    section.classList.add("wizard-page-active");

    [".section-title", ".remote-signature-mode", ".signature-grid"].forEach((selector) => {
      const node = section.querySelector(selector);
      if (!(node instanceof HTMLElement)) return;
      node.hidden = false;
      node.style.removeProperty("display");
    });
  }

  async function cargarContexto() {
    if (!esCorreccion() || consultando || contexto) return;
    if (!window.solicitudVentaAuth?.getBackendAccessToken) {
      setTimeout(cargarContexto, 150);
      return;
    }

    const folio = obtenerFolio();
    if (!folio) {
      setTimeout(cargarContexto, 150);
      return;
    }

    consultando = true;
    try {
      const token = await window.solicitudVentaAuth.getBackendAccessToken();
      if (!token) throw new Error("No fue posible obtener autorizacion.");

      const response = await fetch(ENDPOINT, {
        method: "POST",
        cache: "no-store",
        credentials: "same-origin",
        headers: {
          "Content-Type": "application/json",
          Authorization: `Bearer ${token}`
        },
        body: JSON.stringify({ folio, itemId: obtenerItemId() })
      });
      const data = await response.json().catch(() => null);
      if (!response.ok || !data?.ok) {
        throw new Error(data?.message || data?.error || `HTTP ${response.status}`);
      }

      contexto = data;
      restaurarLugar();
    } catch (error) {
      console.warn("No fue posible completar el contexto de correccion:", error);
    } finally {
      consultando = false;
    }
  }

  function iniciar() {
    asegurarBotonRegresarInicio();
    if (!esCorreccion()) return;

    asegurarCatalogoLugar();
    cargarContexto();

    let ciclos = 0;
    const timer = window.setInterval(() => {
      ciclos += 1;
      asegurarBotonRegresarInicio();
      asegurarCatalogoLugar();
      restaurarLugar();
      asegurarPaginaFirmas();
      if (ciclos >= 40) window.clearInterval(timer);
    }, 250);

    document.addEventListener("click", (event) => {
      const element = event.target instanceof Element ? event.target : null;
      const validate = element?.closest("#btnValidate");
      if (validate instanceof HTMLButtonElement && ambasFirmasRegistradas()) {
        void reenviarCorreccionFirmada(event, validate);
        return;
      }

      const target = element?.closest("#wizardNext, #wizardBack");
      if (!target) return;
      setTimeout(asegurarPaginaFirmas, 0);
      setTimeout(asegurarPaginaFirmas, 80);
    }, true);

    window.addEventListener("focus", () => {
      restaurarLugar();
      asegurarPaginaFirmas();
    });
  }

  if (document.readyState === "loading") {
    document.addEventListener("DOMContentLoaded", iniciar);
  } else {
    iniciar();
  }
})();
