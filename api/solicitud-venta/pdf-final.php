<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/includes/bootstrap.php';
require_once __DIR__ . '/_common.php';
require_once __DIR__ . '/pdf-final-lib.php';
require_once __DIR__ . '/pdf-final-layout.php';
require_once __DIR__ . '/pdf-final-layout-v2.php';
require_once __DIR__ . '/pdf-final-layout-v3.php';

header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');

function pdfFinalError(int $status, string $code, string $message): void
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['ok' => false, 'error' => $code, 'message' => $message], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

/**
 * Corrige etiquetas que el motor PDF convierte con strtoupper() ASCII.
 * Los streams de texto del PDF usan Windows-1252, por lo que la sustitucion
 * se hace sobre esa codificacion y solo para palabras de etiquetas en
 * mayusculas; no modifica el texto narrativo normal del documento.
 */
function pdfFinalNormalizarEtiquetasMayusculas(string $pdf): string
{
    $map = [
        'OPERACIóN' => 'OPERACIÓN',
        'SECCIóN' => 'SECCIÓN',
        'NúMERO' => 'NÚMERO',
        'RéGIMEN' => 'RÉGIMEN',
        'TELéFONO' => 'TELÉFONO',
        'ELECTRóNICO' => 'ELECTRÓNICO',
        'ANTIGüEDAD' => 'ANTIGÜEDAD',
        'CóNYUGE' => 'CÓNYUGE',
        'OCUPACIóN' => 'OCUPACIÓN',
        'DESCRIPCIóN' => 'DESCRIPCIÓN',
        'MéTODO' => 'MÉTODO',
        'DíA' => 'DÍA',
        'BONIFICACIóN' => 'BONIFICACIÓN',
        'INTERéS' => 'INTERÉS',
        'GENERACIóN' => 'GENERACIÓN',
        'DURACIóN' => 'DURACIÓN',
        'INFORMACIóN' => 'INFORMACIÓN',
        'DOCUMENTACIóN' => 'DOCUMENTACIÓN',
        'DECLARACIóN' => 'DECLARACIÓN',
        'IDENTIFICACIóN' => 'IDENTIFICACIÓN',
        'DEFUNCIóN' => 'DEFUNCIÓN',
    ];

    foreach ($map as $from => $to) {
        $fromEncoded = function_exists('iconv') ? @iconv('UTF-8', 'Windows-1252//TRANSLIT//IGNORE', $from) : false;
        $toEncoded = function_exists('iconv') ? @iconv('UTF-8', 'Windows-1252//TRANSLIT//IGNORE', $to) : false;
        if (!is_string($fromEncoded) || !is_string($toEncoded)) continue;
        $pdf = str_replace($fromEncoded, $toEncoded, $pdf);
    }

    return $pdf;
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'GET') pdfFinalError(405, 'METHOD_NOT_ALLOWED', 'Metodo no permitido.');
if (!portal_is_authenticated()) pdfFinalError(401, 'AUTH_REQUIRED', 'La sesion del Portal Interno no esta activa.');

$folio = strtoupper(trim((string) ($_GET['folio'] ?? '')));
if (!preg_match('/^SV-\d{4}-\d{6,}$/', $folio)) pdfFinalError(400, 'INVALID_FOLIO', 'El folio indicado no es valido.');

$config = svConfig();
try {
    $graphToken = svGraphToken($config['tenantId'], $config['clientId'], $config['clientSecret']);
    $grupo = svPdfObtenerGrupo($graphToken, $config['siteId'], $config['listId'], $folio);
} catch (Throwable $error) {
    error_log('Solicitud Venta PDF lectura ' . $folio . ': ' . $error->getMessage());
    pdfFinalError(502, 'PDF_READ_FAILED', 'No fue posible consultar la solicitud para generar el PDF.');
}
if (!$grupo) pdfFinalError(404, 'REQUEST_NOT_FOUND', 'No se encontro la solicitud indicada.');

$principal = svPdfPrincipal($grupo);
$estatus = strtoupper(trim((string) ($principal['field_1'] ?? '')));
if ($estatus !== 'APROBADA') pdfFinalError(409, 'REQUEST_NOT_APPROVED', 'El PDF final solo se genera cuando la solicitud se encuentra APROBADA.');

$user = portal_user();
$userEmail = strtolower(trim((string) ($user['email'] ?? '')));
$sellerEmail = strtolower(trim((string) ($principal['Vendedor_Correo'] ?? '')));
$canReview = portal_user_can_vobo() || portal_user_can_cobranza_vobo();
$isOwner = $userEmail !== '' && $sellerEmail !== '' && hash_equals($sellerEmail, $userEmail);
if (!$canReview && !$isOwner) pdfFinalError(403, 'PDF_FORBIDDEN', 'Tu cuenta no tiene autorizacion para consultar el PDF final de esta solicitud.');

try {
    $resultado = svPdfGenerarYGuardarFisicoV3(
        $folio,
        $grupo,
        $graphToken,
        $config,
        trim((string) ($principal['Cobranza_Por'] ?? '')),
        trim((string) ($principal['Cobranza_Fecha'] ?? ''))
    );
} catch (Throwable $error) {
    error_log('Solicitud Venta PDF generacion ' . $folio . ': ' . $error->getMessage());
    pdfFinalError(502, 'PDF_GENERATION_FAILED', 'No fue posible generar o guardar el PDF final: ' . $error->getMessage());
}

$pdf = (string) ($resultado['contenido'] ?? '');
$name = (string) ($resultado['nombre'] ?? ('SOLICITUD_FINAL_' . $folio . '.pdf'));
if ($pdf === '' || !str_starts_with($pdf, '%PDF-')) pdfFinalError(500, 'INVALID_PDF', 'El documento generado no tiene un formato PDF valido.');

$pdfNormalizado = pdfFinalNormalizarEtiquetasMayusculas($pdf);
if ($pdfNormalizado !== $pdf) {
    $pdf = $pdfNormalizado;
    try {
        $driveId = svPdfDriveExpedientes($graphToken, (string) $config['siteId']);
        svPdfSubir($graphToken, $driveId, $folio, $name, $pdf);
    } catch (Throwable $error) {
        error_log('Solicitud Venta PDF normalizacion ' . $folio . ': ' . $error->getMessage());
        pdfFinalError(502, 'PDF_NORMALIZATION_SAVE_FAILED', 'El PDF se genero, pero no fue posible guardar la version con etiquetas corregidas.');
    }
}

header('Content-Type: application/pdf');
header('Content-Disposition: inline; filename="' . str_replace('"', '', $name) . '"');
header('Content-Length: ' . strlen($pdf));
header('X-Solicitud-Folio: ' . $folio);
header('X-PDF-Layout: solicitud-venta-fisico-v3');
echo $pdf;
exit;