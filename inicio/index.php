<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/includes/bootstrap.php';
portal_require_authentication();

$user = portal_user();
$name = htmlspecialchars((string) ($user['name'] ?? 'Usuario'), ENT_QUOTES, 'UTF-8');
$email = htmlspecialchars((string) ($user['email'] ?? ''), ENT_QUOTES, 'UTF-8');
$voboRole = portal_vobo_role();
$cobranzaVobo = portal_user_can_cobranza_vobo();
?>
<!doctype html>
<html lang="es-MX">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta name="theme-color" content="#ffffff">
  <title>Mis Solicitudes | Jardines de Juan Pablo</title>
  <link rel="stylesheet" href="/assets/css/account-menu.css">
  <link rel="stylesheet" href="./inicio.css?v=20260823-2">
</head>
<body>
  <header class="seller-header">
    <div class="seller-shell seller-header-inner">
      <div class="seller-header-title">
        <p>Jardines de Juan Pablo</p>
        <h1>Solicitud de Venta</h1>
      </div>
      <div class="seller-header-actions">
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

  <main class="seller-shell seller-main">
    <section class="intro-card">
      <div>
        <span class="seller-pill">SOLICITUD DE VENTA</span>
        <h2>Mis solicitudes</h2>
        <p>Crea una solicitud nueva, consulta su seguimiento o revisa autorizaciones cuando tu perfil tenga acceso.</p>
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
        <div><strong>Solicitudes aprobadas</strong><span><b id="countAprobadas">0</b> con aprobación final.</span></div>
      </button>
      <?php if ($voboRole !== ''): ?>
      <a class="menu-card menu-card-vobo" href="/solicitud-venta/vobo/?etapa=comercial">
        <span class="menu-icon">✓</span>
        <div><strong>Vo.Bo. Comercial</strong><span>Revisar solicitudes pendientes como <?= htmlspecialchars($voboRole, ENT_QUOTES, 'UTF-8') ?>.</span></div>
      </a>
      <?php endif; ?>
      <?php if ($cobranzaVobo): ?>
      <a class="menu-card menu-card-vobo" href="/solicitud-venta/vobo/?etapa=cobranza">
        <span class="menu-icon">$</span>
        <div><strong>Vo.Bo. Cobranza</strong><span>Revisar solicitudes autorizadas por Comercial y pendientes de Cobranza.</span></div>
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

  <script src="./inicio.js?v=20260822-1"></script>
</body>
</html>