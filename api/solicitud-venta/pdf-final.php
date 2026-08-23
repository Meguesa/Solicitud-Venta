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

/**
 * Obtiene la fecha real de creacion del elemento principal de SharePoint.
 * Esta fecha es independiente de la fecha/hora en la que el vendedor envia
 * posteriormente la solicitud al flujo de Vo.Bo.
 */
function pdfFinalFechaCreacion(array $grupo): string
{
    $principalItem = null;
    foreach ($grupo as $item) {
        if (!is_array($item)) continue;
        $fields = is_array($item['fields'] ?? null) ? $item['fields'] : [];
        if (svPdfBool($fields['Es_Principal'] ?? false) || (int) ($fields['Componente_Numero'] ?? 0) === 1) {
            $principalItem = $item;
            break;
        }
    }
    if (!is_array($principalItem) && isset($grupo[0]) && is_array($grupo[0])) $principalItem = $grupo[0];
    if (!is_array($principalItem)) return '';

    $raw = trim((string) ($principalItem['createdDateTime'] ?? ''));
    if ($raw === '') return '';

    try {
        return (new DateTimeImmutable($raw))
            ->setTimezone(new DateTimeZone('America/Monterrey'))
            ->format('d/m/Y');
    } catch (Throwable $error) {
        return '';
    }
}

/**
 * El layout V3 conserva fechaSolicitud desde _ESTADO_BORRADOR.json. Ese valor
 * historicamente era solo YYYY-MM-DD y por eso se renderizaba como 00:00.
 * El backend ahora guarda en field_2 el instante real del envio a Vo.Bo.
 * Sustituimos solamente la primera fecha con 00:00 del documento (la Fecha de
 * la seccion General) por el timestamp real. Ambas cadenas tienen igual
 * longitud, de modo que no se alteran offsets ni longitudes internas del PDF.
 *
 * Asimismo reutilizamos el texto ORIGINAL DIGITAL de la primera cabecera para
 * mostrar la fecha de creacion debajo del folio. La cadena se conserva en la
 * misma longitud para mantener intacta la estructura binaria del PDF.
 */
function pdfFinalAplicarFechasAuditoria(string $pdf, array $grupo, array $principal): string
{
    $fechaEnvioRaw = trim((string) ($principal['field_2'] ?? ''));
    if ($fechaEnvioRaw !== '') {
        $fechaEnvio = svPdfFecha($fechaEnvioRaw);
        if (preg_match('/^\d{2}\/\d{2}\/\d{4} \d{2}:\d{2}$/', $fechaEnvio)) {
            $reemplazado = preg_replace(
                '/\(\d{2}\/\d{2}\/\d{4} 00:00\) Tj/',
                '(' . $fechaEnvio . ') Tj',
                $pdf,
                1
            );
            if (is_string($reemplazado)) $pdf = $reemplazado;
        }
    }

    $fechaCreacion = pdfFinalFechaCreacion($grupo);
    if ($fechaCreacion !== '') {
        // 16 caracteres exactos para reemplazar "ORIGINAL DIGITAL" sin mover
        // offsets: "CREADA DD/MM/YY " tambien mide 16 caracteres.
        $textoCreacion = 'CREADA ' . substr($fechaCreacion, 0, 6) . substr($fechaCreacion, -2) . ' ';
        $reemplazado = preg_replace(
            '/\(ORIGINAL DIGITAL\) Tj/',
            '(' . $textoCreacion . ') Tj',
            $pdf,
            1
        );
        if (is_string($reemplazado)) $pdf = $reemplazado;
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

$pdfOriginal = $pdf;
$pdf = pdfFinalNormalizarEtiquetasMayusculas($pdf);
$pdf = pdfFinalAplicarFechasAuditoria($pdf, $grupo, $principal);

if ($pdf !== $pdfOriginal) {
    try {
        $driveId = svPdfDriveExpedientes($graphToken, (string) $config['siteId']);
        svPdfSubir($graphToken, $driveId, $folio, $name, $pdf);
    } catch (Throwable $error) {
        error_log('Solicitud Venta PDF normalizacion/auditoria ' . $folio . ': ' . $error->getMessage());
        pdfFinalError(502, 'PDF_NORMALIZATION_SAVE_FAILED', 'El PDF se genero, pero no fue posible guardar la version final con fechas y etiquetas corregidas.');
    }
}

header('Content-Type: application/pdf');
header('Content-Disposition: inline; filename="' . str_replace('"', '', $name) . '"');
header('Content-Length: ' . strlen($pdf));
header('X-Solicitud-Folio: ' . $folio);
header('X-PDF-Layout: solicitud-venta-fisico-v3');
echo $pdf;
exit;
