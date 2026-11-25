<?php

namespace Tests\Support;

use App\Support\Demo\SamplePdf;
use chillerlan\QRCode\Output\QROutputInterface;
use chillerlan\QRCode\QRCode;
use chillerlan\QRCode\QROptions;

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
        return SamplePdf::render($lines);
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

    /**
     * Imagen con un QR de verdad que contiene $text, como la de una factura
     * escaneada o fotografiada.
     */
    public static function qrImage(string $text, string $format = 'png'): string
    {
        return (new QRCode(new QROptions([
            'outputType' => $format === 'png' ? QROutputInterface::GDIMAGE_PNG : QROutputInterface::GDIMAGE_JPG,
            'outputBase64' => false,
            'scale' => 6,
        ])))->render($text);
    }

    /**
     * Factura en PDF con el QR solo como imagen (la URL no aparece en el
     * fichero): para leerla hay que pasar la página a imagen.
     */
    public static function invoicePdfWithQr(string $url): string
    {
        return SamplePdf::render([
            'FONTANERIA GARCIA S.L.  CIF B12345674',
            'FACTURA N.: F-2026-001   Fecha: 15/11/2026',
            'TOTAL FACTURA: 121,00 EUR',
        ], self::qrImage($url, 'jpg'));
    }

    public static function heic(): string
    {
        return "\x00\x00\x00\x18ftypheic\x00\x00\x00\x00mif1heic".random_bytes(60 * 1024);
    }
}
