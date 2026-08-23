<?php

declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    responderError(405, 'METHOD_NOT_ALLOWED', 'Metodo no permitido.');
}

$configPath = '/home/juanpab1/portal-config/config.php';
if (!is_file($configPath)) {
    responderError(500, 'CONFIG_NOT_FOUND', 'No se encontro la configuracion privada del portal.');
}

/** @var array<string,mixed> $config */
$config = require $configPath;
$tenantId = (string) ($config['solicitud_backend_tenant_id'] ?? '');
$clientId = (string) ($config['solicitud_backend_client_id'] ?? '');
$clientSecret = (string) ($config['solicitud_backend_client_secret'] ?? '');
$siteId = (string) ($config['solicitud_sharepoint_site_id'] ?? '');
$listId = (string) ($config['solicitud_sharepoint_list_id'] ?? '');

if ($tenantId === '' || $clientId === '' || $clientSecret === '' || $siteId === '' || $listId === '') {
    responderError(500, 'CONFIG_INCOMPLETE', 'La configuracion del backend esta incompleta.');
}

$authorization = obtenerAuthorizationHeader();
if (!preg_match('/^Bearer\s+(.+)$/i', $authorization, $matches)) {
    responderError(401, 'TOKEN_REQUIRED', 'Se requiere un access token Bearer.');
}

try {
    $claims = validarAccessTokenEntra(trim($matches[1]), $tenantId, $clientId);
} catch (Throwable $error) {
    error_log('Solicitud Venta archivo token: ' . $error->getMessage());
    responderError(401, 'INVALID_TOKEN', 'El access token no es valido.');
}

$scopes = preg_split('/\s+/', trim((string) ($claims['scp'] ?? ''))) ?: [];
if (!in_array('SolicitudVenta.Access', $scopes, true)) {
    responderError(403, 'SCOPE_REQUIRED', 'El token no contiene el permiso requerido.');
}

$correoUsuario = strtolower(trim((string) ($claims['preferred_username'] ?? $claims['upn'] ?? '')));
$folio = strtoupper(trim((string) ($_POST['folio'] ?? '')));
$tipoDocumento = strtoupper(trim((string) ($_POST['tipoDocumento'] ?? '')));

if (!preg_match('/^SV-(\d{4})-(\d{6,})$/', $folio, $folioMatch)) {
    responderError(400, 'INVALID_FOLIO', 'El folio no es valido.');
}

$tiposPermitidos = [
    'ID_TITULAR', 'ID_SUSTITUTO', 'COMPROBANTE_DOMICILIO', 'COMPROBANTE_PAGO',
    'OTRO', 'FIRMA_CLIENTE', 'FIRMA_VENDEDOR'
];
if (!in_array($tipoDocumento, $tiposPermitidos, true)) {
    responderError(400, 'INVALID_DOCUMENT_TYPE', 'El tipo de documento no esta permitido.');
}

if (!isset($_FILES['archivo']) || !is_array($_FILES['archivo'])) {
    responderError(400, 'FILE_REQUIRED', 'No se recibio ningun archivo.');
}

$archivo = $_FILES['archivo'];
$errorUpload = (int) ($archivo['error'] ?? UPLOAD_ERR_NO_FILE);
if ($errorUpload !== UPLOAD_ERR_OK) {
    responderError(400, 'UPLOAD_ERROR', describirErrorUpload($errorUpload));
}

$tmpName = (string) ($archivo['tmp_name'] ?? '');
$originalName = (string) ($archivo['name'] ?? 'archivo');
$size = (int) ($archivo['size'] ?? 0);
if ($tmpName === '' || !is_uploaded_file($tmpName)) {
    responderError(400, 'INVALID_UPLOAD', 'El archivo recibido no es valido.');
}
if ($size <= 0 || $size > 12 * 1024 * 1024) {
    responderError(400, 'FILE_SIZE', 'El archivo debe pesar entre 1 byte y 12 MB.');
}

$finfo = new finfo(FILEINFO_MIME_TYPE);
$mime = (string) $finfo->file($tmpName);
$mimePermitidos = [
    'image/jpeg' => 'jpg',
    'image/png' => 'png',
    'image/webp' => 'webp',
    'application/pdf' => 'pdf',
];
if (!isset($mimePermitidos[$mime])) {
    responderError(400, 'FILE_TYPE', 'Solo se permiten archivos JPG, PNG, WEBP o PDF.');
}

$itemId = (string) ((int) ltrim($folioMatch[2], '0'));
if ($itemId === '0') {
    responderError(400, 'INVALID_ITEM_ID', 'El folio no contiene un ID valido.');
}

try {
    $graphToken = obtenerGraphAppToken($tenantId, $clientId, $clientSecret);
    verificarBorradorUsuario($graphToken, $siteId, $listId, $itemId, $folio, $correoUsuario);
    $driveId = obtenerDriveExpedientes($graphToken, $siteId);
    asegurarCarpetaFolio($graphToken, $driveId, $folio);

    $extension = $mimePermitidos[$mime];
    $baseOriginal = pathinfo($originalName, PATHINFO_FILENAME);
    $baseOriginal = sanitizarNombreArchivo($baseOriginal);
    if ($baseOriginal === '') $baseOriginal = strtolower($tipoDocumento);

    if (str_starts_with($tipoDocumento, 'FIRMA_')) {
        $nombreFinal = $tipoDocumento . '.png';
    } else {
        $nombreFinal = $tipoDocumento . '_' . gmdate('Ymd_His') . '_' . substr(bin2hex(random_bytes(3)), 0, 6) . '_' . $baseOriginal . '.' . $extension;
    }

    $contenido = file_get_contents($tmpName);
    if (!is_string($contenido)) throw new RuntimeException('No fue posible leer el archivo recibido.');

    $driveItem = subirArchivoGraph($graphToken, $driveId, $folio, $nombreFinal, $mime, $contenido);
    actualizarIndicadoresDocumento($graphToken, $siteId, $listId, $itemId, $tipoDocumento);
} catch (Throwable $error) {
    error_log('Solicitud Venta archivo: ' . $error->getMessage());
    responderError(502, 'FILE_SAVE_FAILED', 'No fue posible guardar el archivo en el expediente: ' . $error->getMessage());
}

http_response_code(201);
echo json_encode([
    'ok' => true,
    'folio' => $folio,
    'tipoDocumento' => $tipoDocumento,
    'archivo' => [
        'name' => (string) ($driveItem['name'] ?? $nombreFinal),
        'id' => (string) ($driveItem['id'] ?? ''),
        'webUrl' => (string) ($driveItem['webUrl'] ?? ''),
    ],
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
exit;

function responderError(int $status, string $code, string $message): void
{
    http_response_code($status);
    echo json_encode(['ok' => false, 'error' => $code, 'message' => $message], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function describirErrorUpload(int $error): string
{
    return match ($error) {
        UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE => 'El archivo supera el limite permitido por el servidor.',
        UPLOAD_ERR_PARTIAL => 'El archivo se recibio de forma incompleta.',
        UPLOAD_ERR_NO_FILE => 'No se recibio ningun archivo.',
        default => 'Ocurrio un error al recibir el archivo.',
    };
}

function sanitizarNombreArchivo(string $nombre): string
{
    $nombre = preg_replace('/[^A-Za-z0-9_-]+/', '_', trim($nombre));
    $nombre = is_string($nombre) ? trim($nombre, '_') : '';
    return substr($nombre, 0, 80);
}

function obtenerAuthorizationHeader(): string
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
function validarAccessTokenEntra(string $jwt, string $tenantId, string $clientId): array
{
    $parts = explode('.', $jwt);
    if (count($parts) !== 3) throw new RuntimeException('JWT con formato invalido.');
    [$encodedHeader, $encodedPayload, $encodedSignature] = $parts;
    $header = json_decode(base64UrlDecode($encodedHeader), true);
    $claims = json_decode(base64UrlDecode($encodedPayload), true);
    if (!is_array($header) || !is_array($claims)) throw new RuntimeException('JWT no decodificable.');
    if (($header['alg'] ?? '') !== 'RS256' || (string) ($claims['ver'] ?? '') !== '2.0') throw new RuntimeException('JWT no soportado.');

    $metadata = obtenerJsonRemoto('https://login.microsoftonline.com/' . rawurlencode($tenantId) . '/v2.0/.well-known/openid-configuration');
    if (!hash_equals((string) ($metadata['issuer'] ?? ''), (string) ($claims['iss'] ?? ''))) throw new RuntimeException('Issuer invalido.');
    if (!hash_equals(strtolower($clientId), strtolower((string) ($claims['aud'] ?? '')))) throw new RuntimeException('Audience invalido.');
    if (!hash_equals(strtolower($tenantId), strtolower((string) ($claims['tid'] ?? '')))) throw new RuntimeException('Tenant invalido.');

    $now = time();
    if ((int) ($claims['exp'] ?? 0) <= $now) throw new RuntimeException('Token expirado.');
    if ((int) ($claims['nbf'] ?? 0) > ($now + 60)) throw new RuntimeException('Token aun no valido.');

    $kid = (string) ($header['kid'] ?? '');
    $jwks = obtenerJsonRemoto((string) ($metadata['jwks_uri'] ?? ''));
    $signingKey = null;
    foreach (($jwks['keys'] ?? []) as $key) {
        if (is_array($key) && (string) ($key['kid'] ?? '') === $kid) {
            $signingKey = $key;
            break;
        }
    }
    if (!is_array($signingKey) || empty($signingKey['x5c'][0])) throw new RuntimeException('Signing key no encontrada.');

    $body = preg_replace('/\s+/', '', (string) $signingKey['x5c'][0]);
    $pem = "-----BEGIN CERTIFICATE-----\n" . chunk_split((string) $body, 64, "\n") . "-----END CERTIFICATE-----\n";
    $publicKey = openssl_pkey_get_public($pem);
    if ($publicKey === false) throw new RuntimeException('Clave publica invalida.');
    if (openssl_verify($encodedHeader . '.' . $encodedPayload, base64UrlDecode($encodedSignature), $publicKey, OPENSSL_ALGO_SHA256) !== 1) {
        throw new RuntimeException('Firma JWT invalida.');
    }
    return $claims;
}

function obtenerGraphAppToken(string $tenantId, string $clientId, string $clientSecret): string
{
    $url = 'https://login.microsoftonline.com/' . rawurlencode($tenantId) . '/oauth2/v2.0/token';
    $body = http_build_query([
        'client_id' => $clientId,
        'client_secret' => $clientSecret,
        'scope' => 'https://graph.microsoft.com/.default',
        'grant_type' => 'client_credentials',
    ], '', '&', PHP_QUERY_RFC3986);
    $json = ejecutarCurlJson($url, 'POST', ['Content-Type: application/x-www-form-urlencoded', 'Accept: application/json'], $body);
    $token = (string) ($json['access_token'] ?? '');
    if ($token === '') throw new RuntimeException('Entra ID no devolvio access_token para Graph.');
    return $token;
}

function verificarBorradorUsuario(string $graphToken, string $siteId, string $listId, string $itemId, string $folio, string $correoUsuario): void
{
    $url = 'https://graph.microsoft.com/v1.0/sites/' . rawurlencode($siteId) . '/lists/' . rawurlencode($listId) . '/items/' . rawurlencode($itemId) . '?$expand=fields($select=Title,field_1,Vendedor_Correo)';
    $item = ejecutarCurlJson($url, 'GET', ['Authorization: Bearer ' . $graphToken, 'Accept: application/json']);
    $fields = is_array($item['fields'] ?? null) ? $item['fields'] : [];
    if (strtoupper(trim((string) ($fields['field_1'] ?? ''))) !== 'BORRADOR') throw new RuntimeException('La solicitud ya no esta en BORRADOR.');
    if (!hash_equals(strtoupper(trim((string) ($fields['Title'] ?? ''))), $folio)) throw new RuntimeException('El folio no corresponde al item solicitado.');
    $correoBorrador = strtolower(trim((string) ($fields['Vendedor_Correo'] ?? '')));
    if ($correoBorrador === '' || $correoUsuario === '' || !hash_equals($correoBorrador, $correoUsuario)) throw new RuntimeException('El borrador no pertenece al usuario autenticado.');
}

function obtenerDriveExpedientes(string $graphToken, string $siteId): string
{
    $url = 'https://graph.microsoft.com/v1.0/sites/' . rawurlencode($siteId) . '/drives?$select=id,name,webUrl';
    $drives = ejecutarCurlJson($url, 'GET', ['Authorization: Bearer ' . $graphToken, 'Accept: application/json']);
    foreach (($drives['value'] ?? []) as $drive) {
        if (!is_array($drive)) continue;
        $nombre = strtolower(trim((string) ($drive['name'] ?? '')));
        if (in_array($nombre, ['expedientes_ventas', 'expedientes ventas'], true)) {
            $id = (string) ($drive['id'] ?? '');
            if ($id !== '') return $id;
        }
    }
    throw new RuntimeException('No se encontro la biblioteca Expedientes_Ventas.');
}

function asegurarCarpetaFolio(string $graphToken, string $driveId, string $folio): void
{
    $url = 'https://graph.microsoft.com/v1.0/drives/' . rawurlencode($driveId) . '/root/children';
    $body = json_encode([
        'name' => $folio,
        'folder' => new stdClass(),
        '@microsoft.graph.conflictBehavior' => 'fail',
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

    [$status, $response] = ejecutarCurlRaw($url, 'POST', [
        'Authorization: Bearer ' . $graphToken,
        'Accept: application/json',
        'Content-Type: application/json',
    ], (string) $body);

    if ($status >= 200 && $status < 300) return;
    if ($status === 409) return;
    $decoded = json_decode($response, true);
    $detalle = is_array($decoded) ? (string) ($decoded['error']['message'] ?? '') : '';
    throw new RuntimeException('No fue posible preparar la carpeta del folio' . ($detalle !== '' ? ': ' . $detalle : '.'));
}

/** @return array<string,mixed> */
function subirArchivoGraph(string $graphToken, string $driveId, string $folio, string $nombre, string $mime, string $contenido): array
{
    $path = rawurlencode($folio) . '/' . rawurlencode($nombre);
    $url = 'https://graph.microsoft.com/v1.0/drives/' . rawurlencode($driveId) . '/root:/' . $path . ':/content';
    return ejecutarCurlJson($url, 'PUT', [
        'Authorization: Bearer ' . $graphToken,
        'Accept: application/json',
        'Content-Type: ' . $mime,
    ], $contenido, 30);
}

function actualizarIndicadoresDocumento(string $graphToken, string $siteId, string $listId, string $itemId, string $tipo): void
{
    $fields = [];
    if ($tipo === 'FIRMA_CLIENTE') $fields['field_102'] = 'FIRMADO';
    if ($tipo === 'FIRMA_VENDEDOR') $fields['field_103'] = 'FIRMADO';

    $booleanTargets = [
        'ID_TITULAR' => 'Documento_ID_Titular',
        'ID_SUSTITUTO' => 'Documento_ID_Sustituto',
        'COMPROBANTE_DOMICILIO' => 'Documento_Comprobante_Domicilio',
        'COMPROBANTE_PAGO' => 'Documento_Comprobante_Pago',
    ];

    if (isset($booleanTargets[$tipo])) {
        $columnsUrl = 'https://graph.microsoft.com/v1.0/sites/' . rawurlencode($siteId) . '/lists/' . rawurlencode($listId) . '/columns?$select=name,displayName';
        $columns = ejecutarCurlJson($columnsUrl, 'GET', ['Authorization: Bearer ' . $graphToken, 'Accept: application/json']);
        $target = normalizar((string) $booleanTargets[$tipo]);
        foreach (($columns['value'] ?? []) as $column) {
            if (!is_array($column)) continue;
            $name = (string) ($column['name'] ?? '');
            $display = (string) ($column['displayName'] ?? '');
            if (normalizar($name) === $target || normalizar($display) === $target) {
                $fields[$name] = true;
                break;
            }
        }
    }

    if (!$fields) return;
    $url = 'https://graph.microsoft.com/v1.0/sites/' . rawurlencode($siteId) . '/lists/' . rawurlencode($listId) . '/items/' . rawurlencode($itemId) . '/fields';
    $body = json_encode($fields, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    ejecutarCurlJson($url, 'PATCH', [
        'Authorization: Bearer ' . $graphToken,
        'Accept: application/json',
        'Content-Type: application/json',
    ], (string) $body);
}

function normalizar(string $value): string
{
    $value = strtolower($value);
    $value = preg_replace('/[^a-z0-9]+/', '', $value);
    return is_string($value) ? $value : '';
}

/** @return array{0:int,1:string} */
function ejecutarCurlRaw(string $url, string $method, array $headers, ?string $body = null, int $timeout = 15): array
{
    $curl = curl_init($url);
    if ($curl === false) throw new RuntimeException('No fue posible inicializar cURL.');
    $options = [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_CONNECTTIMEOUT => 8,
        CURLOPT_TIMEOUT => $timeout,
        CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
    ];
    if ($body !== null) $options[CURLOPT_POSTFIELDS] = $body;
    curl_setopt_array($curl, $options);
    $response = curl_exec($curl);
    $status = (int) curl_getinfo($curl, CURLINFO_HTTP_CODE);
    $error = curl_error($curl);
    curl_close($curl);
    if ($response === false) throw new RuntimeException('La solicitud remota fallo: ' . $error);
    return [$status, (string) $response];
}

/** @return array<string,mixed> */
function ejecutarCurlJson(string $url, string $method, array $headers, ?string $body = null, int $timeout = 15): array
{
    [$status, $response] = ejecutarCurlRaw($url, $method, $headers, $body, $timeout);
    $decoded = json_decode($response, true);
    if ($status < 200 || $status >= 300) {
        $detalle = is_array($decoded) ? (string) ($decoded['error']['message'] ?? $decoded['error_description'] ?? '') : '';
        throw new RuntimeException('Servicio remoto respondio HTTP ' . $status . ($detalle !== '' ? ': ' . $detalle : '.'));
    }
    if (!is_array($decoded)) throw new RuntimeException('El servicio remoto no devolvio JSON valido.');
    return $decoded;
}

/** @return array<string,mixed> */
function obtenerJsonRemoto(string $url): array
{
    return ejecutarCurlJson($url, 'GET', ['Accept: application/json', 'User-Agent: Portal-JJP-Solicitud-Venta/1.0']);
}

function base64UrlDecode(string $value): string
{
    $remainder = strlen($value) % 4;
    if ($remainder > 0) $value .= str_repeat('=', 4 - $remainder);
    $decoded = base64_decode(strtr($value, '-_', '+/'), true);
    if ($decoded === false) throw new RuntimeException('Base64Url invalido.');
    return $decoded;
}
