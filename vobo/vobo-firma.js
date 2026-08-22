(() => {
  let canvas = null;
  let ctx = null;
  let drawing = false;
  let hasInk = false;

  function iniciar() {
    const panel = document.querySelector('.decision-panel');
    const actions = panel?.querySelector('.decision-actions');
    if (!panel || !actions || document.getElementById('voboFirmaPanel')) return;

    const wrap = document.createElement('div');
    wrap.id = 'voboFirmaPanel';
    wrap.style.cssText = 'margin-top:18px;padding:16px;border:1px solid #d7dce2;border-radius:12px;background:#fff;';
    wrap.innerHTML = `
      <div style="display:flex;justify-content:space-between;gap:12px;align-items:center;margin-bottom:10px;">
        <div><strong>Firma de autorización</strong><div style="font-size:13px;color:#667085;margin-top:3px;">Firma dentro del recuadro antes de aprobar esta solicitud.</div></div>
        <button id="btnLimpiarFirmaVobo" type="button" class="secondary-button">Limpiar firma</button>
      </div>
      <canvas id="firmaVoboCanvas" width="900" height="240" style="display:block;width:100%;height:150px;border:1px dashed #aeb7c2;border-radius:8px;background:#fff;touch-action:none;"></canvas>
    `;
    panel.insertBefore(wrap, actions);

    canvas = document.getElementById('firmaVoboCanvas');
    ctx = canvas?.getContext('2d', { alpha: true });
    if (!canvas || !ctx) return;
    ctx.lineWidth = 3;
    ctx.lineCap = 'round';
    ctx.lineJoin = 'round';
    ctx.strokeStyle = '#111';

    const point = (event) => {
      const rect = canvas.getBoundingClientRect();
      return {
        x: (event.clientX - rect.left) * (canvas.width / rect.width),
        y: (event.clientY - rect.top) * (canvas.height / rect.height)
      };
    };

    canvas.addEventListener('pointerdown', (event) => {
      drawing = true;
      hasInk = true;
      canvas.setPointerCapture?.(event.pointerId);
      const p = point(event);
      ctx.beginPath();
      ctx.moveTo(p.x, p.y);
      event.preventDefault();
    });
    canvas.addEventListener('pointermove', (event) => {
      if (!drawing) return;
      const p = point(event);
      ctx.lineTo(p.x, p.y);
      ctx.stroke();
      event.preventDefault();
    });
    const terminar = (event) => {
      drawing = false;
      try { canvas.releasePointerCapture?.(event.pointerId); } catch (_) {}
    };
    canvas.addEventListener('pointerup', terminar);
    canvas.addEventListener('pointercancel', terminar);
    canvas.addEventListener('pointerleave', (event) => { if (drawing && event.buttons === 0) terminar(event); });

    document.getElementById('btnLimpiarFirmaVobo')?.addEventListener('click', limpiar);
  }

  function limpiar() {
    if (!ctx || !canvas) return;
    ctx.clearRect(0, 0, canvas.width, canvas.height);
    hasInk = false;
  }

  function obtener() {
    if (!canvas || !hasInk) return '';
    return canvas.toDataURL('image/png');
  }

  function requerida() {
    return Boolean(canvas && !hasInk);
  }

  window.solicitudVoboFirma = { obtener, limpiar, requerida };
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', iniciar);
  else iniciar();
})();
