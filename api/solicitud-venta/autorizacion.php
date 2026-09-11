<?php

declare(strict_types=1);

require_once __DIR__ . '/_common.php';
require_once __DIR__ . '/sharepoint-grupos.php';

/**
 * Autorizacion propia de Solicitud de Venta.
 *
 * El Portal solo provee la sesion SSO compartida; los roles funcionales de esta
 * herramienta se resuelven aqui, desde sus grupos de SharePoint.
 */

/** @return array<string,array{sharepoint_group:string,label:string}> */
function svSolicitudRoles(): array
{
    return [
        'VENDEDOR' => ['sharepoint_group' => 'Solicitud Venta - Vendedores', 'label' => 'Vendedor'],
        'COORDINADOR' => ['sharepoint_group' => 'Solicitud Venta - Coordinadores', 'label' => 'Coordinador'],
        'GERENCIA_COMERCIAL' => ['sharepoint_group' => 'Solicitud Venta - Gerencia Comercial', 'label' => 'Gerencia Comercial'],
        'ASISTENTE_VENTAS' => ['sharepoint_group' => 'Solicitud Venta - Asistente Ventas', 'label' => 'Asistente Ventas'],
        'COBRANZA' => ['sharepoint_group' => 'Solicitud Venta - Cobranza', 'label' => 'Cobranza'],
        'DIRECCION' => ['sharepoint_group' => 'Solicitud Venta - Direccion', 'label' => 'Direccion'],
        'ADMINISTRADOR' => ['sharepoint_group' => 'Solicitud Venta - Administradores', 'label' => 'Administrador'],
    ];
}

/** @return array<string,array{sharepoint_group:string,label:string}> */
function svSolicitudPermisosFuncionales(): array
{
    return [
        'NOTIFICACIONES_COMERCIAL' => ['sharepoint_group' => 'Solicitud Venta - Notificaciones Comercial', 'label' => 'Notificaciones Comercial'],
        'NOTIFICACIONES_COBRANZA' => ['sharepoint_group' => 'Solicitud Venta - Notificaciones Cobranza', 'label' => 'Notificaciones Cobranza'],
        'EXPEDIENTE_FINAL' => ['sharepoint_group' => 'Solicitud Venta - Expediente Final', 'label' => 'Expediente Final'],
    ];
}

/** @return array<string,mixed> */
function svSolicitudUsuario(): array
{
    $config = svConfig();
    $claims = svUsuarioSesionPortal($config['tenantId']);
    if (!is_array($claims)) return [];

    return [
        'id' => (string) ($claims['oid'] ?? ''),
        'name' => (string) ($claims['name'] ?? 'Usuario'),
        'email' => strtolower(trim((string) ($claims['preferred_username'] ?? $claims['upn'] ?? ''))),
    ];
}

function svSolicitudEstaAutenticado(): bool
{
    $user = svSolicitudUsuario();
    return trim((string) ($user['email'] ?? '')) !== '';
}

function svSolicitudRequireAuthentication(): void
{
    if (svSolicitudEstaAutenticado()) return;

    // svUsuarioSesionPortal() ya abre la sesion compartida cuando la configuracion
    // lo permite. Conservamos return_to para que el login general regrese a la
    // herramienta despues de autenticarse.
    if (session_status() === PHP_SESSION_ACTIVE) {
        $_SESSION['return_to'] = $_SERVER['REQUEST_URI'] ?? '/solicitud-venta/';
    }
    header('Location: /login.php');
    exit;
}

/** @return string[] */
function svSolicitudGruposCandidatos(): array
{
    $result = [];
    foreach (array_merge(svSolicitudRoles(), svSolicitudPermisosFuncionales()) as $definition) {
        $name = trim((string) ($definition['sharepoint_group'] ?? ''));
        if ($name !== '') $result[strtolower($name)] = $name;
    }
    return array_values($result);
}

/**
 * @return array{resolved:bool,groups:string[],roles:string[],functional_permissions:string[],checked_at:int,error:string}
 */
function svSolicitudAuthorizationContext(bool $forceRefresh = false): array
{
    $fallback = [
        'resolved' => false,
        'groups' => [],
        'roles' => [],
        'functional_permissions' => [],
        'checked_at' => time(),
        'error' => '',
    ];

    $user = svSolicitudUsuario();
    $email = strtolower(trim((string) ($user['email'] ?? '')));
    if ($email === '') {
        $fallback['error'] = 'La sesion no contiene correo electronico.';
        return $fallback;
    }

    $cacheKey = 'solicitud_authorization_context_v2';
    $cacheTtl = 300;
    $cached = $_SESSION[$cacheKey] ?? null;
    if (!$forceRefresh && is_array($cached)) {
        $cachedEmail = strtolower(trim((string) ($cached['email'] ?? '')));
        $checkedAt = (int) ($cached['checked_at'] ?? 0);
        $context = $cached['context'] ?? null;
        if ($cachedEmail === $email && $checkedAt > 0 && (time() - $checkedAt) < $cacheTtl && is_array($context)) {
            return $context;
        }
    }

    try {
        $backend = svConfig();
        $groupConfig = svSharePointGruposConfig();
        $graphToken = svGraphToken($backend['tenantId'], $backend['clientId'], $backend['clientSecret']);
        $siteWebUrl = svSharePointSiteWebUrlDesdeGraph($graphToken, $groupConfig['siteId']);
        $host = strtolower((string) parse_url($siteWebUrl, PHP_URL_HOST));
        if ($host === '') throw new RuntimeException('No se pudo determinar el host de SharePoint.');

        $sharePointToken = svSharePointTokenConCertificado(
            $groupConfig['tenantId'],
            $groupConfig['clientId'],
            $host,
            $groupConfig['pfxPath'],
            $groupConfig['pfxPassword']
        );
        $groups = svSharePointGruposUsuarioRobusto(
            $sharePointToken,
            $siteWebUrl,
            $email,
            svSolicitudGruposCandidatos()
        );

        $lookup = [];
        foreach ($groups as $group) $lookup[strtolower(trim((string) $group))] = true;

        $roles = [];
        foreach (svSolicitudRoles() as $role => $definition) {
            if (isset($lookup[strtolower($definition['sharepoint_group'])])) $roles[] = $role;
        }

        $permissions = [];
        foreach (svSolicitudPermisosFuncionales() as $permission => $definition) {
            if (isset($lookup[strtolower($definition['sharepoint_group'])])) $permissions[] = $permission;
        }

        $context = [
            'resolved' => true,
            'groups' => array_values($groups),
            'roles' => $roles,
            'functional_permissions' => $permissions,
            'checked_at' => time(),
            'error' => '',
        ];
    } catch (Throwable $error) {
        error_log('Solicitud Venta autorizacion SharePoint: ' . $error->getMessage());
        $context = $fallback;
        $context['checked_at'] = time();
        $context['error'] = $error->getMessage();
    }

    $_SESSION[$cacheKey] = [
        'email' => $email,
        'checked_at' => (int) $context['checked_at'],
        'context' => $context,
    ];
    return $context;
}

function svSolicitudTieneRol(string $role, ?array $context = null): bool
{
    $context = $context ?? svSolicitudAuthorizationContext();
    $roles = is_array($context['roles'] ?? null) ? $context['roles'] : [];
    return in_array(strtoupper(trim($role)), $roles, true);
}

function svSolicitudVoboRole(?array $context = null): string
{
    $context = $context ?? svSolicitudAuthorizationContext();
    if (!(bool) ($context['resolved'] ?? false)) return '';
    if (svSolicitudTieneRol('GERENCIA_COMERCIAL', $context)) return 'GERENCIA COMERCIAL';
    if (svSolicitudTieneRol('COORDINADOR', $context)) return 'COORDINADOR';
    if (svSolicitudTieneRol('ADMINISTRADOR', $context)) return 'ADMINISTRADOR';
    return '';
}

function svSolicitudCanVobo(?array $context = null): bool
{
    return svSolicitudVoboRole($context) !== '';
}

function svSolicitudCanCobranzaVobo(?array $context = null): bool
{
    $context = $context ?? svSolicitudAuthorizationContext();
    if (!(bool) ($context['resolved'] ?? false)) return false;
    return svSolicitudTieneRol('COBRANZA', $context) || svSolicitudTieneRol('ADMINISTRADOR', $context);
}

function svSolicitudCanAnyVobo(?array $context = null): bool
{
    $context = $context ?? svSolicitudAuthorizationContext();
    return svSolicitudCanVobo($context) || svSolicitudCanCobranzaVobo($context);
}

function svSolicitudRequireVobo(): void
{
    svSolicitudRequireAuthentication();
    if (!svSolicitudCanVobo()) {
        http_response_code(403);
        exit('Tu cuenta no tiene autorizacion para revisar solicitudes en Vo.Bo. Comercial.');
    }
}

function svSolicitudRequireCobranzaVobo(): void
{
    svSolicitudRequireAuthentication();
    if (!svSolicitudCanCobranzaVobo()) {
        http_response_code(403);
        exit('Tu cuenta no tiene autorizacion para revisar solicitudes en Vo.Bo. de Cobranza.');
    }
}
