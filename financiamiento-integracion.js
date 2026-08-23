(() => {
  'use strict';

  if (window.__solicitudFinanciamientoIntegracionActiva) return;
  window.__solicitudFinanciamientoIntegracionActiva = true;

  const ORIGIN = window.location.origin;
  const FINANCIAMIENTO_URL = `${ORIGIN}/financiamiento/?integracion=solicitud`;
  const MSG_READY = 'JDJP_FINANCIAMIENTO_READY';
  const MSG_PREFILL = 'JDJP_FINANCIAMIENTO_PREFILL';
  const MSG_APPLY = 'JDJP_FINANCIAMIENTO_APPLY';
  const MSG_ACK = 'JDJP_FINANCIAMIENTO_ACK';

  let ventanaFinanciamiento = null;
  let contextoPendiente = null;
  let aplicando = false;

  const idsQueInvalidan = ['precioTotal', 'enganche', 'mensualidades', 'interesFinanciamiento', 'fechaPrimerVencimiento'];
  const camposExactos = {
    importeMensual: 'financiamientoImporteMensualBase',
    montoFinanciar: 'financiamientoMontoFinanciarBase',
    totalPagar: 'financiamientoTotalPagarBase',
    interesFinanciamiento: 'financiamientoTasaBase',
    mensualidades: 'financiamientoMesesBase',
    fechaPrimerVencimiento: 'financiamientoFechaBase',
    diaPago: 'financiamientoDiaPagoBase'
  };

  function iniciar() {
    const form = document.getElementById('solicitudForm');
    const contenedor = document.getElementById('financiamientoFields');
    if (!form || !contenedor) {
      window.setTimeout(iniciar, 120);
      return;
    }

    asegurarOcultos(form);
    instalarEstilos();
    instalarTarjeta(contenedor);

    document.getElementById('btnAbrirFinanciamiento')?.addEventListener('click', abrirFinanciamiento);
    document.getElementById('formaPago')?.addEventListener('change', () => {
      if (valor('formaPago') !== 'CREDITO') invalidar('La forma de pago cambió.');
      actualizarEstadoUI();
    });

    idsQueInvalidan.forEach((id) => {
      const control = document.getElementById(id);
      if (!control) return;
      const evento = control.tagName === 'SELECT' ? 'change' : 'input';
      control.addEventListener(evento, (ev) => {
        if (!ev.isTrusted) return;
        if (estadoMarcador() === 'CALCULADO') invalidar('Las condiciones cambiaron. Vuelve a calcular la corrida financiera.');
      });
    });

    form.addEventListener('submit', (event) => {
      if (valor('formaPago') !== 'CREDITO' || estaVigente()) return;
      event.preventDefault();
      event.stopImmediatePropagation();
      mostrarMensajeLocal('Debes calcular y aplicar una corrida financiera antes de continuar con una venta a CREDITO.', 'error');
      document.getElementById('financiamientoIntegracionCard')?.scrollIntoView({ behavior: 'smooth', block: 'center' });
    }, true);

    window.addEventListener('message', recibirMensaje);

    [0, 300, 800, 1600, 3000].forEach((delay) => window.setTimeout(() => {
      restaurarValoresExactos();
      actualizarEstadoUI();
    }, delay));
  }

  function asegurarOcultos(form) {
    [
      ['financiamientoIntegrado', ''],
      ['financiamientoTotalBase', ''],
      ['financiamientoEngancheBase', ''],
      ['financiamientoPdfNombre', ''],
      ['financiamientoAplicadoUtc', ''],
      ['financiamientoImporteMensualBase', ''],
      ['financiamientoMontoFinanciarBase', ''],
      ['financiamientoTotalPagarBase', ''],
      ['financiamientoTasaBase', ''],
      ['financiamientoMesesBase', ''],
      ['financiamientoFechaBase', ''],
      ['financiamientoDiaPagoBase', '']
    ].forEach(([id, value]) => {
      if (document.getElementById(id)) return;
      const input = document.createElement('input');
      input.type = 'hidden';
      input.id = id;
      input.value = value;
      form.appendChild(input);
    });
  }

  function instalarEstilos() {
    if (document.getElementById('solicitudFinanciamientoIntegracionStyle')) return;
    const style = document.createElement('style');
    style.id = 'solicitudFinanciamientoIntegracionStyle';
    style.textContent = `
      .fin-integracion-card{margin:0 0 18px;padding:18px;border:1px solid #cfe0ef;border-left:4px solid #225b8a;border-radius:14px;background:#f6fbff;display:grid;gap:12px}
      .fin-integracion-head{display:flex;align-items:flex-start;justify-content:space-between;gap:16px;flex-wrap:wrap}
      .fin-integracion-head h4{margin:0 0 4px;font-size:17px;color:#163f63}
      .fin-integracion-head p{margin:0;color:#5c6b78;font-size:13px;line-height:1.45}
      .fin-integracion-actions{display:flex;gap:10px;align-items:center;flex-wrap:wrap}
      .fin-integracion-btn{border:0;border-radius:10px;background:#225b8a;color:#fff;font-weight:800;padding:11px 16px;cursor:pointer}
      .fin-integracion-btn:disabled{opacity:.55;cursor:not-allowed}
      .fin-integracion-status{padding:10px 12px;border-radius:10px;background:#fff;border:1px solid #dce6ee;font-size:13px;color:#46535f}
      .fin-integracion-status.ok{background:#edf9f1;border-color:#b9dfc5;color:#176b38}
      .fin-integracion-status.warn{background:#fff8e7;border-color:#ead49a;color:#805c00}
      .fin-integracion-summary{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:10px}
      .fin-integracion-summary div{background:#fff;border:1px solid #e1e8ee;border-radius:10px;padding:10px}
      .fin-integracion-summary span{display:block;font-size:11px;color:#687683;margin-bottom:3px}
      .fin-integracion-summary strong{font-size:14px;color:#1d2a34}
      @media(max-width:850px){.fin-integracion-summary{grid-template-columns:repeat(2,minmax(0,1fr))}}
    `;
    document.head.appendChild(style);
  }

  function instalarTarjeta(contenedor) {
    if (document.getElementById('financiamientoIntegracionCard')) return;
    const card = document.createElement('div');
    card.id = 'financiamientoIntegracionCard';
    card.className = 'fin-integracion-card';
    card.innerHTML = `
      <div class="fin-integracion-head">
        <div>
          <h4>Corrida financiera</h4>
          <p>Calcula las condiciones en la herramienta de Financiamiento. Al aplicar la corrida, sus datos y el PDF se guardarán dentro del expediente de esta solicitud.</p>
        </div>
        <div class="fin-integracion-actions"><button id="btnAbrirFinanciamiento" type="button" class="fin-integracion-btn">Calcular financiamiento →</button></div>
      </div>
      <div id="financiamientoIntegracionStatus" class="fin-integracion-status">Aún no hay una corrida financiera aplicada.</div>
      <div id="financiamientoIntegracionSummary" class="fin-integracion-summary" hidden></div>
    `;
    contenedor.insertBefore(card, contenedor.firstChild);
  }

  async function abrirFinanciamiento() {
    if (valor('formaPago') !== 'CREDITO') {
      mostrarMensajeLocal('Selecciona CREDITO como forma de pago antes de calcular financiamiento.', 'error');
      return;
    }
    const total = numero('precioTotal');
    if (!(total > 0)) {
      mostrarMensajeLocal('Captura primero el Precio total de la venta.', 'error');
      document.getElementById('precioTotal')?.focus();
      return;
    }

    const boton = document.getElementById('btnAbrirFinanciamiento');
    const popup = window.open('about:blank', 'jdjpFinanciamientoSolicitud');
    if (!popup) {
      mostrarMensajeLocal('El navegador bloqueó la ventana de Financiamiento. Permite ventanas emergentes para este portal e inténtalo nuevamente.', 'error');
      return;
    }

    try {
      if (boton) boton.disabled = true;
      estadoTexto('Preparando el folio para la corrida financiera...', 'warn');
      let folio = folioActual();
      if (!folio) {
        if (typeof window.guardarBorrador !== 'function') throw new Error('La función de guardado del borrador no está disponible.');
        await window.guardarBorrador();
        folio = folioActual();
      }
      if (!folio) throw new Error('No fue posible crear o recuperar el folio de la solicitud.');

      contextoPendiente = {
        version: 1,
        folio,
        cliente: nombreCliente(),
        producto: productoSolicitud(),
        total,
        enganche: Math.max(0, numero('enganche')),
        tasaAnualPct: Math.max(0, numero('interesFinanciamiento')),
        meses: Math.max(0, Math.trunc(numero('mensualidades'))),
        primerPago: valor('fechaPrimerVencimiento')
      };
      ventanaFinanciamiento = popup;
      popup.location.href = FINANCIAMIENTO_URL;
      estadoTexto(`Abriendo Financiamiento para ${folio}.`, 'warn');
    } catch (error) {
      try { popup.close(); } catch (_) {}
      mostrarMensajeLocal(`No fue posible abrir Financiamiento: ${error.message || error}`, 'error');
      estadoTexto('No fue posible preparar la corrida financiera.', 'warn');
    } finally {
      if (boton) boton.disabled = false;
    }
  }

  function recibirMensaje(event) {
    if (event.origin !== ORIGIN || !event.data || typeof event.data !== 'object') return;
    if (ventanaFinanciamiento && event.source !== ventanaFinanciamiento) return;
    if (event.data.type === MSG_READY) {
      if (contextoPendiente && event.source) event.source.postMessage({ type: MSG_PREFILL, data: contextoPendiente }, ORIGIN);
      return;
    }
    if (event.data.type === MSG_APPLY) {
      aplicarDesdeFinanciamiento(event).catch((error) => {
        console.error('[Solicitud Venta] Error al aplicar financiamiento:', error);
        mostrarMensajeLocal(`No fue posible aplicar la corrida: ${error.message || error}`, 'error');
        estadoTexto('La corrida no quedó aplicada. Puedes volver a intentarlo.', 'warn');
        responderAck(event.source, false, String(error.message || error));
      });
    }
  }

  async function aplicarDesdeFinanciamiento(event) {
    if (aplicando) return;
    aplicando = true;
    const boton = document.getElementById('btnAbrirFinanciamiento');
    if (boton) boton.disabled = true;
    try {
      const msg = event.data || {};
      const data = msg.result || {};
      const folio = folioActual();
      const folioMensaje = String(msg.folio || '').trim().toUpperCase();
      if (!folio || folioMensaje !== folio) throw new Error('La corrida recibida no corresponde al folio abierto.');
      if (!(Number(data.total) > 0) || !(Number(data.meses) > 0) || !(Number(data.mensualidad) > 0)) throw new Error('La herramienta de Financiamiento no devolvió una corrida válida.');
      if (!(msg.pdfBuffer instanceof ArrayBuffer) || msg.pdfBuffer.byteLength < 100) throw new Error('La corrida no incluyó un PDF válido.');

      estadoTexto('Aplicando condiciones y guardando la corrida en SharePoint...', 'warn');
      aplicarCampos(data);
      setValor('financiamientoIntegrado', 'PENDIENTE');
      const nombrePdf = `CORRIDA_FINANCIERA_${folio}.pdf`;
      await subirPdfCorrida(folio, msg.pdfBuffer, nombrePdf);
      setValor('financiamientoPdfNombre', nombrePdf);
      setValor('financiamientoAplicadoUtc', new Date().toISOString());
      setValor('financiamientoIntegrado', 'CALCULADO');

      if (typeof window.guardarBorrador !== 'function') throw new Error('No fue posible guardar los datos financieros en el borrador.');
      await window.guardarBorrador();
      restaurarValoresExactos();
      if (!estaVigente()) throw new Error('Los datos se guardaron, pero la corrida no quedó marcada como vigente.');

      actualizarEstadoUI();
      mostrarMensajeLocal(`Corrida financiera aplicada y guardada en el expediente ${folio}.`, 'ok');
      responderAck(event.source, true, 'Corrida aplicada correctamente. Ya puedes regresar a Solicitud de Venta.');
    } finally {
      aplicando = false;
      if (boton) boton.disabled = false;
    }
  }

  function aplicarCampos(data) {
    const total = Number(data.total || 0);
    const enganche = Number(data.enganche || 0);
    const meses = Math.max(1, Math.trunc(Number(data.meses || 0)));
    const tasa = Number(data.tasaAnualPct || 0);
    const primerPago = String(data.primerPago || '').slice(0, 10);
    const dia = primerPago ? Number(primerPago.split('-')[2]) : 0;
    const valores = {
      precioTotal: total.toFixed(2), enganche: enganche.toFixed(2), saldo: Math.max(0, total - enganche).toFixed(2),
      mensualidades: String(meses), importeMensual: Number(data.mensualidad || 0).toFixed(2),
      montoFinanciar: Number(data.montoFinanciar || 0).toFixed(2), interesFinanciamiento: tasa.toFixed(2),
      periodoPagos: 'MENSUAL', pagosAnuales: '12', totalPagar: Number(data.totalPagos || 0).toFixed(2),
      fechaPrimerVencimiento: primerPago, diaPago: dia >= 1 && dia <= 31 ? String(dia) : ''
    };
    Object.entries(valores).forEach(([id, value]) => setValor(id, value));
    if (!valor('precioLista')) setValor('precioLista', total.toFixed(2));
    setValor('financiamientoTotalBase', valores.precioTotal);
    setValor('financiamientoEngancheBase', valores.enganche);
    setValor('financiamientoImporteMensualBase', valores.importeMensual);
    setValor('financiamientoMontoFinanciarBase', valores.montoFinanciar);
    setValor('financiamientoTotalPagarBase', valores.totalPagar);
    setValor('financiamientoTasaBase', valores.interesFinanciamiento);
    setValor('financiamientoMesesBase', valores.mensualidades);
    setValor('financiamientoFechaBase', valores.fechaPrimerVencimiento);
    setValor('financiamientoDiaPagoBase', valores.diaPago);
  }

  function restaurarValoresExactos() {
    if (estadoMarcador() !== 'CALCULADO') return;
    Object.entries(camposExactos).forEach(([destino, base]) => {
      const guardado = valor(base);
      if (guardado !== '') setValor(destino, guardado);
    });
  }

  async function subirPdfCorrida(folio, buffer, nombrePdf) {
    const token = await window.solicitudVentaAuth?.getBackendAccessToken?.();
    if (!token) throw new Error('No fue posible obtener autorización para guardar el PDF.');
    const formData = new FormData();
    formData.append('folio', folio);
    formData.append('tipoDocumento', 'CORRIDA_FINANCIERA');
    formData.append('archivo', new Blob([buffer], { type: 'application/pdf' }), nombrePdf);
    const response = await fetch('/api/solicitud-venta/archivos.php', { method: 'POST', headers: { Authorization: `Bearer ${token}` }, body: formData });
    const data = await response.json().catch(() => null);
    if (!response.ok || !data?.ok) throw new Error(data?.message || data?.error || `HTTP ${response.status}`);
    return data;
  }

  function responderAck(target, ok, message) { try { target?.postMessage({ type: MSG_ACK, ok: Boolean(ok), message: String(message || '') }, ORIGIN); } catch (_) {} }

  function invalidar(motivo) {
    if (estadoMarcador() === '') return;
    setValor('financiamientoIntegrado', 'PENDIENTE');
    setValor('financiamientoPdfNombre', '');
    setValor('financiamientoAplicadoUtc', '');
    actualizarEstadoUI(motivo);
  }

  function actualizarEstadoUI(motivo = '') {
    restaurarValoresExactos();
    const status = document.getElementById('financiamientoIntegracionStatus');
    const summary = document.getElementById('financiamientoIntegracionSummary');
    if (!status || !summary) return;
    if (valor('formaPago') !== 'CREDITO') {
      status.className = 'fin-integracion-status';
      status.textContent = 'La integración se activa cuando la Forma de pago es CREDITO.';
      summary.hidden = true;
      return;
    }
    if (estaVigente()) {
      status.className = 'fin-integracion-status ok';
      status.textContent = '✓ Corrida financiera calculada, aplicada y guardada en el expediente.';
      summary.innerHTML = `<div><span>Monto financiado</span><strong>${formatoMoneda(numero('montoFinanciar'))}</strong></div><div><span>Plazo</span><strong>${Math.trunc(numero('mensualidades'))} meses</strong></div><div><span>Tasa anual</span><strong>${numero('interesFinanciamiento').toFixed(2)}%</strong></div><div><span>Mensualidad</span><strong>${formatoMoneda(numero('importeMensual'))}</strong></div>`;
      summary.hidden = false;
      return;
    }
    status.className = 'fin-integracion-status warn';
    status.textContent = motivo || 'La venta es a crédito y todavía requiere una corrida calculada con la herramienta de Financiamiento.';
    summary.hidden = true;
  }

  function estaVigente() {
    if (valor('formaPago') !== 'CREDITO') return true;
    if (estadoMarcador() !== 'CALCULADO') return false;
    const totalBase = Number(valor('financiamientoTotalBase') || NaN);
    const engancheBase = Number(valor('financiamientoEngancheBase') || NaN);
    if (!Number.isFinite(totalBase) || !Number.isFinite(engancheBase)) return false;
    if (Math.abs(totalBase - numero('precioTotal')) > 0.01 || Math.abs(engancheBase - numero('enganche')) > 0.01) return false;
    if (!(numero('mensualidades') > 0) || !(numero('importeMensual') > 0) || !(numero('montoFinanciar') >= 0)) return false;
    return Boolean(valor('fechaPrimerVencimiento') && valor('financiamientoPdfNombre'));
  }

  function estadoMarcador() { return valor('financiamientoIntegrado').toUpperCase(); }
  function folioActual() {
    try { if (typeof borradorActual !== 'undefined' && borradorActual?.folio) return String(borradorActual.folio).trim().toUpperCase(); } catch (_) {}
    return String(document.querySelector('.folio-box strong')?.textContent || '').trim().toUpperCase().match(/^SV-\d{4}-\d+$/)?.[0] || '';
  }
  function nombreCliente() { return [valor('clienteNombres'), valor('clienteApellidoPaterno'), valor('clienteApellidoMaterno')].filter(Boolean).join(' ').replace(/\s+/g, ' ').trim(); }
  function productoSolicitud() { return [valor('paquete'), valor('tipoVentaProcap')].filter(Boolean).join(' · '); }
  function valor(id) { return String(document.getElementById(id)?.value ?? '').trim(); }
  function numero(id) { const n = Number(valor(id) || 0); return Number.isFinite(n) ? n : 0; }
  function setValor(id, value) { const control = document.getElementById(id); if (control) control.value = value == null ? '' : String(value); }
  function formatoMoneda(value) { return new Intl.NumberFormat('es-MX', { style: 'currency', currency: 'MXN' }).format(Number(value) || 0); }
  function estadoTexto(texto, tipo = '') { const status = document.getElementById('financiamientoIntegracionStatus'); if (status) { status.className = `fin-integracion-status ${tipo}`.trim(); status.textContent = texto; } }
  function mostrarMensajeLocal(texto, tipo = '') { if (typeof window.mostrarMensaje === 'function') window.mostrarMensaje(texto, tipo); else estadoTexto(texto, tipo === 'ok' ? 'ok' : 'warn'); }

  window.solicitudFinanciamientoIntegracion = { estaVigente, actualizarEstado: actualizarEstadoUI, abrir: abrirFinanciamiento };
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', iniciar, { once: true }); else iniciar();
})();
