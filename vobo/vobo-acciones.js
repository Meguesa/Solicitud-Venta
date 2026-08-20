(() => {
  const API = '/api/solicitud-venta/vobo.php';
  const btnAprobar = document.getElementById('btnAprobarVobo');
  const btnCorreccion = document.getElementById('btnSolicitarCorreccion');
  const correctionPanel = document.getElementById('correctionPanel');
  const correctionReason = document.getElementById('correctionReason');
  const btnConfirmCorrection = document.getElementById('btnConfirmCorrection');
  const btnCancelCorrection = document.getElementById('btnCancelCorrection');
  const message = document.getElementById('message');

  btnAprobar?.addEventListener('click', aprobar);
  btnCorreccion?.addEventListener('click', mostrarCorreccion);
  btnConfirmCorrection?.addEventListener('click', enviarCorreccion);
  btnCancelCorrection?.addEventListener('click', ocultarCorreccion);
  document.getElementById('btnBack')?.addEventListener('click', ocultarCorreccion);

  async function aprobar() {
    const folio = obtenerFolio();
    if (!folio) return mostrarMensaje('No se pudo identificar el folio de la solicitud.', 'error');

    const confirmado = window.confirm(
      `¿Aprobar el Vo.Bo. de ${folio}?\n\nTodos los componentes del grupo pasarán de PENDIENTE VOBO a APROBADA.`
    );
    if (!confirmado) return;

    bloquear(true);
    mostrarMensaje(`Aprobando ${folio}...`);
    try {
      const data = await llamarApi({ accion: 'aprobar', folio });
      document.getElementById('detailStatus').textContent = data.estatus || 'APROBADA';
      mostrarMensaje(data.message || `Vo.Bo. de ${folio} aprobado correctamente.`, 'ok');
      setTimeout(() => window.location.reload(), 900);
    } catch (error) {
      mostrarMensaje(error.message || String(error), 'error');
      bloquear(false);
    }
  }

  function mostrarCorreccion() {
    correctionPanel.hidden = false;
    correctionReason?.focus();
    correctionPanel.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
  }

  function ocultarCorreccion() {
    correctionPanel.hidden = true;
    if (correctionReason) correctionReason.value = '';
  }

  async function enviarCorreccion() {
    const folio = obtenerFolio();
    const motivo = String(correctionReason?.value || '').trim();
    if (!folio) return mostrarMensaje('No se pudo identificar el folio de la solicitud.', 'error');
    if (motivo.length < 5) {
      correctionReason?.focus();
      return mostrarMensaje('Escribe un motivo de corrección claro antes de continuar.', 'error');
    }

    const confirmado = window.confirm(
      `¿Enviar ${folio} a CORRECCION?\n\nEl motivo quedará registrado para que el vendedor pueda atenderlo.`
    );
    if (!confirmado) return;

    bloquear(true);
    mostrarMensaje(`Enviando ${folio} a corrección...`);
    try {
      const data = await llamarApi({ accion: 'correccion', folio, motivo });
      document.getElementById('detailStatus').textContent = data.estatus || 'CORRECCION';
      mostrarMensaje(data.message || `Corrección solicitada para ${folio}.`, 'ok');
      setTimeout(() => window.location.reload(), 900);
    } catch (error) {
      mostrarMensaje(error.message || String(error), 'error');
      bloquear(false);
    }
  }

  function obtenerFolio() {
    const value = String(document.getElementById('detailFolio')?.textContent || '').trim().toUpperCase();
    return /^SV-\d{4}-\d{6,}$/.test(value) ? value : '';
  }

  function bloquear(value) {
    [btnAprobar, btnCorreccion, btnConfirmCorrection, btnCancelCorrection].forEach((button) => {
      if (button) button.disabled = Boolean(value);
    });
    if (correctionReason) correctionReason.disabled = Boolean(value);
  }

  async function llamarApi(payload) {
    const response = await fetch(API, {
      method: 'POST',
      cache: 'no-store',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify(payload)
    });
    const data = await response.json().catch(() => null);
    if (!response.ok || !data?.ok) {
      throw new Error(data?.message || data?.error || `HTTP ${response.status}`);
    }
    return data;
  }

  function mostrarMensaje(text, type = '') {
    if (!message) return;
    message.textContent = text || '';
    message.className = `message ${type}`.trim();
    if (text) message.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
  }
})();
