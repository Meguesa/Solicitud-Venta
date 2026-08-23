<?php

declare(strict_types=1);

/**
 * Consolida las identificaciones del expediente exclusivamente para el correo final.
 * Los archivos originales de SharePoint no se modifican ni eliminan.
 *
 * Cada identificacion consolidada usa una sola pagina: FRENTE arriba y REVERSO abajo.
 *
 * @param array<int,array<string,mixed>> $adjuntos
 * @return array<int,array<string,mixed>>
 */
function nefConsolidarIdentificacionesAdjuntos(
    string $graphToken,
    string $driveId,
    string $folio,
    array $adjuntos
): array {
    $tipos = [
        'TITULAR' => [
            'match' => static fn(string $upper): bool => str_contains($upper, 'ID_TITULAR'),
            'archivo' => 'ID_TITULAR_' . $folio . '.pdf',
            'titulo' => 'IDENTIFICACION OFICIAL - TITULAR',
        ],
        'SUBSTITUTO' => [
            'match' => static fn(string $upper): bool => str_contains($upper, 'ID_SUSTITUTO') || str_contains($upper, 'ID_SUBSTITUTO'),
            'archivo' => 'ID_SUBSTITUTO_' . $folio . '.pdf',
            'titulo' => 'IDENTIFICACION OFICIAL - SUBSTITUTO',
        ],
    ];

    foreach ($tipos as $tipo) {
        $frentes = [];
        $reversos = [];
        $indicesTipo = [];

        foreach ($adjuntos as $indice => $adjunto) {
            $nombre = trim((string) ($adjunto['name'] ?? ''));
            $upper = strtoupper($nombre);
            if ($nombre === '' || !($tipo['match'])($upper)) continue;

            $indicesTipo[] = $indice;
            if (str_contains($upper, 'FRENTE') || str_contains($upper, 'FRONT')) {
                $frentes[] = $adjunto;
            } elseif (str_contains($upper, 'REVERSO') || str_contains($upper, 'REVERSA') || str_contains($upper, 'BACK')) {
                $reversos[] = $adjunto;
            }
        }

        if (!$frentes || !$reversos) continue;

        usort($frentes, static fn(array $a, array $b): int => strcasecmp((string) ($b['name'] ?? ''), (string) ($a['name'] ?? '')));
        usort($reversos, static fn(array $a, array $b): int => strcasecmp((string) ($b['name'] ?? ''), (string) ($a['name'] ?? '')));
        $frente = $frentes[0];
        $reverso = $reversos[0];

        try {
            $frenteContenido = array_key_exists('contenidoLocal', $frente)
                ? (string) $frente['contenidoLocal']
                : nefDescargarArchivo($graphToken, $driveId, (string) ($frente['id'] ?? ''));
            $reversoContenido = array_key_exists('contenidoLocal', $reverso)
                ? (string) $reverso['contenidoLocal']
                : nefDescargarArchivo($graphToken, $driveId, (string) ($reverso['id'] ?? ''));

            $pdf = nefCrearPdfIdentificacionDosCaras(
                $folio,
                (string) $tipo['titulo'],
                (string) ($frente['name'] ?? 'FRENTE'),
                $frenteContenido,
                (string) ($reverso['name'] ?? 'REVERSO'),
                $reversoContenido
            );
            if (!str_starts_with($pdf, '%PDF-')) throw new RuntimeException('El PDF consolidado no es valido.');

            $filtrados = [];
            foreach ($adjuntos as $indice => $adjunto) {
                if (!in_array($indice, $indicesTipo, true)) $filtrados[] = $adjunto;
            }
            $filtrados[] = [
                'id' => '',
                'name' => (string) $tipo['archivo'],
                'size' => strlen($pdf),
                'contentType' => 'application/pdf',
                'contenidoLocal' => $pdf,
            ];
            $adjuntos = $filtrados;
        } catch (Throwable $error) {
            // El envio final no debe fallar por una conversion de imagen.
            // En ese caso se conservan los archivos originales como adjuntos.
            error_log('Solicitud Venta PDF identificacion ' . $folio . ': ' . $error->getMessage());
        }
    }

    return array_values($adjuntos);
}

function nefCrearPdfIdentificacionDosCaras(
    string $folio,
    string $titulo,
    string $frenteNombre,
    string $frenteContenido,
    string $reversoNombre,
    string $reversoContenido
): string {
    $frente = nefImagenParaPdf($frenteContenido, $frenteNombre);
    $reverso = nefImagenParaPdf($reversoContenido, $reversoNombre);
    $logoContenido = nefCargarLogoJdjp();
    $logo = $logoContenido !== '' ? nefImagenParaPdf($logoContenido, 'logo-jdjp.jpg') : null;

    $objects = [];
    $objects[1] = '<< /Type /Catalog /Pages 2 0 R >>';
    $objects[3] = '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica-Bold /Encoding /WinAnsiEncoding >>';
    $objects[4] = '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica /Encoding /WinAnsiEncoding >>';

    $nextId = 5;
    $frenteId = $nextId++;
    $reversoId = $nextId++;
    $logoId = null;
    if (is_array($logo)) $logoId = $nextId++;
    $contentId = $nextId++;
    $pageId = $nextId++;

    $objects[$frenteId] = nefPdfObjetoImagen($frente);
    $objects[$reversoId] = nefPdfObjetoImagen($reverso);
    if ($logoId !== null && is_array($logo)) $objects[$logoId] = nefPdfObjetoImagen($logo);

    $pageW = 595.28;
    $pageH = 841.89;
    $marginX = 36.0;
    $boxW = $pageW - (2 * $marginX);
    $boxH = 302.0;
    $frontBoxY = 420.0;
    $backBoxY = 78.0;

    [$frontW, $frontH, $frontX, $frontY] = nefPdfFitEnCaja($frente, $marginX + 10.0, $frontBoxY + 10.0, $boxW - 20.0, $boxH - 28.0);
    [$backW, $backH, $backX, $backY] = nefPdfFitEnCaja($reverso, $marginX + 10.0, $backBoxY + 10.0, $boxW - 20.0, $boxH - 28.0);

    $safeTitle = nefPdfEscapeAscii($titulo);
    $safeFolio = nefPdfEscapeAscii($folio);
    $content = "0.12 0.12 0.12 rg\n";

    if ($logoId !== null && is_array($logo)) {
        [$logoW, $logoH, $logoX, $logoY] = nefPdfFitEnCaja($logo, 38.0, 755.0, 82.0, 58.0);
        $content .= sprintf("q %.2F 0 0 %.2F %.2F %.2F cm /Logo Do Q\n", $logoW, $logoH, $logoX, $logoY);
    }

    $content .= "BT /F1 15 Tf 1 0 0 1 132 802 Tm (" . $safeTitle . ") Tj ET\n"
        . "BT /F2 9 Tf 1 0 0 1 132 784 Tm (Jardines de Juan Pablo  |  " . $safeFolio . ") Tj ET\n"
        . "0.76 0.76 0.76 RG 0.7 w " . $marginX . ' ' . $frontBoxY . ' ' . $boxW . ' ' . $boxH . " re S\n"
        . "BT /F1 10 Tf 1 0 0 1 46 " . ($frontBoxY + $boxH - 17.0) . " Tm (FRENTE) Tj ET\n"
        . sprintf("q %.2F 0 0 %.2F %.2F %.2F cm /ImFront Do Q\n", $frontW, $frontH, $frontX, $frontY)
        . "0.76 0.76 0.76 RG 0.7 w " . $marginX . ' ' . $backBoxY . ' ' . $boxW . ' ' . $boxH . " re S\n"
        . "BT /F1 10 Tf 1 0 0 1 46 " . ($backBoxY + $boxH - 17.0) . " Tm (REVERSO) Tj ET\n"
        . sprintf("q %.2F 0 0 %.2F %.2F %.2F cm /ImBack Do Q\n", $backW, $backH, $backX, $backY)
        . "BT /F2 7 Tf 0.38 0.38 0.38 rg 1 0 0 1 40 35 Tm (Documento consolidado desde el expediente digital.) Tj ET\n";

    $objects[$contentId] = '<< /Length ' . strlen($content) . ">>\nstream\n" . $content . "\nendstream";
    $xObjects = '/ImFront ' . $frenteId . ' 0 R /ImBack ' . $reversoId . ' 0 R';
    if ($logoId !== null) $xObjects .= ' /Logo ' . $logoId . ' 0 R';
    $objects[$pageId] = '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 595.28 841.89] '
        . '/Resources << /Font << /F1 3 0 R /F2 4 0 R >> /XObject << ' . $xObjects . ' >> >> '
        . '/Contents ' . $contentId . ' 0 R >>';
    $objects[2] = '<< /Type /Pages /Kids [' . $pageId . ' 0 R] /Count 1 >>';

    ksort($objects);
    return nefPdfConstruirObjetos($objects);
}

/** @param array{width:int,height:int,colorSpace:string,filter:string,decode:string,data:string} $imagen */
function nefPdfObjetoImagen(array $imagen): string
{
    $data = (string) $imagen['data'];
    $decode = trim((string) ($imagen['decode'] ?? ''));
    return '<< /Type /XObject /Subtype /Image /Width ' . (int) $imagen['width']
        . ' /Height ' . (int) $imagen['height']
        . ' /ColorSpace /' . (string) $imagen['colorSpace']
        . ' /BitsPerComponent 8 /Filter /' . (string) $imagen['filter']
        . ($decode !== '' ? ' /Decode ' . $decode : '')
        . ' /Length ' . strlen($data) . ">>\nstream\n" . $data . "\nendstream";
}

/**
 * @param array{width:int,height:int,colorSpace:string,filter:string,decode:string,data:string} $imagen
 * @return array{0:float,1:float,2:float,3:float}
 */
function nefPdfFitEnCaja(array $imagen, float $x, float $y, float $w, float $h): array
{
    $iw = max(1, (int) $imagen['width']);
    $ih = max(1, (int) $imagen['height']);
    $scale = min($w / $iw, $h / $ih);
    $drawW = $iw * $scale;
    $drawH = $ih * $scale;
    return [$drawW, $drawH, $x + (($w - $drawW) / 2.0), $y + (($h - $drawH) / 2.0)];
}

/** @param array<int,string> $objects */
function nefPdfConstruirObjetos(array $objects): string
{
    ksort($objects);
    $pdf = "%PDF-1.4\n%\xE2\xE3\xCF\xD3\n";
    $offsets = [0];
    $maxId = max(array_keys($objects));
    for ($id = 1; $id <= $maxId; $id++) {
        if (!isset($objects[$id])) continue;
        $offsets[$id] = strlen($pdf);
        $pdf .= $id . " 0 obj\n" . $objects[$id] . "\nendobj\n";
    }

    $xref = strlen($pdf);
    $pdf .= "xref\n0 " . ($maxId + 1) . "\n0000000000 65535 f \n";
    for ($id = 1; $id <= $maxId; $id++) {
        if (isset($objects[$id])) $pdf .= sprintf('%010d 00000 n ', $offsets[$id] ?? 0) . "\n";
        else $pdf .= "0000000000 65535 f \n";
    }
    $pdf .= "trailer\n<< /Size " . ($maxId + 1) . " /Root 1 0 R >>\nstartxref\n" . $xref . "\n%%EOF";
    return $pdf;
}

function nefCargarLogoJdjp(): string
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

/** @return array{width:int,height:int,colorSpace:string,filter:string,decode:string,data:string} */
function nefImagenParaPdf(string $data, string $name): array
{
    if ($data === '') throw new RuntimeException('La imagen ' . $name . ' esta vacia.');

    if (substr($data, 0, 2) === "\xFF\xD8") {
        $info = function_exists('getimagesizefromstring') ? @getimagesizefromstring($data) : false;
        if (!is_array($info) || (int) ($info[0] ?? 0) <= 0 || (int) ($info[1] ?? 0) <= 0) {
            throw new RuntimeException('No fue posible leer las dimensiones JPEG de ' . $name . '.');
        }
        $channels = (int) ($info['channels'] ?? 3);
        $colorSpace = $channels === 1 ? 'DeviceGray' : ($channels === 4 ? 'DeviceCMYK' : 'DeviceRGB');
        return [
            'width' => (int) $info[0],
            'height' => (int) $info[1],
            'colorSpace' => $colorSpace,
            'filter' => 'DCTDecode',
            'decode' => $channels === 4 ? '[1 0 1 0 1 0 1 0]' : '',
            'data' => $data,
        ];
    }

    // GD permite normalizar PNG y WEBP a JPEG sobre fondo blanco cuando esta disponible.
    if (function_exists('imagecreatefromstring') && function_exists('imagejpeg')) {
        $src = @imagecreatefromstring($data);
        if ($src !== false) {
            $w = imagesx($src);
            $h = imagesy($src);
            $canvas = imagecreatetruecolor($w, $h);
            if ($canvas !== false) {
                $white = imagecolorallocate($canvas, 255, 255, 255);
                imagefilledrectangle($canvas, 0, 0, $w, $h, $white);
                imagealphablending($canvas, true);
                imagecopy($canvas, $src, 0, 0, 0, 0, $w, $h);
                ob_start();
                imagejpeg($canvas, null, 90);
                $jpg = ob_get_clean();
                imagedestroy($canvas);
                imagedestroy($src);
                if (is_string($jpg) && $jpg !== '') {
                    return [
                        'width' => $w,
                        'height' => $h,
                        'colorSpace' => 'DeviceRGB',
                        'filter' => 'DCTDecode',
                        'decode' => '',
                        'data' => $jpg,
                    ];
                }
            } else {
                imagedestroy($src);
            }
        }
    }

    if (substr($data, 0, 8) === "\x89PNG\x0D\x0A\x1A\x0A") {
        $png = nefDecodificarPngRgb($data);
        return [
            'width' => $png['width'],
            'height' => $png['height'],
            'colorSpace' => 'DeviceRGB',
            'filter' => 'FlateDecode',
            'decode' => '',
            'data' => gzcompress($png['rgb'], 6),
        ];
    }

    throw new RuntimeException('El formato de ' . $name . ' no puede consolidarse en PDF en este servidor.');
}

/** @return array{width:int,height:int,rgb:string} */
function nefDecodificarPngRgb(string $data): array
{
    $offset = 8;
    $width = 0;
    $height = 0;
    $bitDepth = 0;
    $colorType = -1;
    $interlace = -1;
    $idat = '';
    $palette = '';
    $transparency = '';
    $lengthData = strlen($data);

    while (($offset + 12) <= $lengthData) {
        $len = unpack('N', substr($data, $offset, 4))[1];
        $type = substr($data, $offset + 4, 4);
        $chunk = substr($data, $offset + 8, $len);
        $offset += 12 + $len;
        if ($type === 'IHDR') {
            $parts = unpack('Nwidth/Nheight/Cbit/Ccolor/Ccompression/Cfilter/Cinterlace', $chunk);
            $width = (int) $parts['width'];
            $height = (int) $parts['height'];
            $bitDepth = (int) $parts['bit'];
            $colorType = (int) $parts['color'];
            $interlace = (int) $parts['interlace'];
        } elseif ($type === 'PLTE') {
            $palette = $chunk;
        } elseif ($type === 'tRNS') {
            $transparency = $chunk;
        } elseif ($type === 'IDAT') {
            $idat .= $chunk;
        } elseif ($type === 'IEND') {
            break;
        }
    }

    if ($width <= 0 || $height <= 0 || $idat === '') throw new RuntimeException('PNG incompleto.');
    if ($bitDepth !== 8 || $interlace !== 0 || !in_array($colorType, [0, 2, 3, 4, 6], true)) {
        throw new RuntimeException('Formato PNG no soportado para consolidacion.');
    }

    $channels = [0 => 1, 2 => 3, 3 => 1, 4 => 2, 6 => 4][$colorType];
    $stride = $width * $channels;
    $raw = function_exists('zlib_decode') ? @zlib_decode($idat) : @gzuncompress($idat);
    if (!is_string($raw)) throw new RuntimeException('No fue posible descomprimir el PNG.');

    $pos = 0;
    $previous = array_fill(0, $stride, 0);
    $rgb = '';
    for ($row = 0; $row < $height; $row++) {
        if ($pos >= strlen($raw)) throw new RuntimeException('PNG truncado.');
        $filter = ord($raw[$pos++]);
        $filtered = substr($raw, $pos, $stride);
        if (strlen($filtered) !== $stride) throw new RuntimeException('PNG truncado.');
        $pos += $stride;
        $scan = [];

        for ($i = 0; $i < $stride; $i++) {
            $x = ord($filtered[$i]);
            $left = $i >= $channels ? $scan[$i - $channels] : 0;
            $up = $previous[$i] ?? 0;
            $upLeft = $i >= $channels ? ($previous[$i - $channels] ?? 0) : 0;
            if ($filter === 1) $x = ($x + $left) & 0xFF;
            elseif ($filter === 2) $x = ($x + $up) & 0xFF;
            elseif ($filter === 3) $x = ($x + intdiv($left + $up, 2)) & 0xFF;
            elseif ($filter === 4) $x = ($x + nefPngPaeth($left, $up, $upLeft)) & 0xFF;
            elseif ($filter !== 0) throw new RuntimeException('Filtro PNG no soportado.');
            $scan[$i] = $x;
        }
        $previous = $scan;

        for ($px = 0; $px < $width; $px++) {
            $base = $px * $channels;
            $r = 255; $g = 255; $b = 255; $a = 255;
            if ($colorType === 0) {
                $r = $g = $b = $scan[$base];
            } elseif ($colorType === 2) {
                $r = $scan[$base]; $g = $scan[$base + 1]; $b = $scan[$base + 2];
            } elseif ($colorType === 3) {
                $idx = $scan[$base];
                $p = $idx * 3;
                if (($p + 2) >= strlen($palette)) throw new RuntimeException('Paleta PNG invalida.');
                $r = ord($palette[$p]); $g = ord($palette[$p + 1]); $b = ord($palette[$p + 2]);
                if ($idx < strlen($transparency)) $a = ord($transparency[$idx]);
            } elseif ($colorType === 4) {
                $r = $g = $b = $scan[$base]; $a = $scan[$base + 1];
            } else {
                $r = $scan[$base]; $g = $scan[$base + 1]; $b = $scan[$base + 2]; $a = $scan[$base + 3];
            }

            if ($a < 255) {
                $alpha = $a / 255.0;
                $r = (int) round(($r * $alpha) + (255 * (1 - $alpha)));
                $g = (int) round(($g * $alpha) + (255 * (1 - $alpha)));
                $b = (int) round(($b * $alpha) + (255 * (1 - $alpha)));
            }
            $rgb .= chr($r) . chr($g) . chr($b);
        }
    }

    return ['width' => $width, 'height' => $height, 'rgb' => $rgb];
}

function nefPngPaeth(int $a, int $b, int $c): int
{
    $p = $a + $b - $c;
    $pa = abs($p - $a);
    $pb = abs($p - $b);
    $pc = abs($p - $c);
    if ($pa <= $pb && $pa <= $pc) return $a;
    return $pb <= $pc ? $b : $c;
}

function nefPdfEscapeAscii(string $text): string
{
    $text = preg_replace('/[^\x20-\x7E]/', '', $text) ?? '';
    return str_replace(['\\', '(', ')'], ['\\\\', '\\(', '\\)'], $text);
}
