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
      ['totalPagar', 'financiamientoTotalPagarBase']
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

  // Se expone solo como apoyo para los modulos de restauracion/correccion.
  window.solicitudVentaFinanciamientoRestaurarExactos = restaurarImportesExactos;

  const programarRestauracion = () => {
    [0, 150, 500, 1200, 2500, 5000].forEach((delay) => {
      window.setTimeout(restaurarImportesExactos, delay);
    });
  };

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', programarRestauracion, { once: true });
  } else {
    programarRestauracion();
  }
})();
