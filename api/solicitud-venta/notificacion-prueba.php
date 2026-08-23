<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/includes/bootstrap.php';
require_once __DIR__ . '/_common.php';
require_once __DIR__ . '/notificaciones.php';

portal_require_authentication();

$user = portal_user();
$userEmail = strtolower(trim((string) ($user['email'] ?? '')));
$userName = trim((string) ($user['name'] ?? $userEmail));

$configPath = '/home/juanpab1/portal-config/config.php';
$privateConfig = is_file($configPath) ? require $configPath : [];
if (!is_array($privateConfig)) $privateConfig = [];
$adminEmails = svNormalizarDestinatarios(
    is_array($privateConfig['solicitud_vobo_admin_emails'] ?? null)
        ? $privateConfig['solicitud_vobo_admin_emails']
        : []
);

if ($userEmail === '' || !in_array($userEmail, $adminEmails, true)) {
    http_response_code(403);
    echo '<!doctype html><meta charset="utf-8"><title>Acceso denegado</title>';
    echo '<p>Esta prueba solo esta disponible para administradores de Solicitud de Venta.</p>';
    exit;
}

if (session_status() !== PHP_SESSION_ACTIVE) session_start();

$mensaje = '';
$exito = false;
$notificationConfig = [];
try {
    $notificationConfig = svNotificacionesConfig();
} catch (Throwable $error) {
    $mensaje = $error->getMessage();
}

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    $csrf = (string) ($_POST['csrf'] ?? '');
    $csrfEsperado = (string) ($_SESSION['solicitud_notification_test_csrf'] ?? '');
    if ($csrfEsperado === '' || $csrf === '' || !hash_equals($csrfEsperado, $csrf)) {
        http_response_code(400);
        $mensaje = 'La sesion de prueba expiro. Recarga la pagina e intenta nuevamente.';
    } else {
        try {
            $backendConfig = svConfig();
            $graphToken = svGraphToken(
                $backendConfig['tenantId'],
                $backendConfig['clientId'],
                $backendConfig['clientSecret']
            );
            $sender = (string) ($notificationConfig['sender'] ?? '');
            $asunto = '[PRUEBA] Notificaciones Solicitud de Venta';
            $fecha = (new DateTimeImmutable('now', new DateTimeZone('America/Monterrey')))->format('d/m/Y H:i:s');
            $html = '<div style="font-family:Arial,sans-serif;color:#222">'
                . '<h2 style="color:#225b8a">Prueba de notificaciones - Solicitud de Venta</h2>'
                . '<p>Este correo confirma que el backend de <strong>Solicitud de Venta</strong> puede enviar correo mediante Microsoft Graph.</p>'
                . '<table cellpadding="6" cellspacing="0" style="border-collapse:collapse">'
                . '<tr><td><strong>Remitente</strong></td><td>' . htmlspecialchars($sender, ENT_QUOTES, 'UTF-8') . '</td></tr>'
                . '<tr><td><strong>Destinatario</strong></td><td>' . htmlspecialchars($userEmail, ENT_QUOTES, 'UTF-8') . '</td></tr>'
                . '<tr><td><strong>Usuario</strong></td><td>' . htmlspecialchars($userName, ENT_QUOTES, 'UTF-8') . '</td></tr>'
                . '<tr><td><strong>Fecha</strong></td><td>' . htmlspecialchars($fecha, ENT_QUOTES, 'UTF-8') . '</td></tr>'
                . '</table>'
                . '<p>No requiere respuesta.</p>'
                . '</div>';

            svEnviarCorreoGraph($graphToken, $sender, [$userEmail], $asunto, $html);
            $exito = true;
            $mensaje = 'Correo de prueba enviado correctamente a ' . $userEmail . '.';
            $_SESSION['solicitud_notification_test_csrf'] = bin2hex(random_bytes(24));
        } catch (Throwable $error) {
            error_log('Solicitud Venta notificacion prueba: ' . $error->getMessage());
            http_response_code(502);
            $mensaje = 'No fue posible enviar el correo: ' . $error->getMessage();
        }
    }
}

if (empty($_SESSION['solicitud_notification_test_csrf'])) {
    $_SESSION['solicitud_notification_test_csrf'] = bin2hex(random_bytes(24));
}
$csrf = (string) $_SESSION['solicitud_notification_test_csrf'];
$senderVisible = htmlspecialchars((string) ($notificationConfig['sender'] ?? 'No configurado'), ENT_QUOTES, 'UTF-8');
$emailVisible = htmlspecialchars($userEmail, ENT_QUOTES, 'UTF-8');
$mensajeVisible = htmlspecialchars($mensaje, ENT_QUOTES, 'UTF-8');
?>
<!doctype html>
<html lang="es-MX">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Prueba de notificaciones | Solicitud de Venta</title>
<style>
body{font-family:Arial,sans-serif;background:#f6f2ed;color:#26150f;margin:0;padding:32px}.card{max-width:760px;margin:0 auto;background:#fff;border:1px solid #e6ddd4;border-radius:16px;padding:28px;box-shadow:0 8px 28px rgba(57,38,25,.08)}h1{margin-top:0;color:#225b8a}.row{padding:10px 0;border-bottom:1px solid #eee}.label{font-size:.78rem;color:#74645b;text-transform:uppercase;font-weight:700}.value{font-size:1rem;margin-top:4px}.btn{margin-top:22px;background:#225b8a;color:#fff;border:0;border-radius:10px;padding:12px 18px;font-weight:700;cursor:pointer}.msg{margin-top:18px;padding:12px;border-radius:10px;background:#f3f6f8}.msg.ok{background:#eaf6ee;color:#176d3e}.back{display:inline-block;margin-top:18px;color:#225b8a;text-decoration:none;font-weight:700}
</style>
</head>
<body>
<div class="card">
<h1>Prueba de correo</h1>
<p>Esta prueba no cambia ningun estatus de Solicitud de Venta.</p>
<div class="row"><div class="label">Remitente</div><div class="value"><?= $senderVisible ?></div></div>
<div class="row"><div class="label">Destinatario de prueba</div><div class="value"><?= $emailVisible ?></div></div>
<form method="post">
<input type="hidden" name="csrf" value="<?= htmlspecialchars($csrf, ENT_QUOTES, 'UTF-8') ?>">
<button class="btn" type="submit">Enviar correo de prueba</button>
</form>
<?php if ($mensajeVisible !== ''): ?><div class="msg<?= $exito ? ' ok' : '' ?>"><?= $mensajeVisible ?></div><?php endif; ?>
<a class="back" href="/solicitud-venta/inicio/">Volver a Mis Solicitudes</a>
</div>
</body>
</html>
