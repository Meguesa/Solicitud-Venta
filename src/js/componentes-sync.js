(() => {
  let inicializado = false;
  let guardarOriginal = null;

  function iniciar() {
    if (inicializado) return;

    const form = document.getElementById('solicitudForm');
    if (!form || typeof window.guardarBorrador !== 'function' || typeof window.solicitudVentaComponentesObtener !== 'function') {
      setTimeout(iniciar, 60);
      return;
    }

    inicializado = true;
    instalarValidadorSeguro();
    envolverGuardado();
    interceptarValidacionComponentes(form);
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', () => setTimeout(iniciar, 0));
  } else {
    setTimeout(iniciar, 0);
  }

  function instalarValidadorSeguro() {
    window.solicitudVentaComponentesValidar = function () {
      const componentes = typeof window.solicitudVentaComponentesObtener === 'function'
        ? window.solicitudVentaComponentesObtener()
        : [];

      if (!Array.isArray(componentes) || !componentes.length) {
        return { ok: false, message: 'Agrega al menos un componente.' };
      }

      const cards = Array.from(document.querySelectorAll('#componentesContainer .component-card'));

      for (let index = 0; index < componentes.length; index += 1) {
        const componente = componentes[index] || {};
        if (!componente.tipoSolicitud || !componente.tipoOperacion) {
          return { ok: false, message: 'Todos los componentes deben tener tipo y operación.' };
        }

        const card = cards[index];
        if (!card) {
          return { ok: false, message: 'No fue posible validar uno de los componentes de la venta.' };
        }

        const invalido = Array.from(card.querySelectorAll('input, select, textarea')).find((control) =>
          typeof control.checkValidity === 'function' && !control.checkValidity()
        );

        if (invalido) {
          invalido.reportValidity?.();
          return { ok: false, message: `Faltan datos obligatorios en el componente ${index + 1}.` };
        }
      }

      const totalVenta = numero(document.getElementById('precioTotal')?.value);
      const totalAsignado = componentes.reduce((sum, item) => sum + numero(item?.montoComponente), 0);
      if (Math.abs(totalVenta - totalAsignado) > 0.009) {
        return { ok: false, message: 'La suma de los montos asignados debe ser igual al precio total de la venta.' };
      }

      const distribucion = document.getElementById('distribucionTipoUI')?.value || 'AUTOMATICA';
      if (distribucion === 'MANUAL_PROMOCION' && !document.getElementById('promocionNombreUI')?.value?.trim()) {
        return { ok: false, message: 'Captura el nombre de la promoción para una distribución manual.' };
      }

      return { ok: true };
    };
  }

  function numero(value) {
    const n = Number(value || 0);
    return Number.isFinite(n) ? n : 0;
  }

  function envolverGuardado() {
    if (window.__solicitudComponentesGuardadoEnvuelto) return;
    guardarOriginal = window.guardarBorrador;

    window.guardarBorrador = async function (...args) {
      await guardarOriginal.apply(this, args);

      const folio = obtenerFolioActual();
      const mensaje = document.getElementById('formMessage');
      const guardadoPrincipalOk = Boolean(
        folio &&
        mensaje?.classList.contains('ok') &&
        mensaje.textContent?.includes('guardado correctamente')
      );

      if (!guardadoPrincipalOk) return false;

      try {
        mostrarMensaje(`Sincronizando componentes de ${folio} en SharePoint...`);
        const resultado = await sincronizarComponentes(folio);
        await sincronizarClavesServicio(folio, resultado.registros || []);
        mostrarMensaje(
          `Borrador ${folio} guardado correctamente con ${resultado.componenteTotal} componente(s) en SharePoint.`,
          'ok'
        );
        window.__solicitudComponentesUltimaSincronizacionOk = true;
        return true;
      } catch (error) {
        console.error('Error al sincronizar componentes:', error);
        mostrarMensaje(
          `El borrador principal se guardó, pero no fue posible sincronizar todos los componentes: ${error.message || error}`,
          'error'
        );
        window.__solicitudComponentesUltimaSincronizacionOk = false;
        return false;
      }
    };

    window.__solicitudComponentesGuardadoEnvuelto = true;
  }

  function interceptarValidacionComponentes(form) {
    if (form.dataset.componentValidationIntercepted === '1') return;
    form.dataset.componentValidationIntercepted = '1';

    form.addEventListener('submit', (event) => {
      if (typeof window.solicitudVentaComponentesValidar !== 'function') return;
      const validacion = window.solicitudVentaComponentesValidar();
      if (validacion?.ok) return;

      event.preventDefault();
      event.stopImmediatePropagation();
      mostrarMensaje(validacion?.message || 'Revisa los componentes de la venta.', 'error');
      document.getElementById('componentesSection')?.scrollIntoView({ behavior: 'smooth', block: 'start' });
    }, true);
  }

  async function sincronizarComponentes(folio) {
    const token = await window.solicitudVentaAuth.getBackendAccessToken();
    if (!token) throw new Error('No fue posible obtener autorización para sincronizar los componentes.');

    const componentes = window.solicitudVentaComponentesObtener();
    const distribucion = window.solicitudVentaDistribucion || {};

    const response = await fetch('/api/solicitud-venta/componentes.php', {
      method: 'POST',
      headers: {
        'Content-Type': 'application/json',
        'Authorization': `Bearer ${token}`
      },
      body: JSON.stringify({
        folio,
        componentes,
        distribucionTipo: distribucion.tipo || document.getElementById('distribucionTipoUI')?.value || 'AUTOMATICA',
        promocionNombre: distribucion.promocionNombre || document.getElementById('promocionNombreUI')?.value?.trim() || ''
      })
    });

    const resultado = await response.json().catch(() => null);
    if (!response.ok || !resultado?.ok) {
      throw new Error(resultado?.message || resultado?.error || `HTTP ${response.status}`);
    }
    return resultado;
  }

  async function sincronizarClavesServicio(folio, registros) {
    const token = await window.solicitudVentaAuth.getBackendAccessToken();
    if (!token) throw new Error('No fue posible obtener autorización para guardar las claves de servicio.');

    const componentes = window.solicitudVentaComponentesObtener();
    if (!Array.isArray(registros) || registros.length !== componentes.length) {
      throw new Error('La respuesta de SharePoint no coincide con la cantidad de componentes.');
    }

    const response = await fetch('/api/solicitud-venta/servicios.php', {
      method: 'POST',
      headers: {
        'Content-Type': 'application/json',
        'Authorization': `Bearer ${token}`
      },
      body: JSON.stringify({ folio, componentes, registros })
    });

    const resultado = await response.json().catch(() => null);
    if (!response.ok || !resultado?.ok) {
      throw new Error(resultado?.message || resultado?.error || `HTTP ${response.status}`);
    }
    return resultado;
  }

  function obtenerFolioActual() {
    const folio = document.querySelector('.folio-box strong')?.textContent?.trim() || '';
    return /^SV-\d{4}-\d+$/.test(folio) ? folio : '';
  }

  function mostrarMensaje(texto, tipo = '') {
    if (typeof window.mostrarMensaje === 'function') {
      window.mostrarMensaje(texto, tipo);
      return;
    }
    const mensaje = document.getElementById('formMessage');
    if (!mensaje) return;
    mensaje.textContent = texto;
    mensaje.className = `form-message ${tipo}`.trim();
  }
})();
