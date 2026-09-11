(() => {
  const originalFetch = window.fetch.bind(window);
  let registrando = false;

  window.fetch = async function(input, init = {}) {
    const url = typeof input === "string" ? input : String(input?.url || "");
    const method = String(init?.method || "GET").toUpperCase();

    if (!registrando && method === "POST" && url.includes("/api/solicitud-venta/firma-remota-publica.php")) {
      let payload = null;
      try {
        payload = typeof init?.body === "string" ? JSON.parse(init.body) : null;
      } catch (_) {}

      if (payload?.accion === "firmar") {
        if (!payload?.consentimientoPrivacidad) {
          throw new Error("Debes aceptar el Aviso de Privacidad antes de firmar.");
        }

        registrando = true;
        try {
          const response = await originalFetch("/api/solicitud-venta/consentimiento-remoto.php", {
            method: "POST",
            headers: { "Content-Type": "application/json" },
            body: JSON.stringify({
              token: payload.token,
              consentimientoPrivacidad: true,
              consentimientoVersion: payload.consentimientoVersion,
              avisoPrivacidadVersion: payload.avisoPrivacidadVersion,
              firmaDataUrl: payload.firmaDataUrl
            })
          });
          const data = await response.json().catch(() => null);
          if (!response.ok || !data?.ok) {
            throw new Error(data?.message || data?.error || `HTTP ${response.status}`);
          }
        } finally {
          registrando = false;
        }
      }
    }

    return originalFetch(input, init);
  };
})();