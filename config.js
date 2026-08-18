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

  if (!document.querySelector('script[src$="extras.js"]')) {
    const extras = document.createElement("script");
    extras.src = "extras.js";
    extras.defer = true;
    document.body.appendChild(extras);
  }

  // Componentes de la venta debe ser la primera informacion capturable del formulario.
  // componentes.js crea la seccion de forma dinamica, por eso esperamos hasta que exista.
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

  // Los indicadores de documentos son propiedad del proceso de carga de archivos.
  // Un Guardar borrador posterior no debe regresarlos a false solo porque ya no existen los checkboxes antiguos.
  setTimeout(() => {
    const originalConstruirPayload = window.construirPayloadBorrador;
    if (typeof originalConstruirPayload === "function" && !window.__solicitudPayloadDocumentosProtegido) {
      window.construirPayloadBorrador = function () {
        const payload = originalConstruirPayload();
        delete payload.documentoIdTitular;
        delete payload.documentoIdSustituto;
        delete payload.documentoComprobanteDomicilio;
        delete payload.documentoComprobantePago;
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
