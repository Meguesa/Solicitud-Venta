(() => {
  let inicializado = false;
  let consecutivo = 0;
  const componentes = [];

  function iniciar() {
    if (inicializado) return;
    const form = document.getElementById('solicitudForm');
    const precioTotal = document.getElementById('precioTotal');
    const tipoSolicitud = document.getElementById('tipoSolicitud');
    const tipoOperacion = document.getElementById('tipoOperacion');
    if (!form || !precioTotal || !tipoSolicitud || !tipoOperacion) {
      setTimeout(iniciar, 60);
      return;
    }

    inicializado = true;
    prepararInterfaz(form);
    ocultarCapturaAnterior();
    agregarComponenteDesdeActual();
    sincronizarPrincipal();
    recalcularDistribucion();
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', () => setTimeout(iniciar, 0));
  } else {
    setTimeout(iniciar, 0);
  }

  function prepararInterfaz(form) {
    if (document.getElementById('componentesSection')) return;

    const pagoSection = document.getElementById('precioTotal')?.closest('.form-section');
    if (!pagoSection) return;

    const section = document.createElement('section');
    section.id = 'componentesSection';
    section.className = 'form-section';
    section.innerHTML = `
      <div class="section-title">
        <span>C</span>
        <div>
          <h3>Componentes de la venta</h3>
          <p>Agrega cada servicio, lote o nicho incluido en la operación. El primer componente será el principal.</p>
        </div>
      </div>

      <div class="component-toolbar">
        <div class="component-distribution-box">
          <label>Tipo de distribución
            <select id="distribucionTipoUI">
              <option value="AUTOMATICA">AUTOMATICA</option>
              <option value="MANUAL_PROMOCION">MANUAL_PROMOCION</option>
            </select>
          </label>
          <label id="promocionNombreLabel" hidden>Nombre de promoción
            <input id="promocionNombreUI" type="text" placeholder="Ej. PROMOCION AGOSTO 2026">
          </label>
        </div>
        <button id="btnAgregarComponente" type="button" class="draft-button inline-button">+ Agregar componente</button>
      </div>

      <div id="componentesContainer" class="component-list"></div>

      <div class="component-summary">
        <div><span>Precio base componentes</span><strong id="resumenPrecioBase">$0.00</strong></div>
        <div><span>Precio total venta</span><strong id="resumenPrecioVenta">$0.00</strong></div>
        <div><span>Monto asignado</span><strong id="resumenMontoAsignado">$0.00</strong></div>
        <div id="resumenDiferenciaBox"><span>Diferencia</span><strong id="resumenDiferencia">$0.00</strong></div>
      </div>
      <p class="component-help">La solicitud solo podrá validarse cuando la suma de los montos asignados sea igual al precio total de la venta.</p>
    `;

    pagoSection.parentElement.insertBefore(section, pagoSection);

    document.getElementById('btnAgregarComponente')?.addEventListener('click', () => {
      agregarComponente({ tipo: '', operacion: 'PREVISION' });
      recalcularDistribucion();
    });

    document.getElementById('distribucionTipoUI')?.addEventListener('change', () => {
      const manual = obtenerDistribucion() === 'MANUAL_PROMOCION';
      document.getElementById('promocionNombreLabel').hidden = !manual;
      componentes.forEach((item) => {
        item.monto.readOnly = !manual;
      });
      recalcularDistribucion();
    });

    document.getElementById('promocionNombreUI')?.addEventListener('input', sincronizarPrincipal);
    document.getElementById('precioTotal')?.addEventListener('input', recalcularDistribucion);
  }

  function ocultarCapturaAnterior() {
    const tipoSection = document.getElementById('tipoSolicitud')?.closest('.form-section');
    if (tipoSection) tipoSection.hidden = true;

    const servicioFields = document.getElementById('servicioFields');
    const propiedadFields = document.getElementById('propiedadFields');
    if (servicioFields) servicioFields.hidden = true;
    if (propiedadFields) propiedadFields.hidden = true;

    const ventaDescripcion = document.getElementById('ventaDescripcion');
    if (ventaDescripcion) ventaDescripcion.textContent = 'Los datos específicos de cada producto se capturan en Componentes de la venta.';
  }

  function agregarComponenteDesdeActual() {
    agregarComponente({
      tipo: document.getElementById('tipoSolicitud')?.value || '',
      operacion: document.getElementById('tipoOperacion')?.value || 'PREVISION',
      servicioTipo: document.getElementById('servicioTipo')?.value || '',
      servicioAtaud: document.getElementById('servicioAtaud')?.value || '',
      servicioUrna: document.getElementById('servicioUrna')?.value || '',
      servicioDuracion: document.getElementById('servicioDuracion')?.value || '',
      propiedadTipo: document.getElementById('propiedadTipo')?.value || '',
      propiedadSeccion: document.getElementById('propiedadSeccion')?.value || '',
      propiedadManzana: document.getElementById('propiedadManzana')?.value || '',
      propiedadNumero: document.getElementById('propiedadNumero')?.value || '',
      propiedadClave: document.getElementById('propiedadClave')?.value || ''
    });
  }

  function agregarComponente(datos) {
    consecutivo += 1;
    const container = document.getElementById('componentesContainer');
    if (!container) return;

    const card = document.createElement('article');
    card.className = 'component-card';
    card.dataset.componentId = String(consecutivo);
    card.innerHTML = `
      <div class="component-card-header">
        <div>
          <strong class="component-title">Componente ${componentes.length + 1}</strong>
          <span class="component-badge">${componentes.length === 0 ? 'PRINCIPAL' : 'ADICIONAL'}</span>
        </div>
        <button type="button" class="component-remove secondary-button">Eliminar</button>
      </div>

      <div class="form-grid grid-3 component-general-grid">
        <label>Tipo de componente
          <select class="component-type" required>
            <option value="">Selecciona</option>
            <option value="SERVICIO">SERVICIO FUNERARIO</option>
            <option value="LOTE">LOTE</option>
            <option value="NICHO">NICHO</option>
          </select>
        </label>
        <label>Tipo de operación
          <select class="component-operation" required>
            <option value="">Selecciona</option>
            <option value="PREVISION">PREVISION</option>
            <option value="USO INMEDIATO">USO INMEDIATO</option>
          </select>
        </label>
        <label>Tipo de venta ProcaP
          <input class="component-procap" type="text" readonly required>
        </label>
      </div>

      <div class="component-service-fields form-grid grid-4" hidden>
        <label>Servicio funerario
          <select class="component-service-type">
            <option value="">Selecciona</option>
            <option>VELACION E INHUMACION</option>
            <option>VELACION Y CREMACION</option>
            <option>CREMACION DIRECTA</option>
            <option>INHUMACION DIRECTA</option>
            <option>RENTA DE CAPILLA</option>
            <option>TRASLADO</option>
            <option>OTRO</option>
          </select>
        </label>
        <label>Tipo de ataúd<input class="component-service-ataud" type="text"></label>
        <label>Urna<input class="component-service-urna" type="text"></label>
        <label>Duración del servicio<input class="component-service-duracion" type="text"></label>
      </div>

      <div class="component-property-fields form-grid grid-4" hidden>
        <label>Subtipo<select class="component-property-type"><option value="">Selecciona</option></select></label>
        <label>Sección<input class="component-property-seccion" type="text"></label>
        <label>Manzana<input class="component-property-manzana" type="text"></label>
        <label>Número<input class="component-property-numero" type="text"></label>
        <label>Clave de propiedad<input class="component-property-clave" type="text"></label>
      </div>

      <div class="component-money-grid form-grid grid-2">
        <label>Precio base del componente
          <input class="component-base" type="number" min="0" step="0.01" required>
        </label>
        <label>Monto asignado
          <input class="component-amount" type="number" min="0" step="0.01" readonly required>
        </label>
      </div>
    `;

    container.appendChild(card);

    const item = {
      card,
      tipo: card.querySelector('.component-type'),
      operacion: card.querySelector('.component-operation'),
      procap: card.querySelector('.component-procap'),
      servicio: card.querySelector('.component-service-fields'),
      servicioTipo: card.querySelector('.component-service-type'),
      servicioAtaud: card.querySelector('.component-service-ataud'),
      servicioUrna: card.querySelector('.component-service-urna'),
      servicioDuracion: card.querySelector('.component-service-duracion'),
      propiedad: card.querySelector('.component-property-fields'),
      propiedadTipo: card.querySelector('.component-property-type'),
      propiedadSeccion: card.querySelector('.component-property-seccion'),
      propiedadManzana: card.querySelector('.component-property-manzana'),
      propiedadNumero: card.querySelector('.component-property-numero'),
      propiedadClave: card.querySelector('.component-property-clave'),
      base: card.querySelector('.component-base'),
      monto: card.querySelector('.component-amount')
    };

    componentes.push(item);

    item.tipo.value = datos.tipo || '';
    item.operacion.value = datos.operacion || 'PREVISION';
    item.servicioTipo.value = datos.servicioTipo || '';
    item.servicioAtaud.value = datos.servicioAtaud || '';
    item.servicioUrna.value = datos.servicioUrna || '';
    item.servicioDuracion.value = datos.servicioDuracion || '';
    item.propiedadSeccion.value = datos.propiedadSeccion || '';
    item.propiedadManzana.value = datos.propiedadManzana || '';
    item.propiedadNumero.value = datos.propiedadNumero || '';
    item.propiedadClave.value = datos.propiedadClave || '';

    item.tipo.addEventListener('change', () => {
      actualizarComponente(item);
      sincronizarPrincipal();
    });
    item.operacion.addEventListener('change', () => {
      actualizarComponente(item);
      sincronizarPrincipal();
    });
    [item.servicioTipo, item.servicioAtaud, item.servicioUrna, item.servicioDuracion,
      item.propiedadTipo, item.propiedadSeccion, item.propiedadManzana, item.propiedadNumero, item.propiedadClave]
      .forEach((control) => control.addEventListener('input', sincronizarPrincipal));

    item.base.addEventListener('input', recalcularDistribucion);
    item.monto.addEventListener('input', () => {
      actualizarResumen();
      sincronizarPrincipal();
    });

    card.querySelector('.component-remove')?.addEventListener('click', () => eliminarComponente(item));

    actualizarComponente(item, datos.propiedadTipo || '');
    item.monto.readOnly = obtenerDistribucion() !== 'MANUAL_PROMOCION';
    renumerarComponentes();
  }

  function eliminarComponente(item) {
    if (componentes.length <= 1) {
      alert('La solicitud debe conservar al menos un componente.');
      return;
    }
    const index = componentes.indexOf(item);
    if (index >= 0) componentes.splice(index, 1);
    item.card.remove();
    renumerarComponentes();
    sincronizarPrincipal();
    recalcularDistribucion();
  }

  function renumerarComponentes() {
    componentes.forEach((item, index) => {
      item.card.querySelector('.component-title').textContent = `Componente ${index + 1}`;
      item.card.querySelector('.component-badge').textContent = index === 0 ? 'PRINCIPAL' : 'ADICIONAL';
      const remove = item.card.querySelector('.component-remove');
      if (remove) remove.hidden = componentes.length === 1;
    });
  }

  function actualizarComponente(item, valorPropiedadInicial = '') {
    const tipo = item.tipo.value;
    const operacion = item.operacion.value;
    item.procap.value = obtenerTipoVentaProcap(tipo, operacion);

    item.servicio.hidden = tipo !== 'SERVICIO';
    item.propiedad.hidden = !(tipo === 'LOTE' || tipo === 'NICHO');

    item.propiedadTipo.innerHTML = '<option value="">Selecciona</option>';
    if (tipo === 'LOTE') ['BRONCE', 'ORO', 'PLATA', 'PLATINO', 'SJV', 'SMV', 'SPV'].forEach((v) => agregarOpcion(item.propiedadTipo, v));
    if (tipo === 'NICHO') ['PLN', 'SPN'].forEach((v) => agregarOpcion(item.propiedadTipo, v));
    if (valorPropiedadInicial && Array.from(item.propiedadTipo.options).some((o) => o.value === valorPropiedadInicial)) {
      item.propiedadTipo.value = valorPropiedadInicial;
    }

    const requeridosServicio = [item.servicioTipo, item.servicioAtaud, item.servicioUrna, item.servicioDuracion];
    const requeridosPropiedad = [item.propiedadTipo, item.propiedadSeccion, item.propiedadManzana, item.propiedadNumero, item.propiedadClave];
    requeridosServicio.forEach((control) => control.required = tipo === 'SERVICIO');
    requeridosPropiedad.forEach((control) => control.required = tipo === 'LOTE' || tipo === 'NICHO');
  }

  function obtenerTipoVentaProcap(tipo, operacion) {
    if (tipo === 'SERVICIO' && operacion === 'PREVISION') return 'SERVICIO PREVISION';
    if (tipo === 'SERVICIO' && operacion === 'USO INMEDIATO') return 'SERVICIO UI';
    if (tipo === 'LOTE' && operacion === 'PREVISION') return 'CEMENTERIO PREVISION';
    if (tipo === 'LOTE' && operacion === 'USO INMEDIATO') return 'CEMENTERIO UI';
    if (tipo === 'NICHO' && operacion === 'PREVISION') return 'NICHO PREVISION';
    if (tipo === 'NICHO' && operacion === 'USO INMEDIATO') return 'NICHO UI';
    return '';
  }

  function agregarOpcion(select, value) {
    const option = document.createElement('option');
    option.value = value;
    option.textContent = value;
    select.appendChild(option);
  }

  function obtenerDistribucion() {
    return document.getElementById('distribucionTipoUI')?.value || 'AUTOMATICA';
  }

  function recalcularDistribucion() {
    const totalVenta = numero(document.getElementById('precioTotal')?.value);
    const totalBase = componentes.reduce((sum, item) => sum + numero(item.base.value), 0);
    const manual = obtenerDistribucion() === 'MANUAL_PROMOCION';

    if (!manual) {
      let acumulado = 0;
      componentes.forEach((item, index) => {
        let asignado = 0;
        if (totalVenta > 0) {
          if (totalBase > 0) {
            if (index === componentes.length - 1) {
              asignado = Math.max(0, totalVenta - acumulado);
            } else {
              asignado = redondearMoneda(totalVenta * numero(item.base.value) / totalBase);
              acumulado += asignado;
            }
          } else if (componentes.length === 1) {
            asignado = totalVenta;
          }
        }
        item.monto.value = asignado ? asignado.toFixed(2) : '0.00';
        item.monto.readOnly = true;
      });
    } else {
      componentes.forEach((item) => item.monto.readOnly = false);
    }

    actualizarResumen();
    sincronizarPrincipal();
  }

  function actualizarResumen() {
    const totalBase = componentes.reduce((sum, item) => sum + numero(item.base.value), 0);
    const totalVenta = numero(document.getElementById('precioTotal')?.value);
    const totalAsignado = componentes.reduce((sum, item) => sum + numero(item.monto.value), 0);
    const diferencia = redondearMoneda(totalVenta - totalAsignado);

    texto('resumenPrecioBase', moneda(totalBase));
    texto('resumenPrecioVenta', moneda(totalVenta));
    texto('resumenMontoAsignado', moneda(totalAsignado));
    texto('resumenDiferencia', moneda(diferencia));

    const box = document.getElementById('resumenDiferenciaBox');
    if (box) box.classList.toggle('component-summary-error', Math.abs(diferencia) > 0.009);
  }

  function sincronizarPrincipal() {
    const principal = componentes[0];
    if (!principal) return;

    asignar('tipoSolicitud', principal.tipo.value);
    asignar('tipoOperacion', principal.operacion.value);
    asignar('tipoVentaProcap', principal.procap.value);
    asignar('tipoContrato', principal.procap.value);

    asignar('servicioTipo', principal.servicioTipo.value);
    asignar('servicioAtaud', principal.servicioAtaud.value);
    asignar('servicioUrna', principal.servicioUrna.value);
    asignar('servicioDuracion', principal.servicioDuracion.value);
    asignar('propiedadTipo', principal.propiedadTipo.value);
    asignar('propiedadSeccion', principal.propiedadSeccion.value);
    asignar('propiedadManzana', principal.propiedadManzana.value);
    asignar('propiedadNumero', principal.propiedadNumero.value);
    asignar('propiedadClave', principal.propiedadClave.value);

    const precioLista = document.getElementById('precioLista');
    if (precioLista && document.getElementById('formaPago')?.value !== 'CREDITO') {
      precioLista.value = principal.base.value || '';
    }

    window.solicitudVentaComponentes = obtenerPayloadComponentes();
    window.solicitudVentaDistribucion = {
      tipo: obtenerDistribucion(),
      promocionNombre: document.getElementById('promocionNombreUI')?.value?.trim() || ''
    };
  }

  function obtenerPayloadComponentes() {
    return componentes.map((item, index) => ({
      componenteNumero: index + 1,
      esPrincipal: index === 0,
      tipoSolicitud: item.tipo.value,
      tipoOperacion: item.operacion.value,
      tipoVentaProcap: item.procap.value,
      servicioTipo: item.servicioTipo.value,
      servicioAtaud: item.servicioAtaud.value,
      servicioUrna: item.servicioUrna.value,
      servicioDuracion: item.servicioDuracion.value,
      propiedadTipo: item.propiedadTipo.value,
      propiedadSeccion: item.propiedadSeccion.value,
      propiedadManzana: item.propiedadManzana.value,
      propiedadNumero: item.propiedadNumero.value,
      propiedadClave: item.propiedadClave.value,
      precioBaseComponente: numero(item.base.value),
      montoComponente: numero(item.monto.value)
    }));
  }

  function validarComponentes() {
    if (!componentes.length) return { ok: false, message: 'Agrega al menos un componente.' };
    for (const item of componentes) {
      if (!item.card.querySelector('select.component-type')?.value || !item.card.querySelector('select.component-operation')?.value) {
        return { ok: false, message: 'Todos los componentes deben tener tipo y operación.' };
      }
      if (!item.card.checkValidity()) {
        item.card.querySelector(':invalid')?.reportValidity?.();
        return { ok: false, message: 'Faltan datos obligatorios en uno de los componentes.' };
      }
    }

    const totalVenta = numero(document.getElementById('precioTotal')?.value);
    const totalAsignado = componentes.reduce((sum, item) => sum + numero(item.monto.value), 0);
    if (Math.abs(totalVenta - totalAsignado) > 0.009) {
      return { ok: false, message: 'La suma de los montos asignados debe ser igual al precio total de la venta.' };
    }

    if (obtenerDistribucion() === 'MANUAL_PROMOCION' && !document.getElementById('promocionNombreUI')?.value?.trim()) {
      return { ok: false, message: 'Captura el nombre de la promoción para una distribución manual.' };
    }

    return { ok: true };
  }

  function numero(value) {
    const n = Number(value || 0);
    return Number.isFinite(n) ? n : 0;
  }

  function redondearMoneda(value) {
    return Math.round((value + Number.EPSILON) * 100) / 100;
  }

  function moneda(value) {
    return new Intl.NumberFormat('es-MX', { style: 'currency', currency: 'MXN' }).format(value || 0);
  }

  function texto(id, value) {
    const el = document.getElementById(id);
    if (el) el.textContent = value;
  }

  function asignar(id, value) {
    const control = document.getElementById(id);
    if (control) control.value = value || '';
  }

  window.solicitudVentaComponentesValidar = validarComponentes;
  window.solicitudVentaComponentesObtener = obtenerPayloadComponentes;
  window.solicitudVentaComponentesRecalcular = recalcularDistribucion;
})();
