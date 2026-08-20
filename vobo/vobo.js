(() => {
  const API = '/api/solicitud-venta/vobo.php';
  const listPanel = document.getElementById('listPanel');
  const detailPanel = document.getElementById('detailPanel');
  const requestsList = document.getElementById('requestsList');
  const emptyState = document.getElementById('emptyState');
  const message = document.getElementById('message');

  const FIELD_META = {
    FECHA_SOLICITUD: ['General', 'Fecha'],
    LUGAR: ['General', 'Lugar'],
    ORIGEN_VENTA: ['General', 'Origen de venta'],
    VENDEDOR_NOMBRE: ['General', 'Vendedor'],

    CLIENTE_TIPO_ID: ['Información del cliente', 'Tipo de ID'],
    CLIENTE_NUMERO_ID: ['Información del cliente', 'Número de ID'],
    CLIENTE_RFC: ['Información del cliente', 'R.F.C.'],
    CLIENTE_CURP: ['Información del cliente', 'C.U.R.P.'],
    CLIENTE_NOMBRES: ['Información del cliente', 'Nombre'],
    CLIENTE_NOMBRE: ['Información del cliente', 'Nombre'],
    CLIENTE_APELLIDOS: ['Información del cliente', 'Apellidos'],
    CLIENTE_APELLIDO_PATERNO: ['Información del cliente', 'Apellido paterno'],
    CLIENTE_APELLIDO_MATERNO: ['Información del cliente', 'Apellido materno'],
    CLIENTE_FECHA_NACIMIENTO: ['Información del cliente', 'Fecha de nacimiento'],
    CLIENTE_EDAD: ['Información del cliente', 'Edad'],
    CLIENTE_SEXO: ['Información del cliente', 'Sexo'],
    CLIENTE_ESTADO_CIVIL: ['Información del cliente', 'Estado civil'],
    CLIENTE_NACIONALIDAD: ['Información del cliente', 'Nacionalidad'],
    CLIENTE_REGIMEN_MATRIMONIAL: ['Información del cliente', 'Régimen matrimonial'],
    CLIENTE_TIPO_VIVIENDA: ['Información del cliente', 'Vivienda'],
    CLIENTE_VIVIENDA: ['Información del cliente', 'Vivienda'],
    CLIENTE_ESCOLARIDAD: ['Información del cliente', 'Escolaridad'],
    CLIENTE_DOMICILIO: ['Información del cliente', 'Domicilio actual'],
    CLIENTE_NUMERO: ['Información del cliente', 'Número'],
    CLIENTE_COLONIA: ['Información del cliente', 'Colonia'],
    CLIENTE_ESTADO: ['Información del cliente', 'Provincia / Estado'],
    CLIENTE_CP: ['Información del cliente', 'C.P.'],
    CLIENTE_CIUDAD: ['Información del cliente', 'Ciudad'],
    CLIENTE_MUNICIPIO: ['Información del cliente', 'Municipio'],
    CLIENTE_TELEFONO: ['Información del cliente', 'Teléfono'],
    CLIENTE_CELULAR: ['Información del cliente', 'Celular'],
    CLIENTE_CORREO: ['Información del cliente', 'Correo electrónico'],
    CLIENTE_DOMICILIO_ANTERIOR: ['Información del cliente', 'Domicilio anterior'],
    CLIENTE_ANTIGUEDAD_DOMICILIO_ANTERIOR: ['Información del cliente', 'Antigüedad en domicilio anterior'],
    CLIENTE_NUM_DEPENDIENTES: ['Información del cliente', 'Número de dependientes'],
    CLIENTE_DEPENDIENTES: ['Información del cliente', 'Número de dependientes'],
    CLIENTE_EDADES_DEPENDIENTES: ['Información del cliente', 'Edades de dependientes'],
    CLIENTE_CONYUGE: ['Información del cliente', 'Cónyuge'],
    CONYUGE_FECHA_NACIMIENTO: ['Información del cliente', 'Fecha nacimiento cónyuge'],
    CLIENTE_CONYUGE_FECHA_NACIMIENTO: ['Información del cliente', 'Fecha nacimiento cónyuge'],
    CONYUGE_EDAD: ['Información del cliente', 'Edad cónyuge'],
    CLIENTE_CONYUGE_EDAD: ['Información del cliente', 'Edad cónyuge'],

    LABORAL_EMPRESA: ['Información Laboral', 'Empresa actual'],
    LABORAL_OCUPACION: ['Información Laboral', 'Ocupación'],
    LABORAL_DOMICILIO: ['Información Laboral', 'Domicilio laboral'],
    LABORAL_NUMERO: ['Información Laboral', 'Número'],
    LABORAL_COLONIA: ['Información Laboral', 'Colonia'],
    LABORAL_ESTADO: ['Información Laboral', 'Provincia / Estado'],
    LABORAL_CP: ['Información Laboral', 'C.P.'],
    LABORAL_CIUDAD: ['Información Laboral', 'Ciudad'],
    LABORAL_MUNICIPIO: ['Información Laboral', 'Municipio'],
    LABORAL_TELEFONO: ['Información Laboral', 'Teléfono'],
    LABORAL_EXTENSION: ['Información Laboral', 'Ext.'],
    LABORAL_ACTIVIDAD: ['Información Laboral', 'Actividad en la empresa'],
    LABORAL_SECTOR: ['Información Laboral', 'Sector'],
    LABORAL_ANTIGUEDAD: ['Información Laboral', 'Antigüedad en su empleo actual'],
    LABORAL_ANTIGUEDAD_ANTERIOR: ['Información Laboral', 'Antigüedad en su empleo anterior'],

    SUSTITUTO_NOMBRE: ['Datos Titular Substituto', 'Nombre'],
    SUSTITUTO_DOMICILIO: ['Datos Titular Substituto', 'Domicilio'],
    SUSTITUTO_EDAD: ['Datos Titular Substituto', 'Edad'],
    SUSTITUTO_TELEFONO: ['Datos Titular Substituto', 'Teléfono'],
    SUSTITUTO_PARENTESCO: ['Datos Titular Substituto', 'Parentesco'],
    SUSTITUTO_IDENTIFICACION: ['Datos Titular Substituto', 'I.D.'],
    SUSTITUTO_ID: ['Datos Titular Substituto', 'I.D.'],

    REFERENCIA1_NOMBRE: ['Referencias Familiares', 'Referencia 1 · Nombre'],
    REFERENCIA_1_NOMBRE: ['Referencias Familiares', 'Referencia 1 · Nombre'],
    REFERENCIA1_TELEFONO: ['Referencias Familiares', 'Referencia 1 · Teléfono'],
    REFERENCIA_1_TELEFONO: ['Referencias Familiares', 'Referencia 1 · Teléfono'],
    REFERENCIA1_CELULAR: ['Referencias Familiares', 'Referencia 1 · Celular'],
    REFERENCIA_1_CELULAR: ['Referencias Familiares', 'Referencia 1 · Celular'],
    REFERENCIA2_NOMBRE: ['Referencias Familiares', 'Referencia 2 · Nombre'],
    REFERENCIA_2_NOMBRE: ['Referencias Familiares', 'Referencia 2 · Nombre'],
    REFERENCIA2_TELEFONO: ['Referencias Familiares', 'Referencia 2 · Teléfono'],
    REFERENCIA_2_TELEFONO: ['Referencias Familiares', 'Referencia 2 · Teléfono'],
    REFERENCIA2_CELULAR: ['Referencias Familiares', 'Referencia 2 · Celular'],
    REFERENCIA_2_CELULAR: ['Referencias Familiares', 'Referencia 2 · Celular'],

    BANCO1_NOMBRE: ['Información Financiera y de Crédito', 'Banco 1 · Nombre'],
    BANCO_1_NOMBRE: ['Información Financiera y de Crédito', 'Banco 1 · Nombre'],
    BANCO1_TIPO_CUENTA: ['Información Financiera y de Crédito', 'Banco 1 · Tipo de cuenta'],
    BANCO_1_TIPO_CUENTA: ['Información Financiera y de Crédito', 'Banco 1 · Tipo de cuenta'],
    BANCO1_NUMERO_CUENTA: ['Información Financiera y de Crédito', 'Banco 1 · Número de cuenta'],
    BANCO_1_NUMERO_CUENTA: ['Información Financiera y de Crédito', 'Banco 1 · Número de cuenta'],
    BANCO2_NOMBRE: ['Información Financiera y de Crédito', 'Banco 2 · Nombre'],
    BANCO_2_NOMBRE: ['Información Financiera y de Crédito', 'Banco 2 · Nombre'],
    BANCO2_TIPO_CUENTA: ['Información Financiera y de Crédito', 'Banco 2 · Tipo de cuenta'],
    BANCO_2_TIPO_CUENTA: ['Información Financiera y de Crédito', 'Banco 2 · Tipo de cuenta'],
    BANCO2_NUMERO_CUENTA: ['Información Financiera y de Crédito', 'Banco 2 · Número de cuenta'],
    BANCO_2_NUMERO_CUENTA: ['Información Financiera y de Crédito', 'Banco 2 · Número de cuenta'],

    PAQUETE: ['Información de la Venta', 'Paquete / Plan'],
    DESCRIPCION_VENTA: ['Información de la Venta', 'Descripción de la venta'],

    FORMA_PAGO: ['Importe y Forma de Pago', 'Forma de pago'],
    PRECIO_TOTAL: ['Importe y Forma de Pago', 'Precio total'],
    ENGANCHE: ['Importe y Forma de Pago', 'Enganche'],
    SALDO: ['Importe y Forma de Pago', 'Saldo'],
    NUMERO_MENSUALIDADES: ['Importe y Forma de Pago', 'Número de mensualidades'],
    IMPORTE_MENSUALIDAD: ['Importe y Forma de Pago', 'Importe de mensualidad'],
    DIA_PAGO: ['Importe y Forma de Pago', 'Día de pago'],
    METODO_PAGO: ['Importe y Forma de Pago', 'Método de pago'],

    FINANCIAMIENTO_PRECIO_LISTA: ['Información Financiera y de Crédito', 'Precio de lista'],
    FINANCIAMIENTO_BONIFICACION: ['Información Financiera y de Crédito', 'Bonificación'],
    FINANCIAMIENTO_MONTO_FINANCIAR: ['Información Financiera y de Crédito', 'Monto a financiar'],
    FINANCIAMIENTO_INTERES: ['Información Financiera y de Crédito', 'Interés'],
    FINANCIAMIENTO_TOTAL_PAGAR: ['Información Financiera y de Crédito', 'Total a pagar'],
    FINANCIAMIENTO_CONFORMIDAD: ['Información Financiera y de Crédito', 'Conformidad del financiamiento'],

    OBSERVACIONES_SOLICITUD: ['Observaciones', 'Observaciones de la solicitud'],

    FINADO_NOMBRE: ['Uso inmediato', 'Nombre del finado'],
    FINADO_FECHA_NACIMIENTO: ['Uso inmediato', 'Fecha de nacimiento del finado'],
    FINADO_FECHA_DEFUNCION: ['Uso inmediato', 'Fecha de defunción'],
    FINADO_EDAD: ['Uso inmediato', 'Edad del finado'],
    FINADO_SEXO: ['Uso inmediato', 'Sexo del finado'],
    FINADO_ESTADO_CIVIL: ['Uso inmediato', 'Estado civil del finado'],
    FINADO_LUGAR_DEFUNCION: ['Uso inmediato', 'Lugar de defunción'],
    FINADO_CAUSA_DEFUNCION: ['Uso inmediato', 'Causa de defunción']
  };

  const SYSTEM_KEYS = new Set([
    'TITLE', 'ID', 'MODIFICADO', 'MODIFIED', 'CREADO', 'CREATED',
    'AUTHORLOOKUPID', 'EDITORLOOKUPID', 'APPAUTHORLOOKUPID', 'APPEDITORLOOKUPID',
    'DATOS_ADJUNTOS', 'ATTACHMENTS', 'CONTENTTYPE',
    'COBRANZA_ESTATUS', 'COBRANZA_PAGO_INICIAL_VALIDADO', 'COBRANZA_INFORMACION_VALIDADA',
    'CONTRATO_ESTATUS', 'FIRMA_DIRECCION_ESTATUS', 'FIRMA_CLIENTE_FINAL_ESTATUS',
    'VOBO_ESTATUS', 'VOBO_POR', 'VOBO_FECHA',
    'PROCAP_NUMERO', 'PROCAP_ESTATUS', 'PROCAP_FECHA', 'PROCAP_CAPTURADO_POR'
  ]);

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

    renderInformacionCapturada(data.detalle || []);
  }

  function renderInformacionCapturada(items) {
    const detailFields = document.getElementById('detailFields');
    detailFields.textContent = '';

    const grupos = new Map();
    items.forEach((item) => {
      const etiquetaOrigen = String(item.etiqueta || '').trim();
      const key = normalizarClave(etiquetaOrigen);
      if (!key || SYSTEM_KEYS.has(key) || key.endsWith('LOOKUPID')) return;

      const meta = obtenerMetaCampo(key, etiquetaOrigen);
      if (!meta) return;
      const valor = formatearValor(item.valor);
      if (!valor || valor === '—') return;

      if (!grupos.has(meta.seccion)) grupos.set(meta.seccion, []);
      grupos.get(meta.seccion).push({ etiqueta: meta.etiqueta, valor });
    });

    grupos.forEach((campos, seccion) => {
      const heading = document.createElement('h4');
      heading.className = 'detail-section-heading';
      heading.textContent = seccion;
      detailFields.appendChild(heading);

      campos.forEach((item) => {
        const div = document.createElement('div');
        div.className = 'detail-field';
        div.innerHTML = `<span>${escapeHtml(item.etiqueta)}</span><strong>${escapeHtml(item.valor)}</strong>`;
        detailFields.appendChild(div);
      });
    });

    if (!detailFields.children.length) {
      const empty = document.createElement('p');
      empty.className = 'muted detail-empty';
      empty.textContent = 'No hay información adicional capturada para mostrar.';
      detailFields.appendChild(empty);
    }
  }

  function obtenerMetaCampo(key, etiquetaOrigen) {
    const exacta = FIELD_META[key];
    if (exacta) return { seccion: exacta[0], etiqueta: exacta[1] };

    if (key.startsWith('CLIENTE_')) return { seccion: 'Información del cliente', etiqueta: humanizarEtiqueta(key.slice(8)) };
    if (key.startsWith('LABORAL_')) return { seccion: 'Información Laboral', etiqueta: humanizarEtiqueta(key.slice(8)) };
    if (key.startsWith('SUSTITUTO_')) return { seccion: 'Datos Titular Substituto', etiqueta: humanizarEtiqueta(key.slice(10)) };
    if (key.startsWith('REFERENCIA1_') || key.startsWith('REFERENCIA_1_')) return { seccion: 'Referencias Familiares', etiqueta: `Referencia 1 · ${humanizarEtiqueta(key.replace(/^REFERENCIA_?1_/, ''))}` };
    if (key.startsWith('REFERENCIA2_') || key.startsWith('REFERENCIA_2_')) return { seccion: 'Referencias Familiares', etiqueta: `Referencia 2 · ${humanizarEtiqueta(key.replace(/^REFERENCIA_?2_/, ''))}` };
    if (key.startsWith('BANCO1_') || key.startsWith('BANCO_1_')) return { seccion: 'Información Financiera y de Crédito', etiqueta: `Banco 1 · ${humanizarEtiqueta(key.replace(/^BANCO_?1_/, ''))}` };
    if (key.startsWith('BANCO2_') || key.startsWith('BANCO_2_')) return { seccion: 'Información Financiera y de Crédito', etiqueta: `Banco 2 · ${humanizarEtiqueta(key.replace(/^BANCO_?2_/, ''))}` };
    if (key.startsWith('FINANCIAMIENTO_')) return { seccion: 'Información Financiera y de Crédito', etiqueta: humanizarEtiqueta(key.slice(15)) };
    if (key.startsWith('FINADO_')) return { seccion: 'Uso inmediato', etiqueta: humanizarEtiqueta(key.slice(7)) };
    if (key.startsWith('UI_')) return { seccion: 'Uso inmediato', etiqueta: humanizarEtiqueta(key.slice(3)) };

    const permitidas = new Set(['PAQUETE', 'DESCRIPCION_VENTA', 'FORMA_PAGO', 'PRECIO_TOTAL', 'ENGANCHE', 'SALDO', 'NUMERO_MENSUALIDADES', 'IMPORTE_MENSUALIDAD', 'DIA_PAGO', 'METODO_PAGO', 'OBSERVACIONES_SOLICITUD', 'ORIGEN_VENTA', 'LUGAR', 'FECHA_SOLICITUD', 'VENDEDOR_NOMBRE']);
    if (!permitidas.has(key)) return null;
    return { seccion: 'Información capturada', etiqueta: humanizarEtiqueta(etiquetaOrigen) };
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
    const text = textoPlano(value);
    const match = text.match(/^(\d{4})-(\d{2})-(\d{2})/);
    return match ? `${match[3]}/${match[2]}/${match[1]}` : (text || '—');
  }

  function formatearValor(value) {
    const text = textoPlano(value);
    const match = text.match(/^(\d{4})-(\d{2})-(\d{2})(?:T.*)?$/);
    if (match) return `${match[3]}/${match[2]}/${match[1]}`;
    return text || '—';
  }

  function textoPlano(value) {
    const source = String(value ?? '').trim();
    if (!source) return '';
    if (!/[<&]/.test(source)) return source;
    try {
      const doc = new DOMParser().parseFromString(source, 'text/html');
      return String(doc.body?.textContent || '')
        .replace(/\u00a0/g, ' ')
        .replace(/\s+/g, ' ')
        .trim();
    } catch (_) {
      return source.replace(/<[^>]*>/g, ' ').replace(/\s+/g, ' ').trim();
    }
  }

  function normalizarClave(value) {
    return String(value ?? '')
      .normalize('NFD')
      .replace(/[\u0300-\u036f]/g, '')
      .toUpperCase()
      .replace(/[^A-Z0-9]+/g, '_')
      .replace(/^_+|_+$/g, '');
  }

  function humanizarEtiqueta(value) {
    const key = normalizarClave(value);
    const palabras = key.split('_').filter(Boolean).map((token) => {
      const especiales = {
        ID: 'ID', RFC: 'R.F.C.', CURP: 'C.U.R.P.', CP: 'C.P.', NUM: 'Número', NUMERO: 'Número',
        TELEFONO: 'Teléfono', METODO: 'Método', DESCRIPCION: 'Descripción', INFORMACION: 'Información',
        ANTIGUEDAD: 'Antigüedad', CONYUGE: 'Cónyuge', REGIMEN: 'Régimen', DIA: 'Día', INTERES: 'Interés'
      };
      return especiales[token] || token.toLowerCase();
    });
    if (!palabras.length) return String(value || '');
    const texto = palabras.join(' ');
    return texto.charAt(0).toUpperCase() + texto.slice(1);
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
