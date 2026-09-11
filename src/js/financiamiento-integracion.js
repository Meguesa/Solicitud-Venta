(() => {
  'use strict';

  if (window.__solicitudFinanciamientoIntegracionActiva) return;
  window.__solicitudFinanciamientoIntegracionActiva = true;

  const ORIGIN = window.location.origin;
  const MSG_APPLY = 'JDJP_FINANCIAMIENTO_APPLY';
  const MSG_ACK = 'JDJP_FINANCIAMIENTO_ACK';

  let ventanaFinanciamiento = null;
  let aplicando = false;

  // Solo los datos comerciales de la venta pueden invalidar una corrida.
  // Las condiciones financieras de la parte inferior son salida de Financiamiento,
  // nunca entrada manual de Solicitud de Venta.
  const idsQueInvalidan = ['precioTotal', 'enganche'];
  const camposSoloFinanciamiento = [
    'mensualidades',
    'importeMensual',
    'diaPago',
    'fechaPrimerVencimiento',
    'precioLista',
    'bonificacion',
    'montoFinanciar',
    'interesFinanciamiento',
    'periodoPagos',
    'pagosAnuales',
    'importeAnualidad',
    'totalPagar'
  ];
  const camposExactos = {
    mensualidades: 'financiamientoMesesBase',
    importeMensual: 'financiamientoImporteMensualBase',
    diaPago: 'financiamientoDiaPagoBase',
    fechaPrimerVencimiento: 'financiamientoFechaBase',
    precioLista: 'financiamientoPrecioListaBase',
    bonificacion: 'financiamientoBonificacionBase',
    montoFinanciar: 'financiamientoMontoFinanciarBase',
    interesFinanciamiento: 'financiamientoTasaBase',
    periodoPagos: 'financiamientoPeriodoPagosBase',
    pagosAnuales: 'financiamientoPagosAnualesBase',
    importeAnualidad: 'financiamientoImporteAnualidadBase',
    totalPagar: 'financiamientoTotalPagarBase'
  };

  function iniciar() {
    const form = document.getElementById('solicitudForm');
    const contenedor = document.getElementById('financiamientoFields');
    if (!form || !contenedor) {
      window.setTimeout(iniciar, 120);
      return;
    }

    asegurarCamposAnualidad(contenedor);
    asegurarOcultos(form);
    instalarEstilos();
    instalarTarjeta(contenedor);
    bloquearCamposFinanciamiento();

    document.getElementById('btnAbrirFinanciamiento')?.addEventListener('click', abrirFinanciamiento);
    document.getElementById('formaPago')?.addEventListener('change', () => {
      if (valor('formaPago') !== 'CREDITO') {
        invalidar('La forma de pago cambió.');
      } else if (estadoMarcador() !== 'CALCULADO') {
        limpiarCamposFinanciamiento();
      }
      actualizarEstadoUI();
    });

    idsQueInvalidan.forEach((id) => {
      const control = document.getElementById(id);
      if (!control) return;
      const evento = control.tagName === 'SELECT' ? 'change' : 'input';
      control.addEventListener(evento, (ev) => {
        if (!ev.isTrusted) return;
        if (estadoMarcador() === 'CALCULADO') {
          invalidar('El precio total o el enganche cambiaron. Vuelve a calcular la corrida financiera.');
        } else if (valor('formaPago') === 'CREDITO') {
          limpiarCamposFinanciamiento();
        }
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
      asegurarCamposAnualidad(contenedor);
      bloquearCamposFinanciamiento();
      if (valor('formaPago') === 'CREDITO' && estadoMarcador() !== 'CALCULADO') {
        limpiarCamposFinanciamiento();
      } else {
        restaurarValoresExactos();
      }
      actualizarEstadoUI();
    }, delay));
  }

  function asegurarCamposAnualidad(contenedor) {
    const pagos = document.getElementById('pagosAnuales');
    if (pagos instanceof HTMLInputElement) {
      pagos.min = '0';
      pagos.step = '1';
      pagos.placeholder = 'Ej. 3';
      if (estadoMarcador() !== 'CALCULADO' && pagos.value === '12') pagos.value = '';
      const label = pagos.closest('label');
      if (label?.firstChild?.nodeType === Node.TEXT_NODE) label.firstChild.textContent = 'Pagos anuales';
    }

    if (document.getElementById('importeAnualidad')) return;

    const gridExistente = document.getElementById('financiamientoDetalleExtra');
    const grid = gridExistente || (() => {
      const nuevo = document.createElement('div');
      nuevo.id = 'financiamientoAnualidadExtra';
      nuevo.className = 'form-grid grid-4';
      contenedor.insertBefore(nuevo, contenedor.lastElementChild);
      return nuevo;
    })();

    const label = document.createElement('label');
    label.innerHTML = 'Importe de los pagos (anualidad)<input id="importeAnualidad" type="number" min="0" step="0.01" inputmode="decimal" placeholder="Ej. 10000">';

    const pagosLabel = document.getElementById('pagosAnuales')?.closest('label');
    if (pagosLabel?.parentElement === grid) pagosLabel.insertAdjacentElement('afterend', label);
    else grid.appendChild(label);
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
      ['financiamientoDiaPagoBase', ''],
      ['financiamientoPrecioListaBase', ''],
      ['financiamientoBonificacionBase', ''],
      ['financiamientoPeriodoPagosBase', ''],
      ['financiamientoPagosAnualesBase', ''],
      ['financiamientoImporteAnualidadBase', '']
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
      #financiamientoFields .fin-source-field{background:#f7f7f5!important;color:#303030!important;cursor:not-allowed}
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
          <p>Las condiciones de crédito se capturan únicamente en Financiamiento. Al aplicar la corrida, esta sección se llena automáticamente con los mismos datos y el PDF se guarda en el expediente.</p>
        </div>
        <div class="fin-integracion-actions"><button id="btnAbrirFinanciamiento" type="button" class="fin-integracion-btn">Calcular financiamiento →</button></div>
      </div>
      <div id="financiamientoIntegracionStatus" class="fin-integracion-status">Aún no hay una corrida financiera aplicada.</div>
      <div id="financiamientoIntegracionSummary" class="fin-integracion-summary" hidden></div>
    `;
    contenedor.insertBefore(card, contenedor.firstChild);
  }

  function bloquearCamposFinanciamiento() {
    camposSoloFinanciamiento.forEach((id) => {
      const control = document.getElementById(id);
      if (!control) return;
      control.classList.add('fin-source-field');
      control.setAttribute('aria-readonly', 'true');
      control.title = 'Este dato proviene de la corrida de Financiamiento.';
      if (control instanceof HTMLSelectElement) {
        control.disabled = true;
      } else if (control instanceof HTMLInputElement || control instanceof HTMLTextAreaElement) {
        control.readOnly = true;
      }
    });
  }

  function limpiarCamposFinanciamiento() {
    if (estadoMarcador() === 'CALCULADO') return;
    camposSoloFinanciamiento.forEach((id) => setValor(id, ''));
    Object.values(camposExactos).forEach((id) => setValor(id, ''));
    const conformidad = document.getElementById('conformidadFinanciamiento');
    if (conformidad instanceof HTMLInputElement) conformidad.checked = false;
  }

  function construirContexto(folio) {
    // Solicitud de Venta solo entrega a Financiamiento los datos comerciales base.
    // Tasa, plazo, fecha, mensualidad y demas condiciones se capturan y calculan alla.
    return {
      folio,
      cliente: nombreCliente(),
      producto: productoSolicitud(),
      total: Math.max(0, numero('precioTotal')),
      enganche: Math.max(0, numero('enganche'))
    };
  }

  function construirUrlFinanciamiento(data) {
    const params = new URLSearchParams();
    params.set('integracion', 'solicitud');
    params.set('folio', data.folio);
    params.set('total', String(data.total));
    params.set('enganche', String(data.enganche));
    if (data.cliente) params.set('cliente', data.cliente);
    if (data.producto) params.set('producto', data.producto);
    return `${ORIGIN}/financiamiento/?${params.toString()}`;
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

      const contexto = construirContexto(folio);
      const url = construirUrlFinanciamiento(contexto);
      ventanaFinanciamiento = popup;

      console.info('[Solicitud Venta] Enviando datos comerciales a Financiamiento:', contexto);
      popup.location.replace(url);
      estadoTexto(`Abriendo Financiamiento para ${folio}. Captura ahí las condiciones de crédito y anualidades.`, 'warn');
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
      bloquearCamposFinanciamiento();

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
    const diaRecibido = Math.trunc(Number(data.diaPago || 0));
    const diaFecha = primerPago ? Number(primerPago.split('-')[2]) : 0;
    const dia = diaRecibido >= 1 && diaRecibido <= 31 ? diaRecibido : diaFecha;
    const precioLista = Number(data.precioLista ?? total);
    const bonificacion = Number(data.bonificacion ?? 0);
    const periodoPagos = String(data.periodoPagos || 'MENSUAL').trim().toUpperCase();
    const pagosAnuales = Math.max(0, Math.trunc(Number(data.pagosAnuales || 0)));
    const importeAnualidad = Math.max(0, Number(data.importeAnualidad || 0));

    const valores = {
      precioTotal: total.toFixed(2),
      enganche: enganche.toFixed(2),
      saldo: Math.max(0, total - enganche).toFixed(2),
      mensualidades: String(meses),
      importeMensual: Number(data.mensualidad || 0).toFixed(2),
      diaPago: dia >= 1 && dia <= 31 ? String(dia) : '',
      fechaPrimerVencimiento: primerPago,
      precioLista: Number.isFinite(precioLista) ? precioLista.toFixed(2) : total.toFixed(2),
      bonificacion: Number.isFinite(bonificacion) ? bonificacion.toFixed(2) : '0.00',
      montoFinanciar: Number(data.montoFinanciar || 0).toFixed(2),
      interesFinanciamiento: tasa.toFixed(2),
      periodoPagos: periodoPagos || 'MENSUAL',
      pagosAnuales: String(pagosAnuales),
      importeAnualidad: importeAnualidad.toFixed(2),
      totalPagar: Number(data.totalPagos || 0).toFixed(2)
    };

    Object.entries(valores).forEach(([id, value]) => setValor(id, value));

    setValor('financiamientoTotalBase', valores.precioTotal);
    setValor('financiamientoEngancheBase', valores.enganche);
    setValor('financiamientoMesesBase', valores.mensualidades);
    setValor('financiamientoImporteMensualBase', valores.importeMensual);
    setValor('financiamientoDiaPagoBase', valores.diaPago);
    setValor('financiamientoFechaBase', valores.fechaPrimerVencimiento);
    setValor('financiamientoPrecioListaBase', valores.precioLista);
    setValor('financiamientoBonificacionBase', valores.bonificacion);
    setValor('financiamientoMontoFinanciarBase', valores.montoFinanciar);
    setValor('financiamientoTasaBase', valores.interesFinanciamiento);
    setValor('financiamientoPeriodoPagosBase', valores.periodoPagos);
    setValor('financiamientoPagosAnualesBase', valores.pagosAnuales);
    setValor('financiamientoImporteAnualidadBase', valores.importeAnualidad);
    setValor('financiamientoTotalPagarBase', valores.totalPagar);

    // Una corrida nueva requiere que el cliente vuelva a manifestar conformidad.
    const conformidad = document.getElementById('conformidadFinanciamiento');
    if (conformidad instanceof HTMLInputElement) conformidad.checked = false;
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

    const response = await fetch('/api/solicitud-venta/archivos.php', {
      method: 'POST',
      headers: { Authorization: `Bearer ${token}` },
      body: formData
    });

    const data = await response.json().catch(() => null);
    if (!response.ok || !data?.ok) throw new Error(data?.message || data?.error || `HTTP ${response.status}`);
    return data;
  }

  function responderAck(target, ok, message) {
    try {
      target?.postMessage({ type: MSG_ACK, ok: Boolean(ok), message: String(message || '') }, ORIGIN);
    } catch (_) {}
  }

  function invalidar(motivo) {
    if (estadoMarcador() === '' && valor('financiamientoPdfNombre') === '') {
      if (valor('formaPago') === 'CREDITO') limpiarCamposFinanciamiento();
      return;
    }
    setValor('financiamientoIntegrado', 'PENDIENTE');
    setValor('financiamientoPdfNombre', '');
    setValor('financiamientoAplicadoUtc', '');
    setValor('financiamientoTotalBase', '');
    setValor('financiamientoEngancheBase', '');
    Object.values(camposExactos).forEach((id) => setValor(id, ''));
    limpiarCamposFinanciamiento();
    actualizarEstadoUI(motivo);
  }

  function actualizarEstadoUI(motivo = '') {
    bloquearCamposFinanciamiento();
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
      status.textContent = '✓ Corrida financiera calculada, aplicada y guardada en el expediente. Los campos inferiores provienen de Financiamiento.';
      const anualidades = Math.max(0, Math.trunc(numero('pagosAnuales')));
      const anualidadHtml = anualidades > 0
        ? `<div><span>Anualidad</span><strong>${anualidades} × ${formatoMoneda(numero('importeAnualidad'))}</strong></div>`
        : '';
      summary.innerHTML = `<div><span>Monto financiado</span><strong>${formatoMoneda(numero('montoFinanciar'))}</strong></div><div><span>Plazo</span><strong>${Math.trunc(numero('mensualidades'))} meses</strong></div><div><span>Tasa anual</span><strong>${numero('interesFinanciamiento').toFixed(2)}%</strong></div><div><span>Mensualidad</span><strong>${formatoMoneda(numero('importeMensual'))}</strong></div>${anualidadHtml}`;
      summary.hidden = false;
      return;
    }

    status.className = 'fin-integracion-status warn';
    status.textContent = motivo || 'La venta es a crédito. Captura las condiciones únicamente en Financiamiento y aplica la corrida para llenar esta sección.';
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

  function estadoMarcador() {
    return valor('financiamientoIntegrado').toUpperCase();
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
    return [valor('clienteNombres'), valor('clienteApellidoPaterno'), valor('clienteApellidoMaterno')]
      .filter(Boolean)
      .join(' ')
      .replace(/\s+/g, ' ')
      .trim();
  }

  function productoSolicitud() {
    const partes = [];
    const paquete = valor('paquete');
    const procap = valor('tipoVentaProcap');
    if (paquete) partes.push(paquete);
    if (procap) partes.push(procap);

    if (!partes.length) {
      const principal = document.querySelector('#componentesContainer .component-card');
      const tipo = String(principal?.querySelector('.component-type')?.value || '').trim();
      const operacion = String(principal?.querySelector('.component-operation')?.value || '').trim();
      const servicio = String(principal?.querySelector('.component-service-type')?.value || '').trim();
      const propiedad = String(principal?.querySelector('.component-property-type')?.value || '').trim();
      [tipo, operacion, servicio || propiedad].filter(Boolean).forEach((parte) => partes.push(parte));
    }

    return partes.join(' · ');
  }

  function valor(id) {
    return String(document.getElementById(id)?.value ?? '').trim();
  }

  function numero(id) {
    const n = Number(valor(id) || 0);
    return Number.isFinite(n) ? n : 0;
  }

  function setValor(id, value) {
    const control = document.getElementById(id);
    if (control) control.value = value == null ? '' : String(value);
  }

  function formatoMoneda(value) {
    return new Intl.NumberFormat('es-MX', { style: 'currency', currency: 'MXN' }).format(Number(value) || 0);
  }

  function estadoTexto(texto, tipo = '') {
    const status = document.getElementById('financiamientoIntegracionStatus');
    if (status) {
      status.className = `fin-integracion-status ${tipo}`.trim();
      status.textContent = texto;
    }
  }

  function mostrarMensajeLocal(texto, tipo = '') {
    if (typeof window.mostrarMensaje === 'function') window.mostrarMensaje(texto, tipo);
    else estadoTexto(texto, tipo === 'ok' ? 'ok' : 'warn');
  }

  window.solicitudFinanciamientoIntegracion = {
    estaVigente,
    actualizarEstado: actualizarEstadoUI,
    abrir: abrirFinanciamiento,
    restaurarValoresExactos
  };

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', iniciar, { once: true });
  } else {
    iniciar();
  }
})();