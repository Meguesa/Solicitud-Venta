(() => {
  const ENDPOINT = '/api/solicitud-venta/mis-solicitudes.php';
  let data = { pendientes: [], aprobadas: [] };
  let vista = 'pendientes';

  document.addEventListener('DOMContentLoaded', iniciar);

  function iniciar() {
    document.querySelectorAll('[data-view]').forEach((button) => {
      button.addEventListener('click', () => cambiarVista(button.dataset.view || 'pendientes'));
    });
    document.getElementById('btnRecargar')?.addEventListener('click', cargar);
    cargar();
  }

  async function cargar() {
    setLoading(true);
    mostrarMensaje('');
    try {
      const response = await fetch(ENDPOINT, { method: 'GET', cache: 'no-store', credentials: 'same-origin' });
      const payload = await response.json().catch(() => null);
      if (!response.ok || !payload?.ok) {
        if (response.status === 401) {
          location.href = '/login.php';
          return;
        }
        throw new Error(payload?.message || payload?.error || `HTTP ${response.status}`);
      }
      data = {
        pendientes: Array.isArray(payload.pendientes) ? payload.pendientes : [],
        aprobadas: Array.isArray(payload.aprobadas) ? payload.aprobadas : []
      };
      document.getElementById('countPendientes').textContent = String(data.pendientes.length);
      document.getElementById('countAprobadas').textContent = String(data.aprobadas.length);
      render();
    } catch (error) {
      mostrarMensaje(`No fue posible cargar tus solicitudes: ${error.message || error}`);
      document.getElementById('requestsList').innerHTML = '';
      document.getElementById('emptyState').hidden = false;
    } finally {
      setLoading(false);
    }
  }

  function cambiarVista(nuevaVista) {
    vista = nuevaVista === 'aprobadas' ? 'aprobadas' : 'pendientes';
    document.querySelectorAll('[data-view]').forEach((button) => {
      button.classList.toggle('active', button.dataset.view === vista);
    });
    render();
  }

  function render() {
    const lista = data[vista] || [];
    const contenedor = document.getElementById('requestsList');
    const vacio = document.getElementById('emptyState');
    const titulo = document.getElementById('listTitle');
    const subtitulo = document.getElementById('listSubtitle');

    titulo.textContent = vista === 'aprobadas' ? 'Solicitudes aprobadas' : 'Solicitudes pendientes';
    subtitulo.textContent = vista === 'aprobadas'
      ? `${lista.length} solicitud(es) con aprobación final.`
      : `${lista.length} solicitud(es) en seguimiento.`;

    contenedor.innerHTML = '';
    vacio.hidden = lista.length > 0;
    if (!lista.length) return;

    lista.forEach((item) => contenedor.appendChild(crearTarjeta(item)));
  }

  function crearTarjeta(item) {
    const card = document.createElement('div');
    card.className = 'request-card';
    card.setAttribute('role', 'link');
    card.tabIndex = 0;

    const folio = String(item.folio || '');
    const itemId = String(item.itemId || '').trim();
    const voboEstatus = String(item.voboEstatus || '').trim().toUpperCase();
    const estatusBase = String(item.estatus || '').trim().toUpperCase();
    const esCorreccion = voboEstatus === 'CORRECCION' || estatusBase === 'CORRECCION';
    const esRevisionCompleta = [
      'PENDIENTE VOBO',
      'PENDIENTE COBRANZA',
      'APROBADA'
    ].includes(estatusBase) || [
      'PENDIENTE VOBO',
      'PENDIENTE COBRANZA',
      'APROBADA'
    ].includes(voboEstatus);
    const estatusVisible = esCorreccion ? 'CORRECCION' : (item.estatus || '—');

    const params = new URLSearchParams({ folio });
    if (/^\d+$/.test(itemId)) params.set('itemId', itemId);
    if (esCorreccion) params.set('correccion', '1');
    if (esRevisionCompleta) params.set('resumen', '1');
    const solicitudUrl = `/solicitud-venta/?${params.toString()}`;

    const abrirSolicitud = () => {
      window.location.href = solicitudUrl;
    };

    card.addEventListener('click', (event) => {
      if (event.target instanceof Element && event.target.closest('.request-pdf-button')) return;
      abrirSolicitud();
    });
    card.addEventListener('keydown', (event) => {
      if (event.key !== 'Enter' && event.key !== ' ') return;
      if (event.target instanceof Element && event.target.closest('.request-pdf-button')) return;
      event.preventDefault();
      abrirSolicitud();
    });

    card.append(
      campo('Folio', item.folio || '—'),
      campo('Cliente', item.cliente || '—'),
      campo('Estatus', estatusVisible, true),
      campo('Componentes', String(item.componentes || 1)),
      campo('Precio total', moneda(item.precioTotal))
    );

    if (vista === 'aprobadas' && /^SV-\d{4}-\d+$/.test(folio)) {
      card.classList.add('has-pdf-action');
      const pdf = document.createElement('a');
      pdf.className = 'request-pdf-button';
      pdf.href = `/api/solicitud-venta/pdf-final.php?folio=${encodeURIComponent(folio)}`;
      pdf.target = '_blank';
      pdf.rel = 'noopener noreferrer';
      pdf.textContent = 'Ver PDF final';
      pdf.setAttribute('aria-label', `Ver PDF final de ${folio}`);
      pdf.addEventListener('click', (event) => event.stopPropagation());
      card.appendChild(pdf);
    }

    return card;
  }

  function campo(etiqueta, valor, estatus = false) {
    const div = document.createElement('div');
    div.className = 'request-field';
    const label = document.createElement('span');
    label.textContent = etiqueta;
    const strong = document.createElement('strong');
    if (estatus) {
      const badge = document.createElement('span');
      const valorNormalizado = String(valor).toUpperCase();
      badge.className = `status-badge${valorNormalizado === 'APROBADA' ? ' approved' : ''}`;
      badge.textContent = valor;
      strong.appendChild(badge);
    } else {
      strong.textContent = valor;
    }
    div.append(label, strong);
    return div;
  }

  function moneda(valor) {
    const numero = Number(valor);
    if (!Number.isFinite(numero)) return '—';
    return numero.toLocaleString('es-MX', { style: 'currency', currency: 'MXN' });
  }

  function mostrarMensaje(texto) {
    const mensaje = document.getElementById('message');
    if (mensaje) mensaje.textContent = texto || '';
  }

  function setLoading(loading) {
    document.body.classList.toggle('loading', loading);
    const boton = document.getElementById('btnRecargar');
    if (boton) boton.disabled = loading;
  }
})();