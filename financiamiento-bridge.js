(() => {
  'use strict';

  if (window.__solicitudFinanciamientoBridgeActivo) return;
  window.__solicitudFinanciamientoBridgeActivo = true;

  const recalcularOriginal = window.recalcularImportes;
  if (typeof recalcularOriginal !== 'function') {
    console.warn('[Solicitud Venta] No se encontro recalcularImportes para proteger la corrida financiera.');
    return;
  }

  function valor(id) {
    const control = document.getElementById(id);
    return String(control?.value ?? '').trim();
  }

  function corridaCalculada() {
    return valor('financiamientoIntegrado').toUpperCase() === 'CALCULADO';
  }

  function restaurarImportesExactos() {
    if (!corridaCalculada()) return;

    [
      ['importeMensual', 'financiamientoImporteMensualBase'],
      ['montoFinanciar', 'financiamientoMontoFinanciarBase'],
      ['totalPagar', 'financiamientoTotalPagarBase'],
      ['importeAnualidad', 'financiamientoImporteAnualidadBase']
    ].forEach(([destinoId, baseId]) => {
      const destino = document.getElementById(destinoId);
      const exacto = valor(baseId);
      if (destino && exacto !== '') destino.value = exacto;
    });
  }

  // app.js usa una formula preliminar (saldo / mensualidades) mientras se captura
  // la solicitud. Esa formula es util antes de calcular Financiamiento, pero no debe
  // sobrescribir los importes oficiales cuando ya existe una corrida aplicada.
  window.recalcularImportes = function (...args) {
    const resultado = recalcularOriginal.apply(this, args);
    restaurarImportesExactos();
    return resultado;
  };

  async function sincronizarImporteAnualidad() {
    if (valor('formaPago').toUpperCase() !== 'CREDITO') return;
    const control = document.getElementById('importeAnualidad');
    if (!(control instanceof HTMLInputElement)) return;

    let folio = '';
    let itemId = '';
    try {
      if (typeof borradorActual !== 'undefined' && borradorActual) {
        folio = String(borradorActual.folio || '').trim().toUpperCase();
        itemId = String(borradorActual.itemId || '').trim();
      }
    } catch (_) {}

    if (!folio) {
      folio = String(document.querySelector('.folio-box strong')?.textContent || '').trim().toUpperCase();
    }
    if (!itemId && /^SV-\d{4}-\d+$/.test(folio)) {
      itemId = String(Number(folio.split('-').pop() || 0) || '');
    }
    if (!/^SV-\d{4}-\d+$/.test(folio) || !/^\d+$/.test(itemId) || Number(itemId) <= 0) return;

    const importeAnualidad = Math.max(0, Number(control.value || 0));
    const token = await window.solicitudVentaAuth?.getBackendAccessToken?.();
    if (!token) throw new Error('No fue posible obtener autorizacion para guardar el importe de anualidad.');

    const response = await fetch('/api/solicitud-venta/anualidad.php', {
      method: 'POST',
      headers: {
        'Content-Type': 'application/json',
        'Authorization': `Bearer ${token}`
      },
      body: JSON.stringify({ folio, itemId, importeAnualidad })
    });

    const data = await response.json().catch(() => null);
    if (!response.ok || !data?.ok) {
      throw new Error(data?.message || data?.error || `HTTP ${response.status}`);
    }
  }

  function envolverGuardadoAnualidad() {
    if (window.__solicitudAnualidadGuardadoEnvuelto) return true;
    if (typeof window.guardarBorrador !== 'function') return false;

    const guardarAnterior = window.guardarBorrador;
    window.guardarBorrador = async function (...args) {
      const resultado = await guardarAnterior.apply(this, args);
      try {
        await sincronizarImporteAnualidad();
      } catch (error) {
        console.error('[Solicitud Venta] No fue posible sincronizar Importe_Anualidad:', error);
        if (typeof window.mostrarMensaje === 'function') {
          window.mostrarMensaje(
            `El borrador principal se guardo, pero no fue posible guardar el importe de anualidad: ${error.message || error}`,
            'error'
          );
        }
        throw error;
      }
      return resultado;
    };

    window.__solicitudAnualidadGuardadoEnvuelto = true;
    return true;
  }

  // Se expone como apoyo para los modulos de restauracion/correccion.
  window.solicitudVentaFinanciamientoRestaurarExactos = restaurarImportesExactos;
  window.solicitudVentaFinanciamientoSincronizarAnualidad = sincronizarImporteAnualidad;

  const programarRestauracion = () => {
    [0, 150, 500, 1200, 2500, 5000].forEach((delay) => {
      window.setTimeout(restaurarImportesExactos, delay);
    });
  };

  const programarGuardado = () => {
    if (envolverGuardadoAnualidad()) return;
    let intentos = 0;
    const timer = window.setInterval(() => {
      intentos += 1;
      if (envolverGuardadoAnualidad() || intentos >= 40) window.clearInterval(timer);
    }, 150);
  };

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', () => {
      programarRestauracion();
      programarGuardado();
    }, { once: true });
  } else {
    programarRestauracion();
    programarGuardado();
  }
})();
