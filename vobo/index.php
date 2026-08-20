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
  <link rel="stylesheet" href="./vobo.css?v=20260820-2">
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
      <button id="btnRecargar" type="button" class="secondary-button">Actualizar bandeja</button>
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

      <section class="next-step-note">
        <strong>Siguiente etapa</strong>
        <p>Primero validaremos que esta pantalla muestre correctamente la solicitud. Después habilitaremos las acciones Aprobar Vo.Bo. y Solicitar corrección.</p>
      </section>
    </section>
  </main>

  <script src="./vobo.js?v=20260820-2"></script>
</body>
</html>
