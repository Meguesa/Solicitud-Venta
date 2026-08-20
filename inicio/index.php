<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/includes/bootstrap.php';
portal_require_authentication();

$user = portal_user();
$name = htmlspecialchars((string) ($user['name'] ?? 'Usuario'), ENT_QUOTES, 'UTF-8');
$email = htmlspecialchars((string) ($user['email'] ?? ''), ENT_QUOTES, 'UTF-8');
$voboRole = portal_vobo_role();
?>
<!doctype html>
<html lang="es-MX">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta name="theme-color" content="#225b8a">
  <title>Mis Solicitudes | Jardines de Juan Pablo</title>
  <link rel="stylesheet" href="/assets/css/account-menu.css">
  <link rel="stylesheet" href="./inicio.css?v=20260820-3">
</head>
<body>
  <header class="seller-header">
    <div class="seller-shell seller-header-inner">
      <div>
        <p>Jardines de Juan Pablo</p>
        <h1>Solicitud de Venta</h1>
      </div>
      <div class="seller-header-actions">
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

  <main class="seller-shell seller-main">
    <section class="intro-card">
      <div>
        <span class="seller-pill">SOLICITUD DE VENTA</span>
        <h2>Mis solicitudes</h2>
        <p>Crea una solicitud nueva, consulta su seguimiento o revisa Vo.Bo. cuando tu perfil tenga autorización.</p>
      </div>
      <button id="btnRecargar" type="button" class="secondary-button">Actualizar</button>
    </section>

    <section class="menu-grid" aria-label="Opciones de Solicitud de Venta">
      <a id="newRequestLink" class="menu-card menu-card-new" href="/solicitud-venta/?nuevo=1">
        <span class="menu-icon">＋</span>
        <div><strong>Nueva solicitud</strong><span>Iniciar una nueva captura de venta.</span></div>
      </a>
      <button class="menu-card menu-card-filter active" type="button" data-view="pendientes">
        <span class="menu-icon">◷</span>
        <div><strong>Solicitudes pendientes</strong><span><b id="countPendientes">0</b> en seguimiento.</span></div>
      </button>
      <button class="menu-card menu-card-filter" type="button" data-view="aprobadas">
        <span class="menu-icon">✓</span>
        <div><strong>Solicitudes aprobadas</strong><span><b id="countAprobadas">0</b> con Vo.Bo. aprobado.</span></div>
      </button>
      <?php if ($voboRole !== ''): ?>
      <a class="menu-card menu-card-vobo" href="/solicitud-venta/vobo/">
        <span class="menu-icon">✓</span>
        <div><strong>Vo.Bo. de solicitudes</strong><span>Revisar solicitudes pendientes como <?= htmlspecialchars($voboRole, ENT_QUOTES, 'UTF-8') ?>.</span></div>
      </a>
      <?php endif; ?>
    </section>

    <div id="message" class="message" role="status"></div>

    <section class="panel">
      <div class="panel-heading">
        <div>
          <h2 id="listTitle">Solicitudes pendientes</h2>
          <p id="listSubtitle">Cargando tus solicitudes...</p>
        </div>
      </div>
      <div id="requestsList" class="requests-list"></div>
      <div id="emptyState" class="empty-state" hidden>No hay solicitudes en esta sección.</div>
    </section>
  </main>

  <script src="./inicio.js?v=20260820-3"></script>
</body>
</html>
