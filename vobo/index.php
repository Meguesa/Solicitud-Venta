<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/includes/bootstrap.php';

$etapa = strtolower(trim((string) ($_GET['etapa'] ?? '')));
if ($etapa === '') {
    if (portal_user_can_vobo()) {
        $etapa = 'comercial';
    } elseif (portal_user_can_cobranza_vobo()) {
        $etapa = 'cobranza';
    } else {
        portal_require_authentication();
        http_response_code(403);
        exit('Tu cuenta no tiene autorización para revisar solicitudes.');
    }
}
if (!in_array($etapa, ['comercial', 'cobranza'], true)) {
    http_response_code(400);
    exit('La etapa de Vo.Bo. indicada no es válida.');
}
if ($etapa === 'cobranza') portal_require_cobranza_vobo();
else portal_require_vobo();

$user = portal_user();
$name = htmlspecialchars((string) ($user['name'] ?? 'Usuario'), ENT_QUOTES, 'UTF-8');
$email = htmlspecialchars((string) ($user['email'] ?? ''), ENT_QUOTES, 'UTF-8');
$isCobranza = $etapa === 'cobranza';
$role = htmlspecialchars($isCobranza ? 'COBRANZA' : portal_vobo_role(), ENT_QUOTES, 'UTF-8');
$pageTitle = $isCobranza ? 'Vo.Bo. de Cobranza' : 'Vo.Bo. Comercial';
$introTitle = $isCobranza ? 'Solicitudes pendientes de Cobranza' : 'Solicitudes pendientes de revisión comercial';
$introText = $isCobranza
    ? 'Revisa la solicitud ya autorizada por Comercial antes de emitir el Vo.Bo. final de Cobranza.'
    : 'Revisa la información capturada por el vendedor y los componentes antes de autorizar su envío a Cobranza.';
$pendingStatus = $isCobranza ? 'PENDIENTE COBRANZA' : 'PENDIENTE VOBO';
$decisionTitle = $isCobranza ? 'Decisión de Cobranza' : 'Decisión de Vo.Bo. Comercial';
$decisionText = $isCobranza
    ? 'Aprueba la solicitud si la información y condiciones de cobro son correctas. Si requiere cambios, solicita una corrección indicando el motivo.'
    : 'Aprueba la solicitud si la información comercial es correcta. Al aprobarla pasará a Vo.Bo. de Cobranza.';
$approveLabel = $isCobranza ? 'Aprobar Cobranza' : 'Aprobar Vo.Bo. Comercial';
?>
<!doctype html>
<html lang="es-MX">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta name="theme-color" content="#ffffff">
  <title><?= htmlspecialchars($pageTitle, ENT_QUOTES, 'UTF-8') ?> | Jardines de Juan Pablo</title>
  <link rel="stylesheet" href="/assets/css/account-menu.css">
  <link rel="stylesheet" href="./vobo.css?v=20260823-5">
</head>
<body>
  <header class="vobo-header">
    <div class="vobo-shell vobo-header-inner">
      <div class="vobo-header-title">
        <p>Jardines de Juan Pablo</p>
        <h1><?= htmlspecialchars($pageTitle, ENT_QUOTES, 'UTF-8') ?></h1>
      </div>
      <div class="vobo-header-actions">
        <a href="/" class="secondary-link">Regresar al portal</a>
        <details class="account-menu">
          <summary class="account-trigger" aria-label="Abrir menu de usuario" title="<?= $name ?>">
            <svg viewBox="0 0 24 24" aria-hidden="true">
              <circle cx="12" cy="8" r="4" fill="currentColor" />
              <path d="M4 20c0-4.1 3.6-6 8-6s8 1.9 8 6v1H4z" fill="currentColor" />
            </svg>
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
      <div><span class="role-pill"><?= $role ?></span><h2><?= htmlspecialchars($introTitle, ENT_QUOTES, 'UTF-8') ?></h2><p><?= htmlspecialchars($introText, ENT_QUOTES, 'UTF-8') ?></p></div>
      <div class="intro-actions"><a href="/solicitud-venta/inicio/" class="secondary-link">Regresar a inicio</a><button id="btnRecargar" type="button" class="secondary-button">Actualizar bandeja</button></div>
    </section>

    <div id="message" class="message" role="status"></div>

    <section id="listPanel" class="panel">
      <div class="panel-heading"><div><h2>Bandeja</h2><p id="listCount">Consultando solicitudes...</p></div></div>
      <div id="requestsList" class="requests-list"></div>
      <div id="emptyState" class="empty-state" hidden>No hay solicitudes pendientes en esta etapa.</div>
    </section>

    <section id="detailPanel" class="panel" hidden>
      <div class="panel-heading detail-heading">
        <div><button id="btnBack" type="button" class="back-button">← Regresar a la bandeja</button><h2 id="detailFolio">Solicitud</h2><p id="detailClient"></p></div>
        <span id="detailStatus" class="status-pill"><?= htmlspecialchars($pendingStatus, ENT_QUOTES, 'UTF-8') ?></span>
      </div>
      <div id="summaryGrid" class="summary-grid"></div>
      <section class="detail-block"><h3>Componentes de la venta</h3><div id="componentsList" class="components-list"></div></section>
      <section class="detail-block"><h3>Documentación y firmas</h3><div id="documentsList" class="documents-grid"></div></section>
      <section class="detail-block"><h3>Información capturada</h3><p class="muted">Se muestran únicamente los campos capturados en la solicitud, con los mismos nombres utilizados por el vendedor.</p><div id="detailFields" class="detail-grid"></div></section>

      <section class="decision-panel" aria-labelledby="decisionTitle">
        <div class="decision-copy"><strong id="decisionTitle"><?= htmlspecialchars($decisionTitle, ENT_QUOTES, 'UTF-8') ?></strong><p><?= htmlspecialchars($decisionText, ENT_QUOTES, 'UTF-8') ?></p></div>
        <div class="decision-actions">
          <button id="btnSolicitarCorreccion" type="button" class="correction-button">Solicitar corrección</button>
          <button id="btnAprobarVobo" type="button" class="approve-button"><?= htmlspecialchars($approveLabel, ENT_QUOTES, 'UTF-8') ?></button>
        </div>
        <div id="correctionPanel" class="correction-panel" hidden>
          <label for="correctionReason">Motivo de corrección</label>
          <textarea id="correctionReason" rows="4" maxlength="2000" placeholder="Describe exactamente qué debe corregir el vendedor."></textarea>
          <p class="correction-help">El motivo será obligatorio y quedará registrado en la solicitud.</p>
          <div class="correction-actions"><button id="btnCancelCorrection" type="button" class="secondary-button">Cancelar</button><button id="btnConfirmCorrection" type="button" class="correction-confirm-button">Enviar a corrección</button></div>
        </div>
      </section>
    </section>
  </main>

  <script>
    window.SOLICITUD_VOBO_ETAPA = <?= json_encode($etapa, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
    (() => {
      const nativeFetch = window.fetch.bind(window);
      window.fetch = (input, init) => {
        let target = input;
        if (typeof target === 'string' && target.includes('/api/solicitud-venta/vobo.php') && !/[?&]etapa=/.test(target)) target += `${target.includes('?') ? '&' : '?'}etapa=${encodeURIComponent(window.SOLICITUD_VOBO_ETAPA || 'comercial')}`;
        return nativeFetch(target, init);
      };
    })();
  </script>
  <script src="./vobo.js?v=20260820-4"></script>
</body>
</html>