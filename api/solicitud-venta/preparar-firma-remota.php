<?php

declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');

require_once __DIR__ . '/_common.php';

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    svResponderError(405, 'METHOD_NOT_ALLOWED', 'Metodo no permitido.');
}

$config = svConfig();
$claims = svUsuarioAutenticado($config['tenantId'], $config['clientId']);
$correoUsuario = strtolower(trim((string) ($claims['preferred_username'] ?? $claims['upn'] ?? '')));
if ($correoUsuario === '') {
    svResponderError(403, 'USER_EMAIL_REQUIRED', 'No fue posible identificar al vendedor.');
}

$raw = file_get_contents('php://input');
$payload = json_decode(is_string($raw) ? $raw : '', true);
if (!is_array($payload)) {
    svResponderError(400, 'INVALID_JSON', 'El cuerpo debe ser JSON valido.');
}

$folio = strtoupper(trim((string) ($payload['folio'] ?? '')));
if (!preg_match('/^SV-(\d{4})-(\d{6,})$/', $folio, $match)) {
    svResponderError(400, 'INVALID_FOLIO', 'El folio no es valido.');
}

$itemId = trim((string) ($payload['itemId'] ?? ''));
if ($itemId !== '' && (!ctype_digit($itemId) || (int) $itemId <= 0)) {
    svResponderError(400, 'INVALID_ITEM_ID', 'El identificador de SharePoint no es valido.');
}
if ($itemId === '') {
    $itemId = (string) ((int) ltrim((string) ($match[2] ?? ''), '0'));
}
if ($itemId === '' || $itemId === '0') {
    svResponderError(400, 'INVALID_ITEM_ID', 'No fue posible determinar el registro principal.');
}

try {
    $token = svGraphToken($config['tenantId'], $config['clientId'], $config['clientSecret']);
    $principal = pfrObtenerPrincipal(
        $token,
        $config['siteId'],
        $config['listId'],
        $itemId,
        $folio,
        $correoUsuario
    );

    $itemIdReal = trim((string) ($principal['id'] ?? $itemId));
    $fields = is_array($principal['fields'] ?? null) ? $principal['fields'] : [];
    pfrValidarPrincipal($fields, $folio, $correoUsuario);

    $url = 'https://graph.microsoft.com/v1.0/sites/' . rawurlencode($config['siteId'])
        . '/lists/' . rawurlencode($config['listId'])
        . '/items/' . rawurlencode($itemIdReal)
        . '/fields';
    $body = json_encode(['field_102' => 'PENDIENTE'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if (!is_string($body)) throw new RuntimeException('No fue posible preparar la actualizacion del titular.');

    svCurlJson($url, 'PATCH', [
        'Authorization: Bearer ' . $token,
        'Accept: application/json',
        'Content-Type: application/json',
    ], $body);

    http_response_code(200);
    echo json_encode([
        'ok' => true,
        'folio' => $folio,
        'itemId' => $itemIdReal,
        'firmaTitularEstatus' => 'PENDIENTE',
        'firmaVendedorEstatus' => 'FIRMADO',
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
} catch (Throwable $error) {
    error_log('Solicitud Venta preparar firma remota: ' . $error->getMessage());
    svResponderError(502, 'REMOTE_SIGNATURE_PREPARE_FAILED', 'No fue posible preparar la solicitud para firma remota.');
}

/** @return array<string,mixed> */
function pfrObtenerPrincipal(
    string $token,
    string $siteId,
    string $listId,
    string $itemId,
    string $folio,
    string $correoUsuario
): array {
    try {
        $url = 'https://graph.microsoft.com/v1.0/sites/' . rawurlencode($siteId)
            . '/lists/' . rawurlencode($listId)
            . '/items/' . rawurlencode($itemId)
            . '?$select=id&$expand=fields($select=Title,field_1,Vendedor_Correo,Solicitud_Grupo,Componente_Numero,Es_Principal,field_102,field_103)';
        $item = svCurlJson($url, 'GET', [
            'Authorization: Bearer ' . $token,
            'Accept: application/json',
        ]);
        $fields = is_array($item['fields'] ?? null) ? $item['fields'] : [];
        if (pfrCoincide($fields, $folio, $correoUsuario)) return $item;
    } catch (Throwable $error) {
        error_log('Solicitud Venta preparar firma remota item directo: ' . $error->getMessage());
    }

    $url = 'https://graph.microsoft.com/v1.0/sites/' . rawurlencode($siteId)
        . '/lists/' . rawurlencode($listId)
        . '/items?$select=id&$expand=fields($select=Title,field_1,Vendedor_Correo,Solicitud_Grupo,Componente_Numero,Es_Principal,field_102,field_103)&$top=200';
    $candidato = null;

    while ($url !== '') {
        $respuesta = svCurlJson($url, 'GET', [
            'Authorization: Bearer ' . $token,
            'Accept: application/json',
        ]);

        foreach (($respuesta['value'] ?? []) as $item) {
            if (!is_array($item)) continue;
            $fields = is_array($item['fields'] ?? null) ? $item['fields'] : [];
            if (!pfrCoincide($fields, $folio, $correoUsuario)) continue;

            $numero = max(1, (int) ($fields['Componente_Numero'] ?? 1));
            $principal = (bool) ($fields['Es_Principal'] ?? false) || $numero === 1;
            if ($principal) return $item;
            if ($candidato === null) $candidato = $item;
        }

        $url = trim((string) ($respuesta['@odata.nextLink'] ?? ''));
    }

    if (is_array($candidato)) return $candidato;
    throw new RuntimeException('No se encontro el registro principal del folio.');
}

/** @param array<string,mixed> $fields */
function pfrCoincide(array $fields, string $folio, string $correoUsuario): bool
{
    $correo = strtolower(trim((string) ($fields['Vendedor_Correo'] ?? '')));
    if ($correo === '' || !hash_equals($correo, $correoUsuario)) return false;

    $title = strtoupper(trim((string) ($fields['Title'] ?? '')));
    $grupo = strtoupper(trim((string) ($fields['Solicitud_Grupo'] ?? '')));
    return $title === $folio || $grupo === $folio || str_starts_with($title, $folio . '-');
}

/** @param array<string,mixed> $fields */
function pfrValidarPrincipal(array $fields, string $folio, string $correoUsuario): void
{
    if (!pfrCoincide($fields, $folio, $correoUsuario)) {
        svResponderError(403, 'REQUEST_FORBIDDEN', 'La solicitud no pertenece al vendedor autenticado.');
    }

    $estatus = strtoupper(trim((string) ($fields['field_1'] ?? '')));
    if ($estatus !== 'BORRADOR') {
        svResponderError(409, 'REQUEST_NOT_DRAFT', 'La solicitud ya no se encuentra en BORRADOR.');
    }

    $firmaVendedor = strtoupper(trim((string) ($fields['field_103'] ?? '')));
    if ($firmaVendedor !== 'FIRMADO') {
        svResponderError(409, 'SELLER_SIGNATURE_REQUIRED', 'La firma del vendedor debe estar guardada antes de enviar al cliente.');
    }
}
