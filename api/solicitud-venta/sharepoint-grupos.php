<?php

declare(strict_types=1);

/**
 * Acceso app-only a SharePoint REST para leer grupos clasicos del sitio.
 * Requiere _common.php para svCurlJson() y svGraphToken().
 */

/** @return array{tenantId:string,clientId:string,siteId:string,pfxPath:string,pfxPassword:string} */
function svSharePointGruposConfig(): array
{
    $configPath = '/home/juanpab1/portal-config/config.php';
    if (!is_file($configPath)) {
        throw new RuntimeException('No se encontro la configuracion privada del portal.');
    }

    $raw = require $configPath;
    if (!is_array($raw)) {
        throw new RuntimeException('La configuracion privada del portal no es valida.');
    }

    $config = [
        'tenantId' => trim((string) ($raw['solicitud_backend_tenant_id'] ?? '')),
        'clientId' => trim((string) ($raw['solicitud_backend_client_id'] ?? '')),
        'siteId' => trim((string) ($raw['solicitud_sharepoint_site_id'] ?? '')),
        'pfxPath' => trim((string) ($raw['solicitud_sharepoint_pfx_path'] ?? '')),
        'pfxPassword' => (string) ($raw['solicitud_sharepoint_pfx_password'] ?? ''),
    ];

    foreach (['tenantId', 'clientId', 'siteId', 'pfxPath'] as $key) {
        if ($config[$key] === '') {
            throw new RuntimeException('Falta configurar ' . $key . ' para leer grupos de SharePoint.');
        }
    }

    if (!is_file($config['pfxPath']) || !is_readable($config['pfxPath'])) {
        throw new RuntimeException('El certificado PFX de SharePoint no existe o PHP no puede leerlo.');
    }

    return $config;
}

function svBase64UrlEncodeLocal(string $value): string
{
    return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
}

/** @return array{privateKey:mixed,certificate:string,thumbprint:string} */
function svCargarPfxSharePoint(string $path, string $password): array
{
    $bytes = file_get_contents($path);
    if ($bytes === false || $bytes === '') {
        throw new RuntimeException('No fue posible leer el certificado PFX de SharePoint.');
    }

    $certs = [];
    if (!openssl_pkcs12_read($bytes, $certs, $password)) {
        throw new RuntimeException('No fue posible abrir el PFX. Verifica la contrasena configurada.');
    }

    $privateKey = $certs['pkey'] ?? null;
    $certificate = (string) ($certs['cert'] ?? '');
    if ($privateKey === null || $certificate === '') {
        throw new RuntimeException('El PFX no contiene certificado y clave privada utilizables.');
    }

    $der = preg_replace('/-----BEGIN CERTIFICATE-----|-----END CERTIFICATE-----|\s+/', '', $certificate);
    $derBytes = is_string($der) ? base64_decode($der, true) : false;
    if ($derBytes === false || $derBytes === '') {
        throw new RuntimeException('No fue posible convertir el certificado a DER.');
    }

    $thumbprint = svBase64UrlEncodeLocal(hash('sha1', $derBytes, true));

    return [
        'privateKey' => $privateKey,
        'certificate' => $certificate,
        'thumbprint' => $thumbprint,
    ];
}

function svSharePointTokenConCertificado(
    string $tenantId,
    string $clientId,
    string $sharePointHost,
    string $pfxPath,
    string $pfxPassword
): string {
    $sharePointHost = strtolower(trim($sharePointHost));
    if ($sharePointHost === '') {
        throw new RuntimeException('No se pudo determinar el host de SharePoint.');
    }

    $tokenUrl = 'https://login.microsoftonline.com/' . rawurlencode($tenantId) . '/oauth2/v2.0/token';
    $pfx = svCargarPfxSharePoint($pfxPath, $pfxPassword);

    $now = time();
    $header = [
        'alg' => 'RS256',
        'typ' => 'JWT',
        'x5t' => $pfx['thumbprint'],
    ];
    $claims = [
        'aud' => $tokenUrl,
        'iss' => $clientId,
        'sub' => $clientId,
        'jti' => bin2hex(random_bytes(16)),
        'nbf' => $now - 30,
        'iat' => $now,
        'exp' => $now + 300,
    ];

    $headerJson = json_encode($header, JSON_UNESCAPED_SLASHES);
    $claimsJson = json_encode($claims, JSON_UNESCAPED_SLASHES);
    if (!is_string($headerJson) || !is_string($claimsJson)) {
        throw new RuntimeException('No fue posible construir el client assertion para SharePoint.');
    }

    $unsigned = svBase64UrlEncodeLocal($headerJson) . '.' . svBase64UrlEncodeLocal($claimsJson);
    $signature = '';
    if (!openssl_sign($unsigned, $signature, $pfx['privateKey'], OPENSSL_ALGO_SHA256)) {
        throw new RuntimeException('No fue posible firmar el client assertion para SharePoint.');
    }
    $assertion = $unsigned . '.' . svBase64UrlEncodeLocal($signature);

    $body = http_build_query([
        'client_id' => $clientId,
        'scope' => 'https://' . $sharePointHost . '/.default',
        'grant_type' => 'client_credentials',
        'client_assertion_type' => 'urn:ietf:params:oauth:client-assertion-type:jwt-bearer',
        'client_assertion' => $assertion,
    ], '', '&', PHP_QUERY_RFC3986);

    $response = svCurlJson(
        $tokenUrl,
        'POST',
        ['Content-Type: application/x-www-form-urlencoded', 'Accept: application/json'],
        $body
    );

    $token = trim((string) ($response['access_token'] ?? ''));
    if ($token === '') {
        throw new RuntimeException('Microsoft Entra no devolvio access_token para SharePoint.');
    }
    return $token;
}

function svSharePointSiteWebUrlDesdeGraph(string $graphToken, string $siteId): string
{
    $url = 'https://graph.microsoft.com/v1.0/sites/' . rawurlencode($siteId) . '?$select=webUrl';
    $response = svCurlJson($url, 'GET', [
        'Authorization: Bearer ' . $graphToken,
        'Accept: application/json',
    ]);

    $webUrl = rtrim(trim((string) ($response['webUrl'] ?? '')), '/');
    if ($webUrl === '' || !preg_match('#^https://[^/]+\.sharepoint\.com(?:/|$)#i', $webUrl)) {
        throw new RuntimeException('Microsoft Graph no devolvio una URL valida para el sitio de SharePoint.');
    }
    return $webUrl;
}

/** @return array<int,array{title:string,email:string,loginName:string}> */
function svSharePointUsuariosGrupo(string $sharePointToken, string $siteWebUrl, string $groupName): array
{
    $groupName = trim($groupName);
    if ($groupName === '') throw new RuntimeException('El nombre del grupo de SharePoint esta vacio.');

    $escaped = str_replace("'", "''", $groupName);
    $encoded = rawurlencode($escaped);
    $url = rtrim($siteWebUrl, '/')
        . "/_api/web/sitegroups/getbyname('" . $encoded . "')/users"
        . '?$select=Title,Email,LoginName';

    $response = svCurlJson($url, 'GET', [
        'Authorization: Bearer ' . $sharePointToken,
        'Accept: application/json;odata=nometadata',
        'Content-Type: application/json;odata=nometadata',
    ]);

    $rows = $response['value'] ?? [];
    if (!is_array($rows)) return [];

    $usuarios = [];
    foreach ($rows as $row) {
        if (!is_array($row)) continue;
        $usuarios[] = [
            'title' => trim((string) ($row['Title'] ?? '')),
            'email' => strtolower(trim((string) ($row['Email'] ?? ''))),
            'loginName' => trim((string) ($row['LoginName'] ?? '')),
        ];
    }
    return $usuarios;
}

/** @return string[] */
function svSharePointCorreosGrupo(string $sharePointToken, string $siteWebUrl, string $groupName): array
{
    $emails = [];
    foreach (svSharePointUsuariosGrupo($sharePointToken, $siteWebUrl, $groupName) as $usuario) {
        $email = strtolower(trim($usuario['email']));
        if ($email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $emails[$email] = true;
        }
    }
    return array_keys($emails);
}

/** @return string[] */
function svSharePointExtraerTitulosGrupos(array $rows): array
{
    $grupos = [];
    foreach ($rows as $row) {
        if (!is_array($row)) continue;
        $title = trim((string) ($row['Title'] ?? ''));
        if ($title !== '') $grupos[$title] = true;
    }
    return array_keys($grupos);
}

/**
 * Resuelve el ID interno de SharePoint por Email o LoginName.
 * Esto cubre usuarios que existen en el sitio pero que getbyemail() no encuentra.
 */
function svSharePointResolverUsuarioId(
    string $sharePointToken,
    string $siteWebUrl,
    string $email
): ?int {
    $email = strtolower(trim($email));
    if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) return null;

    $url = rtrim($siteWebUrl, '/')
        . '/_api/web/siteusers?$select=Id,Title,Email,LoginName&$top=5000';

    $response = svCurlJson($url, 'GET', [
        'Authorization: Bearer ' . $sharePointToken,
        'Accept: application/json;odata=nometadata',
        'Content-Type: application/json;odata=nometadata',
    ]);

    $rows = $response['value'] ?? [];
    if (!is_array($rows)) return null;

    foreach ($rows as $row) {
        if (!is_array($row)) continue;
        $rowId = (int) ($row['Id'] ?? 0);
        if ($rowId <= 0) continue;

        $rowEmail = strtolower(trim((string) ($row['Email'] ?? '')));
        $loginName = strtolower(trim((string) ($row['LoginName'] ?? '')));

        if ($rowEmail === $email || ($loginName !== '' && strpos($loginName, $email) !== false)) {
            return $rowId;
        }
    }

    return null;
}

/** @return string[] */
function svSharePointGruposUsuarioPorId(
    string $sharePointToken,
    string $siteWebUrl,
    int $userId
): array {
    if ($userId <= 0) return [];

    $url = rtrim($siteWebUrl, '/')
        . '/_api/web/getUserById(' . $userId . ')/groups?$select=Id,Title';

    $response = svCurlJson($url, 'GET', [
        'Authorization: Bearer ' . $sharePointToken,
        'Accept: application/json;odata=nometadata',
        'Content-Type: application/json;odata=nometadata',
    ]);

    $rows = $response['value'] ?? [];
    return is_array($rows) ? svSharePointExtraerTitulosGrupos($rows) : [];
}

/** @return string[] */
function svSharePointGruposUsuario(
    string $sharePointToken,
    string $siteWebUrl,
    string $email
): array {
    $email = strtolower(trim($email));
    if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) return [];

    try {
        $escapedEmail = str_replace("'", "''", $email);
        $encodedEmail = rawurlencode($escapedEmail);
        $url = rtrim($siteWebUrl, '/')
            . "/_api/web/siteusers/getbyemail('" . $encodedEmail . "')/groups"
            . '?$select=Id,Title';

        $response = svCurlJson($url, 'GET', [
            'Authorization: Bearer ' . $sharePointToken,
            'Accept: application/json;odata=nometadata',
            'Content-Type: application/json;odata=nometadata',
        ]);

        $rows = $response['value'] ?? [];
        if (is_array($rows)) {
            $groups = svSharePointExtraerTitulosGrupos($rows);
            if ($groups) return $groups;
        }
    } catch (Throwable $error) {
        $message = $error->getMessage();
        if (strpos($message, 'HTTP 403') === false && strpos($message, 'HTTP 404') === false) {
            throw $error;
        }
        error_log('Portal roles SharePoint: getbyemail no resolvio ' . $email . ': ' . $message);
    }

    $userId = svSharePointResolverUsuarioId($sharePointToken, $siteWebUrl, $email);
    if ($userId === null) return [];

    return svSharePointGruposUsuarioPorId($sharePointToken, $siteWebUrl, $userId);
}

/**
 * Comprueba la membresia de un usuario contra una lista conocida de grupos.
 * Si un grupo concreto no permite lectura app-only (403) o no existe (404),
 * se omite sin cancelar la evaluacion de los demas grupos.
 *
 * @param string[] $candidateGroups
 * @return string[]
 */
function svSharePointGruposUsuarioEntre(
    string $sharePointToken,
    string $siteWebUrl,
    string $email,
    array $candidateGroups
): array {
    $email = strtolower(trim($email));
    if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) return [];

    $candidates = [];
    foreach ($candidateGroups as $candidate) {
        $groupName = trim((string) $candidate);
        if ($groupName !== '') $candidates[strtolower($groupName)] = $groupName;
    }

    $memberships = [];
    foreach ($candidates as $groupName) {
        try {
            $users = svSharePointUsuariosGrupo($sharePointToken, $siteWebUrl, $groupName);
        } catch (Throwable $error) {
            $message = $error->getMessage();
            if (strpos($message, 'HTTP 403') !== false || strpos($message, 'HTTP 404') !== false) {
                error_log('Portal roles SharePoint: grupo omitido por acceso/no encontrado: ' . $groupName . ' - ' . $message);
                continue;
            }
            throw $error;
        }

        foreach ($users as $usuario) {
            $rowEmail = strtolower(trim((string) ($usuario['email'] ?? '')));
            $loginName = strtolower(trim((string) ($usuario['loginName'] ?? '')));
            if ($rowEmail === $email || ($loginName !== '' && strpos($loginName, $email) !== false)) {
                $memberships[] = $groupName;
                break;
            }
        }
    }

    return $memberships;
}

/**
 * Metodo robusto para el Portal: primero consulta los grupos desde el usuario
 * resuelto en SharePoint; si no obtiene resultados, usa el catalogo conocido.
 *
 * @param string[] $candidateGroups
 * @return string[]
 */
function svSharePointGruposUsuarioRobusto(
    string $sharePointToken,
    string $siteWebUrl,
    string $email,
    array $candidateGroups
): array {
    try {
        $groups = svSharePointGruposUsuario($sharePointToken, $siteWebUrl, $email);
        if ($groups) return $groups;
    } catch (Throwable $error) {
        $message = $error->getMessage();
        if (strpos($message, 'HTTP 403') === false && strpos($message, 'HTTP 404') === false) {
            throw $error;
        }
        error_log('Portal roles SharePoint: consulta por usuario fallo, se usara catalogo: ' . $message);
    }

    return svSharePointGruposUsuarioEntre(
        $sharePointToken,
        $siteWebUrl,
        $email,
        $candidateGroups
    );
}
