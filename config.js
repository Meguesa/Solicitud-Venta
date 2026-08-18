window.SOLICITUD_VENTA_CONFIG = {
  msal: {
    clientId: "96212a5f-7229-449c-a46c-a5ec044f3b20",
    authority: "https://login.microsoftonline.com/888d54c0-f785-49d1-b967-54da8b0aed94",
    redirectUri: "https://portal.juanpablo.com.mx/solicitud-venta/",
    backendScope: "api://444e3a82-f188-4b5e-aa6c-50d8cbcab7b2/SolicitudVenta.Access"
  }
};

// Titular Substituto es obligatorio para todos los tipos de solicitud.
document.addEventListener("DOMContentLoaded", () => {
  setTimeout(() => {
    const section = document.getElementById("sustitutoSection");
    const tipoSolicitud = document.getElementById("tipoSolicitud");
    const tipoOperacion = document.getElementById("tipoOperacion");

    const asegurarSustitutoUniversal = () => {
      if (!section) return;
      section.hidden = false;
      section.querySelectorAll("input, select, textarea").forEach((control) => {
        control.required = true;
      });
    };

    asegurarSustitutoUniversal();
    tipoSolicitud?.addEventListener("change", () => setTimeout(asegurarSustitutoUniversal, 0));
    tipoOperacion?.addEventListener("change", () => setTimeout(asegurarSustitutoUniversal, 0));
  }, 0);

  // El workflow del Portal inserta estos scripts directamente. Los cargamos aqui
  // solo como respaldo cuando se abre el repositorio Solicitud-Venta por separado.
  if (!document.querySelector('script[src$="componentes.js"]')) {
    const componentes = document.createElement("script");
    componentes.src = "componentes.js";
    componentes.defer = true;
    document.body.appendChild(componentes);
  }

  if (!document.querySelector('script[src$="componentes-sync.js"]')) {
    const sync = document.createElement("script");
    sync.src = "componentes-sync.js";
    sync.defer = true;
    document.body.appendChild(sync);
  }

  if (!document.querySelector('script[src$="extras.js"]')) {
    const extras = document.createElement("script");
    extras.src = "extras.js";
    extras.defer = true;
    document.body.appendChild(extras);
  }

  // Componentes de la venta debe ser la primera informacion capturable del formulario.
  let intentosComponentes = 0;
  const moverComponentesAlInicio = () => {
    const form = document.getElementById("solicitudForm");
    const banner = form?.querySelector(".form-banner");
    const componentes = document.getElementById("componentesSection");

    if (form && banner && componentes) {
      form.insertBefore(componentes, banner.nextElementSibling);
      const numero = componentes.querySelector(".section-title > span");
      if (numero) numero.textContent = "0";
      return;
    }

    intentosComponentes += 1;
    if (intentosComponentes < 30) setTimeout(moverComponentesAlInicio, 100);
  };
  moverComponentesAlInicio();

  // La referencia general deja de capturarse. Origen de venta pasa a General y la
  // identificacion propia de cada producto se conserva dentro de cada componente.
  configurarReferenciaGeneral();

  // Los servicios requieren numero y una clave de solo lectura por componente.
  configurarDatosServicioPorComponente();

  // Los indicadores de documentos son propiedad del proceso de carga de archivos.
  setTimeout(() => {
    const originalConstruirPayload = window.construirPayloadBorrador;
    if (typeof originalConstruirPayload === "function" && !window.__solicitudPayloadDocumentosProtegido) {
      window.construirPayloadBorrador = function () {
        const payload = originalConstruirPayload();
        delete payload.documentoIdTitular;
        delete payload.documentoIdSustituto;
        delete payload.documentoComprobanteDomicilio;
        delete payload.documentoComprobantePago;
        payload.referencia = "";
        return payload;
      };
      window.__solicitudPayloadDocumentosProtegido = true;
    }
  }, 0);

  // Forzar el orden final solicitado: Documentacion -> Firmas -> acciones.
  setTimeout(() => {
    const documentos = document.getElementById("documentosSection");
    const firmas = document.getElementById("firmasSection");
    if (documentos && firmas && documentos.parentElement === firmas.parentElement) {
      firmas.parentElement.insertBefore(documentos, firmas);
    }
  }, 250);
});

function configurarReferenciaGeneral() {
  let intentos = 0;

  const aplicar = () => {
    const referencia = document.getElementById("referencia");
    const origen = document.getElementById("origenVenta");
    const general = document.getElementById("fechaSolicitud")?.closest(".form-section");
    const referenciaSection = referencia?.closest(".form-section");
    const generalGrid = general?.querySelector(".form-grid");
    const origenLabel = origen?.closest("label");

    if (!referencia || !origen || !general || !referenciaSection || !generalGrid || !origenLabel) {
      intentos += 1;
      if (intentos < 40) setTimeout(aplicar, 100);
      return;
    }

    referencia.required = false;
    referencia.value = "";
    generalGrid.classList.remove("grid-2");
    generalGrid.classList.add("grid-3");
    if (origenLabel.parentElement !== generalGrid) generalGrid.appendChild(origenLabel);
    referenciaSection.hidden = true;

    const numeracion = new Map([
      ["General", "1"],
      ["Información del cliente", "2"],
      ["Información Laboral", "3"],
      ["Datos Titular Substituto", "4"],
      ["Referencias Familiares", "5"],
      ["Información Financiera y de Crédito", "6"],
      ["Información de la Venta", "7"],
      ["Importe y Forma de Pago", "8"]
    ]);

    document.querySelectorAll(".form-section .section-title").forEach((titulo) => {
      const nombre = titulo.querySelector("h3")?.textContent?.trim() || "";
      const numero = titulo.querySelector(":scope > span");
      if (numero && numeracion.has(nombre)) numero.textContent = numeracion.get(nombre);
    });
  };

  aplicar();
}

function configurarDatosServicioPorComponente() {
  let intentos = 0;

  const iniciar = () => {
    const container = document.getElementById("componentesContainer");
    if (!container || typeof window.solicitudVentaComponentesObtener !== "function") {
      intentos += 1;
      if (intentos < 60) setTimeout(iniciar, 100);
      return;
    }

    const prepararCard = (card) => {
      if (!card || card.dataset.servicioNumeroConfigurado === "1") return;
      const serviceFields = card.querySelector(".component-service-fields");
      const tipo = card.querySelector(".component-type");
      if (!serviceFields || !tipo) return;

      const numeroLabel = document.createElement("label");
      numeroLabel.innerHTML = 'Número<input class="component-service-numero" type="text" inputmode="numeric" autocomplete="off">';

      const claveLabel = document.createElement("label");
      claveLabel.innerHTML = 'Clave servicio<input class="component-service-clave" type="text" readonly>';

      serviceFields.appendChild(numeroLabel);
      serviceFields.appendChild(claveLabel);
      card.dataset.servicioNumeroConfigurado = "1";

      const numero = card.querySelector(".component-service-numero");
      const clave = card.querySelector(".component-service-clave");

      const actualizarClave = () => {
        const valorNumero = String(numero?.value || "").trim().toUpperCase();
        if (clave) clave.value = valorNumero ? `SERVICIO-${valorNumero}` : "";
      };

      const actualizarRequired = () => {
        const esServicio = tipo.value === "SERVICIO";
        if (numero) numero.required = esServicio;
        if (clave) clave.required = esServicio;
        if (!esServicio) {
          if (numero) numero.value = "";
          if (clave) clave.value = "";
        }
      };

      numero?.addEventListener("input", actualizarClave);
      tipo.addEventListener("change", () => {
        actualizarRequired();
        actualizarClave();
      });

      actualizarRequired();
      actualizarClave();
    };

    container.querySelectorAll(".component-card").forEach(prepararCard);

    const observer = new MutationObserver(() => {
      container.querySelectorAll(".component-card").forEach(prepararCard);
    });
    observer.observe(container, { childList: true, subtree: true });

    if (!window.__solicitudServicioPayloadEnvuelto) {
      const obtenerOriginal = window.solicitudVentaComponentesObtener;
      window.solicitudVentaComponentesObtener = function () {
        const payload = obtenerOriginal();
        const cards = Array.from(container.querySelectorAll(".component-card"));
        return payload.map((item, index) => {
          const card = cards[index];
          return {
            ...item,
            servicioNumero: card?.querySelector(".component-service-numero")?.value?.trim() || "",
            servicioClave: card?.querySelector(".component-service-clave")?.value?.trim() || ""
          };
        });
      };
      window.__solicitudServicioPayloadEnvuelto = true;
    }
  };

  iniciar();
}
