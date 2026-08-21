(() => {
  const params = new URLSearchParams(window.location.search);

  // Guardia general del wizard: en algunos ciclos de restauracion/observacion la
  // seccion Firmas puede conservar la clase wizard-page-hidden aun cuando el
  // encabezado del wizard ya indica que estamos en ese paso. La mantenemos
  // sincronizada sin alterar los datos ni las firmas capturadas.
  instalarGuardiaFirmas();

  function instalarGuardiaFirmas() {
    let intentos = 0;

    const asegurar = () => {
      const section = document.getElementById('firmasSection');
      const title = document.getElementById('wizardStepTitle');
      const nombre = String(title?.textContent || '').trim().toUpperCase();

      if (!section || !title) {
        intentos += 1;
        if (intentos < 80) setTimeout(asegurar, 100);
        return;
      }

      if (nombre === 'FIRMAS') {
        section.hidden = false;
        section.classList.remove('wizard-page-hidden');
        section.classList.add('wizard-page-active');
      }
    };

    if (document.readyState === 'loading') {
      document.addEventListener('DOMContentLoaded', () => setTimeout(asegurar, 0));
    } else {
      setTimeout(asegurar, 0);
    }

    document.addEventListener('click', (event) => {
      const target = event.target instanceof Element ? event.target : null;
      if (!target?.closest('#wizardNext, #wizardBack')) return;
      setTimeout(asegurar, 0);
      setTimeout(asegurar, 80);
    }, true);

    const observarTitulo = () => {
      const title = document.getElementById('wizardStepTitle');
      if (!title) {
        setTimeout(observarTitulo, 100);
        return;
      }
      const observer = new MutationObserver(() => setTimeout(asegurar, 0));
      observer.observe(title, { childList: true, characterData: true, subtree: true });
      asegurar();
    };
    observarTitulo();
  }

  if (params.get('resumen') !== '1') return;

  const PRIVATE_ENDPOINT = '/api/solicitud-venta/estado-solicitud.php';
  const ESTATUS_RESUMEN = new Set(['PENDIENTE VOBO', 'PENDIENTE COBRANZA', 'APROBADA']);
  let ejecutando = false;
  let terminado = false;
  let intentos = 0;

  function iniciar() {
    if (ejecutando || terminado) return;

    const form = document.getElementById('solicitudForm');
    const auth = window.solicitudVentaAuth;
    const usuario = auth?.getUser?.();
    const wizard = window.solicitudVentaWizard;
    const componentesListos = typeof window.solicitudVentaComponentesObtener === 'function';
    const extrasListos = Boolean(window.solicitudVentaExtras?.restaurarEstadoExpediente);

    if (!form || !auth || !usuario || !wizard || typeof wizard.mostrarResumen !== 'function' || !componentesListos || !extrasListos) {
      setTimeout(iniciar, 120);
      return;
    }

    const referencia = obtenerReferencia();
    if (!referencia?.folio) return;

    ejecutando = true;
    intentos += 1;

    restaurarYMostrar(referencia)
      .then(() => {
        terminado = true;
      })
      .catch((error) => {
        console.error('No fue posible abrir directamente el resumen de la solicitud:', error);
        if (intentos < 8) setTimeout(iniciar, 400);
      })
      .finally(() => {
        ejecutando = false;
      });
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', () => setTimeout(iniciar, 0));
  } else {
    setTimeout(iniciar, 0);
  }

  async function restaurarYMostrar(referencia) {
    const data = await consultarSolicitud(referencia.folio);
    const estatus = String(data?.estatus || '').trim().toUpperCase();

    if (!ESTATUS_RESUMEN.has(estatus)) {
      terminado = true;
      return;
    }

    await aplicarEstadoCompleto(data?.estado || {}, data, referencia);
    await espera(120);

    const folio = document.querySelector('.folio-box strong')?.textContent?.trim() || referencia.folio;
    const cliente = document.getElementById('clienteNombres')?.value?.trim() || '';
    const componente = document.querySelector('#componentesContainer .component-card .component-type')?.value?.trim() || '';

    if (!/^SV-\d{4}-\d+$/.test(folio) || cliente === '' || componente === '') {
      throw new Error('La informacion de la solicitud aun no termina de restaurarse.');
    }

    document.body.dataset.solicitudEstatus = estatus;
    document.body.dataset.solicitudEstadoRestaurado = '1';
    bloquearSoloLectura();

    window.solicitudVentaWizard.mostrarResumen();
  }

  async function consultarSolicitud(folio) {
    const accessToken = await window.solicitudVentaAuth.getBackendAccessToken();
    if (!accessToken) throw new Error('No fue posible obtener autorizacion para consultar la solicitud.');

    const response = await fetch(PRIVATE_ENDPOINT, {
      method: 'POST',
      cache: 'no-store',
      headers: {
        'Content-Type': 'application/json',
        'Authorization': `Bearer ${accessToken}`
      },
      body: JSON.stringify({ accion: 'cargar', folio })
    });

    const data = await response.json().catch(() => null);
    if (!response.ok || !data?.ok) {
      throw new Error(data?.message || data?.error || `HTTP ${response.status}`);
    }
    return data;
  }

  async function aplicarEstadoCompleto(estado, data, referencia) {
    aplicarControles(estado?.controles || {});
    await restaurarComponentesUI(estado?.componentes || [], estado?.distribucion || {});
    window.solicitudVentaExtras?.restaurarEstadoExpediente?.(
      estado?.expediente || { version: 1, documentos: {}, firmas: {} }
    );

    if (typeof borradorActual !== 'undefined') {
      borradorActual.itemId = String(data?.itemId || referencia.itemId || '');
      borradorActual.folio = String(data?.folio || referencia.folio || '');
    }
    if (typeof window.actualizarFolio === 'function') {
      window.actualizarFolio(data?.folio || referencia.folio);
    }

    recalcularDespuesDeRestaurar();
  }

  function aplicarControles(controles) {
    Object.entries(controles || {}).forEach(([id, estado]) => {
      if (!estado || typeof estado !== 'object') return;
      const control = document.getElementById(id);
      if (!(control instanceof HTMLInputElement || control instanceof HTMLSelectElement || control instanceof HTMLTextAreaElement)) return;
      if (control instanceof HTMLInputElement && control.type === 'file') return;

      if (estado.tipo === 'checked' && control instanceof HTMLInputElement) {
        control.checked = Boolean(estado.valor);
      } else if ('valor' in estado) {
        control.value = estado.valor == null ? '' : String(estado.valor);
      }
    });
  }

  async function restaurarComponentesUI(datosComponentes, distribucion) {
    const container = await esperarElemento('componentesContainer');
    const botonAgregar = document.getElementById('btnAgregarComponente');
    if (!container || !botonAgregar) return;

    const componentes = Array.isArray(datosComponentes) && datosComponentes.length
      ? datosComponentes
      : [{ tipoSolicitud: '', tipoOperacion: 'PREVISION' }];

    const agregarEstabaDisabled = botonAgregar.disabled;
    botonAgregar.disabled = false;

    try {
      const distribucionSelect = document.getElementById('distribucionTipoUI');
      if (distribucionSelect) {
        distribucionSelect.value = distribucion?.tipo === 'MANUAL_PROMOCION' ? 'MANUAL_PROMOCION' : 'AUTOMATICA';
        disparar(distribucionSelect, 'change');
      }
      const promocion = document.getElementById('promocionNombreUI');
      if (promocion) promocion.value = distribucion?.promocionNombre || '';

      let cards = Array.from(container.querySelectorAll('.component-card'));
      while (cards.length < componentes.length) {
        botonAgregar.click();
        cards = Array.from(container.querySelectorAll('.component-card'));
      }
      while (cards.length > componentes.length && cards.length > 1) {
        const remove = cards[cards.length - 1].querySelector('.component-remove');
        if (!remove) break;
        const estabaDisabled = remove.disabled;
        remove.disabled = false;
        remove.click();
        if (remove.isConnected) remove.disabled = estabaDisabled;
        cards = Array.from(container.querySelectorAll('.component-card'));
      }

      cards = Array.from(container.querySelectorAll('.component-card'));

      componentes.forEach((datos, index) => {
        const card = cards[index];
        if (!card) return;
        asignarSelect(card.querySelector('.component-type'), datos.tipoSolicitud || '', true);
        asignarSelect(card.querySelector('.component-operation'), datos.tipoOperacion || 'PREVISION', true);
      });

      await esperarCamposNumeroServicio(cards, componentes);

      componentes.forEach((datos, index) => {
        const card = cards[index];
        if (!card) return;

        asignarSelect(card.querySelector('.component-service-type'), datos.servicioTipo || '', true);
        asignarSelect(card.querySelector('.component-service-ataud'), datos.servicioAtaud || '', true);
        asignarSelect(card.querySelector('.component-service-urna'), datos.servicioUrna || '', false);
        asignarSelect(card.querySelector('.component-service-duracion'), datos.servicioDuracion || '', false);

        asignarSelect(card.querySelector('.component-property-type'), datos.propiedadTipo || '', false);
        const seccionSelect = card.querySelector('.component-property-seccion-select');
        const seccionInput = card.querySelector('.component-property-seccion-input');
        if (seccionSelect && !seccionSelect.hidden) asignarSelect(seccionSelect, datos.propiedadSeccion || '', true);
        if (seccionInput && !seccionInput.hidden) {
          seccionInput.value = datos.propiedadSeccion || '';
          disparar(seccionInput, 'input');
        }

        asignarInput(card.querySelector('.component-property-manzana'), datos.propiedadManzana || '', 'input');
        asignarInput(card.querySelector('.component-property-numero'), datos.propiedadNumero || '', 'input');
        asignarInput(card.querySelector('.component-base'), numeroTexto(datos.precioBaseComponente), 'input');

        const numeroServicio = card.querySelector('.component-service-numero');
        if (numeroServicio) {
          numeroServicio.value = datos.servicioNumero || extraerNumeroServicio(datos.servicioClave || '');
          disparar(numeroServicio, 'input');
        }
      });

      if (document.getElementById('distribucionTipoUI')?.value === 'MANUAL_PROMOCION') {
        componentes.forEach((datos, index) => {
          const monto = cards[index]?.querySelector('.component-amount');
          if (!monto) return;
          monto.value = numeroTexto(datos.montoComponente, true);
          disparar(monto, 'input');
        });
      }

      if (typeof window.solicitudVentaComponentesRecalcular === 'function') {
        window.solicitudVentaComponentesRecalcular();
      }
    } finally {
      botonAgregar.disabled = agregarEstabaDisabled;
    }
  }

  function recalcularDespuesDeRestaurar() {
    if (typeof window.actualizarFinanciamiento === 'function') window.actualizarFinanciamiento();
    if (typeof window.actualizarInformacionLaboral === 'function') window.actualizarInformacionLaboral();
    if (typeof window.recalcularImportes === 'function') window.recalcularImportes();
    if (typeof window.copiarUsuarioEnVendedor === 'function') window.copiarUsuarioEnVendedor();
  }

  function bloquearSoloLectura() {
    const form = document.getElementById('solicitudForm');
    form?.querySelectorAll('input, select, textarea').forEach((control) => {
      control.disabled = true;
    });
    document.getElementById('btnAgregarComponente')?.setAttribute('disabled', 'disabled');
    document.querySelectorAll('.component-remove, [data-signature-type] button').forEach((button) => {
      button.disabled = true;
    });
    document.querySelectorAll("input[type='file']").forEach((input) => {
      input.disabled = true;
    });
  }

  function asignarSelect(control, value, emitirCambio) {
    if (!(control instanceof HTMLSelectElement)) return;
    const buscado = String(value || '').trim().toUpperCase();
    const opcion = Array.from(control.options).find((item) =>
      item.value.trim().toUpperCase() === buscado || item.textContent.trim().toUpperCase() === buscado
    );
    control.value = opcion ? opcion.value : '';
    if (emitirCambio) disparar(control, 'change');
  }

  function asignarInput(control, value, evento) {
    if (!(control instanceof HTMLInputElement || control instanceof HTMLTextAreaElement)) return;
    control.value = value == null ? '' : String(value);
    if (evento) disparar(control, evento);
  }

  function disparar(control, tipo) {
    control?.dispatchEvent(new Event(tipo, { bubbles: true }));
  }

  function numeroTexto(value, moneda = false) {
    if (value === null || value === undefined || value === '') return moneda ? '0.00' : '';
    const numero = Number(value);
    if (!Number.isFinite(numero)) return moneda ? '0.00' : '';
    return moneda ? numero.toFixed(2) : String(numero);
  }

  function extraerNumeroServicio(clave) {
    const partes = String(clave || '').trim().split('-');
    return partes.length ? partes[partes.length - 1] : '';
  }

  async function esperarCamposNumeroServicio(cards, componentes) {
    const necesitaNumero = componentes.some((item) => item?.tipoSolicitud === 'SERVICIO');
    if (!necesitaNumero) return;

    for (let intento = 0; intento < 40; intento += 1) {
      const completos = componentes.every((item, index) =>
        item?.tipoSolicitud !== 'SERVICIO' || Boolean(cards[index]?.querySelector('.component-service-numero'))
      );
      if (completos) return;
      await espera(50);
    }
  }

  async function esperarElemento(id) {
    for (let intento = 0; intento < 60; intento += 1) {
      const elemento = document.getElementById(id);
      if (elemento) return elemento;
      await espera(50);
    }
    return null;
  }

  function obtenerReferencia() {
    const folio = String(params.get('folio') || '').trim().toUpperCase();
    const itemIdQuery = String(params.get('itemId') || '').trim();
    if (!/^SV-\d{4}-\d+$/.test(folio)) return null;

    const itemId = /^\d+$/.test(itemIdQuery) && Number(itemIdQuery) > 0
      ? String(Number(itemIdQuery))
      : String(Number(folio.split('-').pop() || 0) || '');

    return { folio, itemId };
  }

  function espera(ms) {
    return new Promise((resolve) => setTimeout(resolve, ms));
  }
})();