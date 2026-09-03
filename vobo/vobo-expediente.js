(() => {
  'use strict';

  if (window.__solicitudVoboExpedienteActivo) return;
  window.__solicitudVoboExpedienteActivo = true;

  const etapa = String(window.SOLICITUD_VOBO_ETAPA || 'comercial').toLowerCase();
  const detailFolio = document.getElementById('detailFolio');
  const detailPanel = document.getElementById('detailPanel');
  const documentsList = document.getElementById('documentsList');
  if (!detailFolio || !detailPanel || !documentsList) return;

  let folioCargado = '';
  let secuencia = 0;

  instalarInterfaz();

  const observer = new MutationObserver(() => programarCarga());
  observer.observe(detailFolio, { childList: true, subtree: true, characterData: true });
  observer.observe(detailPanel, { attributes: true, attributeFilter: ['hidden'] });
  programarCarga();

  function instalarInterfaz() {
    const documentosBlock = documentsList.closest('.detail-block');
    if (!documentosBlock) return;

    if (!document.getElementById('voboReviewTools')) {
      const tools = document.createElement('div');
      tools.id = 'voboReviewTools';
      tools.className = 'vobo-review-tools';
      tools.innerHTML = `
        <div>
          <strong>Revisión del expediente</strong>
          <span>Abre los documentos, firmas y PDF preliminar antes de autorizar la solicitud.</span>
        </div>
        <div class="vobo-review-actions">
          <a id="voboPdfPreliminar" class="vobo-file-link primary" target="_blank" rel="noopener">Ver PDF preliminar</a>
          <button id="voboCorridaFinanciera" class="vobo-file-link" type="button" hidden>Ver corrida financiera</button>
        </div>`;
      documentosBlock.insertBefore(tools, documentsList);
    }

    if (!document.getElementById('voboFirmasPreview')) {
      const section = document.createElement('div');
      section.id = 'voboFirmasPreview';
      section.className = 'vobo-signatures-section';
      section.innerHTML = `
        <h4>Firmas de autorización</h4>
        <div class="vobo-signatures-grid">
          <div class="vobo-signature-card" data-signature="FIRMA_CLIENTE">
            <div class="vobo-signature-heading"><strong>Firma del cliente</strong><span>PENDIENTE</span></div>
            <div class="vobo-signature-canvas"><span>Sin firma disponible</span></div>
          </div>
          <div class="vobo-signature-card" data-signature="FIRMA_VENDEDOR">
            <div class="vobo-signature-heading"><strong>Firma del vendedor</strong><span>PENDIENTE</span></div>
            <div class="vobo-signature-canvas"><span>Sin firma disponible</span></div>
          </div>
        </div>`;
      documentsList.insertAdjacentElement('afterend', section);
    }

    if (!document.getElementById('voboArchivoViewer')) {
      const viewer = document.createElement('div');
      viewer.id = 'voboArchivoViewer';
      viewer.className = 'vobo-viewer';
      viewer.hidden = true;
      viewer.innerHTML = `
        <div class="vobo-viewer-backdrop" data-viewer-close></div>
        <section class="vobo-viewer-dialog" role="dialog" aria-modal="true" aria-labelledby="voboViewerTitle">
          <header class="vobo-viewer-header">
            <div><strong id="voboViewerTitle">Documento</strong><span id="voboViewerMeta"></span></div>
            <div class="vobo-viewer-actions">
              <a id="voboViewerOpen" class="vobo-file-link" target="_blank" rel="noopener">Abrir en pestaña</a>
              <button type="button" class="vobo-viewer-close" data-viewer-close aria-label="Cerrar">×</button>
            </div>
          </header>
          <div id="voboViewerBody" class="vobo-viewer-body"></div>
        </section>`;
      document.body.appendChild(viewer);
      viewer.querySelectorAll('[data-viewer-close]').forEach((el) => el.addEventListener('click', cerrarVisor));
      document.addEventListener('keydown', (event) => {
        if (event.key === 'Escape' && !viewer.hidden) cerrarVisor();
      });
    }
  }

  function programarCarga() {
    window.setTimeout(cargarExpedienteActual, 0);
  }

  async function cargarExpedienteActual() {
    const folio = String(detailFolio.textContent || '').trim().toUpperCase();
    if (detailPanel.hidden) {
      folioCargado = '';
      return;
    }
    if (!/^SV-\d{4}-\d{6,}$/.test(folio)) return;
    if (folio === folioCargado) return;

    folioCargado = folio;
    const token = ++secuencia;
    limpiarAccionesDocumentos();
    mostrarEstadoCarga('Consultando archivos del expediente...');

    try {
      const url = `/api/solicitud-venta/vobo-expediente.php?etapa=${encodeURIComponent(etapa)}&folio=${encodeURIComponent(folio)}`;
      const response = await fetch(url, { method: 'GET', cache: 'no-store', credentials: 'same-origin' });
      const data = await response.json().catch(() => null);
      if (!response.ok || !data?.ok) throw new Error(data?.message || `HTTP ${response.status}`);
      if (token !== secuencia) return;
      renderExpediente(data);
      mostrarEstadoCarga('');
    } catch (error) {
      if (token !== secuencia) return;
      folioCargado = '';
      mostrarEstadoCarga(error?.message || 'No fue posible cargar los archivos del expediente.', true);
    }
  }

  function renderExpediente(data) {
    const archivos = Array.isArray(data.archivos) ? data.archivos : [];
    const porCategoria = agruparArchivos(archivos);

    const pdf = document.getElementById('voboPdfPreliminar');
    if (pdf) pdf.href = data.pdfPreliminarUrl || `/api/solicitud-venta/pdf-preliminar.php?folio=${encodeURIComponent(data.folio || '')}`;

    const corrida = primerArchivo(porCategoria.get('CORRIDA_FINANCIERA'));
    const corridaBtn = document.getElementById('voboCorridaFinanciera');
    if (corridaBtn) {
      corridaBtn.hidden = !corrida;
      corridaBtn.onclick = corrida ? () => abrirVisor(corrida, 'Corrida financiera') : null;
    }

    anexarBotonesDocumento('Identificacion titular', [
      ['ID_TITULAR_FRENTE', 'Ver frente'],
      ['ID_TITULAR_REVERSO', 'Ver reverso'],
      ['ID_TITULAR', 'Ver documento']
    ], porCategoria);
    anexarBotonesDocumento('Identificacion titular substituto', [
      ['ID_SUSTITUTO_FRENTE', 'Ver frente'],
      ['ID_SUSTITUTO_REVERSO', 'Ver reverso'],
      ['ID_SUSTITUTO', 'Ver documento']
    ], porCategoria);
    anexarBotonesDocumento('Comprobante de domicilio', [['COMPROBANTE_DOMICILIO', 'Ver documento']], porCategoria);
    anexarBotonesDocumento('Comprobante de pago', [['COMPROBANTE_PAGO', 'Ver documento']], porCategoria);

    renderFirma('FIRMA_CLIENTE', primerArchivo(porCategoria.get('FIRMA_CLIENTE')), 'Firma del cliente');
    renderFirma('FIRMA_VENDEDOR', primerArchivo(porCategoria.get('FIRMA_VENDEDOR')), 'Firma del vendedor');

    renderArchivosAdicionales(archivos.filter((file) => ['OTRO', 'OTRO_ARCHIVO'].includes(String(file.categoria || ''))));
  }

  function agruparArchivos(archivos) {
    const map = new Map();
    archivos.forEach((archivo) => {
      const key = String(archivo?.categoria || 'OTRO_ARCHIVO');
      if (!map.has(key)) map.set(key, []);
      map.get(key).push(archivo);
    });
    return map;
  }

  function primerArchivo(items) {
    return Array.isArray(items) && items.length ? items[0] : null;
  }

  function anexarBotonesDocumento(nombre, definiciones, porCategoria) {
    const card = buscarCardDocumento(nombre);
    if (!card) return;

    const acciones = document.createElement('div');
    acciones.className = 'vobo-document-actions';
    const usados = new Set();

    definiciones.forEach(([categoria, etiqueta]) => {
      const archivo = primerArchivo(porCategoria.get(categoria));
      if (!archivo || usados.has(archivo.id)) return;
      usados.add(archivo.id);
      acciones.appendChild(crearBotonArchivo(archivo, etiqueta));
    });

    if (!acciones.children.length) {
      const estado = normalizar(card.querySelector('strong')?.textContent || '');
      if (['RECIBIDO', 'FIRMADO'].includes(estado)) {
        const aviso = document.createElement('small');
        aviso.className = 'vobo-file-warning';
        aviso.textContent = 'Archivo no localizado en el expediente.';
        acciones.appendChild(aviso);
      }
    }
    card.appendChild(acciones);
  }

  function crearBotonArchivo(archivo, etiqueta) {
    const button = document.createElement('button');
    button.type = 'button';
    button.className = 'vobo-file-link';
    button.textContent = etiqueta;
    button.addEventListener('click', () => abrirVisor(archivo, etiqueta));
    return button;
  }

  function buscarCardDocumento(nombre) {
    const objetivo = normalizar(nombre);
    return Array.from(documentsList.querySelectorAll('.document-item')).find((card) => {
      return normalizar(card.querySelector('span')?.textContent || '') === objetivo;
    }) || null;
  }

  function renderFirma(categoria, archivo, titulo) {
    const card = document.querySelector(`.vobo-signature-card[data-signature="${categoria}"]`);
    if (!card) return;
    const estadoOrigen = buscarCardDocumento(titulo)?.querySelector('strong')?.textContent || (archivo ? 'FIRMADO' : 'PENDIENTE');
    const estado = card.querySelector('.vobo-signature-heading span');
    if (estado) {
      estado.textContent = estadoOrigen;
      estado.classList.toggle('ok', Boolean(archivo));
    }

    const canvas = card.querySelector('.vobo-signature-canvas');
    if (!canvas) return;
    canvas.textContent = '';

    if (!archivo) {
      const span = document.createElement('span');
      span.textContent = 'Sin imagen de firma disponible';
      canvas.appendChild(span);
      return;
    }

    const img = document.createElement('img');
    img.src = archivo.url;
    img.alt = titulo;
    img.loading = 'lazy';
    img.addEventListener('click', () => abrirVisor(archivo, titulo));
    canvas.appendChild(img);
  }

  function renderArchivosAdicionales(archivos) {
    let block = document.getElementById('voboArchivosAdicionales');
    if (block) block.remove();
    if (!archivos.length) return;

    block = document.createElement('div');
    block.id = 'voboArchivosAdicionales';
    block.className = 'vobo-extra-files';
    block.innerHTML = '<strong>Otros documentos</strong>';
    const actions = document.createElement('div');
    actions.className = 'vobo-extra-actions';
    archivos.forEach((archivo, index) => actions.appendChild(crearBotonArchivo(archivo, archivo.nombre || `Archivo ${index + 1}`)));
    block.appendChild(actions);
    document.getElementById('voboFirmasPreview')?.insertAdjacentElement('beforebegin', block);
  }

  function abrirVisor(archivo, titulo) {
    if (!archivo?.url) return;
    const viewer = document.getElementById('voboArchivoViewer');
    const body = document.getElementById('voboViewerBody');
    const title = document.getElementById('voboViewerTitle');
    const meta = document.getElementById('voboViewerMeta');
    const open = document.getElementById('voboViewerOpen');
    if (!viewer || !body || !title || !open) return;

    title.textContent = titulo || archivo.nombre || 'Documento';
    if (meta) meta.textContent = archivo.nombre || '';
    open.href = archivo.url;
    body.textContent = '';

    if (archivo.esImagen || String(archivo.mime || '').startsWith('image/')) {
      const img = document.createElement('img');
      img.src = archivo.url;
      img.alt = titulo || archivo.nombre || 'Documento';
      body.appendChild(img);
    } else if (archivo.esPdf || String(archivo.mime || '').toLowerCase() === 'application/pdf') {
      const frame = document.createElement('iframe');
      frame.src = archivo.url;
      frame.title = titulo || archivo.nombre || 'Documento PDF';
      body.appendChild(frame);
    } else {
      window.open(archivo.url, '_blank', 'noopener');
      return;
    }

    viewer.hidden = false;
    document.body.classList.add('vobo-viewer-open');
  }

  function cerrarVisor() {
    const viewer = document.getElementById('voboArchivoViewer');
    const body = document.getElementById('voboViewerBody');
    if (viewer) viewer.hidden = true;
    if (body) body.textContent = '';
    document.body.classList.remove('vobo-viewer-open');
  }

  function limpiarAccionesDocumentos() {
    documentsList.querySelectorAll('.vobo-document-actions').forEach((node) => node.remove());
    document.getElementById('voboArchivosAdicionales')?.remove();
    document.querySelectorAll('.vobo-signature-card').forEach((card) => {
      const status = card.querySelector('.vobo-signature-heading span');
      if (status) {
        status.textContent = 'PENDIENTE';
        status.classList.remove('ok');
      }
      const canvas = card.querySelector('.vobo-signature-canvas');
      if (canvas) canvas.innerHTML = '<span>Sin firma disponible</span>';
    });
  }

  function mostrarEstadoCarga(texto, error = false) {
    const tools = document.getElementById('voboReviewTools');
    if (!tools) return;
    let status = tools.querySelector('.vobo-review-status');
    if (!status) {
      status = document.createElement('small');
      status.className = 'vobo-review-status';
      tools.appendChild(status);
    }
    status.textContent = texto || '';
    status.classList.toggle('error', Boolean(error));
  }

  function normalizar(value) {
    return String(value || '')
      .normalize('NFD')
      .replace(/[\u0300-\u036f]/g, '')
      .trim()
      .toUpperCase();
  }
})();