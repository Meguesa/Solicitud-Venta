(() => {
  'use strict';

  if (window.__catalogoUiPreviewActivo) return;
  window.__catalogoUiPreviewActivo = true;

  const VIGENCIA = '30/06/2027';
  const CATALOGO = [
    {
      codigo: 'UI-CDURNABAS-2H',
      referencia: 'CDURNABAS',
      nombre: 'Cremación directa + velación de cenizas - 2 horas',
      precio: 22480,
      servicio: 'CREMACION DIRECTA',
      ataud: 'NO APLICA',
      urna: 'URNA MARMOL',
      duracion: '2 HORAS',
      descripcion: 'SERVICIO FUNERARIO CREMACION DIRECTA - VELACION DE CENIZAS 2 HORAS - URNA ESPECIAL EN MARMOL'
    },
    {
      codigo: 'UI-VI-ATMETBA-6H',
      referencia: 'VI-ATMETBA',
      nombre: 'Metal básico - 6 horas',
      precio: 27850,
      servicio: 'VELACION E INHUMACION',
      ataud: 'ATAUD METALICO BASICO',
      urna: 'URNA MARMOL',
      duracion: '6 HORAS',
      descripcion: 'SERVICIO FUNERARIO - VELACION E INHUMACION ATAUD METAL BASICO - 6 HORAS - CON OPCION A CREMACION'
    },
    {
      codigo: 'UI-VI-ATMETBA-12H',
      referencia: 'VI-ATMETBA',
      nombre: 'Metal básico - 12 horas',
      precio: 31950,
      servicio: 'VELACION E INHUMACION',
      ataud: 'ATAUD METALICO BASICO',
      urna: 'URNA MARMOL',
      duracion: '12 HORAS',
      descripcion: 'SERVICIO FUNERARIO - VELACION E INHUMACION ATAUD METAL BASICO - 12 HORAS - CON OPCION A CREMACION'
    },
    {
      codigo: 'UI-VI-ATMETBA-24H',
      referencia: 'VI-ATMETBA',
      nombre: 'Metal básico - 24 horas',
      precio: 39200,
      servicio: 'VELACION E INHUMACION',
      ataud: 'ATAUD METALICO BASICO',
      urna: 'URNA MARMOL',
      duracion: '24 HORAS',
      descripcion: 'SERVICIO FUNERARIO - VELACION E INHUMACION ATAUD METAL BASICO - 24 HORAS - CON OPCION A CREMACION'
    },
    {
      codigo: 'UI-VI-ATMADBA-12H',
      referencia: 'VI-ATMADBA',
      nombre: 'Madera básico - 12 horas',
      precio: 36150,
      servicio: 'VELACION E INHUMACION',
      ataud: 'ATAUD MADERA BASICO',
      urna: 'URNA MARMOL',
      duracion: '12 HORAS',
      descripcion: 'SERVICIO FUNERARIO - VELACION E INHUMACION ATAUD MADERA BASICO - 12 HORAS - CON OPCION A CREMACION'
    },
    {
      codigo: 'UI-VI-ATMADBA-24H',
      referencia: 'VI-ATMADBA',
      nombre: 'Madera básico - 24 horas',
      precio: 43400,
      servicio: 'VELACION E INHUMACION',
      ataud: 'ATAUD MADERA BASICO',
      urna: 'URNA MARMOL',
      duracion: '24 HORAS',
      descripcion: 'SERVICIO FUNERARIO - VELACION E INHUMACION ATAUD MADERA BASICO - 24 HORAS - CON OPCION A CREMACION'
    },
    {
      codigo: 'UI-VI-ATMADEX-24H',
      referencia: 'VI-ATMADEX',
      nombre: 'Madera exclusivo - hasta 24 horas',
      precio: 49850,
      servicio: 'VELACION E INHUMACION',
      ataud: 'ATAUD MADERA EXCLUSIVO',
      urna: 'URNA MARMOL',
      duracion: '24 HORAS',
      descripcion: 'SERVICIO FUNERARIO EX - VELACION E INHUMACION ATAUD MADERA EXCLUSIVO - HASTA 24 HORAS - CON OPCION A CREMACION'
    },
    {
      codigo: 'UI-VI-ATMADLX-24H',
      referencia: 'VI-ATMADLX',
      nombre: 'Total Service / Ataúd madera de lujo - hasta 24 horas',
      precio: 65000,
      servicio: 'VELACION E INHUMACION',
      ataud: 'ATAUD MADERA DE LUJO',
      urna: 'URNA MARMOL',
      duracion: '24 HORAS',
      descripcion: 'SERVICIO VELACION CON TOTAL SERVICE - ATAUD MADERA DE LUJO - HASTA 24 HORAS - CON OPCION A INHUMACION O CREMACION'
    }
  ];

  const byCode = new Map(CATALOGO.map((item) => [item.codigo, item]));

  function moneda(value) {
    return new Intl.NumberFormat('es-MX', {
      style: 'currency',
      currency: 'MXN',
      minimumFractionDigits: 2
    }).format(Number(value || 0));
  }

  function agregarEstilos() {
    if (document.getElementById('catalogoUiPreviewStyles')) return;
    const style = document.createElement('style');
    style.id = 'catalogoUiPreviewStyles';
    style.textContent = `
      .ui-catalog-panel {
        margin: 2px 0 14px;
        padding: 14px;
        background: #fff8e6;
        border: 1px solid rgba(253,187,45,.55);
        border-left: 4px solid var(--jp-gold);
        border-radius: 10px;
      }
      .ui-catalog-panel .form-grid { margin-bottom: 8px; }
      .ui-catalog-note { margin: 0; color: var(--jp-muted); font-size: 11px; line-height: 1.45; }
      .ui-catalog-note strong { color: var(--jp-brown); }
      .ui-price-variance {
        display: block; margin-top: 7px; padding: 8px 10px;
        color: #8a4b00; background: #fff4d6;
        border: 1px solid #efd391; border-radius: 7px;
        font-size: 11px; line-height: 1.35;
      }
      .ui-price-variance.ok {
        color: #176c45; background: #edf8f2; border-color: #b9ddc8;
      }
    `;
    document.head.appendChild(style);
  }

  function crearPanel(card) {
    if (card.querySelector('.ui-catalog-panel')) return;

    const general = card.querySelector('.component-general-grid');
    if (!general) return;

    const panel = document.createElement('div');
    panel.className = 'ui-catalog-panel';
    panel.hidden = true;
    panel.innerHTML = `
      <div class="form-grid grid-2">
        <label>Paquete UI · Catálogo 2026-2027
          <select class="ui-catalog-select">
            <option value="">Selecciona un paquete</option>
            ${CATALOGO.map((item) => `<option value="${item.codigo}">${item.nombre} · ${moneda(item.precio)}</option>`).join('')}
            <option value="MANUAL">OTRO / CAPTURA MANUAL</option>
          </select>
        </label>
        <label>Precio catálogo
          <input class="ui-catalog-price" type="text" readonly value="">
        </label>
      </div>
      <p class="ui-catalog-note">
        Vigencia del catálogo: <strong>hasta ${VIGENCIA}</strong>. Al seleccionar un paquete se completan referencia,
        servicio, ataúd, urna, duración y precio base. El precio de venta puede ajustarse por separado.
      </p>
    `;

    general.insertAdjacentElement('afterend', panel);

    const select = panel.querySelector('.ui-catalog-select');
    select.addEventListener('change', () => aplicarSeleccion(card, select.value));

    const tipo = card.querySelector('.component-type');
    const operacion = card.querySelector('.component-operation');
    tipo?.addEventListener('change', () => actualizarVisibilidad(card));
    operacion?.addEventListener('change', () => actualizarVisibilidad(card));

    actualizarVisibilidad(card);
  }

  function actualizarVisibilidad(card) {
    const panel = card.querySelector('.ui-catalog-panel');
    if (!panel) return;
    const esUiServicio =
      card.querySelector('.component-type')?.value === 'SERVICIO' &&
      card.querySelector('.component-operation')?.value === 'USO INMEDIATO';

    panel.hidden = !esUiServicio;

    if (!esUiServicio) {
      desbloquearCamposServicio(card);
      return;
    }
  }

  function seleccionar(control, value) {
    if (!(control instanceof HTMLSelectElement)) return;
    const buscado = String(value || '').trim().toUpperCase();
    let option = Array.from(control.options).find((item) =>
      String(item.value || '').trim().toUpperCase() === buscado ||
      String(item.textContent || '').trim().toUpperCase() === buscado
    );
    if (!option && value) {
      option = document.createElement('option');
      option.value = value;
      option.textContent = value;
      control.appendChild(option);
    }
    control.value = option ? option.value : '';
    control.dispatchEvent(new Event('change', { bubbles: true }));
  }

  function bloquearCamposServicio(card, locked) {
    ['.component-service-type', '.component-service-ataud', '.component-service-urna', '.component-service-duracion']
      .forEach((selector) => {
        const control = card.querySelector(selector);
        if (control) {
          control.disabled = locked;
          control.title = locked ? 'Dato definido por el paquete del catálogo UI.' : '';
        }
      });
    const base = card.querySelector('.component-base');
    if (base) {
      base.readOnly = locked;
      base.title = locked ? 'Precio base definido por el catálogo UI.' : '';
    }
  }

  function desbloquearCamposServicio(card) {
    bloquearCamposServicio(card, false);
  }

  function aplicarSeleccion(card, codigo) {
    const priceField = card.querySelector('.ui-catalog-price');

    if (!codigo || codigo === 'MANUAL') {
      if (priceField) priceField.value = '';
      card.dataset.uiCatalogCode = '';
      card.dataset.uiCatalogPrice = '';
      desbloquearCamposServicio(card);
      actualizarAdvertenciaPrecio();
      return;
    }

    const item = byCode.get(codigo);
    if (!item) return;

    card.dataset.uiCatalogCode = item.codigo;
    card.dataset.uiCatalogPrice = String(item.precio);
    if (priceField) priceField.value = moneda(item.precio);

    seleccionar(card.querySelector('.component-service-type'), item.servicio);
    seleccionar(card.querySelector('.component-service-ataud'), item.ataud);
    seleccionar(card.querySelector('.component-service-urna'), item.urna);
    seleccionar(card.querySelector('.component-service-duracion'), item.duracion);

    const base = card.querySelector('.component-base');
    if (base) {
      base.value = Number(item.precio).toFixed(2);
      base.dispatchEvent(new Event('input', { bubbles: true }));
    }

    bloquearCamposServicio(card, true);

    const cards = Array.from(document.querySelectorAll('#componentesContainer .component-card'));
    const principal = cards[0] === card;
    if (principal) {
      asignarValor('referencia', item.referencia, 'input');
      asignarValor('paquete', item.nombre.toUpperCase(), 'input');
      asignarValor('descripcionVenta', item.descripcion, 'input');

      const precioTotal = document.getElementById('precioTotal');
      if (precioTotal && cards.length === 1) {
        precioTotal.value = Number(item.precio).toFixed(2);
        precioTotal.dispatchEvent(new Event('input', { bubbles: true }));
      }
    }

    actualizarAdvertenciaPrecio();
  }

  function asignarValor(id, value, evento) {
    const control = document.getElementById(id);
    if (!(control instanceof HTMLInputElement || control instanceof HTMLTextAreaElement || control instanceof HTMLSelectElement)) return;
    control.value = value == null ? '' : String(value);
    if (evento) control.dispatchEvent(new Event(evento, { bubbles: true }));
  }

  function asegurarAdvertenciaPrecio() {
    const precioTotal = document.getElementById('precioTotal');
    if (!precioTotal) return null;

    let warning = document.getElementById('uiPriceVariance');
    if (warning) return warning;

    warning = document.createElement('span');
    warning.id = 'uiPriceVariance';
    warning.className = 'ui-price-variance';
    warning.hidden = true;
    precioTotal.insertAdjacentElement('afterend', warning);
    precioTotal.addEventListener('input', actualizarAdvertenciaPrecio);
    return warning;
  }

  function actualizarAdvertenciaPrecio() {
    const warning = asegurarAdvertenciaPrecio();
    if (!warning) return;

    const card = document.querySelector('#componentesContainer .component-card');
    const codigo = String(card?.dataset?.uiCatalogCode || '');
    const item = byCode.get(codigo);

    if (!item) {
      warning.hidden = true;
      return;
    }

    const precioVenta = Number(document.getElementById('precioTotal')?.value || 0);
    if (!Number.isFinite(precioVenta) || precioVenta <= 0) {
      warning.hidden = true;
      return;
    }

    const diferencia = Math.round((precioVenta - item.precio) * 100) / 100;
    warning.hidden = false;

    if (Math.abs(diferencia) < 0.01) {
      warning.className = 'ui-price-variance ok';
      warning.textContent = `Precio de venta igual al catálogo: ${moneda(item.precio)}.`;
      return;
    }

    warning.className = 'ui-price-variance';
    warning.textContent =
      `Precio de venta diferente al catálogo. Catálogo: ${moneda(item.precio)} · Venta: ${moneda(precioVenta)} · Diferencia: ${moneda(diferencia)}.`;
  }


  function configurarIdentificacionAlternativa() {
    const numeroId = document.getElementById('clienteNumeroId');
    const rfc = document.getElementById('clienteRfc');
    const curp = document.getElementById('clienteCurp');
    const tipoId = document.getElementById('clienteTipoId');
    if (!numeroId || !rfc || !curp || !tipoId) return;

    [numeroId, rfc, curp, tipoId].forEach((control) => control.removeAttribute('required'));

    const labels = [numeroId, rfc, curp].map((control) => control.closest('label')).filter(Boolean);
    labels.forEach((label) => {
      if (!label.querySelector('.id-alternative-note')) {
        const note = document.createElement('small');
        note.className = 'id-alternative-note';
        note.textContent = 'Captura al menos uno entre Número de ID, RFC o CURP.';
        note.style.color = 'var(--jp-muted)';
        note.style.fontWeight = '400';
        label.appendChild(note);
      }
    });

    function validar() {
      const tieneNumeroId = String(numeroId.value || '').trim() !== '';
      const tieneRfc = String(rfc.value || '').trim() !== '';
      const tieneCurp = String(curp.value || '').trim() !== '';
      const alguno = tieneNumeroId || tieneRfc || tieneCurp;

      numeroId.setCustomValidity(alguno ? '' : 'Captura al menos Número de ID, RFC o CURP.');
      tipoId.setCustomValidity(tieneNumeroId && !tipoId.value
        ? 'Selecciona el tipo de identificación para el Número de ID capturado.'
        : '');
    }

    [numeroId, rfc, curp, tipoId].forEach((control) => {
      control.addEventListener('input', validar);
      control.addEventListener('change', validar);
    });
    validar();
  }

  function esSolicitudUsoInmediato() {
    const principal = document.querySelector('#componentesContainer .component-card');
    if (principal) {
      return principal.querySelector('.component-operation')?.value === 'USO INMEDIATO';
    }
    return document.getElementById('tipoOperacion')?.value === 'USO INMEDIATO';
  }

  function actualizarTitularSustitutoUi() {
    const section = document.getElementById('sustitutoSection');
    if (!section) return;

    const esUi = esSolicitudUsoInmediato();
    section.hidden = esUi;

    section.querySelectorAll('input, select, textarea').forEach((control) => {
      control.required = !esUi;
      if (esUi) control.setCustomValidity('');
    });

    const docSustituto = document.getElementById('documentoIdSustituto');
    const docLabel = docSustituto?.closest('label');
    if (docLabel) docLabel.hidden = esUi;
    if (esUi && docSustituto) docSustituto.checked = false;
  }

  function configurarReglasUsoInmediato() {
    actualizarTitularSustitutoUi();

    const container = document.getElementById('componentesContainer');
    if (container && container.dataset.uiRulesBound !== '1') {
      container.dataset.uiRulesBound = '1';
      container.addEventListener('change', () => {
        window.setTimeout(actualizarTitularSustitutoUi, 0);
      });
    }

    document.getElementById('tipoOperacion')?.addEventListener('change', actualizarTitularSustitutoUi);
  }

  function prepararCards() {
    document.querySelectorAll('#componentesContainer .component-card').forEach(crearPanel);
    actualizarAdvertenciaPrecio();
    actualizarTitularSustitutoUi();
  }

  function iniciar() {
    agregarEstilos();
    configurarIdentificacionAlternativa();
    configurarReglasUsoInmediato();
    asegurarAdvertenciaPrecio();
    prepararCards();

    const container = document.getElementById('componentesContainer');
    if (container) {
      const observer = new MutationObserver(prepararCards);
      observer.observe(container, { childList: true, subtree: true });
    } else {
      const observer = new MutationObserver(() => {
        const found = document.getElementById('componentesContainer');
        if (!found) return;
        observer.disconnect();
        prepararCards();
        const inner = new MutationObserver(prepararCards);
        inner.observe(found, { childList: true, subtree: true });
      });
      observer.observe(document.body, { childList: true, subtree: true });
    }
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', () => setTimeout(iniciar, 100), { once: true });
  } else {
    setTimeout(iniciar, 100);
  }

  window.solicitudVentaCatalogoUiPreview = {
    catalogo: CATALOGO.slice(),
    vigencia: VIGENCIA
  };
})();
