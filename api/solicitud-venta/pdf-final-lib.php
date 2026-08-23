<?php

declare(strict_types=1);

/**
 * Generador autocontenido del PDF final de Solicitud de Venta.
 *
 * Objetivos del formato:
 * - apariencia de solicitud fisica (lineas, recuadros y secciones compactas);
 * - ultima pagina reservada como reverso para autorizaciones y firmas;
 * - incluir las firmas PNG reales guardadas en Expedientes_Ventas;
 * - no depender de librerias PDF externas ni modificar el runtime estable del Portal.
 */
final class SvPdfDocumento
{
    private const WIDTH = 595.28;
    private const HEIGHT = 841.89;
    private const MARGIN = 34.0;
    private const CONTENT_BOTTOM = 786.0;

    /** @var array<int,array{content:string,images:array<string,bool>}> */
    private array $pages = [];
    private string $stream = '';
    /** @var array<string,bool> */
    private array $pageImages = [];
    private float $y = 84.0;
    private string $folio;
    private bool $backPage = false;

    /** @var array<string,array{width:int,height:int,rgb:string,alpha:?string,hash?:string}> */
    private array $images = [];

    public function __construct(string $folio)
    {
        $this->folio = $folio;
        $this->newPage(false);
    }

    public function section(string $title): void
    {
        $this->ensureSpace(27.0);
        $this->rectFillStrokeTop(self::MARGIN, $this->y, self::WIDTH - (2 * self::MARGIN), 18.0, [0.92, 0.92, 0.92], [0.10, 0.10, 0.10], 0.55);
        $this->text(self::MARGIN + 7.0, $this->y + 12.5, strtoupper($title), 8.6, true, [0.08, 0.08, 0.08]);
        $this->y += 23.0;
    }

    public function subSection(string $title): void
    {
        $this->ensureSpace(22.0);
        $this->text(self::MARGIN + 2.0, $this->y + 10.0, $title, 8.4, true, [0.08, 0.08, 0.08]);
        $this->line(self::MARGIN, $this->y + 14.0, self::WIDTH - self::MARGIN, $this->y + 14.0, [0.45, 0.45, 0.45], 0.45);
        $this->y += 19.0;
    }

    public function labelValue(string $label, string $value): void
    {
        $this->fieldPair($label, $value, '', '');
    }

    public function fieldPair(string $label1, string $value1, string $label2, string $value2): void
    {
        $hasSecond = trim($label2) !== '';
        $gap = 7.0;
        $totalWidth = self::WIDTH - (2 * self::MARGIN);
        $width = $hasSecond ? (($totalWidth - $gap) / 2) : $totalWidth;

        $lines1 = $this->wrap($this->displayValue($value1), $hasSecond ? 44 : 92);
        $lines2 = $hasSecond ? $this->wrap($this->displayValue($value2), 44) : [];
        $lineCount = max(count($lines1), count($lines2));
        $height = max(31.0, 21.0 + (($lineCount - 1) * 9.5));
        $this->ensureSpace($height + 3.0);

        $this->fieldBox(self::MARGIN, $this->y, $width, $height, $label1, $lines1);
        if ($hasSecond) {
            $this->fieldBox(self::MARGIN + $width + $gap, $this->y, $width, $height, $label2, $lines2);
        }
        $this->y += $height + 3.0;
    }

    public function componentCard(array $rows, string $title): void
    {
        $this->ensureSpace(25.0);
        $this->subSection($title);
        $pairs = [];
        foreach ($rows as $row) {
            if (!is_array($row)) continue;
            $label = trim((string) ($row[0] ?? ''));
            $value = trim((string) ($row[1] ?? ''));
            if ($label === '') continue;
            $pairs[] = [$label, $value];
        }
        for ($i = 0; $i < count($pairs); $i += 2) {
            $a = $pairs[$i];
            $b = $pairs[$i + 1] ?? ['', ''];
            $this->fieldPair($a[0], $a[1], $b[0], $b[1]);
        }
    }

    public function note(string $text): void
    {
        $lines = $this->wrap(trim($text), 100);
        $height = max(18.0, (count($lines) * 10.0) + 7.0);
        $this->ensureSpace($height + 3.0);
        $this->rectFillStrokeTop(self::MARGIN, $this->y, self::WIDTH - (2 * self::MARGIN), $height, [0.98, 0.98, 0.98], [0.65, 0.65, 0.65], 0.4);
        foreach ($lines as $index => $line) {
            $this->text(self::MARGIN + 7.0, $this->y + 12.0 + ($index * 10.0), $line, 7.4, false, [0.18, 0.18, 0.18]);
        }
        $this->y += $height + 3.0;
    }

    public function checklist(array $items): void
    {
        $clean = [];
        foreach ($items as $item) {
            if (!is_array($item)) continue;
            $label = trim((string) ($item[0] ?? ''));
            if ($label === '') continue;
            $clean[] = [$label, (bool) ($item[1] ?? false)];
        }
        if (!$clean) return;

        $rows = (int) ceil(count($clean) / 2);
        $rowHeight = 17.0;
        $this->ensureSpace(($rows * $rowHeight) + 4.0);
        $gap = 12.0;
        $colW = (self::WIDTH - (2 * self::MARGIN) - $gap) / 2.0;

        foreach ($clean as $index => $item) {
            $row = intdiv($index, 2);
            $col = $index % 2;
            $x = self::MARGIN + ($col * ($colW + $gap));
            $y = $this->y + ($row * $rowHeight);
            [$label, $ok] = $item;

            $this->rectStrokeTop($x + 2.0, $y + 2.0, 9.0, 9.0, [0.15, 0.15, 0.15], 0.6);
            if ($ok) {
                $this->line($x + 4.0, $y + 7.0, $x + 6.2, $y + 9.3, [0.05, 0.05, 0.05], 1.0);
                $this->line($x + 6.2, $y + 9.3, $x + 10.0, $y + 4.0, [0.05, 0.05, 0.05], 1.0);
            }
            $this->text($x + 17.0, $y + 10.0, $this->truncate($label, 43), 7.4, false, [0.10, 0.10, 0.10]);
        }
        $this->y += ($rows * $rowHeight) + 3.0;
    }

    public function beginBackPage(): void
    {
        $this->newPage(true);
    }

    public function approvalBox(string $title, string $status, string $who, string $when): void
    {
        $this->ensureSpace(72.0);
        $x = self::MARGIN;
        $w = self::WIDTH - (2 * self::MARGIN);
        $h = 65.0;
        $this->rectStrokeTop($x, $this->y, $w, $h, [0.20, 0.20, 0.20], 0.7);
        $this->rectFillStrokeTop($x, $this->y, $w, 17.0, [0.93, 0.93, 0.93], [0.20, 0.20, 0.20], 0.55);
        $this->text($x + 7.0, $this->y + 12.0, strtoupper($title), 8.2, true, [0.08, 0.08, 0.08]);
        $this->text($x + 7.0, $this->y + 31.0, 'Estatus:', 7.3, true, [0.12, 0.12, 0.12]);
        $this->text($x + 55.0, $this->y + 31.0, $this->displayValue($status), 7.3, false, [0.12, 0.12, 0.12]);
        $this->text($x + 7.0, $this->y + 45.0, 'Autorizado por:', 7.3, true, [0.12, 0.12, 0.12]);
        $this->text($x + 79.0, $this->y + 45.0, $this->truncate($this->displayValue($who), 67), 7.3, false, [0.12, 0.12, 0.12]);
        $this->text($x + 7.0, $this->y + 58.0, 'Fecha:', 7.3, true, [0.12, 0.12, 0.12]);
        $this->text($x + 42.0, $this->y + 58.0, $this->displayValue($when), 7.3, false, [0.12, 0.12, 0.12]);
        $this->y += $h + 7.0;
    }

    public function conformityBlock(): void
    {
        $this->ensureSpace(82.0);
        $x = self::MARGIN;
        $w = self::WIDTH - (2 * self::MARGIN);
        $h = 74.0;
        $this->rectStrokeTop($x, $this->y, $w, $h, [0.22, 0.22, 0.22], 0.65);
        $this->text($x + 8.0, $this->y + 14.0, 'DECLARACION DE CONFORMIDAD', 8.3, true, [0.08, 0.08, 0.08]);
        $lines = [
            'El cliente declara que reviso la informacion de esta solicitud y manifiesta su conformidad',
            'con los datos, servicios, componentes, importes y condiciones asentados en el presente documento.',
            'Las firmas electronicas mostradas abajo forman parte del expediente digital asociado a este folio.'
        ];
        foreach ($lines as $i => $line) {
            $this->text($x + 8.0, $this->y + 31.0 + ($i * 11.0), $line, 6.7, false, [0.18, 0.18, 0.18]);
        }
        $this->y += $h + 7.0;
    }

    public function signaturePair(?string $clientPng, ?string $sellerPng, string $clientName, string $sellerName): void
    {
        $this->ensureSpace(230.0);
        $gap = 10.0;
        $total = self::WIDTH - (2 * self::MARGIN);
        $w = ($total - $gap) / 2;
        $h = 198.0;
        $x1 = self::MARGIN;
        $x2 = self::MARGIN + $w + $gap;
        $top = $this->y;

        $this->signatureBox($x1, $top, $w, $h, 'FIRMA DEL CLIENTE / TITULAR', $clientPng, $clientName);
        $this->signatureBox($x2, $top, $w, $h, 'FIRMA DEL VENDEDOR', $sellerPng, $sellerName);
        $this->y += $h + 8.0;
    }

    public function build(): string
    {
        $this->finishPage();
        if (!$this->pages) throw new RuntimeException('No hay paginas para generar el PDF.');

        $count = count($this->pages);
        foreach ($this->pages as $index => $page) {
            $pageNo = $index + 1;
            $footer = $this->pdfTextCommand(
                self::MARGIN,
                819.0,
                $this->folio . '  |  Pagina ' . $pageNo . ' de ' . $count,
                6.8,
                false,
                [0.38, 0.38, 0.38]
            );
            $this->pages[$index]['content'] = $page['content'] . $footer;
        }

        $objects = [];
        $objects[1] = '<< /Type /Catalog /Pages 2 0 R >>';
        $objects[3] = '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica /Encoding /WinAnsiEncoding >>';
        $objects[4] = '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica-Bold /Encoding /WinAnsiEncoding >>';

        $nextId = 5;
        /** @var array<string,int> $imageObjectIds */
        $imageObjectIds = [];
        foreach ($this->images as $name => $image) {
            $alphaId = null;
            if (is_string($image['alpha']) && $image['alpha'] !== '') {
                $alphaId = $nextId++;
                $alpha = $image['alpha'];
                $objects[$alphaId] = '<< /Type /XObject /Subtype /Image /Width ' . $image['width']
                    . ' /Height ' . $image['height']
                    . ' /ColorSpace /DeviceGray /BitsPerComponent 8 /Filter /FlateDecode /Length ' . strlen($alpha) . ">>\nstream\n"
                    . $alpha . "\nendstream";
            }

            $imageId = $nextId++;
            $imageObjectIds[$name] = $imageId;
            $rgb = $image['rgb'];
            $smask = $alphaId !== null ? ' /SMask ' . $alphaId . ' 0 R' : '';
            $objects[$imageId] = '<< /Type /XObject /Subtype /Image /Width ' . $image['width']
                . ' /Height ' . $image['height']
                . ' /ColorSpace /DeviceRGB /BitsPerComponent 8 /Filter /FlateDecode' . $smask
                . ' /Length ' . strlen($rgb) . ">>\nstream\n" . $rgb . "\nendstream";
        }

        $kids = [];
        foreach ($this->pages as $page) {
            $pageId = $nextId++;
            $contentId = $nextId++;
            $kids[] = $pageId . ' 0 R';

            $xObjects = [];
            foreach (array_keys($page['images']) as $name) {
                if (isset($imageObjectIds[$name])) $xObjects[] = '/' . $name . ' ' . $imageObjectIds[$name] . ' 0 R';
            }
            $xObjectResource = $xObjects ? ' /XObject << ' . implode(' ', $xObjects) . ' >>' : '';

            $objects[$pageId] = '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 ' . self::WIDTH . ' ' . self::HEIGHT . '] '
                . '/Resources << /Font << /F1 3 0 R /F2 4 0 R >>' . $xObjectResource . ' >> /Contents ' . $contentId . ' 0 R >>';
            $content = $page['content'];
            $objects[$contentId] = '<< /Length ' . strlen($content) . ">>\nstream\n" . $content . "\nendstream";
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
        $pdf .= "xref\n0 " . ($maxId + 1) . "\n";
        $pdf .= "0000000000 65535 f \n";
        for ($id = 1; $id <= $maxId; $id++) {
            $pdf .= sprintf('%010d 00000 n ', $offsets[$id] ?? 0) . "\n";
        }
        $pdf .= "trailer\n<< /Size " . ($maxId + 1) . " /Root 1 0 R >>\nstartxref\n" . $xref . "\n%%EOF";
        return $pdf;
    }

    private function newPage(bool $back): void
    {
        if ($this->stream !== '') $this->finishPage();
        $this->stream = '';
        $this->pageImages = [];
        $this->backPage = $back;
        $this->y = 84.0;

        $this->rectStrokeTop(self::MARGIN, 23.0, self::WIDTH - (2 * self::MARGIN), 52.0, [0.10, 0.10, 0.10], 0.9);
        $this->text(self::MARGIN + 10.0, 41.0, 'JARDINES DE JUAN PABLO', 10.2, true, [0.05, 0.05, 0.05]);
        $this->text(self::MARGIN + 10.0, 58.0, $back ? 'SOLICITUD DE VENTA - REVERSO' : 'SOLICITUD DE VENTA', 15.0, true, [0.05, 0.05, 0.05]);
        $this->text(self::WIDTH - self::MARGIN - 155.0, 41.0, 'FOLIO', 6.8, true, [0.25, 0.25, 0.25]);
        $this->text(self::WIDTH - self::MARGIN - 155.0, 57.0, $this->folio, 10.0, true, [0.05, 0.05, 0.05]);
        $this->text(self::WIDTH - self::MARGIN - 65.0, 69.0, 'ORIGINAL DIGITAL', 5.8, false, [0.35, 0.35, 0.35]);
    }

    private function finishPage(): void
    {
        if ($this->stream === '') return;
        $this->pages[] = ['content' => $this->stream, 'images' => $this->pageImages];
        $this->stream = '';
        $this->pageImages = [];
    }

    private function ensureSpace(float $height): void
    {
        if (($this->y + $height) <= self::CONTENT_BOTTOM) return;
        $this->newPage(false);
    }

    private function fieldBox(float $x, float $y, float $w, float $h, string $label, array $lines): void
    {
        $this->rectStrokeTop($x, $y, $w, $h, [0.36, 0.36, 0.36], 0.45);
        $this->text($x + 5.0, $y + 9.0, strtoupper(trim($label)), 5.9, true, [0.30, 0.30, 0.30]);
        foreach ($lines as $index => $line) {
            $this->text($x + 5.0, $y + 20.0 + ($index * 9.5), $line, 7.7, false, [0.07, 0.07, 0.07]);
        }
    }

    private function signatureBox(float $x, float $yTop, float $w, float $h, string $label, ?string $png, string $name): void
    {
        $this->rectStrokeTop($x, $yTop, $w, $h, [0.18, 0.18, 0.18], 0.8);
        $this->rectFillStrokeTop($x, $yTop, $w, 19.0, [0.93, 0.93, 0.93], [0.18, 0.18, 0.18], 0.55);
        $this->text($x + 7.0, $yTop + 13.0, $label, 7.5, true, [0.08, 0.08, 0.08]);

        $imageTop = $yTop + 27.0;
        $imageHeight = 118.0;
        $imageWidth = $w - 20.0;
        if (is_string($png) && $png !== '') {
            try {
                $resource = $this->registerPng($png);
                $this->drawImageFit($resource, $x + 10.0, $imageTop, $imageWidth, $imageHeight);
            } catch (Throwable $error) {
                $this->text($x + 12.0, $imageTop + 54.0, 'Firma registrada en expediente', 7.2, false, [0.30, 0.30, 0.30]);
            }
        } else {
            $this->text($x + 12.0, $imageTop + 54.0, 'Firma registrada en expediente', 7.2, false, [0.30, 0.30, 0.30]);
        }

        $lineY = $yTop + 157.0;
        $this->line($x + 16.0, $lineY, $x + $w - 16.0, $lineY, [0.20, 0.20, 0.20], 0.55);
        $this->text($x + 8.0, $lineY + 13.0, $this->truncate($this->displayValue($name), 38), 7.0, true, [0.10, 0.10, 0.10]);
        $this->text($x + 8.0, $lineY + 27.0, 'Firma almacenada electronicamente en el expediente del folio.', 5.8, false, [0.35, 0.35, 0.35]);
    }

    private function registerPng(string $data): string
    {
        $hash = sha1($data);
        foreach ($this->images as $name => $image) {
            if (($image['hash'] ?? '') === $hash) return $name;
        }
        $decoded = $this->decodePng($data);
        $name = 'Im' . (count($this->images) + 1);
        $this->images[$name] = [
            'width' => $decoded['width'],
            'height' => $decoded['height'],
            'rgb' => gzcompress($decoded['rgb'], 6),
            'alpha' => $decoded['alpha'] !== null ? gzcompress($decoded['alpha'], 6) : null,
            'hash' => $hash,
        ];
        return $name;
    }

    private function drawImageFit(string $resource, float $x, float $yTop, float $boxW, float $boxH): void
    {
        if (!isset($this->images[$resource])) return;
        $img = $this->images[$resource];
        $iw = max(1, (int) $img['width']);
        $ih = max(1, (int) $img['height']);
        $scale = min($boxW / $iw, $boxH / $ih);
        $w = $iw * $scale;
        $h = $ih * $scale;
        $dx = $x + (($boxW - $w) / 2.0);
        $dyTop = $yTop + (($boxH - $h) / 2.0);
        $pdfY = self::HEIGHT - $dyTop - $h;
        $this->pageImages[$resource] = true;
        $this->stream .= sprintf("q %.2F 0 0 %.2F %.2F %.2F cm /%s Do Q\n", $w, $h, $dx, $pdfY, $resource);
    }

    /** @return array{width:int,height:int,rgb:string,alpha:?string} */
    private function decodePng(string $data): array
    {
        if (substr($data, 0, 8) !== "\x89PNG\x0D\x0A\x1A\x0A") throw new RuntimeException('Firma PNG invalida.');
        $offset = 8;
        $width = 0;
        $height = 0;
        $bitDepth = 0;
        $colorType = -1;
        $interlace = -1;
        $idat = '';
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
            } elseif ($type === 'IDAT') {
                $idat .= $chunk;
            } elseif ($type === 'IEND') {
                break;
            }
        }
        if ($width <= 0 || $height <= 0 || $idat === '') throw new RuntimeException('Firma PNG incompleta.');
        if ($bitDepth !== 8 || $interlace !== 0 || !in_array($colorType, [0, 2, 4, 6], true)) {
            throw new RuntimeException('Formato PNG de firma no soportado.');
        }
        $raw = function_exists('zlib_decode') ? @zlib_decode($idat) : @gzuncompress($idat);
        if (!is_string($raw)) throw new RuntimeException('No fue posible descomprimir la firma PNG.');

        $channels = [0 => 1, 2 => 3, 4 => 2, 6 => 4][$colorType];
        $stride = $width * $channels;
        $pos = 0;
        $previous = array_fill(0, $stride, 0);
        $rgb = '';
        $alpha = ($colorType === 4 || $colorType === 6) ? '' : null;

        for ($row = 0; $row < $height; $row++) {
            if ($pos >= strlen($raw)) throw new RuntimeException('Firma PNG truncada.');
            $filter = ord($raw[$pos++]);
            $filtered = substr($raw, $pos, $stride);
            if (strlen($filtered) !== $stride) throw new RuntimeException('Firma PNG truncada.');
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
                elseif ($filter === 4) $x = ($x + $this->paeth($left, $up, $upLeft)) & 0xFF;
                elseif ($filter !== 0) throw new RuntimeException('Filtro PNG de firma no soportado.');
                $scan[$i] = $x;
            }
            $previous = $scan;

            for ($px = 0; $px < $width; $px++) {
                $base = $px * $channels;
                if ($colorType === 0) {
                    $g = $scan[$base];
                    $rgb .= chr($g) . chr($g) . chr($g);
                } elseif ($colorType === 2) {
                    $rgb .= chr($scan[$base]) . chr($scan[$base + 1]) . chr($scan[$base + 2]);
                } elseif ($colorType === 4) {
                    $g = $scan[$base];
                    $rgb .= chr($g) . chr($g) . chr($g);
                    $alpha .= chr($scan[$base + 1]);
                } else {
                    $rgb .= chr($scan[$base]) . chr($scan[$base + 1]) . chr($scan[$base + 2]);
                    $alpha .= chr($scan[$base + 3]);
                }
            }
        }

        return ['width' => $width, 'height' => $height, 'rgb' => $rgb, 'alpha' => $alpha];
    }

    private function paeth(int $a, int $b, int $c): int
    {
        $p = $a + $b - $c;
        $pa = abs($p - $a);
        $pb = abs($p - $b);
        $pc = abs($p - $c);
        if ($pa <= $pb && $pa <= $pc) return $a;
        return $pb <= $pc ? $b : $c;
    }

    private function text(float $x, float $yTop, string $text, float $size, bool $bold, array $color): void
    {
        $this->stream .= $this->pdfTextCommand($x, $yTop, $text, $size, $bold, $color);
    }

    private function displayValue(string $value): string
    {
        $value = trim($value);
        return $value !== '' ? $value : '-';
    }

    /** @return string[] */
    private function wrap(string $text, int $maxChars): array
    {
        $text = preg_replace('/\s+/', ' ', trim($text));
        if (!is_string($text) || $text === '') return ['-'];
        $words = preg_split('/\s+/', $text) ?: [];
        $lines = [];
        $line = '';
        foreach ($words as $word) {
            $candidate = $line === '' ? $word : $line . ' ' . $word;
            if (mb_strlen($candidate) <= $maxChars || $line === '') {
                $line = $candidate;
                continue;
            }
            $lines[] = $line;
            $line = $word;
        }
        if ($line !== '') $lines[] = $line;
        return $lines ?: ['-'];
    }

    private function truncate(string $text, int $max): string
    {
        if (mb_strlen($text) <= $max) return $text;
        return rtrim(mb_substr($text, 0, max(1, $max - 3))) . '...';
    }

    private function pdfTextCommand(float $x, float $yTop, string $text, float $size, bool $bold, array $color): string
    {
        $font = $bold ? 'F2' : 'F1';
        $y = self::HEIGHT - $yTop;
        $encoded = $this->encode($text);
        return sprintf("%.3F %.3F %.3F rg BT /%s %.2F Tf %.2F %.2F Td (%s) Tj ET\n", (float) $color[0], (float) $color[1], (float) $color[2], $font, $size, $x, $y, $encoded);
    }

    private function rectStrokeTop(float $x, float $yTop, float $width, float $height, array $color, float $lineWidth): void
    {
        $y = self::HEIGHT - $yTop - $height;
        $this->stream .= sprintf("%.3F %.3F %.3F RG %.2F w %.2F %.2F %.2F %.2F re S\n", (float) $color[0], (float) $color[1], (float) $color[2], $lineWidth, $x, $y, $width, $height);
    }

    private function rectFillStrokeTop(float $x, float $yTop, float $width, float $height, array $fill, array $stroke, float $lineWidth): void
    {
        $y = self::HEIGHT - $yTop - $height;
        $this->stream .= sprintf("%.3F %.3F %.3F rg %.3F %.3F %.3F RG %.2F w %.2F %.2F %.2F %.2F re B\n", (float) $fill[0], (float) $fill[1], (float) $fill[2], (float) $stroke[0], (float) $stroke[1], (float) $stroke[2], $lineWidth, $x, $y, $width, $height);
    }

    private function line(float $x1, float $y1Top, float $x2, float $y2Top, array $color, float $lineWidth): void
    {
        $y1 = self::HEIGHT - $y1Top;
        $y2 = self::HEIGHT - $y2Top;
        $this->stream .= sprintf("%.3F %.3F %.3F RG %.2F w %.2F %.2F m %.2F %.2F l S\n", (float) $color[0], (float) $color[1], (float) $color[2], $lineWidth, $x1, $y1, $x2, $y2);
    }

    private function encode(string $text): string
    {
        $text = str_replace(["\r", "\n", "\t"], ' ', $text);
        $encoded = false;
        if (function_exists('iconv')) $encoded = @iconv('UTF-8', 'Windows-1252//TRANSLIT//IGNORE', $text);
        if ($encoded === false && function_exists('mb_convert_encoding')) $encoded = @mb_convert_encoding($text, 'Windows-1252', 'UTF-8');
        if (!is_string($encoded)) {
            $encoded = preg_replace('/[^\x20-\x7E]/', '?', $text);
            if (!is_string($encoded)) $encoded = '';
        }
        return str_replace(['\\', '(', ')'], ['\\\\', '\\(', '\\)'], $encoded);
    }
}

/**
 * @param array<int,array<string,mixed>> $grupo
 * @param array<int,array<string,mixed>> $columnas
 * @return array{contenido:string,nombre:string,cliente:string,tipoVenta:string}
 */
function svPdfConstruirFinal(
    string $folio,
    array $grupo,
    array $columnas,
    string $cobranzaRevisor = '',
    string $cobranzaFecha = '',
    ?string $firmaCliente = null,
    ?string $firmaVendedor = null
): array {
    if (!$grupo) throw new RuntimeException('La solicitud no contiene componentes para generar el PDF.');

    usort($grupo, static function (array $a, array $b): int {
        $fa = is_array($a['fields'] ?? null) ? $a['fields'] : [];
        $fb = is_array($b['fields'] ?? null) ? $b['fields'] : [];
        return ((int) ($fa['Componente_Numero'] ?? 0)) <=> ((int) ($fb['Componente_Numero'] ?? 0));
    });

    $principal = svPdfPrincipal($grupo);
    $labels = svPdfLabels($columnas);
    $cliente = svPdfNombreCliente($principal);
    $vendedor = trim((string) ($principal['Vendedor_Nombre'] ?? ''));
    $tipoVenta = trim((string) ($principal['field_48'] ?? ''));
    $pdf = new SvPdfDocumento($folio);

    $pdf->section('Datos de la solicitud');
    $pdf->fieldPair('Folio', $folio, 'Estatus', 'APROBADA');
    $pdf->fieldPair('Fecha de solicitud', svPdfFecha((string) ($principal['field_2'] ?? '')), 'Lugar / sucursal', trim((string) ($principal['field_3'] ?? $principal['field_49'] ?? '')));
    $pdf->fieldPair('Tipo de venta', $tipoVenta, 'Vendedor', $vendedor);

    $pdf->section('Datos del titular / solicitante');
    $pdf->fieldPair('Nombre completo', $cliente, 'Correo del vendedor', trim((string) ($principal['Vendedor_Correo'] ?? '')));

    $detalle = svPdfDetalleComun($principal, $labels);
    for ($i = 0; $i < count($detalle); $i += 2) {
        $a = $detalle[$i];
        $b = $detalle[$i + 1] ?? ['etiqueta' => '', 'valor' => ''];
        $pdf->fieldPair($a['etiqueta'], $a['valor'], $b['etiqueta'], $b['valor']);
    }

    $pdf->section('Componentes de la venta');
    foreach ($grupo as $index => $item) {
        $fields = is_array($item['fields'] ?? null) ? $item['fields'] : [];
        $numero = (int) ($fields['Componente_Numero'] ?? ($index + 1));
        $tipo = trim((string) ($fields['Tipo_Componente'] ?? $fields['Tipo_Solicitud'] ?? ''));
        $rows = [
            ['Tipo de componente', $tipo],
            ['Tipo de operacion', trim((string) ($fields['field_47'] ?? ''))],
            ['Sucursal', trim((string) ($fields['field_49'] ?? ''))],
        ];
        if (strtoupper($tipo) === 'SERVICIO' || trim((string) ($fields['field_52'] ?? '')) !== '') {
            $rows[] = ['Servicio funerario', trim((string) ($fields['field_52'] ?? ''))];
            $ataud = trim((string) ($fields['field_53'] ?? ''));
            if ($ataud === '' && strtoupper(trim((string) ($fields['field_52'] ?? ''))) === 'CREMACION DIRECTA') $ataud = 'NO APLICA';
            $rows[] = ['Tipo de ataud', $ataud];
            $rows[] = ['Urna', trim((string) ($fields['field_54'] ?? ''))];
            $rows[] = ['Duracion', trim((string) ($fields['field_55'] ?? ''))];
            $rows[] = ['Clave / referencia', trim((string) ($fields['field_4'] ?? ''))];
        }
        if (strtoupper($tipo) === 'PROPIEDAD' || trim((string) ($fields['field_57'] ?? '')) !== '') {
            $rows[] = ['Tipo de propiedad', trim((string) ($fields['Propiedad_Subtipo'] ?? $fields['field_56'] ?? ''))];
            $rows[] = ['Seccion', trim((string) ($fields['field_57'] ?? ''))];
            $rows[] = ['Manzana', trim((string) ($fields['field_58'] ?? ''))];
            $rows[] = ['Lote / nicho', trim((string) ($fields['field_59'] ?? ''))];
            $rows[] = ['Clave de propiedad', trim((string) ($fields['field_60'] ?? ''))];
        }
        $rows[] = ['Precio base', svPdfMoneda($fields['Precio_Base_Componente'] ?? 0)];
        $rows[] = ['Monto asignado', svPdfMoneda($fields['Monto_Componente'] ?? 0)];
        $pdf->componentCard($rows, 'Componente ' . $numero . ($tipo !== '' ? ' - ' . $tipo : ''));
    }

    $pdf->section('Condiciones economicas');
    $pdf->fieldPair('Precio total de venta', svPdfMoneda($principal['field_63'] ?? 0), 'Forma de pago', trim((string) ($principal['field_62'] ?? '')));
    $pdf->fieldPair('Metodo de pago', trim((string) ($principal['field_69'] ?? '')), 'Tipo de venta ProcaP', $tipoVenta);

    $pdf->section('Documentacion entregada');
    $pdf->checklist([
        ['Identificacion oficial del titular - frente y reverso', svPdfBool($principal['Documento_ID_Titular'] ?? false)],
        ['Identificacion oficial del titular substituto - frente y reverso', svPdfBool($principal['Documento_ID_Sustituto'] ?? false)],
        ['Comprobante de domicilio', svPdfBool($principal['Documento_Comprobante_Domicilio'] ?? false)],
        ['Comprobante de pago', svPdfBool($principal['Documento_Comprobante_Pago'] ?? false)],
    ]);

    // REVERSO: autorizaciones, conformidad y firmas reales del expediente.
    $pdf->beginBackPage();
    $pdf->section('Autorizaciones internas');
    $pdf->approvalBox(
        'Vo.Bo. Comercial',
        strtoupper(trim((string) ($principal['VoBo_Estatus'] ?? 'APROBADO'))),
        trim((string) ($principal['VoBo_Por'] ?? '')),
        svPdfFecha((string) ($principal['VoBo_Fecha'] ?? ''))
    );

    $cobranzaPor = trim($cobranzaRevisor) !== '' ? trim($cobranzaRevisor) : trim((string) ($principal['Cobranza_Por'] ?? ''));
    $cobranzaCuando = trim($cobranzaFecha) !== '' ? trim($cobranzaFecha) : trim((string) ($principal['Cobranza_Fecha'] ?? ''));
    $pdf->approvalBox('Vo.Bo. de Cobranza', 'APROBADO', $cobranzaPor, svPdfFecha($cobranzaCuando));

    $pdf->conformityBlock();
    $pdf->section('Firmas de conformidad');
    $pdf->signaturePair($firmaCliente, $firmaVendedor, $cliente, $vendedor);

    $pdf->section('Control del documento');
    $pdf->fieldPair('Estatus final', 'APROBADA', 'Fecha de generacion', svPdfFecha(gmdate('Y-m-d\TH:i:s\Z')));
    $pdf->note('Documento final generado por el Portal Interno de Jardines de Juan Pablo. El expediente electronico conserva la documentacion y evidencias asociadas al folio.');

    return [
        'contenido' => $pdf->build(),
        'nombre' => 'SOLICITUD_FINAL_' . $folio . '.pdf',
        'cliente' => $cliente,
        'tipoVenta' => $tipoVenta,
    ];
}

/**
 * @param array<int,array<string,mixed>> $grupo
 * @param array<int,array<string,mixed>> $columnas
 * @param array<string,string> $config
 * @return array<string,mixed>
 */
function svPdfGenerarYGuardar(string $folio, array $grupo, array $columnas, string $graphToken, array $config, string $cobranzaRevisor = '', string $cobranzaFecha = ''): array
{
    $driveId = svPdfDriveExpedientes($graphToken, (string) $config['siteId']);
    svPdfAsegurarCarpeta($graphToken, $driveId, $folio);

    $firmaCliente = svPdfDescargarFirma($graphToken, $driveId, $folio, 'FIRMA_CLIENTE');
    $firmaVendedor = svPdfDescargarFirma($graphToken, $driveId, $folio, 'FIRMA_VENDEDOR');

    $documento = svPdfConstruirFinal($folio, $grupo, $columnas, $cobranzaRevisor, $cobranzaFecha, $firmaCliente, $firmaVendedor);
    $item = svPdfSubir($graphToken, $driveId, $folio, $documento['nombre'], $documento['contenido']);

    return [
        'nombre' => $documento['nombre'],
        'contenido' => $documento['contenido'],
        'cliente' => $documento['cliente'],
        'tipoVenta' => $documento['tipoVenta'],
        'driveItemId' => (string) ($item['id'] ?? ''),
        'webUrl' => (string) ($item['webUrl'] ?? ''),
        'firmaClienteIncluida' => is_string($firmaCliente) && $firmaCliente !== '',
        'firmaVendedorIncluida' => is_string($firmaVendedor) && $firmaVendedor !== '',
    ];
}

/** @return array<int,array<string,mixed>> */
function svPdfObtenerGrupo(string $graphToken, string $siteId, string $listId, string $folio): array
{
    $items = [];
    $url = 'https://graph.microsoft.com/v1.0/sites/' . rawurlencode($siteId)
        . '/lists/' . rawurlencode($listId)
        . '/items?$expand=fields&$top=200';
    $pages = 0;
    while ($url !== '' && $pages < 50) {
        $data = svCurlJson($url, 'GET', ['Authorization: Bearer ' . $graphToken, 'Accept: application/json']);
        foreach (($data['value'] ?? []) as $item) {
            if (!is_array($item)) continue;
            $fields = is_array($item['fields'] ?? null) ? $item['fields'] : [];
            $group = strtoupper(trim((string) ($fields['Solicitud_Grupo'] ?? '')));
            $title = strtoupper(trim((string) ($fields['Title'] ?? '')));
            if ($group === $folio || $title === $folio) $items[] = $item;
        }
        $url = trim((string) ($data['@odata.nextLink'] ?? ''));
        $pages++;
    }
    return $items;
}

/** @return array<int,array<string,mixed>> */
function svPdfObtenerColumnas(string $graphToken, string $siteId, string $listId): array
{
    $url = 'https://graph.microsoft.com/v1.0/sites/' . rawurlencode($siteId)
        . '/lists/' . rawurlencode($listId)
        . '/columns?$select=name,displayName,readOnly&$top=300';
    $data = svCurlJson($url, 'GET', ['Authorization: Bearer ' . $graphToken, 'Accept: application/json']);
    return is_array($data['value'] ?? null) ? $data['value'] : [];
}

/** @param array<int,array<string,mixed>> $grupo */
function svPdfPrincipal(array $grupo): array
{
    $principal = is_array($grupo[0]['fields'] ?? null) ? $grupo[0]['fields'] : [];
    foreach ($grupo as $item) {
        $fields = is_array($item['fields'] ?? null) ? $item['fields'] : [];
        if (svPdfBool($fields['Es_Principal'] ?? false) || (int) ($fields['Componente_Numero'] ?? 0) === 1) return $fields;
    }
    return $principal;
}

/** @param array<int,array<string,mixed>> $columnas @return array<string,string> */
function svPdfLabels(array $columnas): array
{
    $labels = [];
    foreach ($columnas as $column) {
        if (!is_array($column)) continue;
        $name = trim((string) ($column['name'] ?? ''));
        $display = trim((string) ($column['displayName'] ?? ''));
        if ($name !== '') $labels[$name] = $display !== '' ? $display : $name;
    }
    return $labels;
}

/** @param array<string,mixed> $fields @param array<string,string> $labels @return array<int,array{etiqueta:string,valor:string}> */
function svPdfDetalleComun(array $fields, array $labels): array
{
    $exclude = array_fill_keys([
        'id', 'ID', 'Title', 'field_1', 'field_2', 'field_3', 'field_4', 'field_47', 'field_48', 'field_49', 'field_51',
        'field_52', 'field_53', 'field_54', 'field_55', 'field_56', 'Propiedad_Subtipo', 'field_57', 'field_58', 'field_59', 'field_60',
        'field_62', 'field_63', 'field_69', 'Monto_Componente', 'Precio_Base_Componente', 'Distribucion_Tipo', 'Promocion_Nombre',
        'Solicitud_Grupo', 'Componente_Numero', 'Componente_Total', 'Tipo_Componente', 'Tipo_Solicitud', 'Es_Principal',
        'Vendedor_Nombre', 'Vendedor_Correo', 'field_102', 'field_103', 'field_104',
        'Documento_ID_Titular', 'Documento_ID_Sustituto', 'Documento_Comprobante_Domicilio', 'Documento_Comprobante_Pago',
        'VoBo_Estatus', 'VoBo_Por', 'VoBo_Fecha', 'VoBo_Motivo_Correccion', 'VoBo_Observaciones', 'Motivo_Correccion',
        'Cobranza_Estatus', 'Cobranza_Por', 'Cobranza_Fecha', 'Cobranza_Motivo_Correccion',
        'ProcaP_Numero', 'ProcaP_Estatus', 'ProcaP_Fecha', 'ProcaP_Capturado_Por',
        'ContentType', 'Modified', 'Created', 'AuthorLookupId', 'EditorLookupId', '_UIVersionString', 'Attachments', 'Edit', 'LinkTitleNoMenu', 'LinkTitle'
    ], true);

    $rows = [];
    foreach ($fields as $name => $value) {
        if (!is_string($name) || isset($exclude[$name])) continue;
        if (str_starts_with($name, '@') || str_starts_with($name, '_') || str_starts_with($name, 'ContentType')) continue;
        if (str_starts_with($name, 'ProcaP_')) continue;
        if (is_array($value) || is_object($value) || $value === null) continue;
        $text = is_bool($value) ? ($value ? 'SI' : 'NO') : trim((string) $value);
        if ($text === '') continue;
        $label = trim((string) ($labels[$name] ?? $name));
        if ($label === '' || preg_match('/^(field_\d+|id)$/i', $label)) continue;
        $rows[] = ['etiqueta' => $label, 'valor' => $text];
    }
    return $rows;
}

function svPdfNombreCliente(array $fields): string
{
    $name = trim(trim((string) ($fields['field_8'] ?? '')) . ' ' . trim((string) ($fields['field_9'] ?? '')));
    if ($name === '') return 'SIN NOMBRE';
    $clean = preg_replace('/\s+/', ' ', $name);
    return is_string($clean) ? $clean : $name;
}

function svPdfBool($value): bool
{
    if (is_bool($value)) return $value;
    if (is_numeric($value)) return (int) $value !== 0;
    return in_array(strtoupper(trim((string) $value)), ['TRUE', 'SI', 'SÍ', 'YES', '1'], true);
}

function svPdfMoneda($value): string
{
    $number = is_numeric($value) ? (float) $value : 0.0;
    return '$' . number_format($number, 2, '.', ',');
}

function svPdfFecha(string $value): string
{
    $value = trim($value);
    if ($value === '') return '-';
    try {
        $date = new DateTimeImmutable($value);
        $date = $date->setTimezone(new DateTimeZone('America/Monterrey'));
        return $date->format('d/m/Y H:i');
    } catch (Throwable $error) {
        return $value;
    }
}

function svPdfDriveExpedientes(string $graphToken, string $siteId): string
{
    $url = 'https://graph.microsoft.com/v1.0/sites/' . rawurlencode($siteId) . '/drives?$select=id,name,webUrl';
    $data = svCurlJson($url, 'GET', ['Authorization: Bearer ' . $graphToken, 'Accept: application/json']);
    foreach (($data['value'] ?? []) as $drive) {
        if (!is_array($drive)) continue;
        $name = strtolower(trim((string) ($drive['name'] ?? '')));
        if (in_array($name, ['expedientes_ventas', 'expedientes ventas'], true)) {
            $id = trim((string) ($drive['id'] ?? ''));
            if ($id !== '') return $id;
        }
    }
    throw new RuntimeException('No se encontro la biblioteca Expedientes_Ventas.');
}

function svPdfAsegurarCarpeta(string $graphToken, string $driveId, string $folio): void
{
    $checkUrl = 'https://graph.microsoft.com/v1.0/drives/' . rawurlencode($driveId) . '/root:/' . rawurlencode($folio);
    try {
        svCurlJson($checkUrl, 'GET', ['Authorization: Bearer ' . $graphToken, 'Accept: application/json']);
        return;
    } catch (Throwable $error) {
        // Se intenta crear la carpeta a continuacion.
    }

    $url = 'https://graph.microsoft.com/v1.0/drives/' . rawurlencode($driveId) . '/root/children';
    $body = json_encode(['name' => $folio, 'folder' => new stdClass(), '@microsoft.graph.conflictBehavior' => 'fail'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    try {
        svCurlJson($url, 'POST', ['Authorization: Bearer ' . $graphToken, 'Accept: application/json', 'Content-Type: application/json'], (string) $body);
    } catch (Throwable $error) {
        svCurlJson($checkUrl, 'GET', ['Authorization: Bearer ' . $graphToken, 'Accept: application/json']);
    }
}

/** @return array<string,mixed> */
function svPdfSubir(string $graphToken, string $driveId, string $folio, string $name, string $content): array
{
    $path = rawurlencode($folio) . '/' . rawurlencode($name);
    $url = 'https://graph.microsoft.com/v1.0/drives/' . rawurlencode($driveId) . '/root:/' . $path . ':/content';
    return svCurlJson($url, 'PUT', ['Authorization: Bearer ' . $graphToken, 'Accept: application/json', 'Content-Type: application/pdf'], $content);
}

function svPdfDescargarFirma(string $graphToken, string $driveId, string $folio, string $prefix): ?string
{
    $listUrl = 'https://graph.microsoft.com/v1.0/drives/' . rawurlencode($driveId) . '/root:/' . rawurlencode($folio) . ':/children?$select=id,name,file,size&$top=200';
    $data = svCurlJson($listUrl, 'GET', ['Authorization: Bearer ' . $graphToken, 'Accept: application/json']);
    $wanted = strtoupper($prefix);
    $matches = [];

    foreach (($data['value'] ?? []) as $item) {
        if (!is_array($item)) continue;
        $nameOriginal = trim((string) ($item['name'] ?? ''));
        $name = strtoupper($nameOriginal);
        $id = trim((string) ($item['id'] ?? ''));
        if ($id === '' || !str_starts_with($name, $wanted) || !str_ends_with($name, '.PNG')) continue;
        $matches[] = ['id' => $id, 'name' => $nameOriginal, 'exact' => $name === ($wanted . '.PNG')];
    }

    usort($matches, static function (array $a, array $b): int {
        if (($a['exact'] ?? false) !== ($b['exact'] ?? false)) return ($a['exact'] ?? false) ? -1 : 1;
        return strcmp((string) ($b['name'] ?? ''), (string) ($a['name'] ?? ''));
    });

    foreach ($matches as $item) {
        $url = 'https://graph.microsoft.com/v1.0/drives/' . rawurlencode($driveId) . '/items/' . rawurlencode((string) $item['id']) . '/content';
        [$status, $body] = svPdfCurlRaw($url, ['Authorization: Bearer ' . $graphToken, 'Accept: image/png']);
        if ($status < 200 || $status >= 300) continue;

        // Validar los 8 bytes reales de la firma PNG. Antes se comparaba contra
        // la cadena literal "\\x89PNG", por lo que una firma valida nunca pasaba.
        if (substr($body, 0, 8) === "\x89PNG\x0D\x0A\x1A\x0A") return $body;
    }

    return null;
}

/** @return array{0:int,1:string} */
function svPdfCurlRaw(string $url, array $headers): array
{
    $curl = curl_init($url);
    if ($curl === false) throw new RuntimeException('No fue posible inicializar cURL para obtener la firma.');
    curl_setopt_array($curl, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_CONNECTTIMEOUT => 8,
        CURLOPT_TIMEOUT => 20,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
    ]);
    $body = curl_exec($curl);
    $status = (int) curl_getinfo($curl, CURLINFO_HTTP_CODE);
    $error = curl_error($curl);
    curl_close($curl);
    if ($body === false) throw new RuntimeException('No fue posible descargar la firma: ' . $error);
    return [$status, (string) $body];
}
