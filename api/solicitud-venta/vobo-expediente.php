<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/includes/bootstrap.php';
require_once __DIR__ . '/_common.php';

header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');

function voboExpError(int $status, string $code, string $message): void
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode([
        'ok' => false,
        'error' => $code,
        'message' => $message,
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'GET') {
    voboExpError(405, 'METHOD_NOT_ALLOWED', 'Metodo no permitido.');
}
if (!portal_is_authenticated()) {
    voboExpError(401, 'AUTH_REQUIRED', 'La sesion del Portal Interno no esta activa.');
}

$etapa = strtolower(trim((string) ($_GET['etapa'] ?? 'comercial')));
if (!in_array($etapa, ['comercial', 'cobranza'], true)) {
    voboExpError(400, 'INVALID_STAGE', 'La etapa de Vo.Bo. solicitada no es valida.');
}
if ($etapa === 'cobranza') {
    if (!portal_user_can_cobranza_vobo()) {
        voboExpError(403, 'COBRANZA_FORBIDDEN', 'Tu cuenta no tiene autorizacion para revisar expedientes de Cobranza.');
    }
} elseif (!portal_user_can_vobo()) {
    voboExpError(403, 'VOBO_FORBIDDEN', 'Tu cuenta no tiene autorizacion para revisar expedientes de Vo.Bo. Comercial.');
}

$folio = strtoupper(trim((string) ($_GET['folio'] ?? '')));
if (!preg_match('/^SV-\d{4}-(\d{6,})$/', $folio, $folioMatch)) {
    voboExpError(400, 'INVALID_FOLIO', 'El folio indicado no es valido.');
}

$accion = strtolower(trim((string) ($_GET['accion'] ?? 'listar')));
if (!in_array($accion, ['listar', 'archivo'], true)) {
    voboExpError(400, 'INVALID_ACTION', 'La accion solicitada no es valida.');
}

$config = svConfig();
try {
    $graphToken = svGraphToken($config['tenantId'], $config['clientId'], $config['clientSecret']);
    voboExpValidarSolicitud($graphToken, $config['siteId'], $config['listId'], $folio, $folioMatch[1], $etapa);
    $driveId = voboExpDriveId($graphToken, $config['siteId']);
    $archivos = voboExpListarArchivos($graphToken, $driveId, $folio);
} catch (Throwable $error) {
    error_log('Solicitud Venta VoBo expediente ' . $folio . ': ' . $error->getMessage());
    voboExpError(502, 'EXPEDIENT_READ_FAILED', 'No fue posible consultar los archivos del expediente.');
}

if ($accion === 'archivo') {
    $archivoId = trim((string) ($_GET['archivo'] ?? ''));
    if ($archivoId === '') voboExpError(400, 'FILE_ID_REQUIRED', 'No se indico el archivo que deseas visualizar.');

    $seleccionado = null;
    foreach ($archivos as $archivo) {
        if (hash_equals((string) ($archivo['id'] ?? ''), $archivoId)) {
            $seleccionado = $archivo;
            break;
        }
    }
    if (!is_array($seleccionado)) voboExpError(404, 'FILE_NOT_FOUND', 'El archivo no pertenece al expediente indicado.');

    try {
        voboExpEnviarArchivo($graphToken, $driveId, $seleccionado);
    } catch (Throwable $error) {
        error_log('Solicitud Venta VoBo archivo ' . $folio . ': ' . $error->getMessage());
        voboExpError(502, 'FILE_READ_FAILED', 'No fue posible abrir el archivo del expediente.');
    }
    exit;
}

$resultado = [];
foreach ($archivos as $archivo) {
    $id = (string) ($archivo['id'] ?? '');
    $nombre = (string) ($archivo['name'] ?? '');
    if ($id === '' || $nombre === '') continue;

    $file = is_array($archivo['file'] ?? null) ? $archivo['file'] : [];
    $mime = trim((string) ($file['mimeType'] ?? 'application/octet-stream'));
    $resultado[] = [
        'id' => $id,
        'nombre' => $nombre,
        'mime' => $mime,
        'tamano' => (int) ($archivo['size'] ?? 0),
        'modificado' => (string) ($archivo['lastModifiedDateTime'] ?? ''),
        'categoria' => voboExpCategoria($nombre),
        'esImagen' => str_starts_with(strtolower($mime), 'image/'),
        'esPdf' => strtolower($mime) === 'application/pdf',
        'url' => '/api/solicitud-venta/vobo-expediente.php?accion=archivo'
            . '&etapa=' . rawurlencode($etapa)
            . '&folio=' . rawurlencode($folio)
            . '&archivo=' . rawurlencode($id),
    ];
}

usort($resultado, static function (array $a, array $b): int {
    $ta = strtotime((string) ($a['modificado'] ?? '')) ?: 0;
    $tb = strtotime((string) ($b['modificado'] ?? '')) ?: 0;
    return $tb <=> $ta;
});

header('Content-Type: application/json; charset=utf-8');
http_response_code(200);
echo json_encode([
    'ok' => true,
    'folio' => $folio,
    'etapa' => $etapa,
    'archivos' => $resultado,
    'pdfPreliminarUrl' => '/api/solicitud-venta/pdf-preliminar.php?folio=' . rawurlencode($folio),
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
exit;

function voboExpValidarSolicitud(string $token, string $siteId, string $listId, string $folio, string $folioNumero, string $etapa): void
{
    $itemId = (string) ((int) ltrim($folioNumero, '0'));
    if ($itemId === '0') throw new RuntimeException('El folio no contiene un item valido.');

    $url = 'https://graph.microsoft.com/v1.0/sites/' . rawurlencode($siteId)
        . '/lists/' . rawurlencode($listId)
        . '/items/' . rawurlencode($itemId)
        . '?$expand=fields($select=Title,Solicitud_Grupo,field_1)';
    $item = svCurlJson($url, 'GET', [
        'Authorization: Bearer ' . $token,
        'Accept: application/json',
    ]);
    $fields = is_array($item['fields'] ?? null) ? $item['fields'] : [];
    $title = strtoupper(trim((string) ($fields['Title'] ?? '')));
    $grupo = strtoupper(trim((string) ($fields['Solicitud_Grupo'] ?? '')));
    if ($title !== $folio && $grupo !== $folio) throw new RuntimeException('El item no corresponde al folio solicitado.');

    $esperado = $etapa === 'cobranza' ? 'PENDIENTE COBRANZA' : 'PENDIENTE VOBO';
    $estatus = strtoupper(trim((string) ($fields['field_1'] ?? '')));
    if ($estatus !== $esperado) throw new RuntimeException('La solicitud ya no esta pendiente en la etapa indicada.');
}

function voboExpDriveId(string $token, string $siteId): string
{
    $url = 'https://graph.microsoft.com/v1.0/sites/' . rawurlencode($siteId) . '/drives?$select=id,name&$top=100';
    $data = svCurlJson($url, 'GET', [
        'Authorization: Bearer ' . $token,
        'Accept: application/json',
    ]);

    foreach (($data['value'] ?? []) as $drive) {
        if (!is_array($drive)) continue;
        $nombre = strtolower(trim((string) ($drive['name'] ?? '')));
        $normalizado = str_replace([' ', '-'], '_', $nombre);
        if ($normalizado === 'expedientes_ventas') {
            $id = trim((string) ($drive['id'] ?? ''));
            if ($id !== '') return $id;
        }
    }
    throw new RuntimeException('No se encontro la biblioteca Expedientes_Ventas.');
}

/** @return array<int,array<string,mixed>> */
function voboExpListarArchivos(string $token, string $driveId, string $folio): array
{
    $resultado = [];
    $url = 'https://graph.microsoft.com/v1.0/drives/' . rawurlencode($driveId)
        . '/root:/' . rawurlencode($folio)
        . ':/children?$select=id,name,size,lastModifiedDateTime,file&$top=200';
    $paginas = 0;

    while ($url !== '' && $paginas < 10) {
        $data = svCurlJson($url, 'GET', [
            'Authorization: Bearer ' . $token,
            'Accept: application/json',
        ]);
        foreach (($data['value'] ?? []) as $item) {
            if (!is_array($item) || !is_array($item['file'] ?? null)) continue;

            $nombre = strtolower(trim((string) ($item['name'] ?? '')));
            $file = is_array($item['file'] ?? null) ? $item['file'] : [];
            $mime = strtolower(trim((string) ($file['mimeType'] ?? '')));

            // Los JSON son archivos internos de control, estado y evidencia del flujo.
            // No forman parte de la documentación que Comercial o Cobranza deben revisar.
            if ($mime === 'application/json' || str_ends_with($nombre, '.json')) continue;

            $resultado[] = $item;
        }
        $url = trim((string) ($data['@odata.nextLink'] ?? ''));
        $paginas++;
    }
    return $resultado;
}

function voboExpCategoria(string $nombre): string
{
    $upper = strtoupper($nombre);
    if (str_starts_with($upper, 'FIRMA_CLIENTE')) return 'FIRMA_CLIENTE';
    if (str_starts_with($upper, 'FIRMA_VENDEDOR')) return 'FIRMA_VENDEDOR';
    if (str_contains($upper, 'ID_TITULAR_FRENTE')) return 'ID_TITULAR_FRENTE';
    if (str_contains($upper, 'ID_TITULAR_REVERSO')) return 'ID_TITULAR_REVERSO';
    if (str_starts_with($upper, 'ID_TITULAR_')) return 'ID_TITULAR';
    if (str_contains($upper, 'ID_SUSTITUTO_FRENTE')) return 'ID_SUSTITUTO_FRENTE';
    if (str_contains($upper, 'ID_SUSTITUTO_REVERSO')) return 'ID_SUSTITUTO_REVERSO';
    if (str_starts_with($upper, 'ID_SUSTITUTO_')) return 'ID_SUSTITUTO';
    if (str_starts_with($upper, 'COMPROBANTE_DOMICILIO_')) return 'COMPROBANTE_DOMICILIO';
    if (str_starts_with($upper, 'COMPROBANTE_PAGO_')) return 'COMPROBANTE_PAGO';
    if (str_starts_with($upper, 'CORRIDA_FINANCIERA_')) return 'CORRIDA_FINANCIERA';
    if (str_starts_with($upper, 'SOLICITUD_PRELIMINAR_')) return 'PDF_PRELIMINAR';
    if (str_starts_with($upper, 'SOLICITUD_FINAL_')) return 'PDF_FINAL';
    if (str_starts_with($upper, 'OTRO_')) return 'OTRO';
    return 'OTRO_ARCHIVO';
}

/** @param array<string,mixed> $archivo */
function voboExpEnviarArchivo(string $token, string $driveId, array $archivo): void
{
    $id = trim((string) ($archivo['id'] ?? ''));
    $nombre = trim((string) ($archivo['name'] ?? 'archivo'));
    if ($id === '') throw new RuntimeException('Archivo sin identificador.');

    $url = 'https://graph.microsoft.com/v1.0/drives/' . rawurlencode($driveId)
        . '/items/' . rawurlencode($id) . '/content';
    $curl = curl_init($url);
    if ($curl === false) throw new RuntimeException('No fue posible inicializar cURL.');

    curl_setopt_array($curl, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS => 5,
        CURLOPT_CONNECTTIMEOUT => 8,
        CURLOPT_TIMEOUT => 45,
        CURLOPT_HTTPHEADER => [
            'Authorization: Bearer ' . $token,
            'Accept: */*',
        ],
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
    ]);

    $contenido = curl_exec($curl);
    $status = (int) curl_getinfo($curl, CURLINFO_HTTP_CODE);
    $error = curl_error($curl);
    curl_close($curl);

    if (!is_string($contenido) || $status < 200 || $status >= 300) {
        throw new RuntimeException('Microsoft Graph no devolvio el archivo' . ($error !== '' ? ': ' . $error : '.'));
    }

    $file = is_array($archivo['file'] ?? null) ? $archivo['file'] : [];
    $mime = trim((string) ($file['mimeType'] ?? 'application/octet-stream'));
    if ($mime === '') $mime = 'application/octet-stream';

    $safeName = str_replace(["\r", "\n", '"'], '', $nombre);
    header('Content-Type: ' . $mime);
    header('Content-Disposition: inline; filename="' . $safeName . '"; filename*=UTF-8\'\'' . rawurlencode($safeName));
    header('Content-Length: ' . strlen($contenido));
    echo $contenido;
}
