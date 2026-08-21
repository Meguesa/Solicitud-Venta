(() => {
  const REQUERIDOS = [
    ['ID_TITULAR_FRENTE', 'Identificación oficial del titular · Frente'],
    ['ID_TITULAR_REVERSO', 'Identificación oficial del titular · Reverso'],
    ['ID_SUSTITUTO_FRENTE', 'Identificación del titular substituto · Frente'],
    ['ID_SUSTITUTO_REVERSO', 'Identificación del titular substituto · Reverso'],
    ['COMPROBANTE_DOMICILIO', 'Comprobante de domicilio'],
    ['COMPROBANTE_PAGO', 'Comprobante de pago']
  ];

  let inicializado = false;
  let puenteInstalado = false;

  function iniciar() {
    if (inicializado) return;

    const section = document.getElementById('documentosSection');
    const grid = section?.querySelector('.upload-grid');
    const extras = window.solicitudVentaExtras;
    if (!section || !grid || !extras
      || typeof extras.capturarEstadoExpediente !== 'function'
      || typeof extras.restaurarEstadoExpediente !== 'function') {
      setTimeout(iniciar, 80);
      return;
    }

    reemplazarIdentificaciones(grid);
    enlazarArchivos(section);
    instalarPuenteExpediente(extras);
    instalarValidacion(section);
    instalarGuardian(section);
    inicializado = true;
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', () => setTimeout(iniciar, 0));
  } else {
    setTimeout(iniciar, 0);
  }

  function instalarGuardian(section) {
    if (section.dataset.dobleIdentificacionGuardian === '1') return;
    section.dataset.dobleIdentificacionGuardian = '1';

    let reparando = false;
    const reparar = () => {
      if (reparando) return;
      const grid = section.querySelector('.upload-grid');
      if (!grid) return;

      const faltaTitular = Boolean(grid.querySelector('[data-document-type="ID_TITULAR"]:not([data-document-key])'));
      const faltaSustituto = Boolean(grid.querySelector('[data-document-type="ID_SUSTITUTO"]:not([data-document-key])'));
      if (!faltaTitular && !faltaSustituto) return;

      reparando = true;
      reemplazarIdentificaciones(grid);
      enlazarArchivos(section);
      reparando = false;
    };

    const observer = new MutationObserver(() => queueMicrotask(reparar));
    observer.observe(section, { childList: true, subtree: true });
    setTimeout(reparar, 0);
  }

  function reemplazarIdentificaciones(grid) {
    const titular = grid.querySelector('[data-document-type="ID_TITULAR"]:not([data-document-key])');
    if (titular) {
      titular.replaceWith(
        crearCeldaDocumento('Identificación oficial del titular · Frente', 'docTitularFrente', 'ID_TITULAR', 'ID_TITULAR_FRENTE'),
        crearCeldaDocumento('Identificación oficial del titular · Reverso', 'docTitularReverso', 'ID_TITULAR', 'ID_TITULAR_REVERSO')
      );
    }

    const sustituto = grid.querySelector('[data-document-type="ID_SUSTITUTO"]:not([data-document-key])');
    if (sustituto) {
      sustituto.replaceWith(
        crearCeldaDocumento('Identificación del titular substituto · Frente', 'docSustitutoFrente', 'ID_SUSTITUTO', 'ID_SUSTITUTO_FRENTE'),
        crearCeldaDocumento('Identificación del titular substituto · Reverso', 'docSustitutoReverso', 'ID_SUSTITUTO', 'ID_SUSTITUTO_REVERSO')
      );
    }
  }

  function crearCeldaDocumento(titulo, baseId, tipoUpload, key) {
    const wrapper = document.createElement('div');
    wrapper.className = 'upload-card';
    wrapper.dataset.documentType = tipoUpload;
    wrapper.dataset.documentKey = key;
    wrapper.dataset.baseId = baseId;
    wrapper.dataset.archivoCargado = '0';
    wrapper.innerHTML = `
      <strong>${titulo}</strong>
      <div class="upload-actions">
        <label class="file-button">
          <span>Elegir foto / archivo</span>
          <input id="${baseId}Archivo" type="file" accept="image/jpeg,image/png,image/webp,application/pdf">
        </label>
        <label class="file-button camera-button">
          <span>Tomar foto</span>
          <input id="${baseId}Camara" type="file" accept="image/*" capture="environment">
        </label>
      </div>
      <small class="file-status" id="${baseId}Estado">Sin archivo seleccionado</small>`;
    return wrapper;
  }

  function enlazarArchivos(section) {
    section.querySelectorAll('.upload-card[data-document-key] input[type="file"]').forEach((input) => {
      if (input.dataset.dobleIdBound === '1') return;
      input.dataset.dobleIdBound = '1';
      input.addEventListener('change', () => actualizarArchivoSeleccionado(input));
    });
  }

  function actualizarArchivoSeleccionado(input) {
    const card = input.closest('.upload-card');
    if (!card) return;

    if (input.files?.length) renombrarArchivoSeleccionado(input, card.dataset.documentKey || 'IDENTIFICACION');

    const baseId = card.dataset.baseId || '';
    const otroId = input.id.endsWith('Archivo') ? `${baseId}Camara` : `${baseId}Archivo`;
    const otro = document.getElementById(otroId);
    if (input.files?.length && otro) otro.value = '';

    card.dataset.uploadedKeys = '';
    const archivos = obtenerArchivosCard(card);
    const estado = card.querySelector('.file-status');
    if (!archivos.length) {
      if (estado) estado.textContent = card.dataset.archivoCargado === '1'
        ? 'Documento ya cargado en el expediente'
        : 'Sin archivo seleccionado';
      return;
    }

    if (estado) estado.textContent = archivos.map((file) => file.name).join(', ');
  }

  function renombrarArchivoSeleccionado(input, key) {
    if (!input.files?.length || typeof DataTransfer === 'undefined' || typeof File === 'undefined') return;
    const original = input.files[0];
    const extension = obtenerExtension(original);
    const nombre = `${key}.${extension}`;
    const file = new File([original], nombre, {
      type: original.type,
      lastModified: original.lastModified || Date.now()
    });
    const transfer = new DataTransfer();
    transfer.items.add(file);
    input.files = transfer.files;
  }

  function obtenerExtension(file) {
    const match = String(file?.name || '').match(/\.([A-Za-z0-9]{2,5})$/);
    if (match) return match[1].toLowerCase();
    const mime = String(file?.type || '').toLowerCase();
    if (mime === 'image/png') return 'png';
    if (mime === 'image/webp') return 'webp';
    if (mime === 'application/pdf') return 'pdf';
    return 'jpg';
  }

  function obtenerArchivosCard(card) {
    const archivos = [];
    card?.querySelectorAll('input[type="file"]').forEach((input) => {
      if (input.files) archivos.push(...Array.from(input.files));
    });
    return archivos;
  }

  function cardTieneDocumento(card) {
    if (!card) return false;
    if (card.dataset.archivoCargado === '1') return true;
    return obtenerArchivosCard(card).length > 0;
  }

  function instalarPuenteExpediente(extras) {
    if (puenteInstalado) return;

    const capturarOriginal = extras.capturarEstadoExpediente.bind(extras);
    const restaurarOriginal = extras.restaurarEstadoExpediente.bind(extras);

    extras.capturarEstadoExpediente = () => {
      const estado = capturarOriginal() || {};
      const documentos = {
        ...(estado.documentos && typeof estado.documentos === 'object' ? estado.documentos : {})
      };

      document.querySelectorAll('#documentosSection .upload-card[data-document-key]').forEach((card) => {
        documentos[card.dataset.documentKey] = cardTieneDocumento(card);
      });

      documentos.ID_TITULAR = Boolean(documentos.ID_TITULAR_FRENTE && documentos.ID_TITULAR_REVERSO);
      documentos.ID_SUSTITUTO = Boolean(documentos.ID_SUSTITUTO_FRENTE && documentos.ID_SUSTITUTO_REVERSO);

      return {
        ...estado,
        documentos
      };
    };

    extras.restaurarEstadoExpediente = (estado = {}) => {
      restaurarOriginal(estado);
      const documentos = estado?.documentos && typeof estado.documentos === 'object' ? estado.documentos : {};

      aplicarEstadoCard('ID_TITULAR_FRENTE', documentos, 'ID_TITULAR');
      aplicarEstadoCard('ID_TITULAR_REVERSO', documentos, 'ID_TITULAR');
      aplicarEstadoCard('ID_SUSTITUTO_FRENTE', documentos, 'ID_SUSTITUTO');
      aplicarEstadoCard('ID_SUSTITUTO_REVERSO', documentos, 'ID_SUSTITUTO');
    };

    puenteInstalado = true;
  }

  function aplicarEstadoCard(key, documentos, legacyKey) {
    const card = document.querySelector(`#documentosSection .upload-card[data-document-key="${key}"]`);
    if (!card) return;

    const tieneEspecifico = Object.prototype.hasOwnProperty.call(documentos, key);
    const cargado = tieneEspecifico ? Boolean(documentos[key]) : Boolean(documentos[legacyKey]);
    card.dataset.archivoCargado = cargado ? '1' : '0';
    card.dataset.uploadedKeys = '';
    const estado = card.querySelector('.file-status');
    if (estado) estado.textContent = cargado ? 'Documento ya cargado en el expediente' : 'Sin archivo seleccionado';
  }

  function instalarValidacion(section) {
    document.addEventListener('click', (event) => {
      const target = event.target instanceof Element ? event.target : null;
      if (!target) return;

      const next = target.closest('#wizardNext');
      if (next && section.classList.contains('wizard-page-active') && !validarDocumentacion()) {
        event.preventDefault();
        event.stopImmediatePropagation();
        return;
      }

      const validar = target.closest('#btnValidate');
      if (validar && !validar.disabled && !validarDocumentacion()) {
        event.preventDefault();
        event.stopImmediatePropagation();
      }
    }, true);
  }

  function validarDocumentacion() {
    const section = document.getElementById('documentosSection');
    if (!section) return true;

    const faltantes = [];
    REQUERIDOS.forEach(([key, titulo]) => {
      const card = key.startsWith('ID_')
        ? section.querySelector(`.upload-card[data-document-key="${key}"]`)
        : section.querySelector(`.upload-card[data-document-type="${key}"]`);
      if (!cardTieneDocumento(card)) faltantes.push(titulo);
    });

    if (!faltantes.length) return true;

    mostrarMensaje(
      `Falta cargar documentación obligatoria: ${faltantes.join(', ')}.`,
      'error'
    );
    section.scrollIntoView({ behavior: 'smooth', block: 'start' });
    return false;
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

  window.solicitudVentaDocumentosDobles = {
    validar: validarDocumentacion
  };
})();
