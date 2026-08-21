(() => {
  const ENDPOINT = "/api/solicitud-venta/reabrir-correccion.php";
  let contexto = null;
  let consultando = false;

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

    // Nunca sobreescribimos una seleccion que el vendedor ya haya hecho durante
    // la correccion. Este respaldo solo repara borradores historicos que quedaron
    // con el select vacio aunque SharePoint si conserve field_3.
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

    // En correccion puede ocurrir una carrera entre el monitor de estatus y el
    // wizard: el encabezado avanza a Firmas pero la seccion conserva la clase
    // wizard-page-hidden. Forzamos coherencia solamente cuando Firmas es el paso
    // actual, sin alterar la navegacion de las demas paginas.
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
    if (!esCorreccion()) return;

    asegurarCatalogoLugar();
    cargarContexto();

    // Durante los primeros segundos varios modulos restauran estado y recalculan
    // el wizard. Reaplicamos solo reparaciones idempotentes para evitar que una
    // restauracion tardia vuelva a dejar Lugar o Firmas vacios.
    let ciclos = 0;
    const timer = window.setInterval(() => {
      ciclos += 1;
      asegurarCatalogoLugar();
      restaurarLugar();
      asegurarPaginaFirmas();
      if (ciclos >= 40) window.clearInterval(timer);
    }, 250);

    document.addEventListener("click", (event) => {
      const target = event.target instanceof Element ? event.target.closest("#wizardNext, #wizardBack") : null;
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
