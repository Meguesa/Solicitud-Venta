window.SOLICITUD_VENTA_CONFIG = {
  msal: {
    clientId: "96212a5f-7229-449c-a46c-a5ec044f3b20",
    authority: "https://login.microsoftonline.com/888d54c0-f785-49d1-b967-54da8b0aed94",
    redirectUri: "https://portal.juanpablo.com.mx/solicitud-venta/",
    backendScope: "api://444e3a82-f188-4b5e-aa6c-50d8cbcab7b2/SolicitudVenta.Access"
  }
};

// Titular Substituto es obligatorio para todos los tipos de solicitud.
// Se registra despues de app.js para que prevalezca sobre la visibilidad dinamica general.
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
});
