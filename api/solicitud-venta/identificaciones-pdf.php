<?php

declare(strict_types=1);

/**
 * Consolida las identificaciones del expediente exclusivamente para el correo final.
 * Los archivos originales de SharePoint no se modifican ni eliminan.
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
    $imagenes = [
        ['lado' => 'FRENTE', 'name' => $frenteNombre, 'data' => $frenteContenido],
        ['lado' => 'REVERSO', 'name' => $reversoNombre, 'data' => $reversoContenido],
    ];

    $objects = [];
    $objects[1] = '<< /Type /Catalog /Pages 2 0 R >>';
    $objects[3] = '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica-Bold /Encoding /WinAnsiEncoding >>';
    $objects[4] = '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica /Encoding /WinAnsiEncoding >>';

    $nextId = 5;
    $kids = [];
    foreach ($imagenes as $indice => $imagen) {
        $normalizada = nefImagenParaPdf((string) $imagen['data'], (string) $imagen['name']);
        $imageId = $nextId++;
        $contentId = $nextId++;
        $pageId = $nextId++;
        $kids[] = $pageId . ' 0 R';

        $colorSpace = (string) $normalizada['colorSpace'];
        $decode = (string) ($normalizada['decode'] ?? '');
        $filter = (string) $normalizada['filter'];
        $imageData = (string) $normalizada['data'];
        $objects[$imageId] = '<< /Type /XObject /Subtype /Image /Width ' . (int) $normalizada['width']
            . ' /Height ' . (int) $normalizada['height']
            . ' /ColorSpace /' . $colorSpace
            . ' /BitsPerComponent 8 /Filter /' . $filter
            . ($decode !== '' ? ' /Decode ' . $decode : '')
            . ' /Length ' . strlen($imageData) . ">>\nstream\n" . $imageData . "\nendstream";

        $pageW = 595.28;
        $pageH = 841.89;
        $boxW = 515.0;
        $boxH = 650.0;
        $iw = max(1, (int) $normalizada['width']);
        $ih = max(1, (int) $normalizada['height']);
        $scale = min($boxW / $iw, $boxH / $ih);
        $drawW = $iw * $scale;
        $drawH = $ih * $scale;
        $x = ($pageW - $drawW) / 2.0;
        $y = 74.0 + (($boxH - $drawH) / 2.0);

        $safeTitle = nefPdfEscapeAscii($titulo);
        $safeSide = nefPdfEscapeAscii((string) $imagen['lado'] . '  |  ' . $folio);
        $content = "0.12 0.12 0.12 rg\n"
            . "BT /F1 15 Tf 1 0 0 1 40 804 Tm (" . $safeTitle . ") Tj ET\n"
            . "BT /F2 10 Tf 1 0 0 1 40 783 Tm (" . $safeSide . ") Tj ET\n"
            . "0.72 0.72 0.72 RG 0.7 w 36 58 523 700 re S\n"
            . sprintf("q %.2F 0 0 %.2F %.2F %.2F cm /Im%d Do Q\n", $drawW, $drawH, $x, $y, $indice + 1)
            . "BT /F2 7 Tf 0.38 0.38 0.38 rg 1 0 0 1 40 34 Tm (Documento consolidado desde el expediente digital.) Tj ET\n";

        $objects[$contentId] = '<< /Length ' . strlen($content) . ">>\nstream\n" . $content . "\nendstream";
        $objects[$pageId] = '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 595.28 841.89] '
            . '/Resources << /Font << /F1 3 0 R /F2 4 0 R >> /XObject << /Im' . ($indice + 1) . ' ' . $imageId . ' 0 R >> >> '
            . '/Contents ' . $contentId . ' 0 R >>';
    }

    $objects[2] = '<< /Type /Pages /Kids [' . implode(' ', $kids) . '] /Count ' . count($kids) . ' >>';
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
        $pdf .= sprintf('%010d 00000 n ', $offsets[$id] ?? 0) . "\n";
    }
    $pdf .= "trailer\n<< /Size " . ($maxId + 1) . " /Root 1 0 R >>\nstartxref\n" . $xref . "\n%%EOF";
    return $pdf;
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
