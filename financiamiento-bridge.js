(() => {
  'use strict';

  if (window.__solicitudFinanciamientoBridgeActivo) return;
  window.__solicitudFinanciamientoBridgeActivo = true;

  const ORIGIN = window.location.origin;
  const STORAGE_LATEST = 'JDJP_FINANCIAMIENTO_PREFILL_LATEST';
  const STORAGE_PREFIX = 'JDJP_FINANCIAMIENTO_PREFILL_';
  const CHANNEL_PREFIX = 'JDJP_FINANCIAMIENTO_CHANNEL_';
  const MSG_APPLY = 'JDJP_FINANCIAMIENTO_APPLY';

  let canalActivo = null;
  let popupActivo = null;

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

  function generarBridgeId() {
    try {
      if (crypto?.randomUUID) return crypto.randomUUID().replace(/-/g, '');
    } catch (_) {}
    return `${Date.now().toString(36)}${Math.random().toString(36).slice(2, 12)}`;
  }

  function construirData(folio) {
    return {
      version: 3,
      folio,
      cliente: nombreCliente(),
      producto: productoSolicitud(),
      total: numero('precioTotal'),
      enganche: Math.max(0, numero('enganche')),
      tasaAnualPct: Math.max(0, numero('interesFinanciamiento')),
      meses: Math.max(0, Math.trunc(numero('mensualidades'))),
      primerPago: valor('fechaPrimerVencimiento')
    };
  }

  function guardarPrecarga(bridgeId, data) {
    const envelope = {
      createdAt: Date.now(),
      expiresAt: Date.now() + (10 * 60 * 1000),
      data
    };

    try {
      localStorage.setItem(`${STORAGE_PREFIX}${bridgeId}`, JSON.stringify(envelope));
      localStorage.setItem(STORAGE_LATEST, JSON.stringify(envelope));
      console.info('[Solicitud Venta] Bridge preparado para Financiamiento:', bridgeId, data.folio);
    } catch (error) {
      console.warn('[Solicitud Venta] No fue posible guardar la precarga temporal:', error);
    }
  }

  function abrirCanal(bridgeId, popup) {
    try { canalActivo?.close(); } catch (_) {}
    canalActivo = null;

    if (!('BroadcastChannel' in window)) return;

    try {
      canalActivo = new BroadcastChannel(`${CHANNEL_PREFIX}${bridgeId}`);
      canalActivo.addEventListener('message', (event) => {
        const msg = event.data || {};
        if (msg.type !== MSG_APPLY) return;

        window.dispatchEvent(new MessageEvent('message', {
          data: msg,
          origin: ORIGIN,
          source: popup || popupActivo || null
        }));
      });
    } catch (error) {
      console.warn('[Solicitud Venta] No fue posible abrir BroadcastChannel:', error);
    }
  }

  function construirUrl(bridgeId, data) {
    const params = new URLSearchParams();
    params.set('integracion', 'solicitud');
    params.set('bridge', bridgeId);
    params.set('folio', data.folio);
    params.set('total', String(data.total));
    params.set('enganche', String(data.enganche));
    if (data.cliente) params.set('cliente', data.cliente);
    if (data.producto) params.set('producto', data.producto);
    if (data.tasaAnualPct > 0) params.set('tasa', String(data.tasaAnualPct));
    if (data.meses > 0) params.set('meses', String(data.meses));
    if (/^\d{4}-\d{2}-\d{2}$/.test(data.primerPago || '')) params.set('primerPago', data.primerPago);
    return `${ORIGIN}/financiamiento/?${params.toString()}`;
  }

  function estado(texto, tipo = 'warn') {
    const control = document.getElementById('financiamientoIntegracionStatus');
    if (!control) return;
    control.className = `fin-integracion-status ${tipo}`.trim();
    control.textContent = texto;
  }

  async function abrirFinanciamientoDesdeBridge(event, target) {
    if (valor('formaPago') !== 'CREDITO') return;

    const total = numero('precioTotal');
    if (!(total > 0)) return;

    event.preventDefault();
    event.stopPropagation();
    event.stopImmediatePropagation();

    const bridgeId = generarBridgeId();
    const popup = window.open('about:blank', `jdjpFinanciamiento_${bridgeId}`);
    if (!popup) {
      estado('El navegador bloqueó la ventana de Financiamiento. Permite ventanas emergentes e inténtalo nuevamente.', 'warn');
      return;
    }

    popupActivo = popup;
    const disabledAnterior = Boolean(target.disabled);
    target.disabled = true;

    try {
      estado('Preparando el folio para la corrida financiera...', 'warn');

      let folio = folioActual();
      if (!folio) {
        if (typeof window.guardarBorrador !== 'function') {
          throw new Error('No está disponible el guardado del borrador.');
        }
        await window.guardarBorrador();
        folio = folioActual();
      }

      if (!folio) throw new Error('No fue posible obtener el folio de la solicitud.');

      const data = construirData(folio);
      guardarPrecarga(bridgeId, data);
      abrirCanal(bridgeId, popup);

      const url = construirUrl(bridgeId, data);
      popup.location.replace(url);
      estado(`Abriendo Financiamiento para ${folio}.`, 'warn');
    } catch (error) {
      try { popup.close(); } catch (_) {}
      estado(`No fue posible abrir Financiamiento: ${error.message || error}`, 'warn');
    } finally {
      target.disabled = disabledAnterior;
    }
  }

  function iniciar() {
    document.addEventListener('click', (event) => {
      const target = event.target instanceof Element
        ? event.target.closest('#btnAbrirFinanciamiento')
        : null;
      if (!target) return;

      abrirFinanciamientoDesdeBridge(event, target).catch((error) => {
        console.error('[Solicitud Venta] Error en bridge de Financiamiento:', error);
      });
    }, true);
  }

  window.addEventListener('beforeunload', () => {
    try { canalActivo?.close(); } catch (_) {}
  });

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', iniciar, { once: true });
  } else {
    iniciar();
  }
})();
