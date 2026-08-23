<?php

declare(strict_types=1);

function svResponderError(int $status, string $code, string $message): void
{
    http_response_code($status);
    echo json_encode(['ok' => false, 'error' => $code, 'message' => $message], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

/** @return array<string,string> */
function svConfig(): array
{
    $configPath = '/home/juanpab1/portal-config/config.php';
    if (!is_file($configPath)) svResponderError(500, 'CONFIG_NOT_FOUND', 'No se encontro la configuracion privada del portal.');

    /** @var array<string,mixed> $raw */
    $raw = require $configPath;
    $config = [
        'tenantId' => (string) ($raw['solicitud_backend_tenant_id'] ?? ''),
        'clientId' => (string) ($raw['solicitud_backend_client_id'] ?? ''),
        'clientSecret' => (string) ($raw['solicitud_backend_client_secret'] ?? ''),
        'siteId' => (string) ($raw['solicitud_sharepoint_site_id'] ?? ''),
        'listId' => (string) ($raw['solicitud_sharepoint_list_id'] ?? ''),
    ];

    foreach ($config as $value) {
        if ($value === '') svResponderError(500, 'CONFIG_INCOMPLETE', 'La configuracion del backend esta incompleta.');
    }
    return $config;
}

/** @return array<string,mixed> */
function svUsuarioAutenticado(string $tenantId, string $clientId): array
{
    // Solicitud de Venta vive dentro del Portal y debe reutilizar la misma sesion
    // PHP HttpOnly. Leer la sesion directamente evita cargar bootstrap.php dentro
    // de esta funcion y, por tanto, evita sobrescribir variables globales llamadas
    // $config que los endpoints usan para la configuracion de Microsoft Graph.
    $portalClaims = svUsuarioSesionPortal($tenantId);
    if ($portalClaims !== null) {
        return $portalClaims;
    }

    // Compatibilidad temporal con clientes antiguos que aun envien un access token
    // real de SolicitudVenta.Access.
    $authorization = svAuthorizationHeader();
    if (!preg_match('/^Bearer\s+(.+)$/i', $authorization, $matches)) {
        svResponderError(401, 'SESSION_REQUIRED', 'La sesion del Portal no esta activa. Inicia sesion nuevamente.');
    }

    try {
        $claims = svValidarAccessToken(trim($matches[1]), $tenantId, $clientId);
    } catch (Throwable $error) {
        error_log('Solicitud Venta token: ' . $error->getMessage());
        svResponderError(401, 'INVALID_TOKEN', 'La sesion o el access token no son validos.');
    }

    $scopes = preg_split('/\s+/', trim((string) ($claims['scp'] ?? ''))) ?: [];
    if (!in_array('SolicitudVenta.Access', $scopes, true)) {
        svResponderError(403, 'SCOPE_REQUIRED', 'El token no contiene el permiso requerido.');
    }

    return $claims;
}

/** @return array<string,mixed>|null */
function svUsuarioSesionPortal(string $tenantId): ?array
{
    $configPath = '/home/juanpab1/portal-config/config.php';
    if (!is_file($configPath)) return null;

    $portalConfig = require $configPath;
    if (!is_array($portalConfig)) return null;

    $sessionName = trim((string) ($portalConfig['session_name'] ?? ''));
    $lifetime = (int) ($portalConfig['session_lifetime_seconds'] ?? 0);
    if ($sessionName === '' || $lifetime <= 0) return null;

    if (session_status() !== PHP_SESSION_ACTIVE) {
        $isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
            || (isset($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https');

        session_name($sessionName);
        session_set_cookie_params([
            'lifetime' => $lifetime,
            'path' => '/',
            'domain' => '',
            'secure' => $isHttps,
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
        session_start();
    }

    $user = $_SESSION['portal_user'] ?? null;
    if (!is_array($user)) return null;

    $authenticatedAt = (int) ($_SESSION['authenticated_at'] ?? 0);
    if ($authenticatedAt <= 0 || (time() - $authenticatedAt) >= $lifetime) return null;

    $email = strtolower(trim((string) ($user['email'] ?? '')));
    if ($email === '') return null;

    return [
        'oid' => (string) ($user['id'] ?? ''),
        'name' => (string) ($user['name'] ?? $email),
        'preferred_username' => $email,
        'upn' => $email,
        'tid' => $tenantId,
        'scp' => 'SolicitudVenta.Access',
        'auth_mode' => 'PORTAL_SESSION',
    ];
}

function svAuthorizationHeader(): string
{
    if (!empty($_SERVER['HTTP_AUTHORIZATION'])) return trim((string) $_SERVER['HTTP_AUTHORIZATION']);
    if (!empty($_SERVER['REDIRECT_HTTP_AUTHORIZATION'])) return trim((string) $_SERVER['REDIRECT_HTTP_AUTHORIZATION']);
    if (function_exists('getallheaders')) {
        foreach (getallheaders() as $name => $value) {
            if (strcasecmp((string) $name, 'Authorization') === 0) return trim((string) $value);
        }
    }
    return '';
}

/** @return array<string,mixed> */
function svValidarAccessToken(string $jwt, string $tenantId, string $clientId): array
{
    $parts = explode('.', $jwt);
    if (count($parts) !== 3) throw new RuntimeException('JWT con formato invalido.');
    [$encodedHeader, $encodedPayload, $encodedSignature] = $parts;

    $header = json_decode(svBase64UrlDecode($encodedHeader), true);
    $claims = json_decode(svBase64UrlDecode($encodedPayload), true);
    if (!is_array($header) || !is_array($claims)) throw new RuntimeException('JWT no decodificable.');
    if (($header['alg'] ?? '') !== 'RS256') throw new RuntimeException('Algoritmo JWT no permitido.');
    if ((string) ($claims['ver'] ?? '') !== '2.0') throw new RuntimeException('Version de token no soportada.');

    $metadata = svObtenerJsonRemoto('https://login.microsoftonline.com/' . rawurlencode($tenantId) . '/v2.0/.well-known/openid-configuration');
    if (!hash_equals((string) ($metadata['issuer'] ?? ''), (string) ($claims['iss'] ?? ''))) throw new RuntimeException('Issuer invalido.');
    if (!hash_equals(strtolower($clientId), strtolower((string) ($claims['aud'] ?? '')))) throw new RuntimeException('Audience invalido.');
    if (!hash_equals(strtolower($tenantId), strtolower((string) ($claims['tid'] ?? '')))) throw new RuntimeException('Tenant invalido.');

    $now = time();
    if ((int) ($claims['exp'] ?? 0) <= $now) throw new RuntimeException('Token expirado.');
    if ((int) ($claims['nbf'] ?? 0) > ($now + 60)) throw new RuntimeException('Token aun no valido.');

    $kid = (string) ($header['kid'] ?? '');
    if ($kid === '') throw new RuntimeException('JWT sin kid.');
    $jwksUri = (string) ($metadata['jwks_uri'] ?? '');
    if ($jwksUri === '') throw new RuntimeException('Metadata sin jwks_uri.');
    $jwks = svObtenerJsonRemoto($jwksUri);

    $signingKey = null;
    foreach (($jwks['keys'] ?? []) as $key) {
        if (is_array($key) && (string) ($key['kid'] ?? '') === $kid) {
            $signingKey = $key;
            break;
        }
    }
    if (!is_array($signingKey) || empty($signingKey['x5c'][0])) throw new RuntimeException('Signing key no encontrada.');

    $certificateBody = preg_replace('/\s+/', '', (string) $signingKey['x5c'][0]);
    if (!is_string($certificateBody) || $certificateBody === '') throw new RuntimeException('Certificado invalido.');
    $pem = "-----BEGIN CERTIFICATE-----\n" . chunk_split($certificateBody, 64, "\n") . "-----END CERTIFICATE-----\n";
    $publicKey = openssl_pkey_get_public($pem);
    if ($publicKey === false) throw new RuntimeException('Clave publica invalida.');

    if (openssl_verify($encodedHeader . '.' . $encodedPayload, svBase64UrlDecode($encodedSignature), $publicKey, OPENSSL_ALGO_SHA256) !== 1) {
        throw new RuntimeException('Firma JWT invalida.');
    }
    return $claims;
}

function svGraphToken(string $tenantId, string $clientId, string $clientSecret): string
{
    $url = 'https://login.microsoftonline.com/' . rawurlencode($tenantId) . '/oauth2/v2.0/token';
    $body = http_build_query([
        'client_id' => $clientId,
        'client_secret' => $clientSecret,
        'scope' => 'https://graph.microsoft.com/.default',
        'grant_type' => 'client_credentials',
    ], '', '&', PHP_QUERY_RFC3986);

    $response = svCurlJson($url, 'POST', ['Content-Type: application/x-www-form-urlencoded', 'Accept: application/json'], $body);
    $token = (string) ($response['access_token'] ?? '');
    if ($token === '') throw new RuntimeException('Microsoft Entra ID no devolvio access_token para Graph.');
    return $token;
}

/** @return array<string,mixed> */
function svCurlJson(string $url, string $method, array $headers, ?string $body = null): array
{
    $curl = curl_init($url);
    if ($curl === false) throw new RuntimeException('No fue posible inicializar cURL.');

    $options = [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_CONNECTTIMEOUT => 8,
        CURLOPT_TIMEOUT => 20,
        CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
    ];
    if ($body !== null) $options[CURLOPT_POSTFIELDS] = $body;
    curl_setopt_array($curl, $options);

    $response = curl_exec($curl);
    $httpCode = (int) curl_getinfo($curl, CURLINFO_HTTP_CODE);
    $curlError = curl_error($curl);
    curl_close($curl);

    if ($response === false || ($response === '' && $httpCode !== 204)) throw new RuntimeException('La solicitud remota fallo: ' . $curlError);
    if ($httpCode === 204) return [];

    $decoded = json_decode((string) $response, true);
    if ($httpCode < 200 || $httpCode >= 300) {
        $detalle = '';
        if (is_array($decoded)) $detalle = (string) ($decoded['error']['message'] ?? $decoded['error_description'] ?? '');
        throw new RuntimeException('Servicio remoto respondio HTTP ' . $httpCode . ($detalle !== '' ? ': ' . $detalle : '.'));
    }
    if (!is_array($decoded)) throw new RuntimeException('El servicio remoto no devolvio JSON valido.');
    return $decoded;
}

/** @return array<string,mixed> */
function svObtenerJsonRemoto(string $url): array
{
    if ($url === '') throw new RuntimeException('URL remota vacia.');
    return svCurlJson($url, 'GET', ['Accept: application/json', 'User-Agent: Portal-JJP-Solicitud-Venta/1.0']);
}

function svBase64UrlDecode(string $value): string
{
    $remainder = strlen($value) % 4;
    if ($remainder > 0) $value .= str_repeat('=', 4 - $remainder);
    $decoded = base64_decode(strtr($value, '-_', '+/'), true);
    if ($decoded === false) throw new RuntimeException('Base64Url invalido.');
    return $decoded;
}
