(() => {
  'use strict';

  if (window.__solicitudFinanciamientoBridgeActivo) return;
  window.__solicitudFinanciamientoBridgeActivo = true;

  const STORAGE_LATEST = 'JDJP_FINANCIAMIENTO_PREFILL_LATEST';

  function valor(id) {
    return String(document.getElementById(id)?.value ?? '').trim();
  }

  function numero(id) {
    const n = Number(valor(id) || 0);
    return Number.isFinite(n) ? n : 0;
  }

  function folioActual() {
    try {
      if (typeof borradorActual !== 'undefined' && borradorActual?.folio) {
        return String(borradorActual.folio).trim().toUpperCase();
      }
    } catch (_) {}

    return String(document.querySelector('.folio-box strong')?.textContent || '')
      .trim()
      .toUpperCase()
      .match(/^SV-\d{4}-\d+$/)?.[0] || '';
  }

  function nombreCliente() {
    return [
      valor('clienteNombres'),
      valor('clienteApellidoPaterno'),
      valor('clienteApellidoMaterno')
    ].filter(Boolean).join(' ').replace(/\s+/g, ' ').trim();
  }

  function productoSolicitud() {
    return [valor('paquete'), valor('tipoVentaProcap')].filter(Boolean).join(' · ');
  }

  function guardarPrecarga() {
    if (valor('formaPago') !== 'CREDITO') return;

    const folio = folioActual();
    const total = numero('precioTotal');
    if (!folio || !(total > 0)) return;

    const data = {
      version: 2,
      folio,
      cliente: nombreCliente(),
      producto: productoSolicitud(),
      total,
      enganche: Math.max(0, numero('enganche')),
      tasaAnualPct: Math.max(0, numero('interesFinanciamiento')),
      meses: Math.max(0, Math.trunc(numero('mensualidades'))),
      primerPago: valor('fechaPrimerVencimiento')
    };

    try {
      localStorage.setItem(STORAGE_LATEST, JSON.stringify({
        createdAt: Date.now(),
        expiresAt: Date.now() + (10 * 60 * 1000),
        data
      }));
      console.info('[Solicitud Venta] Precarga temporal preparada para Financiamiento:', folio);
    } catch (error) {
      console.warn('[Solicitud Venta] No fue posible preparar la precarga temporal:', error);
    }
  }

  function iniciar() {
    document.addEventListener('click', (event) => {
      const target = event.target instanceof Element
        ? event.target.closest('#btnAbrirFinanciamiento')
        : null;
      if (!target) return;
      guardarPrecarga();
    }, true);
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', iniciar, { once: true });
  } else {
    iniciar();
  }
})();
