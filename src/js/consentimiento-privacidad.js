(() => {
  const CONSENTIMIENTO_VERSION = "solicitud-venta-2026-09-11-v1";
  const AVISO_PRIVACIDAD_VERSION = "2026-09-11";
  const AVISO_URL = "https://portal.juanpablo.com.mx/privacidad.php";
  let instalado = false;
  let registrando = false;

  function iniciar() {
    if (instalado) return;
    const form = document.getElementById("solicitudForm");
    const firmasSection = document.getElementById("firmasSection");
    if (!form || !firmasSection || !window.solicitudVentaAuth) {
      setTimeout(iniciar, 80);
      return;
    }

    instalado = true;
    insertarEstilos();
    insertarConsentimiento(firmasSection);
    envolverFetchValidacion();
    form.addEventListener("submit", validarConsentimientoPresencial, true);
    document.addEventListener("change", (event) => {
      if (event.target instanceof Element && event.target.id === "modalidadFirma") sincronizarModalidad();
    });
    document.getElementById("btnReset")?.addEventListener("click", () => {
      const checkbox = document.getElementById("consentimientoPrivacidadPresencial");
      if (checkbox) checkbox.checked = false;
      setTimeout(sincronizarModalidad, 0);
    });
    sincronizarModalidad();
    setTimeout(sincronizarModalidad, 300);
  }

  if (document.readyState === "loading") {
    document.addEventListener("DOMContentLoaded", () => setTimeout(iniciar, 0));
  } else {
    setTimeout(iniciar, 0);
  }

  function insertarEstilos() {
    if (document.getElementById("solicitudPrivacyConsentStyles")) return;
    const style = document.createElement("style");
    style.id = "solicitudPrivacyConsentStyles";
    style.textContent = `
      .privacy-consent-box{margin:0 0 18px;padding:14px 16px;border:1px solid #c7d8e6;border-radius:12px;background:#f4f8fb;color:#2b2927}
      .privacy-consent-line{display:flex;gap:10px;align-items:flex-start;line-height:1.5;cursor:pointer}
      .privacy-consent-line input{width:20px;height:20px;flex:0 0 auto;margin-top:2px}
      .privacy-consent-line a{color:#174f7c;font-weight:700;text-underline-offset:2px}
      .privacy-consent-box small{display:block;margin:8px 0 0 30px;color:#6f665f}
      .privacy-consent-line input:focus-visible,.privacy-consent-line a:focus-visible{outline:3px solid #f0ad1f;outline-offset:3px}
    `;
    document.head.appendChild(style);
  }

  function insertarConsentimiento(section) {
    if (document.getElementById("consentimientoPrivacidadPresencialWrap")) return;
    const grid = section.querySelector(".signature-grid");
    const wrap = document.createElement("div");
    wrap.id = "consentimientoPrivacidadPresencialWrap";
    wrap.className = "privacy-consent-box";
    wrap.innerHTML = `
      <label class="privacy-consent-line" for="consentimientoPrivacidadPresencial">
        <input id="consentimientoPrivacidadPresencial" type="checkbox">
        <span>
          El cliente confirma que revisó la información de la Solicitud de Venta y que los datos y condiciones mostrados corresponden a lo acordado. Asimismo, declara que leyó el
          <a href="${AVISO_URL}" target="_blank" rel="noopener noreferrer">Aviso de Privacidad de MEGUESA, S.A. de C.V.</a>
          y autoriza el tratamiento de sus datos personales y, cuando corresponda, datos patrimoniales o financieros para elaborar, evaluar, formalizar, administrar y dar seguimiento a esta Solicitud de Venta.
        </span>
      </label>
      <small>Aviso de Privacidad: versión 11/09/2026 · Consentimiento: ${CONSENTIMIENTO_VERSION}</small>`;
    if (grid) section.insertBefore(wrap, grid);
    else section.appendChild(wrap);
  }

  function esFirmaRemota() {
    return document.getElementById("modalidadFirma")?.value === "REMOTA";
  }

  function sincronizarModalidad() {
    const wrap = document.getElementById("consentimientoPrivacidadPresencialWrap");
    if (!wrap) return;
    wrap.hidden = esFirmaRemota();
  }

  function validarConsentimientoPresencial(event) {
    if (esFirmaRemota()) return;
    const checkbox = document.getElementById("consentimientoPrivacidadPresencial");
    if (checkbox?.checked) return;
    event.preventDefault();
    event.stopImmediatePropagation();
    mostrarMensaje("Antes de enviar la solicitud, el cliente debe leer y aceptar el Aviso de Privacidad.", "error");
    checkbox?.focus();
    checkbox?.scrollIntoView({ behavior: "smooth", block: "center" });
  }

  function envolverFetchValidacion() {
    if (window.__solicitudConsentimientoFetchEnvuelto) return;
    const fetchOriginal = window.fetch.bind(window);

    window.fetch = async function(input, init = {}) {
      const url = typeof input === "string" ? input : String(input?.url || "");
      const method = String(init?.method || "GET").toUpperCase();

      if (!registrando && method === "POST" && url.includes("/api/solicitud-venta/validar.php")) {
        if (esFirmaRemota()) return fetchOriginal(input, init);

        const checkbox = document.getElementById("consentimientoPrivacidadPresencial");
        if (!checkbox?.checked) {
          throw new Error("El cliente debe aceptar el Aviso de Privacidad antes de enviar la solicitud.");
        }

        let folio = "";
        try {
          const body = typeof init?.body === "string" ? JSON.parse(init.body) : null;
          folio = String(body?.folio || "").trim().toUpperCase();
        } catch (_) {}
        if (!/^SV-\d{4}-\d+$/.test(folio)) {
          throw new Error("No fue posible identificar el folio para registrar el consentimiento.");
        }

        registrando = true;
        try {
          const token = await window.solicitudVentaAuth.getBackendAccessToken();
          if (!token) throw new Error("No fue posible obtener autorización para registrar el consentimiento.");

          const response = await fetchOriginal("/api/solicitud-venta/consentimiento.php", {
            method: "POST",
            headers: {
              "Content-Type": "application/json",
              "Authorization": `Bearer ${token}`
            },
            body: JSON.stringify({
              folio,
              consentimientoPrivacidad: true,
              consentimientoVersion: CONSENTIMIENTO_VERSION,
              avisoPrivacidadVersion: AVISO_PRIVACIDAD_VERSION,
              metodo: "PRESENCIAL"
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

      return fetchOriginal(input, init);
    };

    window.__solicitudConsentimientoFetchEnvuelto = true;
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

  window.solicitudVentaConsentimiento = {
    version: CONSENTIMIENTO_VERSION,
    avisoVersion: AVISO_PRIVACIDAD_VERSION,
    avisoUrl: AVISO_URL
  };
})();