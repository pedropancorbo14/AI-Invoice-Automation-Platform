<?php

namespace App\Support\Demo;

/**
 * PDF mínimo pero válido, con capa de texto real (una línea por elemento).
 * Para la cuenta demo y los tests: facturas ficticias sin ficheros binarios.
 * Opcionalmente lleva una imagen JPEG arriba a la derecha (p. ej. un QR).
 */
class SamplePdf
{
    /**
     * @param  list<string>  $lines
     */
    public static function render(array $lines, ?string $jpeg = null): string
    {
        $stream = "BT /F1 11 Tf 50 800 Td 16 TL\n";
        foreach ($lines as $line) {
            $text = (string) iconv('UTF-8', 'Windows-1252//TRANSLIT', $line);
            $stream .= '('.addcslashes($text, '()\\').") Tj T*\n";
        }
        $stream .= 'ET';

        $size = $jpeg !== null ? getimagesizefromstring($jpeg) : false;
        if ($size !== false) {
            // 120 x 120 puntos (unos 4 cm), arriba a la derecha.
            $stream .= "\nq 120 0 0 120 425 700 cm /Im1 Do Q";
        }

        $objects = [
            '<< /Type /Catalog /Pages 2 0 R >>',
            '<< /Type /Pages /Kids [3 0 R] /Count 1 >>',
            '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 595 842] /Contents 4 0 R /Resources << /Font << /F1 5 0 R >>'
                .($size !== false ? ' /XObject << /Im1 6 0 R >>' : '').' >> >>',
            '<< /Length '.strlen($stream)." >>\nstream\n{$stream}\nendstream",
            '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica /Encoding /WinAnsiEncoding >>',
        ];

        if ($size !== false) {
            $objects[] = "<< /Type /XObject /Subtype /Image /Width {$size[0]} /Height {$size[1]} /ColorSpace /DeviceRGB"
                .' /BitsPerComponent 8 /Filter /DCTDecode /Length '.strlen($jpeg)." >>\nstream\n{$jpeg}\nendstream";
        }

        $pdf = "%PDF-1.4\n";
        $offsets = [];
        foreach ($objects as $i => $object) {
            $offsets[] = strlen($pdf);
            $pdf .= ($i + 1)." 0 obj\n{$object}\nendobj\n";
        }

        $xref = strlen($pdf);
        $pdf .= "xref\n0 ".(count($objects) + 1)."\n0000000000 65535 f \n";
        foreach ($offsets as $offset) {
            $pdf .= sprintf("%010d 00000 n \n", $offset);
        }

        return $pdf.'trailer << /Size '.(count($objects) + 1)." /Root 1 0 R >>\nstartxref\n{$xref}\n%%EOF\n";
    }
}
