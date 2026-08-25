<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/includes/bootstrap.php';
require_once __DIR__ . '/_common.php';
require_once __DIR__ . '/pdf-final-lib.php';
require_once __DIR__ . '/pdf-branding.php';
require_once __DIR__ . '/pdf-final-layout.php';
require_once __DIR__ . '/pdf-final-layout-v2.php';
require_once __DIR__ . '/pdf-final-layout-v3.php';

header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');

function pdfPreliminarError(int $status, string $code, string $message): void
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

function pdfPreliminarNormalizarEtiquetasMayusculas(string $pdf): string
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

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'GET') {
    pdfPreliminarError(405, 'METHOD_NOT_ALLOWED', 'Metodo no permitido.');
}
if (!portal_is_authenticated()) {
    pdfPreliminarError(401, 'AUTH_REQUIRED', 'La sesion del Portal Interno no esta activa.');
}

$folio = strtoupper(trim((string) ($_GET['folio'] ?? '')));
if (!preg_match('/^SV-\d{4}-\d{6,}$/', $folio)) {
    pdfPreliminarError(400, 'INVALID_FOLIO', 'El folio indicado no es valido.');
}

$config = svConfig();
try {
    $graphToken = svGraphToken($config['tenantId'], $config['clientId'], $config['clientSecret']);
    $grupo = svPdfObtenerGrupo($graphToken, $config['siteId'], $config['listId'], $folio);
} catch (Throwable $error) {
    error_log('Solicitud Venta PDF preliminar lectura ' . $folio . ': ' . $error->getMessage());
    pdfPreliminarError(502, 'PDF_PREVIEW_READ_FAILED', 'No fue posible consultar la solicitud para generar el PDF preliminar.');
}

if (!$grupo) {
    pdfPreliminarError(404, 'REQUEST_NOT_FOUND', 'No se encontro la solicitud indicada.');
}

$principal = svPdfPrincipal($grupo);
$estatus = strtoupper(trim((string) ($principal['field_1'] ?? '')));
if (!in_array($estatus, ['PENDIENTE VOBO', 'PENDIENTE COBRANZA'], true)) {
    pdfPreliminarError(
        409,
        'REQUEST_NOT_IN_REVIEW',
        'El PDF preliminar solo esta disponible mientras la solicitud se encuentra en revision Comercial o de Cobranza.'
    );
}

$user = portal_user();
$userEmail = strtolower(trim((string) ($user['email'] ?? '')));
$sellerEmail = strtolower(trim((string) ($principal['Vendedor_Correo'] ?? '')));
$canReview = portal_user_can_vobo() || portal_user_can_cobranza_vobo();
$isOwner = $userEmail !== '' && $sellerEmail !== '' && hash_equals($sellerEmail, $userEmail);
if (!$canReview && !$isOwner) {
    pdfPreliminarError(403, 'PDF_PREVIEW_FORBIDDEN', 'Tu cuenta no tiene autorizacion para consultar este PDF preliminar.');
}

if (!function_exists('svPdfGenerarYGuardarPreliminarFisicoV3')) {
    pdfPreliminarError(500, 'PDF_PREVIEW_RUNTIME_MISSING', 'El generador de PDF preliminar no esta disponible en esta version del Portal.');
}

try {
    $resultado = svPdfGenerarYGuardarPreliminarFisicoV3(
        $folio,
        $grupo,
        $graphToken,
        $config
    );
} catch (Throwable $error) {
    error_log('Solicitud Venta PDF preliminar generacion ' . $folio . ': ' . $error->getMessage());
    pdfPreliminarError(502, 'PDF_PREVIEW_GENERATION_FAILED', 'No fue posible generar o guardar el PDF preliminar: ' . $error->getMessage());
}

$pdf = (string) ($resultado['contenido'] ?? '');
$name = trim((string) ($resultado['nombre'] ?? ('SOLICITUD_PRELIMINAR_' . $folio . '.pdf')));
if ($pdf === '' || !str_starts_with($pdf, '%PDF-')) {
    pdfPreliminarError(500, 'INVALID_PDF_PREVIEW', 'El documento preliminar generado no tiene un formato PDF valido.');
}

$pdfNormalizado = pdfPreliminarNormalizarEtiquetasMayusculas($pdf);
if ($pdfNormalizado !== $pdf) {
    $pdf = $pdfNormalizado;
    try {
        $driveId = svPdfDriveExpedientes($graphToken, (string) $config['siteId']);
        svPdfSubir($graphToken, $driveId, $folio, $name, $pdf);
    } catch (Throwable $error) {
        error_log('Solicitud Venta PDF preliminar normalizacion ' . $folio . ': ' . $error->getMessage());
        pdfPreliminarError(502, 'PDF_PREVIEW_SAVE_FAILED', 'El PDF preliminar se genero, pero no fue posible guardar la version normalizada.');
    }
}

header('Content-Type: application/pdf');
header('Content-Disposition: inline; filename="' . str_replace('"', '', $name) . '"');
header('Content-Length: ' . strlen($pdf));
header('X-Solicitud-Folio: ' . $folio);
header('X-PDF-Tipo: preliminar-no-oficial');
echo $pdf;
exit;
