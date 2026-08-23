<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/includes/bootstrap.php';
require_once dirname(__DIR__, 2) . '/includes/portal-roles.php';
require_once __DIR__ . '/_common.php';
require_once __DIR__ . '/sharepoint-grupos.php';

portal_require_authentication();

$user = portal_user();
$userEmail = strtolower(trim((string) ($user['email'] ?? '')));
$userName = trim((string) ($user['name'] ?? 'Usuario'));

$groups = [];
$roleContext = portal_role_context_from_sharepoint_groups([]);
$message = '';
$ok = false;

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    try {
        if ($userEmail === '') {
            throw new RuntimeException('La sesion actual no contiene correo electronico.');
        }

        $backend = svConfig();
        $groupConfig = svSharePointGruposConfig();

        $graphToken = svGraphToken(
            $backend['tenantId'],
            $backend['clientId'],
            $backend['clientSecret']
        );

        $siteWebUrl = svSharePointSiteWebUrlDesdeGraph(
            $graphToken,
            $groupConfig['siteId']
        );

        $host = strtolower((string) parse_url($siteWebUrl, PHP_URL_HOST));
        if ($host === '') {
            throw new RuntimeException('No se pudo determinar el host del sitio SharePoint.');
        }

        $sharePointToken = svSharePointTokenConCertificado(
            $groupConfig['tenantId'],
            $groupConfig['clientId'],
            $host,
            $groupConfig['pfxPath'],
            $groupConfig['pfxPassword']
        );

        $candidateGroups = [];
        foreach (portal_role_definitions() as $definition) {
            $candidateGroups[] = $definition['sharepoint_group'];
        }
        foreach (portal_permission_group_definitions() as $definition) {
            $candidateGroups[] = $definition['sharepoint_group'];
        }
        foreach (portal_dashboard_group_definitions() as $definition) {
            $candidateGroups[] = $definition['sharepoint_group'];
        }

        $groups = svSharePointGruposUsuarioRobusto(
            $sharePointToken,
            $siteWebUrl,
            $userEmail,
            $candidateGroups
        );

        $roleContext = portal_role_context_from_sharepoint_groups($groups);
        $ok = true;
        $message = 'Consulta completada correctamente. Esta pagina solo diagnostica roles; no aplica permisos.';
    } catch (Throwable $error) {
        error_log('Portal JJP prueba roles SharePoint: ' . $error->getMessage());
        http_response_code(502);
        $message = $error->getMessage();
    }
}

function portalRolesHtml(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
}

function portalRolesList(array $values): string
{
    if (!$values) return '<span class="muted">Ninguno detectado</span>';

    $items = '';
    foreach ($values as $value) {
        $items .= '<li>' . portalRolesHtml((string) $value) . '</li>';
    }
    return '<ul>' . $items . '</ul>';
}
?>
<!doctype html>
<html lang="es-MX">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Prueba de roles SharePoint | Portal JdJP</title>
<style>
body{font-family:Arial,sans-serif;background:#f6f2ed;color:#26150f;margin:0;padding:32px}.card{max-width:960px;margin:0 auto;background:#fff;border:1px solid #e6ddd4;border-radius:16px;padding:28px;box-shadow:0 8px 28px rgba(57,38,25,.08)}h1{margin-top:0;color:#225b8a}.grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(240px,1fr));gap:16px;margin-top:20px}.panel{border:1px solid #e7e1db;border-radius:12px;padding:16px;background:#faf8f5}.label{font-size:.78rem;color:#74645b;text-transform:uppercase;font-weight:700}.value{font-size:1rem;margin-top:4px}.btn{margin-top:22px;background:#225b8a;color:#fff;border:0;border-radius:10px;padding:12px 18px;font-weight:700;cursor:pointer}.msg{margin-top:18px;padding:12px;border-radius:10px;background:#fff3e8;color:#8b3f12}.msg.ok{background:#eaf6ee;color:#176d3e}.back{display:inline-block;margin-top:18px;color:#225b8a;text-decoration:none;font-weight:700}.muted{color:#77675e;font-size:.92rem}ul{margin:8px 0 0;padding-left:20px}li{margin:4px 0}
</style>
</head>
<body>
<div class="card">
<h1>Prueba de roles SharePoint</h1>
<p>Consulta los grupos SharePoint del usuario actualmente autenticado y muestra como los interpretaria la nueva capa de roles. No modifica accesos, menus ni herramientas.</p>
<div class="panel">
<div class="label">Usuario autenticado</div>
<div class="value"><strong><?= portalRolesHtml($userName) ?></strong><br><?= portalRolesHtml($userEmail) ?></div>
</div>
<form method="post"><button class="btn" type="submit">Consultar mis grupos y roles</button></form>
<?php if ($message !== ''): ?><div class="msg<?= $ok ? ' ok' : '' ?>"><?= portalRolesHtml($message) ?></div><?php endif; ?>
<?php if ($ok): ?>
<div class="grid">
<div class="panel"><div class="label">Grupos SharePoint detectados</div><?= portalRolesList($roleContext['groups']) ?></div>
<div class="panel"><div class="label">Roles internos detectados</div><?= portalRolesList($roleContext['roles']) ?></div>
<div class="panel"><div class="label">Permisos funcionales detectados</div><?= portalRolesList($roleContext['functional_permissions']) ?></div>
<div class="panel"><div class="label">Membresias BI / Dashboard detectadas</div><?= portalRolesList($roleContext['dashboard_memberships']) ?></div>
</div>
<?php endif; ?>
<a class="back" href="/">Volver al Portal</a>
</div>
</body>
</html>
