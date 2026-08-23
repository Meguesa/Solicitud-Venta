<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/includes/bootstrap.php';
require_once __DIR__ . '/_common.php';
require_once __DIR__ . '/notificaciones.php';
require_once __DIR__ . '/sharepoint-grupos.php';

portal_require_authentication();

$user = portal_user();
$userEmail = strtolower(trim((string) ($user['email'] ?? '')));

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

$grupo = 'Solicitud Venta - Notificaciones Comercial';
$mensaje = '';
$exito = false;
$usuarios = [];
$siteWebUrl = '';

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    try {
        $backend = svConfig();
        $groupConfig = svSharePointGruposConfig();

        $graphToken = svGraphToken(
            $backend['tenantId'],
            $backend['clientId'],
            $backend['clientSecret']
        );
        $siteWebUrl = svSharePointSiteWebUrlDesdeGraph($graphToken, $groupConfig['siteId']);
        $host = strtolower((string) parse_url($siteWebUrl, PHP_URL_HOST));
        if ($host === '') throw new RuntimeException('No se pudo determinar el host del sitio SharePoint.');

        $sharePointToken = svSharePointTokenConCertificado(
            $groupConfig['tenantId'],
            $groupConfig['clientId'],
            $host,
            $groupConfig['pfxPath'],
            $groupConfig['pfxPassword']
        );

        $usuarios = svSharePointUsuariosGrupo($sharePointToken, $siteWebUrl, $grupo);
        $exito = true;
        $mensaje = 'Consulta completada. SharePoint devolvio ' . count($usuarios) . ' integrante(s).';
    } catch (Throwable $error) {
        error_log('Solicitud Venta prueba grupo SharePoint: ' . $error->getMessage());
        http_response_code(502);
        $mensaje = $error->getMessage();
    }
}

function svHtml(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
}
?>
<!doctype html>
<html lang="es-MX">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Prueba de grupo SharePoint | Solicitud de Venta</title>
<style>
body{font-family:Arial,sans-serif;background:#f6f2ed;color:#26150f;margin:0;padding:32px}.card{max-width:900px;margin:0 auto;background:#fff;border:1px solid #e6ddd4;border-radius:16px;padding:28px;box-shadow:0 8px 28px rgba(57,38,25,.08)}h1{margin-top:0;color:#225b8a}.row{padding:10px 0;border-bottom:1px solid #eee}.label{font-size:.78rem;color:#74645b;text-transform:uppercase;font-weight:700}.value{font-size:1rem;margin-top:4px}.btn{margin-top:22px;background:#225b8a;color:#fff;border:0;border-radius:10px;padding:12px 18px;font-weight:700;cursor:pointer}.msg{margin-top:18px;padding:12px;border-radius:10px;background:#fff3e8;color:#8b3f12}.msg.ok{background:#eaf6ee;color:#176d3e}.back{display:inline-block;margin-top:18px;color:#225b8a;text-decoration:none;font-weight:700}table{width:100%;border-collapse:collapse;margin-top:20px}th,td{text-align:left;padding:10px;border-bottom:1px solid #e7e1db}th{font-size:.78rem;text-transform:uppercase;color:#66574f;background:#faf8f5}.muted{color:#77675e;font-size:.92rem}
</style>
</head>
<body>
<div class="card">
<h1>Prueba de grupo SharePoint</h1>
<p>Esta prueba solo consulta integrantes. No envia correos ni modifica solicitudes.</p>
<div class="row"><div class="label">Grupo</div><div class="value"><?= svHtml($grupo) ?></div></div>
<?php if ($siteWebUrl !== ''): ?><div class="row"><div class="label">Sitio</div><div class="value"><?= svHtml($siteWebUrl) ?></div></div><?php endif; ?>
<form method="post"><button class="btn" type="submit">Consultar grupo</button></form>
<?php if ($mensaje !== ''): ?><div class="msg<?= $exito ? ' ok' : '' ?>"><?= svHtml($mensaje) ?></div><?php endif; ?>
<?php if ($exito): ?>
<table>
<thead><tr><th>Nombre</th><th>Correo</th><th>Login</th></tr></thead>
<tbody>
<?php if (!$usuarios): ?>
<tr><td colspan="3" class="muted">El grupo existe, pero no devolvio integrantes.</td></tr>
<?php else: foreach ($usuarios as $usuario): ?>
<tr>
<td><?= svHtml($usuario['title']) ?></td>
<td><?= svHtml($usuario['email'] !== '' ? $usuario['email'] : '(sin correo)') ?></td>
<td class="muted"><?= svHtml($usuario['loginName']) ?></td>
</tr>
<?php endforeach; endif; ?>
</tbody>
</table>
<?php endif; ?>
<a class="back" href="/solicitud-venta/inicio/">Volver a Mis Solicitudes</a>
</div>
</body>
</html>
