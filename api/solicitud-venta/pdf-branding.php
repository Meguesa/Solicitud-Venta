<?php

declare(strict_types=1);

/**
 * Agrega el logo institucional de Jardines de Juan Pablo a la pagina actual
 * de SvPdfDocumento sin acoplar el motor PDF a una ruta absoluta del servidor.
 *
 * El logo fuente vive en Financiamiento. En produccion cPanel dispone de GD;
 * se convierte el JPEG a PNG en memoria para reutilizar el soporte PNG nativo
 * del generador de Solicitud de Venta.
 */
function svPdfAgregarLogoJdjp(SvPdfDocumento $pdf): void
{
    if (!function_exists('imagecreatefromstring') || !function_exists('imagepng')) return;

    $logo = svPdfLogoJdjpContenido();
    if ($logo === '') return;

    $src = @imagecreatefromstring($logo);
    if ($src === false) return;

    ob_start();
    imagealphablending($src, true);
    imagesavealpha($src, true);
    imagepng($src, null, 6);
    $png = ob_get_clean();
    imagedestroy($src);
    if (!is_string($png) || $png === '') return;

    try {
        $ref = new ReflectionClass(SvPdfDocumento::class);
        $register = $ref->getMethod('registerPng');
        $draw = $ref->getMethod('drawImageFit');
        $resource = $register->invoke($pdf, $png);
        // Cabecera: logo a la izquierda, sin invadir el folio ni el contenido.
        $draw->invoke($pdf, $resource, 41.0, 28.0, 72.0, 40.0);
    } catch (Throwable $error) {
        error_log('Solicitud Venta logo PDF: ' . $error->getMessage());
    }
}

function svPdfLogoJdjpContenido(): string
{
    $candidates = [];
    $documentRoot = rtrim((string) ($_SERVER['DOCUMENT_ROOT'] ?? ''), '/');
    if ($documentRoot !== '') $candidates[] = $documentRoot . '/financiamiento/assets/logo.jpg';
    $candidates[] = dirname(__DIR__, 2) . '/financiamiento/assets/logo.jpg';
    $candidates[] = '/home/juanpab1/public_html/portal.juanpablo.com.mx/financiamiento/assets/logo.jpg';

    foreach (array_unique($candidates) as $path) {
        if (!is_file($path) || !is_readable($path)) continue;
        $data = @file_get_contents($path);
        if (is_string($data) && $data !== '') return $data;
    }
    return '';
}
