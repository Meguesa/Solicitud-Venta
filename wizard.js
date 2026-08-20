(() => {
  let inicializado = false;
  let paginaActual = 0;
  let enResumen = false;
  let header = null;
  let nav = null;
  let summary = null;
  let observer = null;

  function iniciar() {
    if (inicializado) return;
    const form = document.getElementById('solicitudForm');
    const banner = form?.querySelector('.form-banner');
    const actions = form?.querySelector('.form-actions');
    const componentes = document.getElementById('componentesSection');
    const firmas = document.getElementById('firmasSection');
    if (!form || !banner || !actions || !componentes || !firmas) {
      setTimeout(iniciar, 120);
      return;
    }

    inicializado = true;
    integrarVendedorEnGeneral(form);
    crearControles(form, banner, actions);
    enlazarGuardadoCompleto();
    observarCambios(form);
    mostrarPagina(0, false);
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', () => setTimeout(iniciar, 0));
  } else {
    setTimeout(iniciar, 0);
  }

  function integrarVendedorEnGeneral(form) {
    const vendedor = document.getElementById('vendedorNombre');
    const correo = document.getElementById('vendedorCorreo');
    const bloque = vendedor?.closest('.form-section');
    if (!vendedor || !correo || !bloque) return;

    const general = Array.from(form.children).find((element) =>
      element instanceof HTMLElement
      && element.matches('section.form-section')
      && Boolean(element.querySelector('#lugar'))
      && Boolean(element.querySelector('#fechaSolicitud'))
    );
    if (!general) return;

    const grid = bloque.querySelector('.form-grid');
    if (!grid) return;

    grid.classList.add('wizard-vendedor-grid');
    general.appendChild(grid);
    bloque.remove();
  }

  function crearControles(form, banner, actions) {
    header = document.createElement('div');
    header.id = 'wizardHeader';
    header.className = 'wizard-header';
    header.innerHTML = `
      <div class="wizard-progress-copy">
        <span id="wizardStepLabel">Paso 1</span>
        <strong id="wizardStepTitle">Componentes de la venta</strong>
      </div>
      <div class="wizard-progress-track" aria-hidden="true"><span id="wizardProgressBar"></span></div>`;
    form.insertBefore(header, banner.nextElementSibling);

    summary = document.createElement('section');
    summary.id = 'wizardSummary';
    summary.className = 'wizard-summary';
    summary.hidden = true;
    form.insertBefore(summary, actions);

    nav = document.createElement('div');
    nav.id = 'wizardNav';
    nav.className = 'wizard-nav';
    nav.innerHTML = `
      <button id="wizardBack" type="button" class="wizard-button wizard-button-secondary">← Atrás</button>
      <div class="wizard-nav-copy"><span id="wizardNavHint">Completa esta sección para continuar.</span></div>
      <button id="wizardNext" type="button" class="wizard-button wizard-button-primary">Siguiente →</button>`;
    form.insertBefore(nav, actions);

    document.getElementById('wizardBack')?.addEventListener('click', atras);
    document.getElementById('wizardNext')?.addEventListener('click', adelante);

    document.getElementById('btnReset')?.addEventListener('click', () => {
      setTimeout(() => mostrarPagina(0, false), 50);
    });
  }

  function enlazarGuardadoCompleto() {
    const boton = document.getElementById('btnSaveDraft');
    if (!boton || boton.dataset.wizardSaveDispatch === '1') return;
    boton.dataset.wizardSaveDispatch = '1';

    // app.js enlaza inicialmente el boton a la funcion original. Los modulos de
    // Componentes y Persistencia reemplazan despues window.guardarBorrador para
    // ejecutar una cadena completa de guardado. Este listener en fase de captura
    // evita ejecutar la referencia antigua y llama siempre a la version vigente.
    boton.addEventListener('click', async (event) => {
      event.preventDefault();
      event.stopImmediatePropagation();
      if (typeof window.guardarBorrador !== 'function') return;
      await window.guardarBorrador();
    }, true);
  }

  function observarCambios(form) {
    observer = new MutationObserver((mutations) => {
      if (enResumen) return;

      const cambioDePaginas = mutations.some((mutation) => {
        if (mutation.type === 'attributes') {
          const target = mutation.target;
          return target instanceof HTMLElement
            && target.matches('section.form-section')
            && target.id !== 'wizardSummary';
        }

        if (mutation.type === 'childList') {
          const nodes = [...mutation.addedNodes, ...mutation.removedNodes];
          return nodes.some((node) => node instanceof HTMLElement && (
            node.matches('section.form-section')
            || Boolean(node.querySelector?.('section.form-section'))
          ));
        }

        return false;
      });

      if (!cambioDePaginas) return;

      const paginas = paginasDisponibles();
      if (!paginas.length) return;
      if (paginaActual >= paginas.length) paginaActual = paginas.length - 1;
      aplicarVisibilidad(paginas);
      actualizarControles(paginas);
    });

    observer.observe(form, {
      attributes: true,
      attributeFilter: ['hidden'],
      subtree: true,
      childList: true
    });
  }

  function paginasDisponibles() {
    const form = document.getElementById('solicitudForm');
    if (!form) return [];
    return Array.from(form.children).filter((element) => {
      if (!(element instanceof HTMLElement)) return false;
      if (!element.matches('section.form-section')) return false;
      if (element.hidden) return false;
      if (element.id === 'wizardSummary') return false;
      if (element.querySelector('#tipoSolicitud')) return false;
      if (element.querySelector('#referencia')) return false;
      return true;
    });
  }

  function mostrarPagina(indice, scroll = true) {
    enResumen = false;
    summary.hidden = true;
    const paginas = paginasDisponibles();
    if (!paginas.length) return;
    paginaActual = Math.max(0, Math.min(indice, paginas.length - 1));
    aplicarVisibilidad(paginas);
    actualizarControles(paginas);
    controlarBotonFinal(false);
    if (scroll) header?.scrollIntoView({ behavior: 'smooth', block: 'start' });
  }

  function aplicarVisibilidad(paginas) {
    const todas = Array.from(document.querySelectorAll('#solicitudForm > section.form-section'));
    todas.forEach((section) => {
      if (section.hidden) {
        section.classList.remove('wizard-page-active');
        section.classList.add('wizard-page-hidden');
        return;
      }
      const indice = paginas.indexOf(section);
      section.classList.toggle('wizard-page-active', indice === paginaActual);
      section.classList.toggle('wizard-page-hidden', indice !== paginaActual);
    });
  }

  function actualizarControles(paginas) {
    const actual = paginas[paginaActual];
    const titulo = nombreSeccion(actual) || `Sección ${paginaActual + 1}`;
    const total = paginas.length + 1;
    const label = document.getElementById('wizardStepLabel');
    const title = document.getElementById('wizardStepTitle');
    const bar = document.getElementById('wizardProgressBar');
    const back = document.getElementById('wizardBack');
    const next = document.getElementById('wizardNext');
    const hint = document.getElementById('wizardNavHint');

    if (label) label.textContent = `Paso ${paginaActual + 1} de ${total}`;
    if (title) title.textContent = titulo;
    if (bar) bar.style.width = `${((paginaActual + 1) / total) * 100}%`;
    if (back) back.disabled = paginaActual === 0;
    if (next) next.textContent = paginaActual === paginas.length - 1 ? 'Revisar solicitud →' : 'Siguiente →';
    if (hint) hint.textContent = paginaActual === paginas.length - 1
      ? 'Continúa para revisar el resumen completo antes de enviar.'
      : 'Completa esta sección para continuar.';
  }

  function atras() {
    if (enResumen) {
      const paginas = paginasDisponibles();
      mostrarPagina(Math.max(0, paginas.length - 1));
      return;
    }
    mostrarPagina(paginaActual - 1);
  }

  function adelante() {
    const paginas = paginasDisponibles();
    const actual = paginas[paginaActual];
    if (!actual) return;

    if (!validarSeccion(actual)) return;

    if (paginaActual < paginas.length - 1) {
      mostrarPagina(paginaActual + 1);
      return;
    }

    if (!validarFormularioCompleto(paginas)) return;
    mostrarResumen(paginas);
  }

  function validarSeccion(section) {
    const controles = controlesValidables(section);
    for (const control of controles) {
      if (!control.checkValidity()) {
        control.reportValidity();
        control.focus({ preventScroll: true });
        control.scrollIntoView({ behavior: 'smooth', block: 'center' });
        mostrarMensaje('Completa los campos obligatorios de esta sección antes de continuar.', 'error');
        return false;
      }
    }
    return true;
  }

  function validarFormularioCompleto(paginas) {
    const validacionComponentes = typeof window.solicitudVentaComponentesValidar === 'function'
      ? window.solicitudVentaComponentesValidar()
      : { ok: true };
    if (!validacionComponentes?.ok) {
      const index = paginas.findIndex((section) => section.id === 'componentesSection');
      if (index >= 0) mostrarPagina(index);
      mostrarMensaje(validacionComponentes?.message || 'Revisa los componentes de la venta.', 'error');
      return false;
    }

    for (let index = 0; index < paginas.length; index += 1) {
      const invalid = controlesValidables(paginas[index]).find((control) => !control.checkValidity());
      if (!invalid) continue;
      mostrarPagina(index);
      invalid.reportValidity();
      invalid.focus({ preventScroll: true });
      invalid.scrollIntoView({ behavior: 'smooth', block: 'center' });
      mostrarMensaje('Faltan campos obligatorios por completar antes de mostrar el resumen.', 'error');
      return false;
    }
    return true;
  }

  function controlesValidables(section) {
    return Array.from(section.querySelectorAll('input, select, textarea')).filter((control) => {
      if (!(control instanceof HTMLInputElement || control instanceof HTMLSelectElement || control instanceof HTMLTextAreaElement)) return false;
      if (control.disabled) return false;
      if (control.closest('[hidden]')) return false;
      if (control instanceof HTMLInputElement && ['file', 'button', 'submit', 'reset'].includes(control.type)) return false;
      return control.required;
    });
  }

  function mostrarResumen(paginas) {
    enResumen = true;
    document.querySelectorAll('#solicitudForm > section.form-section').forEach((section) => section.classList.add('wizard-page-hidden'));
    construirResumen(paginas);
    summary.hidden = false;

    const total = paginas.length + 1;
    document.getElementById('wizardStepLabel').textContent = `Paso ${total} de ${total}`;
    document.getElementById('wizardStepTitle').textContent = 'Resumen de la solicitud';
    document.getElementById('wizardProgressBar').style.width = '100%';
    document.getElementById('wizardBack').disabled = false;
    document.getElementById('wizardNext').hidden = true;
    document.getElementById('wizardNavHint').textContent = 'Revisa toda la información. Si todo es correcto, utiliza las acciones inferiores para guardar o enviar.';
    controlarBotonFinal(true);
    header?.scrollIntoView({ behavior: 'smooth', block: 'start' });
  }

  function construirResumen(paginas) {
    summary.innerHTML = `
      <div class="wizard-summary-heading">
        <span>Resumen final</span>
        <h3>Revisa toda la solicitud</h3>
        <p>Esta vista reúne las secciones capturadas antes de guardar o enviar la solicitud.</p>
      </div>`;

    paginas.forEach((section) => {
      const clone = section.cloneNode(true);

      // cloneNode copia la estructura/atributos, pero no garantiza copiar el
      // estado vivo de controles modificados por el usuario. En particular, los
      // <select> regresaban visualmente a la opcion inicial "Selecciona" en el
      // resumen aunque el formulario original tuviera una opcion valida elegida.
      copiarEstadoFormulario(section, clone);

      clone.removeAttribute('id');
      clone.classList.remove('wizard-page-hidden', 'wizard-page-active');
      clone.classList.add('wizard-summary-section');
      clone.querySelectorAll('[id]').forEach((node) => node.removeAttribute('id'));
      clone.querySelectorAll('button').forEach((button) => button.remove());
      clone.querySelectorAll('input[type="file"]').forEach((input) => input.remove());
      clone.querySelectorAll('input, select, textarea').forEach((control) => {
        control.disabled = true;
        control.removeAttribute('required');
        control.removeAttribute('name');
      });
      clone.querySelectorAll('canvas').forEach((canvas) => {
        const firma = document.createElement('div');
        firma.className = 'wizard-signature-summary';
        firma.textContent = 'Firma registrada / consultar expediente';
        canvas.replaceWith(firma);
      });
      summary.appendChild(clone);
    });
  }

  function copiarEstadoFormulario(originalSection, clonedSection) {
    const originales = Array.from(originalSection.querySelectorAll('input, select, textarea'));
    const clonados = Array.from(clonedSection.querySelectorAll('input, select, textarea'));

    originales.forEach((original, index) => {
      const clone = clonados[index];
      if (!clone) return;

      if (original instanceof HTMLSelectElement && clone instanceof HTMLSelectElement) {
        // Copiar selectedness real, no solo el atributo selected del HTML inicial.
        Array.from(clone.options).forEach((option, optionIndex) => {
          option.selected = Boolean(original.options[optionIndex]?.selected);
        });
        clone.value = original.value;
        return;
      }

      if (original instanceof HTMLTextAreaElement && clone instanceof HTMLTextAreaElement) {
        clone.value = original.value;
        clone.textContent = original.value;
        return;
      }

      if (original instanceof HTMLInputElement && clone instanceof HTMLInputElement) {
        if (original.type === 'checkbox' || original.type === 'radio') {
          clone.checked = original.checked;
          if (original.checked) clone.setAttribute('checked', 'checked');
          else clone.removeAttribute('checked');
          return;
        }

        clone.value = original.value;
        clone.setAttribute('value', original.value);
      }
    });
  }

  function controlarBotonFinal(enFinal) {
    const next = document.getElementById('wizardNext');
    if (next) next.hidden = false;
    const validar = document.getElementById('btnValidate');
    if (!validar) return;
    const estatus = String(document.body.dataset.solicitudEstatus || document.querySelector('.status-pill')?.textContent || '').trim().toUpperCase();
    const editable = !estatus || estatus === 'BORRADOR';
    validar.classList.toggle('wizard-final-action-hidden', editable && !enFinal);
  }

  function nombreSeccion(section) {
    return section?.querySelector('.section-title h3')?.textContent?.trim()
      || section?.querySelector('h3')?.textContent?.trim()
      || '';
  }

  function mostrarMensaje(texto, tipo) {
    if (typeof window.mostrarMensaje === 'function') {
      window.mostrarMensaje(texto, tipo);
      return;
    }
    const mensaje = document.getElementById('formMessage');
    if (!mensaje) return;
    mensaje.textContent = texto;
    mensaje.className = `form-message ${tipo || ''}`.trim();
  }

  window.solicitudVentaWizard = {
    irAlInicio: () => mostrarPagina(0),
    mostrarResumen: () => {
      const paginas = paginasDisponibles();
      if (validarFormularioCompleto(paginas)) mostrarResumen(paginas);
    }
  };
})();
