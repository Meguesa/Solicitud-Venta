<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/includes/bootstrap.php';
portal_require_vobo();

$user = portal_user();
$name = htmlspecialchars((string) ($user['name'] ?? 'Usuario'), ENT_QUOTES, 'UTF-8');
$email = htmlspecialchars((string) ($user['email'] ?? ''), ENT_QUOTES, 'UTF-8');
$role = htmlspecialchars(portal_vobo_role(), ENT_QUOTES, 'UTF-8');
?>
<!doctype html>
<html lang="es-MX">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta name="theme-color" content="#225b8a">
  <title>Vo.Bo. de Solicitudes | Jardines de Juan Pablo</title>
  <link rel="stylesheet" href="/assets/css/account-menu.css">
  <link rel="stylesheet" href="./vobo.css?v=20260820-4">
</head>
<body>
  <header class="vobo-header">
    <div class="vobo-shell vobo-header-inner">
      <div>
        <p>Jardines de Juan Pablo</p>
        <h1>Vo.Bo. de Solicitudes</h1>
      </div>
      <div class="vobo-header-actions">
        <a href="/" class="secondary-link">Regresar al portal</a>
        <details class="account-menu">
          <summary class="account-trigger" aria-label="Abrir menu de usuario" title="<?= $name ?>">
            <svg viewBox="0 0 24 24" aria-hidden="true"><path fill="currentColor" d="M12 12c2.21 0 4-1.79 4-4s-1.79-4-4-4-4 1.79-4 4 1.79 4 4 4Zm0 2c-2.67 0-8 1.34-8 4v2h16v-2c0-2.66-5.33-4-8-4Z"/></svg>
          </summary>
          <div class="account-menu-panel">
            <div class="account-menu-info"><strong><?= $name ?></strong><span><?= $email ?></span></div>
            <a class="account-menu-logout" href="/logout.php">Cerrar sesión</a>
          </div>
        </details>
      </div>
    </div>
  </header>

  <main class="vobo-shell vobo-main">
    <section class="intro-card">
      <div>
        <span class="role-pill"><?= $role ?></span>
        <h2>Solicitudes pendientes de revisión</h2>
        <p>Revisa la información capturada por el vendedor y los componentes antes de dar el Vo.Bo.</p>
      </div>
      <div class="intro-actions">
        <a href="/solicitud-venta/inicio/" class="secondary-link">Regresar a inicio</a>
        <button id="btnRecargar" type="button" class="secondary-button">Actualizar bandeja</button>
      </div>
    </section>

    <div id="message" class="message" role="status"></div>

    <section id="listPanel" class="panel">
      <div class="panel-heading">
        <div>
          <h2>Bandeja</h2>
          <p id="listCount">Consultando solicitudes...</p>
        </div>
      </div>
      <div id="requestsList" class="requests-list"></div>
      <div id="emptyState" class="empty-state" hidden>No hay solicitudes pendientes de Vo.Bo.</div>
    </section>

    <section id="detailPanel" class="panel" hidden>
      <div class="panel-heading detail-heading">
        <div>
          <button id="btnBack" type="button" class="back-button">← Regresar a la bandeja</button>
          <h2 id="detailFolio">Solicitud</h2>
          <p id="detailClient"></p>
        </div>
        <span id="detailStatus" class="status-pill">PENDIENTE VOBO</span>
      </div>

      <div id="summaryGrid" class="summary-grid"></div>

      <section class="detail-block">
        <h3>Componentes de la venta</h3>
        <div id="componentsList" class="components-list"></div>
      </section>

      <section class="detail-block">
        <h3>Documentación y firmas</h3>
        <div id="documentsList" class="documents-grid"></div>
      </section>

      <section class="detail-block">
        <h3>Información capturada</h3>
        <p class="muted">Se muestran únicamente los campos capturados en la solicitud, con los mismos nombres utilizados por el vendedor.</p>
        <div id="detailFields" class="detail-grid"></div>
      </section>

      <section class="decision-panel" aria-labelledby="decisionTitle">
        <div class="decision-copy">
          <strong id="decisionTitle">Decisión de Vo.Bo.</strong>
          <p>Aprueba la solicitud si la información es correcta. Si requiere cambios, solicita una corrección indicando el motivo.</p>
        </div>
        <div class="decision-actions">
          <button id="btnSolicitarCorreccion" type="button" class="correction-button">Solicitar corrección</button>
          <button id="btnAprobarVobo" type="button" class="approve-button">Aprobar Vo.Bo.</button>
        </div>

        <div id="correctionPanel" class="correction-panel" hidden>
          <label for="correctionReason">Motivo de corrección</label>
          <textarea id="correctionReason" rows="4" maxlength="2000" placeholder="Describe exactamente qué debe corregir el vendedor."></textarea>
          <p class="correction-help">El motivo será obligatorio y quedará registrado en la solicitud.</p>
          <div class="correction-actions">
            <button id="btnCancelCorrection" type="button" class="secondary-button">Cancelar</button>
            <button id="btnConfirmCorrection" type="button" class="correction-confirm-button">Enviar a corrección</button>
          </div>
        </div>
      </section>
    </section>
  </main>

  <script src="./vobo.js?v=20260820-3"></script>
  <script>
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
      if (!window.confirm(`¿Aprobar el Vo.Bo. de ${folio}?\n\nTodos los componentes del grupo pasarán de PENDIENTE VOBO a APROBADA.`)) return;

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
      if (!window.confirm(`¿Enviar ${folio} a CORRECCION?\n\nEl motivo quedará registrado para que el vendedor pueda atenderlo.`)) return;

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
      if (!response.ok || !data?.ok) throw new Error(data?.message || data?.error || `HTTP ${response.status}`);
      return data;
    }

    function mostrarMensaje(text, type = '') {
      if (!message) return;
      message.textContent = text || '';
      message.className = `message ${type}`.trim();
      if (text) message.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
    }
  })();
  </script>
</body>
</html>
