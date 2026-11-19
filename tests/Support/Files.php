<?php

namespace Tests\Support;

/**
 * Ficheros de prueba con contenido real (magic bytes correctos).
 */
class Files
{
    /** @var array<string, string> */
    private static array $cache = [];

    public static function pdf(string $seed = 'factura'): string
    {
        return "%PDF-1.7\n% {$seed}\n".str_repeat("0 0 obj << /Seed ({$seed}) >> endobj\n", 200)."%%EOF\n";
    }

    /** Foto de móvil: grande y con algo de ruido. */
    public static function photo(int $width = 1200, int $height = 1600, string $format = 'jpeg'): string
    {
        return self::$cache["{$width}x{$height}.{$format}"] ??= self::render($width, $height, $format);
    }

    private static function render(int $width, int $height, string $format): string
    {
        $image = imagecreatetruecolor($width, $height);
        mt_srand(crc32("{$width}x{$height}{$format}"));

        for ($i = 0; $i < 300; $i++) {
            $color = imagecolorallocate($image, mt_rand(0, 255), mt_rand(0, 255), mt_rand(0, 255));
            imagefilledrectangle($image, mt_rand(0, $width), mt_rand(0, $height), mt_rand(0, $width), mt_rand(0, $height), (int) $color);
        }

        ob_start();
        $format === 'png' ? imagepng($image) : imagejpeg($image, null, 90);

        return (string) ob_get_clean();
    }

    /** Logo de firma: pequeño en peso y en tamaño. */
    public static function logo(): string
    {
        $image = imagecreatetruecolor(120, 40);
        imagefill($image, 0, 0, (int) imagecolorallocate($image, 0, 90, 200));

        ob_start();
        imagepng($image);

        return (string) ob_get_clean();
    }

    /** Banner ancho pero bajito: pesa lo suficiente pero no es una factura. */
    public static function banner(): string
    {
        return self::photo(1400, 120);
    }

    /**
     * PDF válido con capa de texto real (una línea por elemento de $lines).
     *
     * @param  list<string>  $lines
     */
    public static function textPdf(array $lines): string
    {
        $stream = "BT /F1 11 Tf 50 800 Td 14 TL\n";
        foreach ($lines as $line) {
            $stream .= '('.addcslashes($line, '()\\').") Tj T*\n";
        }
        $stream .= 'ET';

        $objects = [
            '<< /Type /Catalog /Pages 2 0 R >>',
            '<< /Type /Pages /Kids [3 0 R] /Count 1 >>',
            '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 595 842] /Contents 4 0 R /Resources << /Font << /F1 5 0 R >> >> >>',
            '<< /Length '.strlen($stream)." >>\nstream\n{$stream}\nendstream",
            '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica /Encoding /WinAnsiEncoding >>',
        ];

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

    /** Factura de ejemplo en texto, con datos coherentes. */
    public static function invoicePdf(string $number = 'F-2026-001'): string
    {
        return self::textPdf([
            'FONTANERIA GARCIA S.L.  CIF B12345674',
            'Calle Mayor 1, 28001 Madrid',
            "FACTURA N.: {$number}",
            'Fecha: 15/11/2026   Vencimiento: 15/12/2026',
            'Cliente: Panaderia La Espiga S.L.  CIF B87654323',
            'Reparacion de caldera ............ 100,00',
            'Base imponible: 100,00 EUR',
            'IVA 21%: 21,00 EUR',
            'TOTAL FACTURA: 121,00 EUR',
        ]);
    }

    public static function heic(): string
    {
        return "\x00\x00\x00\x18ftypheic\x00\x00\x00\x00mif1heic".random_bytes(60 * 1024);
    }
}
