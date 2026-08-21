(() => {
  const params = new URLSearchParams(window.location.search);
  if (params.get('resumen') !== '1') return;

  const ESTATUS_RESUMEN = new Set(['PENDIENTE VOBO', 'PENDIENTE COBRANZA', 'APROBADA']);
  let abierto = false;
  let intentos = 0;

  function iniciar() {
    if (abierto) return;
    if (!document.getElementById('solicitudForm') || !window.solicitudVentaWizard) {
      setTimeout(iniciar, 100);
      return;
    }
    setTimeout(intentarAbrirResumen, 250);
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', () => setTimeout(iniciar, 0));
  } else {
    setTimeout(iniciar, 0);
  }

  function intentarAbrirResumen() {
    if (abierto) return;
    intentos += 1;

    const estatus = obtenerEstatus();
    const folio = document.querySelector('.folio-box strong')?.textContent?.trim() || '';
    const cliente = document.getElementById('clienteNombres')?.value?.trim() || '';
    const componente = document.querySelector('#componentesContainer .component-card .component-type')?.value?.trim() || '';
    const datosListos = /^SV-\d{4}-\d+$/.test(folio) && cliente !== '' && componente !== '';

    if (ESTATUS_RESUMEN.has(estatus) && datosListos && typeof window.solicitudVentaWizard?.mostrarResumen === 'function') {
      abierto = true;
      window.solicitudVentaWizard.mostrarResumen();
      return;
    }

    if (intentos < 100) {
      setTimeout(intentarAbrirResumen, 150);
    }
  }

  function obtenerEstatus() {
    const bodyStatus = String(document.body.dataset.solicitudEstatus || '').trim().toUpperCase();
    if (bodyStatus) return bodyStatus;
    return String(document.querySelector('.status-pill')?.textContent || '').trim().toUpperCase();
  }
})();