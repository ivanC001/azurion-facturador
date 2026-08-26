<?php

namespace App\Infrastructure\Pdf;

use BaconQrCode\Common\ErrorCorrectionLevel;
use BaconQrCode\Encoder\Encoder;

/**
 * Lienzo de bajo nivel para escribir PDF 1.4 a mano.
 *
 * Se ocupa unicamente del dibujo y del ensamblado del fichero: no sabe nada de
 * comprobantes, importes ni SUNAT. Estaba mezclado con la maquetacion dentro de
 * SimpleDocumentPdfGenerator, que asi cargaba a la vez con las reglas fiscales
 * y con la sintaxis del formato PDF.
 *
 * El origen de coordenadas del PDF esta abajo a la izquierda; toda la API de
 * esta clase recibe la Y medida desde arriba ($yTop) y hace la conversion, que
 * es como razona el codigo de maquetacion.
 */
final class PdfCanvas
{
    private float $pageHeight;

    /**
     * Imagenes JPEG registradas para incrustarse como XObject del PDF.
     *
     * @var array<int, array{name: string, width: int, height: int, data: string}>
     */
    private array $imageXObjects = [];

    public function __construct(float $pageHeight)
    {
        $this->pageHeight = $pageHeight;
    }

    /**
     * Cambia el alto de pagina activo: los formatos ticket calculan su alto
     * en tiempo de render y necesitan reajustar el origen de coordenadas.
     */
    public function setPageHeight(float $pageHeight): void
    {
        $this->pageHeight = $pageHeight;
    }

    public function pageHeight(): float
    {
        return $this->pageHeight;
    }

    /**
     * Registra una imagen JPEG y devuelve su nombre de recurso PDF.
     *
     * @return array{name: string, width: int, height: int}
     */
    public function registerImage(string $jpegData, int $width, int $height): array
    {
        $name = 'Im'.(count($this->imageXObjects) + 1);
        $this->imageXObjects[] = [
            'name' => $name,
            'width' => $width,
            'height' => $height,
            'data' => $jpegData,
        ];

        return ['name' => $name, 'width' => $width, 'height' => $height];
    }

    /**
     * @param  array<int, string>  $commands
     * @param  array<int, int>  $strokeRgb
     * @param  array<int, int>  $fillRgb
     */
    public function drawBox(array &$commands, float $x, float $yTop, float $w, float $h, array $strokeRgb, array $fillRgb, float $lineWidth): void
    {
        $y = $this->pageHeight - $yTop - $h;
        $stroke = $this->rgb($strokeRgb);
        $fill = $this->rgb($fillRgb);

        $commands[] = sprintf(
            '%.3f %.3f %.3f rg %.3f %.3f %.3f RG %.2f w %.2f %.2f %.2f %.2f re B',
            $fill[0],
            $fill[1],
            $fill[2],
            $stroke[0],
            $stroke[1],
            $stroke[2],
            $lineWidth,
            $x,
            $y,
            $w,
            $h
        );
    }

    /**
     * @param  array<int, string>  $commands
     * @param  array<int, int>  $strokeRgb
     * @param  array<int, int>  $fillRgb
     */
    public function drawRect(array &$commands, float $x, float $yTop, float $w, float $h, array $strokeRgb, array $fillRgb, float $lineWidth): void
    {
        $this->drawBox($commands, $x, $yTop, $w, $h, $strokeRgb, $fillRgb, $lineWidth);
    }

    /**
     * @param  array<int, string>  $commands
     * @param  array<int, int>  $strokeRgb
     */
    public function drawLine(array &$commands, float $x1, float $yTop1, float $x2, float $yTop2, array $strokeRgb, float $lineWidth): void
    {
        $y1 = $this->pageHeight - $yTop1;
        $y2 = $this->pageHeight - $yTop2;
        $stroke = $this->rgb($strokeRgb);

        $commands[] = sprintf(
            '%.3f %.3f %.3f RG %.2f w %.2f %.2f m %.2f %.2f l S',
            $stroke[0],
            $stroke[1],
            $stroke[2],
            $lineWidth,
            $x1,
            $y1,
            $x2,
            $y2
        );
    }

    /**
     * @param  array<int, string>  $commands
     * @param  array<int, int>  $rgb
     */
    public function drawText(
        array &$commands,
        float $x,
        float $yTop,
        string $text,
        float $size = 9,
        bool $bold = false,
        array $rgb = [17, 24, 39],
        string $align = 'left'
    ): void {
        $ascii = PdfTextMetrics::ascii($text);
        $safeText = PdfTextMetrics::escapePdfText($ascii);
        $font = $bold ? 'F2' : 'F1';

        if ($align === 'right') {
            $x -= PdfTextMetrics::estimateTextWidth($ascii, $size);
        } elseif ($align === 'center') {
            $x -= PdfTextMetrics::estimateTextWidth($ascii, $size) / 2;
        }

        $color = $this->rgb($rgb);
        $y = $this->pageHeight - $yTop;

        $commands[] = sprintf(
            'BT /%s %.2f Tf %.3f %.3f %.3f rg 1 0 0 1 %.2f %.2f Tm (%s) Tj ET',
            $font,
            $size,
            $color[0],
            $color[1],
            $color[2],
            $x,
            $y,
            $safeText
        );
    }

    /**
     * Ajusta solo la tipografia, nunca recorta importes ni cantidades.
     * Esto mantiene cada valor dentro de su columna incluso en papel de 80 mm.
     *
     * @param  array<int, string>  $commands
     * @param  array<int, int>  $rgb
     */
    public function drawTextWithinWidth(
        array &$commands,
        float $x,
        float $yTop,
        string $text,
        float $maxWidth,
        float $preferredSize,
        bool $bold = false,
        array $rgb = [17, 24, 39],
        string $align = 'left',
    ): void {
        $fontSize = $preferredSize;
        $estimatedWidth = PdfTextMetrics::estimateTextWidth(PdfTextMetrics::ascii($text), $preferredSize);
        if ($estimatedWidth > $maxWidth && $estimatedWidth > 0.0) {
            $fontSize = floor(($preferredSize * $maxWidth / $estimatedWidth) * 100) / 100;
            $fontSize = max(1.0, $fontSize);
            while ($fontSize > 1.0 && PdfTextMetrics::estimateTextWidth(PdfTextMetrics::ascii($text), $fontSize) > $maxWidth) {
                $fontSize = round($fontSize - 0.01, 2);
            }
        }

        $this->drawText($commands, $x, $yTop, $text, $fontSize, $bold, $rgb, $align);
    }

    /**
     * Dibuja el QR modulo a modulo. Un contenido invalido no debe impedir la
     * emision del comprobante, asi que el fallo se traga y el recuadro queda
     * simplemente vacio.
     *
     * @param  array<int, string>  $commands
     */
    public function drawQrCode(array &$commands, string $content, float $x, float $yTop, float $size): void
    {
        $payload = trim($content);
        if ($payload === '') {
            return;
        }

        try {
            $matrix = Encoder::encode($payload, ErrorCorrectionLevel::M(), 'UTF-8', null, false)->getMatrix();
        } catch (\Throwable) {
            return;
        }

        $quietZone = 4;
        $modules = $matrix->getWidth() + ($quietZone * 2);
        $moduleSize = $size / max(1, $modules);
        $this->drawBox($commands, $x, $yTop, $size, $size, [255, 255, 255], [255, 255, 255], 0.0);

        for ($row = 0; $row < $matrix->getHeight(); $row++) {
            for ($column = 0; $column < $matrix->getWidth(); $column++) {
                if ($matrix->get($column, $row) !== 1) {
                    continue;
                }

                $rectX = $x + (($column + $quietZone) * $moduleSize);
                $rectYTop = $yTop + (($row + $quietZone) * $moduleSize);
                $rectY = $this->pageHeight - $rectYTop - $moduleSize;
                // El sobreancho evita costuras blancas entre modulos al render.
                $commands[] = sprintf(
                    '0 0 0 rg %.3f %.3f %.3f %.3f re f',
                    $rectX,
                    $rectY,
                    $moduleSize + 0.04,
                    $moduleSize + 0.04
                );
            }
        }
    }

    /**
     * Dibuja una imagen ya registrada con registerImage().
     *
     * @param  array<int, string>  $commands
     */
    public function drawImage(array &$commands, string $imageName, float $x, float $yTop, float $width, float $height): void
    {
        $y = $this->pageHeight - $yTop - $height;

        $commands[] = sprintf(
            'q %.2f 0 0 %.2f %.2f %.2f cm /%s Do Q',
            $width,
            $height,
            $x,
            $y,
            $imageName
        );
    }

    /**
     * Convierte RGB 0-255 al rango 0-1 que usa el operador de color del PDF.
     *
     * @param  array<int, int>  $rgb
     * @return array{0: float, 1: float, 2: float}
     */
    public function rgb(array $rgb): array
    {
        return [
            max(0.0, min(1.0, ((int) ($rgb[0] ?? 0)) / 255)),
            max(0.0, min(1.0, ((int) ($rgb[1] ?? 0)) / 255)),
            max(0.0, min(1.0, ((int) ($rgb[2] ?? 0)) / 255)),
        ];
    }

    /**
     * Ensambla el fichero PDF completo: catalogo, pagina, fuentes, imagenes,
     * flujo de contenido y tabla xref.
     *
     * @param  array<int, string>  $commands
     */
    public function buildDocument(string $content, float $pageWidth, float $pageHeight): string
    {
        $imageResourceParts = [];
        foreach ($this->imageXObjects as $index => $image) {
            $imageResourceParts[] = '/'.$image['name'].' '.(6 + $index).' 0 R';
        }

        $contentObjectId = 6 + count($this->imageXObjects);
        $xObjectResource = $imageResourceParts !== []
            ? ' /XObject << '.implode(' ', $imageResourceParts).' >>'
            : '';

        $objects = [];
        $objects[] = '<< /Type /Catalog /Pages 2 0 R >>';
        $objects[] = '<< /Type /Pages /Kids [3 0 R] /Count 1 >>';
        $objects[] = '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 '.$pageWidth.' '.$pageHeight.'] /Resources << /Font << /F1 4 0 R /F2 5 0 R >>'.$xObjectResource.' >> /Contents '.$contentObjectId.' 0 R >>';
        $objects[] = '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>';
        $objects[] = '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica-Bold >>';

        foreach ($this->imageXObjects as $image) {
            $objects[] = '<< /Type /XObject /Subtype /Image /Width '.$image['width'].' /Height '.$image['height'].' /ColorSpace /DeviceRGB /BitsPerComponent 8 /Filter /DCTDecode /Length '.strlen($image['data'])." >>\nstream\n".$image['data']."\nendstream";
        }

        $objects[] = '<< /Length '.strlen($content)." >>\nstream\n".$content.'endstream';

        $pdf = "%PDF-1.4\n";
        $offsets = [0];

        foreach ($objects as $index => $object) {
            $offsets[] = strlen($pdf);
            $pdf .= ($index + 1)." 0 obj\n".$object."\nendobj\n";
        }

        $xrefOffset = strlen($pdf);
        $pdf .= "xref\n";
        $pdf .= '0 '.(count($objects) + 1)."\n";
        $pdf .= "0000000000 65535 f \n";

        for ($index = 1; $index <= count($objects); $index++) {
            $pdf .= sprintf("%010d 00000 n \n", $offsets[$index]);
        }

        $pdf .= 'trailer << /Size '.(count($objects) + 1).' /Root 1 0 R >>'."\n";
        $pdf .= "startxref\n".$xrefOffset."\n%%EOF";

        return $pdf;
    }
}
