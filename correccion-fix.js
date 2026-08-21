(() => {
  const ENDPOINT = "/api/solicitud-venta/reabrir-correccion.php";
  const STORAGE_PREFIX = "solicitudVenta:borradorActivo:v1:";
  let contexto = null;
  let consultando = false;
  let reenviando = false;
  let restaurandoComponentes = false;
  let componentesContextoAplicados = false;

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

  function esperar(ms) {
    return new Promise((resolve) => window.setTimeout(resolve, ms));
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

  function asegurarFolioVisible() {
    const folio = String(contexto?.folio || obtenerFolio() || "").trim().toUpperCase();
    if (!/^SV-\d{4}-\d+$/.test(folio)) return;
    const strong = document.querySelector(".folio-box strong");
    if (strong && strong.textContent?.trim().toUpperCase() !== folio) strong.textContent = folio;
  }

  function asegurarBotonRegresarInicio() {
    // Version anterior: el boton se agregaba en la barra inferior. Lo retiramos
    // para dejar una sola accion, junto al folio como parte del encabezado.
    document.getElementById("btnRegresarInicioInferior")?.remove();

    if (document.getElementById("btnRegresarInicioSolicitud")) return;
    const banner = document.querySelector("#solicitudForm .form-banner");
    const folioBox = banner?.querySelector(".folio-box");
    if (!(banner instanceof HTMLElement) || !(folioBox instanceof HTMLElement)) return;

    const wrapper = document.createElement("div");
    wrapper.id = "accionesEncabezadoSolicitud";
    wrapper.style.display = "flex";
    wrapper.style.alignItems = "center";
    wrapper.style.justifyContent = "flex-end";
    wrapper.style.gap = "12px";
    wrapper.style.flexWrap = "wrap";

    const button = document.createElement("button");
    button.id = "btnRegresarInicioSolicitud";
    button.type = "button";
    button.className = "secondary-button inline-button";
    button.textContent = "Regresar a inicio";
    button.style.whiteSpace = "nowrap";
    button.addEventListener("click", () => {
      window.location.href = "/solicitud-venta/inicio/";
    });

    banner.insertBefore(wrapper, folioBox);
    wrapper.appendChild(button);
    wrapper.appendChild(folioBox);
  }

  function firmaContextoRegistrada(tipo) {
    if (!contexto) return false;
    const valor = tipo === "FIRMA_CLIENTE" ? contexto.firmaCliente : contexto.firmaVendedor;
    return normalizar(valor) === "FIRMADO";
  }

  function firmaRegistrada(tipo) {
    if (firmaContextoRegistrada(tipo)) return true;

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

  function limpiarSeguimientoFirmaAnterior() {
    if (!ambasFirmasRegistradas()) return;

    const input = document.getElementById("firmaRemotaUrl");
    if (input instanceof HTMLInputElement) input.value = "";

    const resultado = document.getElementById("firmaRemotaResultado");
    if (resultado instanceof HTMLElement) {
      resultado.hidden = true;
      resultado.style.display = "none";
    }

    const gestion = document.getElementById("firmaRemotaGestion");
    if (gestion instanceof HTMLElement) {
      gestion.hidden = true;
      gestion.style.display = "none";
    }

    try {
      const usuario = window.solicitudVentaAuth?.getUser?.();
      const correo = String(usuario?.username || document.getElementById("userEmail")?.textContent || "")
        .trim()
        .toLowerCase();
      if (!correo) return;

      const key = `${STORAGE_PREFIX}${correo}`;
      const raw = localStorage.getItem(key);
      if (!raw) return;
      const data = JSON.parse(raw);
      if (!data || typeof data !== "object") return;

      const folio = obtenerFolio();
      if (folio && data.folio && normalizar(data.folio) !== normalizar(folio)) return;

      delete data.firmaUrl;
      data.estatus = "BORRADOR";
      data.actualizado = new Date().toISOString();
      localStorage.setItem(key, JSON.stringify(data));
    } catch (error) {
      console.warn("No fue posible limpiar el seguimiento de la firma ya concluida:", error);
    }
  }

  function aplicarFirmasPersistidas() {
    if (!contexto) return;

    const cliente = firmaContextoRegistrada("FIRMA_CLIENTE");
    const vendedor = firmaContextoRegistrada("FIRMA_VENDEDOR");
    if (!cliente && !vendedor) return;

    // Sincronizamos el estado interno de extras.js para que la validacion normal
    // reconozca las firmas que ya existen en SharePoint, aunque el canvas este vacio.
    try {
      const extras = window.solicitudVentaExtras;
      if (extras?.capturarEstadoExpediente && extras?.restaurarEstadoExpediente) {
        const actual = extras.capturarEstadoExpediente() || {};
        const firmas = {
          ...(actual?.firmas && typeof actual.firmas === "object" ? actual.firmas : {})
        };
        if (cliente) firmas.FIRMA_CLIENTE = true;
        if (vendedor) firmas.FIRMA_VENDEDOR = true;
        extras.restaurarEstadoExpediente({ ...actual, firmas });
      }
    } catch (error) {
      console.warn("No fue posible sincronizar visualmente las firmas existentes:", error);
    }

    const aplicarFirma = (tipo, guardada) => {
      if (!guardada) return;
      const status = document.querySelector(`[data-signature-status="${tipo}"]`);
      if (status) status.textContent = "Firma ya guardada en el expediente. No es necesario volver a firmar.";

      const card = document.querySelector(`[data-signature-type="${tipo}"]`);
      const limpiar = card?.querySelector(".signature-clear");
      if (limpiar instanceof HTMLButtonElement) {
        limpiar.disabled = true;
        limpiar.hidden = true;
      }
    };

    aplicarFirma("FIRMA_CLIENTE", cliente);
    aplicarFirma("FIRMA_VENDEDOR", vendedor);

    if (cliente && vendedor) {
      limpiarSeguimientoFirmaAnterior();

      const modalidad = document.getElementById("modalidadFirma");
      if (modalidad instanceof HTMLSelectElement) modalidad.disabled = true;

      const ayuda = document.getElementById("firmaRemotaAyuda");
      if (ayuda instanceof HTMLElement) {
        ayuda.hidden = false;
        ayuda.innerHTML = "Las firmas del cliente y del vendedor ya están registradas. Al validar la corrección, la solicitud regresará directamente a <strong>Vo.Bo.</strong> sin generar otro enlace de firma.";
      }

      const validar = document.getElementById("btnValidate");
      if (validar instanceof HTMLButtonElement && !document.body.dataset.solicitudEstatus) {
        validar.disabled = false;
        validar.textContent = "Validar solicitud";
      }
    }
  }

  async function reenviarCorreccionFirmada(event, button) {
    if (!esCorreccion() || reenviando || !ambasFirmasRegistradas()) return false;

    event.preventDefault();
    event.stopImmediatePropagation();
    reenviando = true;
    button.disabled = true;

    const modalidad = document.getElementById("modalidadFirma");
    const modalidadAnterior = modalidad instanceof HTMLSelectElement ? modalidad.value : "";
    const modalidadDisabledAnterior = modalidad instanceof HTMLSelectElement ? modalidad.disabled : false;

    try {
      mostrarMensaje("Guardando los cambios de la corrección antes de regresar a Vo.Bo...");
      if (typeof window.guardarBorrador !== "function") {
        throw new Error("No fue posible localizar el guardado del borrador.");
      }
      await window.guardarBorrador();

      // firma-remota.js intercepta el click cuando la modalidad es REMOTA. En una
      // correccion ya firmada la omitimos temporalmente y dejamos que extras.js
      // cargue documentos pendientes y envie directamente a validar.php.
      if (modalidad instanceof HTMLSelectElement) {
        modalidad.disabled = false;
        modalidad.value = "PRESENCIAL";
      }

      const form = document.getElementById("solicitudForm");
      if (!(form instanceof HTMLFormElement)) throw new Error("No fue posible localizar el formulario.");
      form.requestSubmit(button);

      window.setTimeout(() => {
        if (modalidad instanceof HTMLSelectElement) {
          if (modalidadAnterior) modalidad.value = modalidadAnterior;
          modalidad.disabled = modalidadDisabledAnterior;
        }
      }, 0);
    } catch (error) {
      console.error("No fue posible reenviar la corrección a Vo.Bo.:", error);
      if (modalidad instanceof HTMLSelectElement) {
        if (modalidadAnterior) modalidad.value = modalidadAnterior;
        modalidad.disabled = modalidadDisabledAnterior;
      }
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

  async function esperarRestauracionInicial() {
    for (let intento = 0; intento < 35; intento += 1) {
      const texto = String(document.getElementById("formMessage")?.textContent || "");
      if (/borrador\s+SV-\d{4}-\d+\s+recuperado/i.test(texto)) return;
      await esperar(100);
    }
  }

  function asignarSelect(select, valor, dispararCambio = true) {
    if (!(select instanceof HTMLSelectElement)) return false;
    const texto = String(valor || "").trim();
    if (!texto) {
      select.value = "";
      if (dispararCambio) {
        select.dispatchEvent(new Event("input", { bubbles: true }));
        select.dispatchEvent(new Event("change", { bubbles: true }));
      }
      return true;
    }

    const esperado = normalizar(texto);
    let option = Array.from(select.options).find((item) =>
      normalizar(item.value) === esperado || normalizar(item.textContent) === esperado
    );

    if (!option) {
      option = document.createElement("option");
      option.value = texto.toUpperCase();
      option.textContent = texto;
      select.appendChild(option);
    }

    select.value = option.value;
    if (dispararCambio) {
      select.dispatchEvent(new Event("input", { bubbles: true }));
      select.dispatchEvent(new Event("change", { bubbles: true }));
    }
    return true;
  }

  function asignarInput(control, valor, evento = "input") {
    if (!(control instanceof HTMLInputElement || control instanceof HTMLTextAreaElement)) return false;
    control.value = valor == null ? "" : String(valor);
    control.dispatchEvent(new Event(evento, { bubbles: true }));
    return true;
  }

  async function asegurarCantidadCards(cantidad) {
    const container = document.getElementById("componentesContainer");
    const agregar = document.getElementById("btnAgregarComponente");
    if (!(container instanceof HTMLElement) || !(agregar instanceof HTMLButtonElement)) return [];

    let cards = Array.from(container.querySelectorAll(".component-card"));
    while (cards.length < cantidad) {
      agregar.click();
      await esperar(30);
      cards = Array.from(container.querySelectorAll(".component-card"));
    }
    while (cards.length > cantidad && cards.length > 1) {
      const eliminar = cards[cards.length - 1]?.querySelector(".component-remove");
      if (eliminar instanceof HTMLButtonElement) eliminar.click();
      await esperar(30);
      cards = Array.from(container.querySelectorAll(".component-card"));
    }
    return cards;
  }

  async function restaurarComponentesContexto() {
    if (componentesContextoAplicados || restaurandoComponentes || !esCorreccion()) return;
    const datos = Array.isArray(contexto?.componentesDetalle) ? contexto.componentesDetalle.slice() : [];
    if (!datos.length) return;

    restaurandoComponentes = true;
    try {
      // persistencia.js restaura primero el JSON historico. Esperamos a que termine
      // y despues aplicamos la copia autoritativa de los componentes de SharePoint.
      await esperarRestauracionInicial();

      datos.sort((a, b) => Number(a?.componenteNumero || 0) - Number(b?.componenteNumero || 0));
      let cards = await asegurarCantidadCards(datos.length);
      if (cards.length !== datos.length) throw new Error("No fue posible preparar todos los componentes de la solicitud.");

      const distribucion = document.getElementById("distribucionTipoUI");
      if (distribucion instanceof HTMLSelectElement) {
        asignarSelect(distribucion, contexto?.distribucionTipo || "AUTOMATICA", true);
      }
      const promocion = document.getElementById("promocionNombreUI");
      if (promocion instanceof HTMLInputElement) promocion.value = String(contexto?.promocionNombre || "");

      // Primero restauramos tipo y operacion. Esos cambios construyen los campos
      // dinamicos de servicio/propiedad y el catalogo de sucursal.
      datos.forEach((item, index) => {
        const card = cards[index];
        if (!card) return;
        asignarSelect(card.querySelector(".component-type"), item?.tipoSolicitud || "", true);
        asignarSelect(card.querySelector(".component-operation"), item?.tipoOperacion || "PREVISION", true);
      });

      await esperar(120);
      cards = Array.from(document.querySelectorAll("#componentesContainer .component-card"));

      datos.forEach((item, index) => {
        const card = cards[index];
        if (!card) return;

        asignarSelect(card.querySelector(".component-sucursal"), item?.sucursal || "", true);
        asignarSelect(card.querySelector(".component-service-type"), item?.servicioTipo || "", true);
        asignarSelect(card.querySelector(".component-service-ataud"), item?.servicioAtaud || "", true);
        asignarSelect(card.querySelector(".component-service-urna"), item?.servicioUrna || "", true);
        asignarSelect(card.querySelector(".component-service-duracion"), item?.servicioDuracion || "", true);

        asignarSelect(card.querySelector(".component-property-type"), item?.propiedadTipo || "", true);
        const seccionSelect = card.querySelector(".component-property-seccion-select");
        const seccionInput = card.querySelector(".component-property-seccion-input");
        if (seccionSelect instanceof HTMLSelectElement && !seccionSelect.hidden) {
          asignarSelect(seccionSelect, item?.propiedadSeccion || "", true);
        }
        if (seccionInput instanceof HTMLInputElement && !seccionInput.hidden) {
          asignarInput(seccionInput, item?.propiedadSeccion || "", "input");
        }

        asignarInput(card.querySelector(".component-property-manzana"), item?.propiedadManzana || "", "input");
        asignarInput(card.querySelector(".component-property-numero"), item?.propiedadNumero || "", "input");
        asignarInput(card.querySelector(".component-base"), Number(item?.precioBaseComponente || 0), "input");
      });

      // El campo Numero de servicio lo agrega otro modulo despues de conocer el
      // tipo de servicio. Esperamos a que aparezca antes de restaurarlo.
      for (let intento = 0; intento < 20; intento += 1) {
        const actuales = Array.from(document.querySelectorAll("#componentesContainer .component-card"));
        const faltan = datos.some((item, index) =>
          normalizar(item?.tipoSolicitud) === "SERVICIO"
          && String(item?.servicioNumero || "").trim() !== ""
          && !(actuales[index]?.querySelector(".component-service-numero") instanceof HTMLInputElement)
        );
        if (!faltan) break;
        await esperar(80);
      }

      cards = Array.from(document.querySelectorAll("#componentesContainer .component-card"));
      datos.forEach((item, index) => {
        const card = cards[index];
        if (!card) return;
        asignarInput(card.querySelector(".component-service-numero"), item?.servicioNumero || "", "input");

        if (normalizar(contexto?.distribucionTipo) === "MANUAL_PROMOCION") {
          asignarInput(card.querySelector(".component-amount"), Number(item?.montoComponente || 0), "input");
        }
      });

      if (typeof window.solicitudVentaComponentesRecalcular === "function") {
        window.solicitudVentaComponentesRecalcular();
      }

      componentesContextoAplicados = true;
      asegurarFolioVisible();
    } catch (error) {
      console.warn("No fue posible restaurar todos los componentes desde SharePoint:", error);
    } finally {
      restaurandoComponentes = false;
    }
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
      asegurarFolioVisible();
      restaurarLugar();
      aplicarFirmasPersistidas();
      void restaurarComponentesContexto();
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
      asegurarFolioVisible();
      asegurarCatalogoLugar();
      restaurarLugar();
      asegurarPaginaFirmas();
      aplicarFirmasPersistidas();
      if (!componentesContextoAplicados && !restaurandoComponentes) void restaurarComponentesContexto();
      if (ciclos >= 80) window.clearInterval(timer);
    }, 250);

    document.addEventListener("click", (event) => {
      const element = event.target instanceof Element ? event.target : null;
      const validate = element?.closest("#btnValidate");

      if (validate instanceof HTMLButtonElement && esCorreccion()) {
        // En una correccion nunca generamos automaticamente una segunda liga de
        // firma. Primero verificamos el estado persistido en SharePoint.
        if (!contexto) {
          event.preventDefault();
          event.stopImmediatePropagation();
          void cargarContexto();
          mostrarMensaje("Verificando las firmas ya registradas antes de validar la corrección...");
          return;
        }

        if (!componentesContextoAplicados && Array.isArray(contexto?.componentesDetalle) && contexto.componentesDetalle.length) {
          event.preventDefault();
          event.stopImmediatePropagation();
          void restaurarComponentesContexto();
          mostrarMensaje("Terminando de restaurar los componentes originales antes de validar la corrección...");
          return;
        }

        if (ambasFirmasRegistradas()) {
          void reenviarCorreccionFirmada(event, validate);
          return;
        }

        event.preventDefault();
        event.stopImmediatePropagation();
        mostrarMensaje(
          "La corrección no generará un nuevo enlace automáticamente. No fue posible confirmar en SharePoint ambas firmas existentes; revisa el expediente antes de continuar.",
          "error"
        );
        return;
      }

      const target = element?.closest("#wizardNext, #wizardBack");
      if (!target) return;
      setTimeout(asegurarPaginaFirmas, 0);
      setTimeout(asegurarPaginaFirmas, 80);
    }, true);

    window.addEventListener("focus", () => {
      asegurarFolioVisible();
      restaurarLugar();
      asegurarPaginaFirmas();
      aplicarFirmasPersistidas();
      if (!componentesContextoAplicados && !restaurandoComponentes) void restaurarComponentesContexto();
    });
  }

  if (document.readyState === "loading") {
    document.addEventListener("DOMContentLoaded", iniciar);
  } else {
    iniciar();
  }
})();