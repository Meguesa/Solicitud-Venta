(() => {
  const ENDPOINT = '/api/solicitud-venta/sucursales-componentes.php';
  const STORAGE_PREFIX = 'solicitudVenta:borradorActivo:v1:';
  const SUCURSALES_SERVICIO = ['CHURUBUSCO', 'AGUA FRIA'];
  let inicializado = false;
  let getterEnvuelto = false;
  let guardadoEnvuelto = false;
  let restauracionSolicitada = false;
  let folioInicial = '';

  function iniciar() {
    if (inicializado) return;
    const container = document.getElementById('componentesContainer');
    const lugar = document.getElementById('lugar');
    if (!container || !lugar || !window.solicitudVentaAuth || typeof window.solicitudVentaComponentesObtener !== 'function') {
      setTimeout(iniciar, 80);
      return;
    }

    inicializado = true;
    folioInicial = obtenerFolioInicial();
    configurarLugar(lugar);
    instalarCards(container);
    envolverGetterComponentes();
    instalarObservador(container);
    instalarEnriquecimientoFirmaRemota();
    prepararEnvolturaGuardado();

    if (folioInicial) {
      setTimeout(() => restaurarSucursales(folioInicial), 1400);
    }
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', () => setTimeout(iniciar, 0));
  } else {
    setTimeout(iniciar, 0);
  }

  function configurarLugar(select) {
    if (!(select instanceof HTMLSelectElement)) return;
    const actual = String(select.value || '').trim().toUpperCase();
    const opciones = [
      ['', 'Selecciona'],
      ['SUCURSAL', 'Sucursal'],
      ['PUNTO DE VENTA', 'Punto de Venta'],
      ['CANVACEO', 'Canvaceo'],
      ['OTRO', 'Otro']
    ];

    select.textContent = '';
    opciones.forEach(([value, label]) => {
      const option = document.createElement('option');
      option.value = value;
      option.textContent = label;
      select.appendChild(option);
    });

    select.value = opciones.some(([value]) => value === actual) ? actual : '';
  }

  function instalarObservador(container) {
    const observer = new MutationObserver(() => instalarCards(container));
    observer.observe(container, { childList: true, subtree: true });
  }

  function instalarCards(container) {
    Array.from(container.querySelectorAll('.component-card')).forEach(instalarCard);
  }

  function instalarCard(card) {
    if (!(card instanceof HTMLElement) || card.dataset.sucursalInstalada === '1') return;
    const grid = card.querySelector('.component-general-grid');
    const tipo = card.querySelector('.component-type');
    if (!grid || !(tipo instanceof HTMLSelectElement)) return;

    const label = document.createElement('label');
    label.className = 'component-sucursal-label';
    label.innerHTML = `Sucursal
      <select class="component-sucursal">
        <option value="">Selecciona</option>
      </select>`;
    grid.appendChild(label);

    card.dataset.sucursalInstalada = '1';
    tipo.addEventListener('change', () => actualizarSucursalCard(card));
    actualizarSucursalCard(card);
  }

  function actualizarSucursalCard(card, valorPreferido = '') {
    const tipo = String(card.querySelector('.component-type')?.value || '').trim().toUpperCase();
    const select = card.querySelector('.component-sucursal');
    if (!(select instanceof HTMLSelectElement)) return;

    const actual = String(valorPreferido || select.value || '').trim().toUpperCase();
    select.textContent = '';

    if (tipo === 'SERVICIO') {
      agregarOpcion(select, '', 'Selecciona');
      SUCURSALES_SERVICIO.forEach((value) => agregarOpcion(select, value, value));
      select.disabled = false;
      select.required = true;
      if (SUCURSALES_SERVICIO.includes(actual)) select.value = actual;
      return;
    }

    if (tipo === 'LOTE' || tipo === 'NICHO') {
      agregarOpcion(select, 'PARQUE', 'PARQUE');
      select.value = 'PARQUE';
      select.required = false;
      select.disabled = true;
      return;
    }

    agregarOpcion(select, '', 'Selecciona');
    select.value = '';
    select.required = false;
    select.disabled = true;
  }

  function agregarOpcion(select, value, label) {
    const option = document.createElement('option');
    option.value = value;
    option.textContent = label;
    select.appendChild(option);
  }

  function obtenerSucursalCard(card) {
    const tipo = String(card.querySelector('.component-type')?.value || '').trim().toUpperCase();
    if (tipo === 'LOTE' || tipo === 'NICHO') return 'PARQUE';
    if (tipo !== 'SERVICIO') return '';
    return String(card.querySelector('.component-sucursal')?.value || '').trim().toUpperCase();
  }

  function envolverGetterComponentes() {
    if (getterEnvuelto || window.__solicitudSucursalesGetterEnvuelto) return;
    const getterAnterior = window.solicitudVentaComponentesObtener;
    if (typeof getterAnterior !== 'function') return;

    window.solicitudVentaComponentesObtener = function (...args) {
      const base = getterAnterior.apply(this, args);
      const cards = Array.from(document.querySelectorAll('#componentesContainer .component-card'));
      if (!Array.isArray(base)) return base;
      return base.map((componente, index) => ({
        ...componente,
        sucursal: obtenerSucursalCard(cards[index])
      }));
    };

    getterEnvuelto = true;
    window.__solicitudSucursalesGetterEnvuelto = true;
  }

  function prepararEnvolturaGuardado() {
    if (guardadoEnvuelto || window.__solicitudSucursalesGuardadoEnvuelto) return;
    if (typeof window.guardarBorrador !== 'function' || window.__solicitudPersistenciaGuardadoEnvuelto !== true) {
      setTimeout(prepararEnvolturaGuardado, 120);
      return;
    }

    const guardarAnterior = window.guardarBorrador;
    window.guardarBorrador = async function (...args) {
      const resultado = await guardarAnterior.apply(this, args);
      const folio = obtenerFolioActual();
      if (!folio) return resultado;

      const mensaje = document.getElementById('formMessage');
      const guardadoOk = Boolean(
        mensaje?.classList.contains('ok') ||
        String(mensaje?.textContent || '').includes('guardado correctamente')
      );
      if (!guardadoOk) return resultado;

      try {
        await guardarSucursales(folio);
        return resultado;
      } catch (error) {
        console.error('No fue posible guardar las sucursales de los componentes:', error);
        if (typeof window.mostrarMensaje === 'function') {
          window.mostrarMensaje(`El borrador se guardó, pero faltó sincronizar la sucursal de los componentes: ${error.message || error}`, 'error');
        }
        throw error;
      }
    };

    guardadoEnvuelto = true;
    window.__solicitudSucursalesGuardadoEnvuelto = true;
  }

  async function guardarSucursales(folio) {
    const token = await window.solicitudVentaAuth.getBackendAccessToken();
    if (!token) throw new Error('No fue posible obtener autorización para guardar las sucursales.');

    const componentes = window.solicitudVentaComponentesObtener();
    const response = await fetch(ENDPOINT, {
      method: 'POST',
      cache: 'no-store',
      headers: {
        'Content-Type': 'application/json',
        Authorization: `Bearer ${token}`
      },
      body: JSON.stringify({ accion: 'guardar', folio, componentes })
    });
    const data = await response.json().catch(() => null);
    if (!response.ok || !data?.ok) throw new Error(data?.message || data?.error || `HTTP ${response.status}`);
    return data;
  }

  async function restaurarSucursales(folio) {
    if (restauracionSolicitada || !folio) return;
    restauracionSolicitada = true;

    try {
      const token = await window.solicitudVentaAuth.getBackendAccessToken();
      if (!token) return;
      const response = await fetch(ENDPOINT, {
        method: 'POST',
        cache: 'no-store',
        headers: {
          'Content-Type': 'application/json',
          Authorization: `Bearer ${token}`
        },
        body: JSON.stringify({ accion: 'cargar', folio })
      });
      const data = await response.json().catch(() => null);
      if (!response.ok || !data?.ok || !Array.isArray(data.componentes)) return;
      await aplicarSucursalesRestauradas(data.componentes);
    } catch (error) {
      console.warn('No fue posible restaurar las sucursales de los componentes:', error);
    }
  }

  async function aplicarSucursalesRestauradas(componentes) {
    for (let intento = 0; intento < 20; intento += 1) {
      const cards = Array.from(document.querySelectorAll('#componentesContainer .component-card'));
      instalarCards(document.getElementById('componentesContainer'));
      if (cards.length === componentes.length && cards.length > 0) {
        componentes.forEach((item, index) => {
          const card = cards[index];
          if (!card) return;
          actualizarSucursalCard(card, item?.sucursal || '');
        });
        return;
      }
      await esperar(150);
    }
  }

  function instalarEnriquecimientoFirmaRemota() {
    if (window.__solicitudSucursalesFetchEnvuelto) return;
    const fetchAnterior = window.fetch.bind(window);

    window.fetch = function (input, init = {}) {
      try {
        const url = typeof input === 'string' ? input : String(input?.url || '');
        const metodo = String(init?.method || 'GET').toUpperCase();
        if (url.includes('/api/solicitud-venta/iniciar-firma-remota.php') && metodo === 'POST' && typeof init?.body === 'string') {
          const body = JSON.parse(init.body);
          const componentes = typeof window.solicitudVentaComponentesObtener === 'function'
            ? window.solicitudVentaComponentesObtener()
            : [];
          const campos = componentes
            .map((item, index) => ({
              etiqueta: `Componente ${index + 1} · Sucursal`,
              valor: String(item?.sucursal || '').trim(),
              tipo: 'texto'
            }))
            .filter((item) => item.valor);

          if (campos.length) {
            const detalle = Array.isArray(body.detalleSolicitud) ? body.detalleSolicitud.slice() : [];
            detalle.push({ titulo: 'Sucursales de los componentes', campos });
            body.detalleSolicitud = detalle;
            init = { ...init, body: JSON.stringify(body) };
          }
        }
      } catch (error) {
        console.warn('No fue posible agregar las sucursales al detalle de firma:', error);
      }
      return fetchAnterior(input, init);
    };

    window.__solicitudSucursalesFetchEnvuelto = true;
  }

  function obtenerFolioInicial() {
    const query = String(new URLSearchParams(location.search).get('folio') || '').trim().toUpperCase();
    if (/^SV-\d{4}-\d+$/.test(query)) return query;

    const key = claveStorage();
    if (!key) return '';
    try {
      const raw = localStorage.getItem(key);
      const data = raw ? JSON.parse(raw) : null;
      const folio = String(data?.folio || '').trim().toUpperCase();
      return /^SV-\d{4}-\d+$/.test(folio) ? folio : '';
    } catch (_) {
      return '';
    }
  }

  function obtenerFolioActual() {
    const folio = document.querySelector('.folio-box strong')?.textContent?.trim() || '';
    return /^SV-\d{4}-\d+$/.test(folio) ? folio : '';
  }

  function claveStorage() {
    const usuario = window.solicitudVentaAuth?.getUser?.();
    const correo = String(usuario?.username || document.getElementById('userEmail')?.textContent || '').trim().toLowerCase();
    return correo ? `${STORAGE_PREFIX}${correo}` : '';
  }

  function esperar(ms) {
    return new Promise((resolve) => setTimeout(resolve, ms));
  }
})();
