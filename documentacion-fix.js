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

  function prepararArchivosParaResumen() {
    const reemplazos = [];

    document.querySelectorAll('#documentosSection input[type="file"]').forEach((input) => {
      if (!(input instanceof HTMLInputElement) || !input.files?.length) return;

      // El wizard clona los controles para construir el resumen. Los navegadores
      // no permiten asignar por JavaScript un valor no vacio a un input file;
      // copiar C:\\fakepath\\... provoca InvalidStateError y detiene el resumen.
      // Sustituimos el control solo durante el evento por un clon vacio y luego
      // restauramos el nodo original, conservando intacto su FileList y listeners.
      const proxy = input.cloneNode(true);
      proxy.value = '';
      input.replaceWith(proxy);
      reemplazos.push({ input, proxy });
    });

    if (!reemplazos.length) return;

    setTimeout(() => {
      reemplazos.forEach(({ input, proxy }) => {
        if (proxy.isConnected) proxy.replaceWith(input);
      });
      corregirControlesResumen();
    }, 0);
  }

  function corregirControlesResumen() {
    const summary = document.getElementById('wizardSummary');
    if (!summary || summary.hidden) return;

    // En el resumen ya no debe permanecer visible el boton que vuelve a ejecutar
    // "Revisar solicitud". La accion final corresponde al boton de Validar/Enviar.
    const next = document.getElementById('wizardNext');
    if (next) next.hidden = true;
  }

  function iniciar() {
    if (!normalizarDocumentacion()) {
      setTimeout(iniciar, 80);
      return;
    }

    const form = document.getElementById('solicitudForm');
    if (form && !observer) {
      observer = new MutationObserver(() => {
        normalizarDocumentacion();
        corregirControlesResumen();
      });
      observer.observe(form, {
        childList: true,
        subtree: true,
        attributes: true,
        attributeFilter: ['required', 'hidden']
      });
    }

    // El wizard valida al hacer clic. Ejecutamos antes, en fase de captura,
    // para garantizar que Documentacion llegue limpia a esa validacion y que
    // los inputs file no interrumpan la construccion del resumen.
    document.addEventListener('click', (event) => {
      if (!(event.target instanceof Element) || !event.target.closest('#wizardNext')) return;
      normalizarDocumentacion();
      prepararArchivosParaResumen();
      setTimeout(corregirControlesResumen, 0);
    }, true);
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', iniciar, { once: true });
  } else {
    iniciar();
  }
})();
