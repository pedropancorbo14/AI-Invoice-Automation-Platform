<?php

namespace App\Support\Extraction;

use App\Support\Extraction\Exceptions\UnsupportedInput;
use Smalot\PdfParser\Parser;
use Throwable;

/**
 * Decide qué se le manda al modelo y lo deja listo:
 * - PDF con capa de texto → el texto (lo más barato).
 * - PDF escaneado → el PDF entero.
 * - Foto JPG/PNG → reducida a un tamaño razonable.
 * - Foto HEIC (iPhone) → convertida a JPG, porque la API no admite HEIC.
 */
class InputPreparer
{
    public function __construct(private readonly HeicConverter $heic = new HeicConverter) {}

    public function prepare(string $contents, string $mimeType, ?string $companyName = null, ?string $companyTaxId = null): ExtractionInput
    {
        [$mode, $content, $mediaType] = match ($mimeType) {
            'application/pdf' => $this->pdf($contents),
            'image/jpeg', 'image/png' => [InputMode::Image, ...$this->image($contents, $mimeType)],
            'image/heic' => [InputMode::Image, ...$this->image($this->heic->toJpeg($contents), 'image/jpeg')],
            default => throw new UnsupportedInput("No se puede leer automáticamente un documento de tipo {$mimeType}."),
        };

        return new ExtractionInput($mode, $content, $mediaType, $companyName, $companyTaxId);
    }

    /**
     * @return array{0: InputMode, 1: string, 2: string}
     */
    private function pdf(string $contents): array
    {
        if (config('invoices.extraction.prefer_text')) {
            $text = $this->pdfText($contents);

            if (mb_strlen($text) >= (int) config('invoices.extraction.min_text_chars')) {
                return [InputMode::Text, $text, 'text/plain'];
            }
        }

        return [InputMode::Pdf, $contents, 'application/pdf'];
    }

    private function pdfText(string $contents): string
    {
        try {
            $text = (new Parser)->parseContent($contents)->getText();
        } catch (Throwable) {
            // PDF raro o cifrado: que lo lea el modelo como imagen.
            return '';
        }

        // Quitamos el ruido de espacios para no pagar tokens de más.
        $text = (string) preg_replace('/[ \t\x{00A0}]+/u', ' ', $text);
        $text = (string) preg_replace('/\n\s*\n+/', "\n\n", $text);

        return trim($text);
    }

    /**
     * Reduce la imagen si es muy grande: más píxeles no mejoran la lectura y
     * cuestan más tokens (y la API tiene un límite de tamaño por imagen).
     *
     * @return array{0: string, 1: string}
     */
    private function image(string $contents, string $mimeType): array
    {
        $maxSide = (int) config('invoices.extraction.max_image_side');
        $size = @getimagesizefromstring($contents);

        if ($size === false) {
            throw new UnsupportedInput('La imagen está dañada o no se puede abrir.');
        }

        [$width, $height] = $size;

        if (max($width, $height) <= $maxSide && strlen($contents) <= 4 * 1024 * 1024) {
            return [$contents, $mimeType];
        }

        $source = @imagecreatefromstring($contents);

        if ($source === false) {
            throw new UnsupportedInput('La imagen está dañada o no se puede abrir.');
        }

        $scale = min(1, $maxSide / max($width, $height));
        $resized = imagescale($source, max(1, (int) round($width * $scale)), max(1, (int) round($height * $scale)));

        if ($resized === false) {
            throw new UnsupportedInput('No se pudo reducir la imagen.');
        }

        ob_start();
        imagejpeg($resized, null, 85);

        return [(string) ob_get_clean(), 'image/jpeg'];
    }
}
