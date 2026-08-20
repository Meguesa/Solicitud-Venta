(() => {
  const API = '/api/solicitud-venta/vobo.php';
  const listPanel = document.getElementById('listPanel');
  const detailPanel = document.getElementById('detailPanel');
  const requestsList = document.getElementById('requestsList');
  const emptyState = document.getElementById('emptyState');
  const message = document.getElementById('message');

  document.getElementById('btnRecargar')?.addEventListener('click', cargarBandeja);
  document.getElementById('btnBack')?.addEventListener('click', mostrarBandeja);

  cargarBandeja();

  async function cargarBandeja() {
    mostrarMensaje('Consultando solicitudes pendientes...');
    try {
      const data = await llamarApi({ accion: 'listar' });
      renderBandeja(data.solicitudes || []);
      mostrarMensaje('');
    } catch (error) {
      mostrarMensaje(error.message || String(error), 'error');
    }
  }

  function renderBandeja(items) {
    requestsList.textContent = '';
    document.getElementById('listCount').textContent = `${items.length} solicitud(es) pendiente(s)`;
    emptyState.hidden = items.length !== 0;

    items.forEach((item) => {
      const button = document.createElement('button');
      button.type = 'button';
      button.className = 'request-card';
      button.innerHTML = `
        ${requestCell('Folio', escapeHtml(item.folio || ''))}
        ${requestCell('Cliente', escapeHtml(item.cliente || ''))}
        ${requestCell('Vendedor', escapeHtml(item.vendedor || ''))}
        ${requestCell('Componentes', String(item.componentes || 1))}
        <div class="request-cell request-price"><span>Precio total</span><strong>${moneda(item.precioTotal)}</strong></div>
      `;
      button.addEventListener('click', () => cargarDetalle(item.folio));
      requestsList.appendChild(button);
    });
  }

  async function cargarDetalle(folio) {
    mostrarMensaje(`Cargando ${folio}...`);
    try {
      const data = await llamarApi({ accion: 'detalle', folio });
      renderDetalle(data);
      listPanel.hidden = true;
      detailPanel.hidden = false;
      detailPanel.scrollIntoView({ behavior: 'smooth', block: 'start' });
      mostrarMensaje('');
    } catch (error) {
      mostrarMensaje(error.message || String(error), 'error');
    }
  }

  function renderDetalle(data) {
    const resumen = data.resumen || {};
    document.getElementById('detailFolio').textContent = data.folio || 'Solicitud';
    document.getElementById('detailClient').textContent = resumen.cliente || '';
    document.getElementById('detailStatus').textContent = resumen.estatus || '';

    const summaryGrid = document.getElementById('summaryGrid');
    summaryGrid.textContent = '';
    [
      ['Fecha', formatearFecha(resumen.fecha)],
      ['Vendedor', resumen.vendedor || '—'],
      ['Tipo de venta', resumen.tipoVenta || '—'],
      ['Lugar', resumen.lugar || '—'],
      ['Forma de pago', resumen.formaPago || '—'],
      ['Método de pago', resumen.metodoPago || '—'],
      ['Precio total', moneda(resumen.precioTotal)],
      ['Estatus', resumen.estatus || '—']
    ].forEach(([label, value]) => summaryGrid.appendChild(summaryItem(label, value)));

    const componentsList = document.getElementById('componentsList');
    componentsList.textContent = '';
    (data.componentes || []).forEach((item) => componentsList.appendChild(componentItem(item)));

    const documentsList = document.getElementById('documentsList');
    documentsList.textContent = '';
    (data.documentos || []).forEach((item) => {
      const div = document.createElement('div');
      const recibido = ['RECIBIDO', 'FIRMADO', 'NO APLICA'].includes(String(item.estado || '').toUpperCase());
      div.className = `document-item ${recibido ? 'received' : 'pending'}`;
      div.innerHTML = `<span>${escapeHtml(item.nombre || '')}</span><strong>${escapeHtml(item.estado || 'PENDIENTE')}</strong>`;
      documentsList.appendChild(div);
    });

    const detailFields = document.getElementById('detailFields');
    detailFields.textContent = '';
    (data.detalle || []).forEach((item) => {
      const div = document.createElement('div');
      div.className = 'detail-field';
      div.innerHTML = `<span>${escapeHtml(item.etiqueta || '')}</span><strong>${escapeHtml(formatearValor(item.valor))}</strong>`;
      detailFields.appendChild(div);
    });
  }

  function componentItem(item) {
    const article = document.createElement('article');
    article.className = 'component-item';
    const tipo = String(item.tipo || '').toUpperCase();
    const fields = [
      ['Operación', item.operacion],
      ['Tipo de venta ProcaP', item.tipoVentaProcap],
      ['Sucursal', item.sucursal],
      ['Referencia / Clave', item.referencia],
      ['Precio base', moneda(item.precioBase)],
      ['Monto asignado', moneda(item.monto)]
    ];

    if (tipo === 'SERVICIO') {
      fields.push(
        ['Servicio', item.servicioTipo],
        ['Ataúd', item.servicioAtaud],
        ['Urna', item.servicioUrna],
        ['Duración', item.servicioDuracion]
      );
    } else {
      fields.push(
        ['Subtipo', item.propiedadTipo],
        ['Sección', item.propiedadSeccion],
        ['Manzana', item.propiedadManzana],
        ['Número', item.propiedadNumero],
        ['Clave de propiedad', item.propiedadClave]
      );
    }

    article.innerHTML = `
      <div class="component-title">
        <strong>Componente ${Number(item.numero || 0)} · ${escapeHtml(tipo || 'SIN TIPO')}</strong>
        <strong>${moneda(item.monto)}</strong>
      </div>
      <div class="component-grid">
        ${fields.filter(([, value]) => value !== null && value !== undefined && String(value) !== '').map(([label, value]) => `
          <div class="component-field"><span>${escapeHtml(label)}</span><strong>${escapeHtml(String(value))}</strong></div>
        `).join('')}
      </div>
    `;
    return article;
  }

  function summaryItem(label, value) {
    const div = document.createElement('div');
    div.className = 'summary-item';
    div.innerHTML = `<span>${escapeHtml(label)}</span><strong>${escapeHtml(String(value))}</strong>`;
    return div;
  }

  function requestCell(label, value) {
    return `<div class="request-cell"><span>${escapeHtml(label)}</span><strong>${value}</strong></div>`;
  }

  function mostrarBandeja() {
    detailPanel.hidden = true;
    listPanel.hidden = false;
    listPanel.scrollIntoView({ behavior: 'smooth', block: 'start' });
  }

  async function llamarApi(payload) {
    const response = await fetch(API, {
      method: 'POST',
      cache: 'no-store',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify(payload)
    });
    const data = await response.json().catch(() => null);
    if (!response.ok || !data?.ok) throw new Error(data?.message || data?.error || `HTTP ${response.status}`);
    return data;
  }

  function mostrarMensaje(text, type = '') {
    message.textContent = text || '';
    message.className = `message ${type}`.trim();
  }

  function moneda(value) {
    return new Intl.NumberFormat('es-MX', { style: 'currency', currency: 'MXN' }).format(Number(value || 0));
  }

  function formatearFecha(value) {
    const text = String(value || '').trim();
    const match = text.match(/^(\d{4})-(\d{2})-(\d{2})/);
    return match ? `${match[3]}/${match[2]}/${match[1]}` : (text || '—');
  }

  function formatearValor(value) {
    const text = String(value ?? '').trim();
    const match = text.match(/^(\d{4})-(\d{2})-(\d{2})(?:T.*)?$/);
    if (match) return `${match[3]}/${match[2]}/${match[1]}`;
    return text || '—';
  }

  function escapeHtml(value) {
    return String(value ?? '')
      .replaceAll('&', '&amp;')
      .replaceAll('<', '&lt;')
      .replaceAll('>', '&gt;')
      .replaceAll('"', '&quot;')
      .replaceAll("'", '&#039;');
  }
})();
