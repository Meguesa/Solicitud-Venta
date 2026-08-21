(() => {
  let observer = null;

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
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', iniciar, { once: true });
  } else {
    iniciar();
  }
})();
