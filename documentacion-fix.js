(() => {
  let observer = null;
  let puenteFirmaVendedorInstalado = false;

  function normalizarDocumentacion() {
    const section = document.getElementById('documentosSection');
    if (!section) return false;

    // "Otros documentos" y su descripcion son opcionales. La plantilla
    // original de app.js los creo como obligatorios y, dependiendo del orden
    // de inicializacion, ese required podia sobrevivir hasta el wizard.
    const descripcion = document.getElementById('documentoOtros');
    if (descripcion) {
      descripcion.required = false;
      descripcion.removeAttribute('required');
      descripcion.removeAttribute('data-required-when-visible');
    }

    // Los archivos se validan por el modulo de expediente, no por required HTML.
    // Evitamos que un atributo residual vuelva a bloquear el paso Documentacion.
    section.querySelectorAll('input, select, textarea').forEach((control) => {
      if (control instanceof HTMLInputElement && control.type === 'file') return;
      if (control.id === 'documentoOtros') control.required = false;
    });

    return true;
  }

  function canvasFirmaVendedorTieneTinta() {
    const canvas = document.getElementById('firmaVendedor');
    if (!(canvas instanceof HTMLCanvasElement) || canvas.width <= 0 || canvas.height <= 0) return false;

    try {
      const context = canvas.getContext('2d', { willReadFrequently: true });
      if (!context) return false;
      const pixels = context.getImageData(0, 0, canvas.width, canvas.height).data;

      // El canvas se inicializa en blanco. Muestreamos pixeles para detectar
      // trazos oscuros reales y no depender del texto visible del resumen.
      // Esto evita falsos negativos cuando el wizard clona la seccion Firmas.
      const pixelCount = canvas.width * canvas.height;
      const sampleEvery = Math.max(1, Math.floor(pixelCount / 60000));
      const stride = sampleEvery * 4;

      for (let index = 0; index < pixels.length; index += stride) {
        const alpha = pixels[index + 3];
        if (alpha < 20) continue;
        const red = pixels[index];
        const green = pixels[index + 1];
        const blue = pixels[index + 2];
        if (red < 225 || green < 225 || blue < 225) return true;
      }
    } catch (error) {
      console.warn('No fue posible comprobar la firma del vendedor en el canvas:', error);
    }

    return false;
  }

  function instalarPuenteFirmaVendedor() {
    if (puenteFirmaVendedorInstalado) return;

    const extras = window.solicitudVentaExtras;
    if (!extras || typeof extras.capturarEstadoExpediente !== 'function') {
      setTimeout(instalarPuenteFirmaVendedor, 100);
      return;
    }

    if (extras.__firmaVendedorCanvasFixActivo) {
      puenteFirmaVendedorInstalado = true;
      return;
    }

    const capturarAnterior = extras.capturarEstadoExpediente.bind(extras);
    extras.capturarEstadoExpediente = () => {
      const estado = capturarAnterior() || {};
      const firmas = {
        ...(estado?.firmas && typeof estado.firmas === 'object' ? estado.firmas : {})
      };

      // En firma remota el resumen sustituye el canvas por el texto
      // "Firma registrada / consultar expediente". La validacion anterior
      // podia interpretar una firma recien dibujada como inexistente. Si el
      // canvas original contiene tinta real, se considera disponible y el
      // flujo posterior la sube al expediente antes de generar el enlace.
      if (!firmas.FIRMA_VENDEDOR && canvasFirmaVendedorTieneTinta()) {
        firmas.FIRMA_VENDEDOR = true;
      }

      return {
        ...estado,
        firmas
      };
    };

    extras.__firmaVendedorCanvasFixActivo = true;
    puenteFirmaVendedorInstalado = true;
  }

  function iniciar() {
    if (!normalizarDocumentacion()) {
      setTimeout(iniciar, 80);
      return;
    }

    const form = document.getElementById('solicitudForm');
    if (form && !observer) {
      observer = new MutationObserver(() => normalizarDocumentacion());
      observer.observe(form, { childList: true, subtree: true, attributes: true, attributeFilter: ['required'] });
    }

    // El wizard valida al hacer clic. Ejecutamos antes, en fase de captura,
    // para garantizar que Documentacion llegue limpia a esa validacion.
    document.addEventListener('click', (event) => {
      if (event.target instanceof Element && event.target.closest('#wizardNext')) {
        normalizarDocumentacion();
      }
    }, true);

    instalarPuenteFirmaVendedor();
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', iniciar, { once: true });
  } else {
    iniciar();
  }
})();
