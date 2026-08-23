<?php

declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');

require_once dirname(__DIR__, 2) . '/includes/bootstrap.php';
require_once __DIR__ . '/_common.php';
require_once __DIR__ . '/notificaciones.php';
require_once __DIR__ . '/notificaciones-flujo.php';
require_once __DIR__ . '/sharepoint-grupos.php';
require_once __DIR__ . '/pdf-final-lib.php';
require_once __DIR__ . '/pdf-final-layout.php';
require_once __DIR__ . '/pdf-final-layout-v2.php';
require_once __DIR__ . '/pdf-final-layout-v3.php';
require_once __DIR__ . '/identificaciones-pdf.php';

function nefError(int $status, string $code, string $message): void
{
    http_response_code($status);
    echo json_encode([
        'ok' => false,
        'error' => $code,
        'message' => $message,
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    nefError(405, 'METHOD_NOT_ALLOWED', 'Metodo no permitido.');
}
if (!portal_is_authenticated()) {
    nefError(401, 'AUTH_REQUIRED', 'La sesion del Portal Interno no esta activa.');
}
if (!portal_user_can_cobranza_vobo()) {
    nefError(403, 'COBRANZA_FORBIDDEN', 'Tu cuenta no tiene autorizacion para enviar el expediente final.');
}

$raw = file_get_contents('php://input');
$payload = json_decode(is_string($raw) ? $raw : '', true);
if (!is_array($payload)) $payload = [];
$folio = strtoupper(trim((string) ($payload['folio'] ?? '')));
if (!preg_match('/^SV-\d{4}-\d{6,}$/', $folio)) {
    nefError(400, 'INVALID_FOLIO', 'El folio indicado no es valido.');
}

try {
    $config = svConfig();
    $graphToken = svGraphToken($config['tenantId'], $config['clientId'], $config['clientSecret']);
    $grupo = svPdfObtenerGrupo($graphToken, $config['siteId'], $config['listId'], $folio);
    if (!$grupo) throw new RuntimeException('No se encontro la solicitud indicada.');

    $principal = svPdfPrincipal($grupo);
    $estatus = strtoupper(trim((string) ($principal['field_1'] ?? '')));
    if ($estatus !== 'APROBADA') {
        nefError(409, 'REQUEST_NOT_APPROVED', 'El expediente final solo se envia cuando la solicitud se encuentra APROBADA.');
    }

    $driveId = svPdfDriveExpedientes($graphToken, (string) $config['siteId']);
    $existentes = nefListarArchivosExpediente($graphToken, $driveId, $folio);
    foreach ($existentes as $archivoExistente) {
        if (strcasecmp((string) ($archivoExistente['name'] ?? ''), '_NOTIFICACION_EXPEDIENTE_FINAL.json') === 0) {
            http_response_code(200);
            echo json_encode([
                'ok' => true,
                'folio' => $folio,
                'alreadySent' => true,
                'message' => 'El expediente final ya habia sido enviado anteriormente. No se duplico el correo.',
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            exit;
        }
    }

    $resultadoPdf = svPdfGenerarYGuardarFisicoV3(
        $folio,
        $grupo,
        $graphToken,
        $config,
        trim((string) ($principal['Cobranza_Por'] ?? '')),
        trim((string) ($principal['Cobranza_Fecha'] ?? ''))
    );

    $pdf = (string) ($resultadoPdf['contenido'] ?? '');
    $pdfName = trim((string) ($resultadoPdf['nombre'] ?? ('SOLICITUD_FINAL_' . $folio . '.pdf')));
    if ($pdf === '' || !str_starts_with($pdf, '%PDF-')) {
        throw new RuntimeException('El PDF final generado no es valido.');
    }

    $pdf = nefNormalizarEtiquetasMayusculas($pdf);
    svPdfSubir($graphToken, $driveId, $folio, $pdfName, $pdf);

    [$grupoDestino, $destinatarios] = nefDestinatariosFinales($graphToken);
    $mailConfig = svNotificacionesConfig();
    $sender = $mailConfig['sender'];

    // Volver a listar despues de generar el PDF para incorporar la version final guardada.
    $archivos = nefListarArchivosExpediente($graphToken, $driveId, $folio);
    $adjuntos = [];
    foreach ($archivos as $archivo) {
        $nombre = trim((string) ($archivo['name'] ?? ''));
        $id = trim((string) ($archivo['id'] ?? ''));
        $size = (int) ($archivo['size'] ?? 0);
        $mime = trim((string) ($archivo['file']['mimeType'] ?? ''));
        if ($nombre === '' || $id === '' || $size < 0) continue;
        if (!nefEsAdjuntoFinal($nombre)) continue;
        $adjuntos[] = [
            'id' => $id,
            'name' => $nombre,
            'size' => $size,
            'contentType' => $mime !== '' ? $mime : nefMimePorNombre($nombre),
        ];
    }

    // Asegurar que el PDF siempre este incluido aunque la lectura de children tarde en reflejar el PUT.
    $tienePdf = false;
    foreach ($adjuntos as $adjunto) {
        if (strcasecmp((string) $adjunto['name'], $pdfName) === 0) {
            $tienePdf = true;
            break;
        }
    }
    if (!$tienePdf) {
        $adjuntos[] = [
            'id' => '',
            'name' => $pdfName,
            'size' => strlen($pdf),
            'contentType' => 'application/pdf',
            'contenidoLocal' => $pdf,
        ];
    }

    // Para el correo final, consolidar frente y reverso de cada identificacion
    // en un solo PDF. Los originales permanecen intactos en SharePoint.
    $adjuntos = nefConsolidarIdentificacionesAdjuntos($graphToken, $driveId, $folio, $adjuntos);

    usort($adjuntos, static function (array $a, array $b) use ($pdfName): int {
        $apdf = strcasecmp((string) ($a['name'] ?? ''), $pdfName) === 0;
        $bpdf = strcasecmp((string) ($b['name'] ?? ''), $pdfName) === 0;
        if ($apdf !== $bpdf) return $apdf ? -1 : 1;
        return strcasecmp((string) ($a['name'] ?? ''), (string) ($b['name'] ?? ''));
    });

    if (!$adjuntos) throw new RuntimeException('No se encontraron archivos para adjuntar al expediente final.');

    $partes = nefPartirAdjuntos($adjuntos, 18 * 1024 * 1024);
    $cliente = svNotificacionNombreCliente($principal);
    $precio = $principal['field_63'] ?? null;
    $precioTexto = ($precio !== null && $precio !== '' && is_numeric($precio))
        ? '$' . number_format((float) $precio, 2, '.', ',')
        : 'No disponible';

    $totalPartes = count($partes);
    $enviados = [];
    foreach ($partes as $indice => $parte) {
        $numeroParte = $indice + 1;
        $asunto = 'Expediente final aprobado | ' . $folio;
        if ($totalPartes > 1) $asunto .= ' | Parte ' . $numeroParte . ' de ' . $totalPartes;

        $html = nefPlantillaFinal([
            'folio' => $folio,
            'cliente' => $cliente,
            'precio' => $precioTexto,
            'componentes' => (string) max(1, (int) ($principal['Componente_Total'] ?? count($grupo))),
            'parte' => (string) $numeroParte,
            'partes' => (string) $totalPartes,
            'archivos' => implode(', ', array_map(static fn(array $a): string => (string) ($a['name'] ?? ''), $parte)),
        ]);

        $messageId = nefCrearBorrador($graphToken, $sender, $destinatarios, $asunto, $html);
        try {
            foreach ($parte as $adjunto) {
                $contenido = array_key_exists('contenidoLocal', $adjunto)
                    ? (string) $adjunto['contenidoLocal']
                    : nefDescargarArchivo($graphToken, $driveId, (string) $adjunto['id']);
                if (strlen($contenido) !== (int) $adjunto['size']) {
                    // Graph puede reportar 0 temporalmente en archivos recien creados; solo validar cuando size > 0.
                    if ((int) $adjunto['size'] > 0) {
                        throw new RuntimeException('El archivo ' . $adjunto['name'] . ' no se descargo completo.');
                    }
                }
                nefAdjuntarArchivo(
                    $graphToken,
                    $sender,
                    $messageId,
                    (string) $adjunto['name'],
                    (string) $adjunto['contentType'],
                    $contenido
                );
            }
            nefEnviarBorrador($graphToken, $sender, $messageId);
        } catch (Throwable $mailError) {
            nefEliminarBorradorSilencioso($graphToken, $sender, $messageId);
            throw $mailError;
        }
        $enviados[] = [
            'parte' => $numeroParte,
            'archivos' => array_map(static fn(array $a): string => (string) ($a['name'] ?? ''), $parte),
        ];
    }

    try {
        svGuardarEvidenciaNotificacion(
            $graphToken,
            $config['siteId'],
            $folio,
            '_NOTIFICACION_EXPEDIENTE_FINAL.json',
            [
                'version' => 1,
                'folio' => $folio,
                'etapa' => 'EXPEDIENTE_FINAL',
                'grupo' => $grupoDestino,
                'destinatarios' => $destinatarios,
                'remitente' => $sender,
                'enviadoUtc' => gmdate('c'),
                'partes' => $enviados,
            ]
        );
    } catch (Throwable $auditError) {
        error_log('Solicitud Venta evidencia expediente final: ' . $auditError->getMessage());
    }

    http_response_code(200);
    echo json_encode([
        'ok' => true,
        'folio' => $folio,
        'grupo' => $grupoDestino,
        'destinatarios' => $destinatarios,
        'partes' => $totalPartes,
        'archivos' => count($adjuntos),
        'message' => 'Expediente final enviado correctamente con PDF y documentos adjuntos.',
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
} catch (Throwable $error) {
    error_log('Solicitud Venta expediente final ' . $folio . ': ' . $error->getMessage());
    nefError(502, 'FINAL_NOTIFICATION_FAILED', 'La solicitud quedo aprobada, pero no fue posible enviar el expediente final: ' . $error->getMessage());
}

/** @return array<int,array<string,mixed>> */
function nefListarArchivosExpediente(string $token, string $driveId, string $folio): array
{
    $url = 'https://graph.microsoft.com/v1.0/drives/' . rawurlencode($driveId)
        . '/root:/' . rawurlencode($folio) . ':/children?$select=id,name,size,file,folder&$top=200';
    $data = svCurlJson($url, 'GET', ['Authorization: Bearer ' . $token, 'Accept: application/json']);
    $result = [];
    foreach (($data['value'] ?? []) as $item) {
        if (is_array($item) && isset($item['file'])) $result[] = $item;
    }
    return $result;
}

function nefEsAdjuntoFinal(string $nombre): bool
{
    $upper = strtoupper($nombre);
    if ($nombre === '' || str_starts_with($nombre, '_')) return false;
    if (str_starts_with($upper, 'FIRMA_CLIENTE')) return false;
    if (str_starts_with($upper, 'FIRMA_VENDEDOR')) return false;
    if (str_starts_with($upper, 'FIRMA_VOBO_')) return false;
    return true;
}

/** @return array{0:string,1:array<int,string>} */
function nefDestinatariosFinales(string $graphToken): array
{
    $grupo = 'Solicitud Venta - Expediente Final';
    $groupConfig = svSharePointGruposConfig();
    $siteWebUrl = svSharePointSiteWebUrlDesdeGraph($graphToken, $groupConfig['siteId']);
    $host = strtolower(trim((string) parse_url($siteWebUrl, PHP_URL_HOST)));
    if ($host === '') throw new RuntimeException('No fue posible determinar el host del sitio de SharePoint.');

    $sharePointToken = svSharePointTokenConCertificado(
        $groupConfig['tenantId'],
        $groupConfig['clientId'],
        $host,
        $groupConfig['pfxPath'],
        $groupConfig['pfxPassword']
    );
    $destinatarios = svNormalizarDestinatarios(
        svSharePointCorreosGrupo($sharePointToken, $siteWebUrl, $grupo)
    );
    if (!$destinatarios) throw new RuntimeException('El grupo ' . $grupo . ' no contiene correos validos.');
    return [$grupo, $destinatarios];
}

/** @param array<int,array<string,mixed>> $adjuntos @return array<int,array<int,array<string,mixed>>> */
function nefPartirAdjuntos(array $adjuntos, int $maxBytes): array
{
    $partes = [];
    $actual = [];
    $bytes = 0;
    foreach ($adjuntos as $adjunto) {
        $size = max(0, (int) ($adjunto['size'] ?? 0));
        if ($actual && ($bytes + $size) > $maxBytes) {
            $partes[] = $actual;
            $actual = [];
            $bytes = 0;
        }
        $actual[] = $adjunto;
        $bytes += $size;
    }
    if ($actual) $partes[] = $actual;
    return $partes;
}

function nefCrearBorrador(string $token, string $sender, array $destinatarios, string $asunto, string $html): string
{
    $to = [];
    foreach (svNormalizarDestinatarios($destinatarios) as $correo) {
        $to[] = ['emailAddress' => ['address' => $correo]];
    }
    $payload = json_encode([
        'subject' => $asunto,
        'body' => ['contentType' => 'HTML', 'content' => $html],
        'toRecipients' => $to,
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    $data = nefGraphJson(
        'https://graph.microsoft.com/v1.0/users/' . rawurlencode($sender) . '/messages',
        'POST',
        $token,
        $payload ?: '{}',
        [201]
    );
    $id = trim((string) ($data['id'] ?? ''));
    if ($id === '') throw new RuntimeException('Microsoft Graph no devolvio el ID del borrador.');
    return $id;
}

function nefAdjuntarArchivo(string $token, string $sender, string $messageId, string $name, string $contentType, string $content): void
{
    $size = strlen($content);
    if ($size <= 2 * 1024 * 1024) {
        $payload = json_encode([
            '@odata.type' => '#microsoft.graph.fileAttachment',
            'name' => $name,
            'contentType' => $contentType,
            'contentBytes' => base64_encode($content),
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        nefGraphJson(
            'https://graph.microsoft.com/v1.0/users/' . rawurlencode($sender)
                . '/messages/' . rawurlencode($messageId) . '/attachments',
            'POST',
            $token,
            $payload ?: '{}',
            [201]
        );
        return;
    }

    $payload = json_encode([
        'AttachmentItem' => [
            'attachmentType' => 'file',
            'name' => $name,
            'size' => $size,
            'contentType' => $contentType,
        ],
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    $session = nefGraphJson(
        'https://graph.microsoft.com/v1.0/users/' . rawurlencode($sender)
            . '/messages/' . rawurlencode($messageId) . '/attachments/createUploadSession',
        'POST',
        $token,
        $payload ?: '{}',
        [200, 201]
    );
    $uploadUrl = trim((string) ($session['uploadUrl'] ?? ''));
    if ($uploadUrl === '') throw new RuntimeException('No fue posible crear la sesion de carga para ' . $name . '.');

    $chunkSize = 3276800; // 10 x 320 KiB, requerido por la sesion de carga.
    for ($start = 0; $start < $size; $start += $chunkSize) {
        $end = min($start + $chunkSize, $size) - 1;
        $chunk = substr($content, $start, $end - $start + 1);
        nefPutChunk($uploadUrl, $chunk, $start, $end, $size, $name);
    }
}

function nefEnviarBorrador(string $token, string $sender, string $messageId): void
{
    nefGraphRaw(
        'https://graph.microsoft.com/v1.0/users/' . rawurlencode($sender)
            . '/messages/' . rawurlencode($messageId) . '/send',
        'POST',
        $token,
        '',
        [200, 202, 204]
    );
}

function nefEliminarBorradorSilencioso(string $token, string $sender, string $messageId): void
{
    try {
        nefGraphRaw(
            'https://graph.microsoft.com/v1.0/users/' . rawurlencode($sender)
                . '/messages/' . rawurlencode($messageId),
            'DELETE',
            $token,
            '',
            [200, 202, 204]
        );
    } catch (Throwable $ignored) {
    }
}

function nefDescargarArchivo(string $token, string $driveId, string $itemId): string
{
    $url = 'https://graph.microsoft.com/v1.0/drives/' . rawurlencode($driveId)
        . '/items/' . rawurlencode($itemId) . '/content';
    $curl = curl_init($url);
    if ($curl === false) throw new RuntimeException('No fue posible iniciar la descarga del expediente.');
    curl_setopt_array($curl, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS => 4,
        CURLOPT_CONNECTTIMEOUT => 15,
        CURLOPT_TIMEOUT => 90,
        CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $token],
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
    ]);
    $body = curl_exec($curl);
    $status = (int) curl_getinfo($curl, CURLINFO_HTTP_CODE);
    $error = curl_error($curl);
    curl_close($curl);
    if ($body === false) throw new RuntimeException('Fallo la descarga de un documento: ' . $error);
    if ($status < 200 || $status >= 300) throw new RuntimeException('Microsoft Graph respondio HTTP ' . $status . ' al descargar un documento.');
    return (string) $body;
}

/** @return array<string,mixed> */
function nefGraphJson(string $url, string $method, string $token, string $body, array $okCodes): array
{
    $response = nefGraphRaw($url, $method, $token, $body, $okCodes, true);
    if ($response === '') return [];
    $data = json_decode($response, true);
    if (!is_array($data)) throw new RuntimeException('Microsoft Graph devolvio una respuesta JSON invalida.');
    return $data;
}

function nefGraphRaw(string $url, string $method, string $token, string $body, array $okCodes, bool $json = true): string
{
    $curl = curl_init($url);
    if ($curl === false) throw new RuntimeException('No fue posible iniciar una solicitud a Microsoft Graph.');

    $bodyMethod = in_array($method, ['POST', 'PUT', 'PATCH'], true);
    $headers = ['Authorization: Bearer ' . $token, 'Accept: application/json'];
    if ($json && $method !== 'DELETE') $headers[] = 'Content-Type: application/json';
    if ($bodyMethod && $body === '') $headers[] = 'Content-Length: 0';

    curl_setopt_array($curl, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_CONNECTTIMEOUT => 15,
        CURLOPT_TIMEOUT => 90,
        CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
    ]);

    // Incluso un POST sin contenido debe establecer un cuerpo vacio para que
    // cURL envie Content-Length: 0. Microsoft Graph rechaza /send con HTTP 411
    // si la peticion no incluye longitud de contenido.
    if ($bodyMethod) curl_setopt($curl, CURLOPT_POSTFIELDS, $body);

    $response = curl_exec($curl);
    $status = (int) curl_getinfo($curl, CURLINFO_HTTP_CODE);
    $error = curl_error($curl);
    curl_close($curl);
    if ($response === false) throw new RuntimeException('Microsoft Graph no respondio: ' . $error);
    if (!in_array($status, $okCodes, true)) {
        $detalle = '';
        $decoded = json_decode((string) $response, true);
        if (is_array($decoded)) $detalle = trim((string) ($decoded['error']['message'] ?? ''));
        throw new RuntimeException('Microsoft Graph respondio HTTP ' . $status . ($detalle !== '' ? ': ' . $detalle : '.'));
    }
    return (string) $response;
}

function nefPutChunk(string $uploadUrl, string $chunk, int $start, int $end, int $total, string $name): void
{
    $curl = curl_init($uploadUrl);
    if ($curl === false) throw new RuntimeException('No fue posible iniciar la carga de ' . $name . '.');
    curl_setopt_array($curl, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_CONNECTTIMEOUT => 15,
        CURLOPT_TIMEOUT => 120,
        CURLOPT_CUSTOMREQUEST => 'PUT',
        CURLOPT_HTTPHEADER => [
            'Content-Length: ' . strlen($chunk),
            'Content-Range: bytes ' . $start . '-' . $end . '/' . $total,
            'Content-Type: application/octet-stream',
        ],
        CURLOPT_POSTFIELDS => $chunk,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
    ]);
    $response = curl_exec($curl);
    $status = (int) curl_getinfo($curl, CURLINFO_HTTP_CODE);
    $error = curl_error($curl);
    curl_close($curl);
    if ($response === false) throw new RuntimeException('Fallo la carga de ' . $name . ': ' . $error);
    if (!in_array($status, [200, 201, 202], true)) {
        throw new RuntimeException('La carga de ' . $name . ' respondio HTTP ' . $status . '.');
    }
}

/** @param array<string,string> $datos */
function nefPlantillaFinal(array $datos): string
{
    $h = static fn(string $v): string => htmlspecialchars($v, ENT_QUOTES, 'UTF-8');
    $folio = $h($datos['folio'] ?? '');
    $cliente = $h($datos['cliente'] ?? '');
    $precio = $h($datos['precio'] ?? '');
    $componentes = $h($datos['componentes'] ?? '');
    $parte = $h($datos['parte'] ?? '1');
    $partes = $h($datos['partes'] ?? '1');
    $archivos = $h($datos['archivos'] ?? '');
    $notaParte = $partes !== '1' ? '<p style="margin:8px 0 0;color:#665d58">Este correo corresponde a la parte ' . $parte . ' de ' . $partes . ' del expediente.</p>' : '';

    return '<!doctype html><html><body style="margin:0;padding:0;background:#f5f1ec;font-family:Arial,sans-serif;color:#2b1b15">'
        . '<table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="background:#f5f1ec;padding:28px 12px"><tr><td align="center">'
        . '<table role="presentation" width="640" cellspacing="0" cellpadding="0" style="max-width:640px;background:#fff;border:1px solid #e4d9cf;border-radius:14px;overflow:hidden">'
        . '<tr><td style="padding:24px 28px;border-top:5px solid #e28a16">'
        . '<div style="font-size:12px;letter-spacing:1.5px;font-weight:700;color:#225b8a">JARDINES DE JUAN PABLO</div>'
        . '<h1 style="font-size:24px;margin:8px 0 6px">Expediente final aprobado</h1>'
        . '<p style="margin:0;color:#665d58;line-height:1.5">La Solicitud de Venta concluyo sus Vo.Bo. Comercial y de Cobranza. Se adjunta el expediente autorizado.</p>'
        . $notaParte . '</td></tr>'
        . '<tr><td style="padding:0 28px 22px">'
        . '<table role="presentation" width="100%" cellspacing="0" cellpadding="8" style="border-collapse:collapse;font-size:14px">'
        . '<tr><td style="width:38%;font-weight:700;border-bottom:1px solid #eee7e1">Folio</td><td style="border-bottom:1px solid #eee7e1">' . $folio . '</td></tr>'
        . '<tr><td style="font-weight:700;border-bottom:1px solid #eee7e1">Cliente</td><td style="border-bottom:1px solid #eee7e1">' . $cliente . '</td></tr>'
        . '<tr><td style="font-weight:700;border-bottom:1px solid #eee7e1">Precio total</td><td style="border-bottom:1px solid #eee7e1">' . $precio . '</td></tr>'
        . '<tr><td style="font-weight:700;border-bottom:1px solid #eee7e1">Componentes</td><td style="border-bottom:1px solid #eee7e1">' . $componentes . '</td></tr>'
        . '<tr><td style="font-weight:700">Archivos adjuntos</td><td>' . $archivos . '</td></tr>'
        . '</table>'
        . '<p style="margin:22px 0 0;color:#756a64;font-size:12px;line-height:1.5">Este mensaje fue generado automaticamente por Solicitud de Venta. El expediente original permanece resguardado en SharePoint.</p>'
        . '</td></tr></table></td></tr></table></body></html>';
}

function nefMimePorNombre(string $name): string
{
    $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
    return match ($ext) {
        'pdf' => 'application/pdf',
        'jpg', 'jpeg' => 'image/jpeg',
        'png' => 'image/png',
        'webp' => 'image/webp',
        default => 'application/octet-stream',
    };
}

function nefNormalizarEtiquetasMayusculas(string $pdf): string
{
    $map = [
        'OPERACIóN'=>'OPERACIÓN','SECCIóN'=>'SECCIÓN','NúMERO'=>'NÚMERO','RéGIMEN'=>'RÉGIMEN',
        'TELéFONO'=>'TELÉFONO','ELECTRóNICO'=>'ELECTRÓNICO','ANTIGüEDAD'=>'ANTIGÜEDAD','CóNYUGE'=>'CÓNYUGE',
        'OCUPACIóN'=>'OCUPACIÓN','DESCRIPCIóN'=>'DESCRIPCIÓN','MéTODO'=>'MÉTODO','DíA'=>'DÍA',
        'BONIFICACIóN'=>'BONIFICACIÓN','INTERéS'=>'INTERÉS','GENERACIóN'=>'GENERACIÓN','DURACIóN'=>'DURACIÓN',
        'INFORMACIóN'=>'INFORMACIÓN','DOCUMENTACIóN'=>'DOCUMENTACIÓN','DECLARACIóN'=>'DECLARACIÓN',
        'IDENTIFICACIóN'=>'IDENTIFICACIÓN','DEFUNCIóN'=>'DEFUNCIÓN',
    ];
    foreach ($map as $from => $to) {
        $a = function_exists('iconv') ? @iconv('UTF-8', 'Windows-1252//TRANSLIT//IGNORE', $from) : false;
        $b = function_exists('iconv') ? @iconv('UTF-8', 'Windows-1252//TRANSLIT//IGNORE', $to) : false;
        if (is_string($a) && is_string($b)) $pdf = str_replace($a, $b, $pdf);
    }
    return $pdf;
}
