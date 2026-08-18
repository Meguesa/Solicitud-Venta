(() => {
  const STORAGE_PREFIX = 'solicitudVenta:borradorActivo:v1:';
  let inicializado = false;
  let restaurando = false;

  function iniciar() {
    if (inicializado) return;

    const form = document.getElementById('solicitudForm');
    const auth = window.solicitudVentaAuth;
    const usuario = auth?.getUser?.();
    const componentesListos = typeof window.solicitudVentaComponentesObtener === 'function';
    const guardadoComponentesListo = window.__solicitudComponentesGuardadoEnvuelto === true;

    if (!form || !auth || !usuario || !componentesListos || !guardadoComponentesListo || typeof window.guardarBorrador !== 'function') {
      setTimeout(iniciar, 120);
      return;
    }

    inicializado = true;
    envolverGuardado();
    document.getElementById('btnReset')?.addEventListener('click', () => {
      limpiarBorradorActivoLocal();
      setTimeout(() => restaurarComponentesUI([], { tipo: 'AUTOMATICA', promocionNombre: '' }), 0);
    });

    setTimeout(restaurarBorradorActivo, 150);
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', () => setTimeout(iniciar, 0));
  } else {
    setTimeout(iniciar, 0);
  }

  function envolverGuardado() {
    if (window.__solicitudPersistenciaGuardadoEnvuelto) return;
    const guardarAnterior = window.guardarBorrador;

    window.guardarBorrador = async function (...args) {
      const resultado = await guardarAnterior.apply(this, args);
      if (restaurando) return resultado;

      const folio = obtenerFolioActual();
      const itemId = obtenerItemIdActual();
      const mensaje = document.getElementById('formMessage');
      const textoMensaje = mensaje?.textContent || '';
      const principalGuardado = Boolean(
        folio &&
        itemId &&
        (mensaje?.classList.contains('ok') || textoMensaje.includes('borrador principal se guardó'))
      );

      if (!principalGuardado) return resultado;

      try {
        await guardarEstadoServidor(folio, itemId);
        guardarBorradorActivoLocal(folio, itemId);
      } catch (error) {
        console.error('No fue posible preparar la reanudación del borrador:', error);
        if (typeof window.mostrarMensaje === 'function') {
          window.mostrarMensaje(
            `El borrador ${folio} quedó guardado, pero no fue posible habilitar su recuperación automática: ${error.message || error}`,
            'error'
          );
        }
      }

      return resultado;
    };

    window.__solicitudPersistenciaGuardadoEnvuelto = true;
  }

  async function guardarEstadoServidor(folio, itemId) {
    const token = await window.solicitudVentaAuth.getBackendAccessToken();
    if (!token) throw new Error('No fue posible obtener autorización para guardar el estado del borrador.');

    const response = await fetch('/api/solicitud-venta/estado-borrador.php', {
      method: 'POST',
      headers: {
        'Content-Type': 'application/json',
        'Authorization': `Bearer ${token}`
      },
      body: JSON.stringify({
        accion: 'guardar',
        folio,
        itemId,
        estado: capturarEstado()
      })
    });

    const data = await response.json().catch(() => null);
    if (!response.ok || !data?.ok) {
      throw new Error(data?.message || data?.error || `HTTP ${response.status}`);
    }
  }

  function capturarEstado() {
    const form = document.getElementById('solicitudForm');
    const controles = {};

    form?.querySelectorAll('[id]').forEach((control) => {
      if (!(control instanceof HTMLInputElement || control instanceof HTMLSelectElement || control instanceof HTMLTextAreaElement)) return;
      if (control instanceof HTMLInputElement && ['file', 'button', 'submit', 'reset'].includes(control.type)) return;
      if (control.id === 'distribucionTipoUI' || control.id === 'promocionNombreUI') return;

      if (control instanceof HTMLInputElement && (control.type === 'checkbox' || control.type === 'radio')) {
        controles[control.id] = { tipo: 'checked', valor: Boolean(control.checked) };
      } else {
        controles[control.id] = { tipo: 'value', valor: String(control.value ?? '') };
      }
    });

    return {
      controles,
      componentes: typeof window.solicitudVentaComponentesObtener === 'function'
        ? window.solicitudVentaComponentesObtener()
        : [],
      distribucion: {
        tipo: document.getElementById('distribucionTipoUI')?.value || 'AUTOMATICA',
        promocionNombre: document.getElementById('promocionNombreUI')?.value?.trim() || ''
      }
    };
  }

  async function restaurarBorradorActivo() {
    if (restaurando || obtenerFolioActual()) return;

    const referencia = leerBorradorActivoLocal();
    if (!referencia?.folio || !referencia?.itemId) return;

    restaurando = true;
    try {
      if (typeof window.mostrarMensaje === 'function') {
        window.mostrarMensaje(`Recuperando borrador ${referencia.folio} desde SharePoint...`);
      }

      const token = await window.solicitudVentaAuth.getBackendAccessToken();
      if (!token) return;

      const response = await fetch('/api/solicitud-venta/estado-borrador.php', {
        method: 'POST',
        headers: {
          'Content-Type': 'application/json',
          'Authorization': `Bearer ${token}`
        },
        body: JSON.stringify({ accion: 'cargar', folio: referencia.folio })
      });

      const data = await response.json().catch(() => null);
      if (!response.ok || !data?.ok) {
        if ([403, 404, 409].includes(response.status)) limpiarBorradorActivoLocal();
        throw new Error(data?.message || data?.error || `HTTP ${response.status}`);
      }

      const estado = data.estado || {};
      aplicarControles(estado.controles || {});
      await restaurarComponentesUI(estado.componentes || [], estado.distribucion || {});

      if (typeof borradorActual !== 'undefined') {
        borradorActual.itemId = String(data.itemId || referencia.itemId || '');
        borradorActual.folio = String(data.folio || referencia.folio || '');
      }
      if (typeof window.actualizarFolio === 'function') window.actualizarFolio(data.folio || referencia.folio);

      recalcularDespuesDeRestaurar();
      if (typeof window.mostrarMensaje === 'function') {
        window.mostrarMensaje(
          `Borrador ${data.folio || referencia.folio} recuperado. Puedes continuar capturando y volver a guardarlo sin crear otro folio.`,
          'ok'
        );
      }
    } catch (error) {
      console.error('No fue posible recuperar el borrador activo:', error);
      if (typeof window.mostrarMensaje === 'function') {
        window.mostrarMensaje(`No fue posible recuperar el borrador anterior: ${error.message || error}`, 'error');
      }
    } finally {
      restaurando = false;
    }
  }

  function aplicarControles(controles) {
    Object.entries(controles).forEach(([id, estado]) => {
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
      cards[cards.length - 1].querySelector('.component-remove')?.click();
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

    if (distribucionSelect?.value === 'MANUAL_PROMOCION') {
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
  }

  function recalcularDespuesDeRestaurar() {
    if (typeof window.actualizarFormularioDinamico === 'function') window.actualizarFormularioDinamico();
    if (typeof window.actualizarFinanciamiento === 'function') window.actualizarFinanciamiento();
    if (typeof window.actualizarInformacionLaboral === 'function') window.actualizarInformacionLaboral();
    if (typeof window.recalcularImportes === 'function') window.recalcularImportes();
    if (typeof window.actualizarRequiredVisibles === 'function') window.actualizarRequiredVisibles();
    if (typeof window.copiarUsuarioEnVendedor === 'function') window.copiarUsuarioEnVendedor();
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

  function espera(ms) {
    return new Promise((resolve) => setTimeout(resolve, ms));
  }

  function obtenerFolioActual() {
    if (typeof borradorActual !== 'undefined' && /^SV-\d{4}-\d+$/.test(String(borradorActual.folio || ''))) {
      return String(borradorActual.folio);
    }
    const folio = document.querySelector('.folio-box strong')?.textContent?.trim() || '';
    return /^SV-\d{4}-\d+$/.test(folio) ? folio : '';
  }

  function obtenerItemIdActual() {
    if (typeof borradorActual !== 'undefined' && /^\d+$/.test(String(borradorActual.itemId || ''))) {
      return String(borradorActual.itemId);
    }
    const folio = obtenerFolioActual();
    if (!folio) return '';
    return String(Number(folio.split('-').pop() || 0) || '');
  }

  function claveStorage() {
    const usuario = window.solicitudVentaAuth?.getUser?.();
    const correo = String(usuario?.username || document.getElementById('userEmail')?.textContent || '').trim().toLowerCase();
    return correo ? `${STORAGE_PREFIX}${correo}` : '';
  }

  function guardarBorradorActivoLocal(folio, itemId) {
    const key = claveStorage();
    if (!key) return;
    try {
      localStorage.setItem(key, JSON.stringify({ folio, itemId, actualizado: new Date().toISOString() }));
    } catch (error) {
      console.warn('No fue posible guardar el apuntador local del borrador:', error);
    }
  }

  function leerBorradorActivoLocal() {
    const key = claveStorage();
    if (!key) return null;
    try {
      const raw = localStorage.getItem(key);
      if (!raw) return null;
      const data = JSON.parse(raw);
      if (!/^SV-\d{4}-\d+$/.test(String(data?.folio || '')) || !/^\d+$/.test(String(data?.itemId || ''))) {
        localStorage.removeItem(key);
        return null;
      }
      return data;
    } catch (error) {
      console.warn('No fue posible leer el apuntador local del borrador:', error);
      return null;
    }
  }

  function limpiarBorradorActivoLocal() {
    const key = claveStorage();
    if (!key) return;
    try {
      localStorage.removeItem(key);
    } catch (error) {
      console.warn('No fue posible limpiar el apuntador local del borrador:', error);
    }
  }
})();
