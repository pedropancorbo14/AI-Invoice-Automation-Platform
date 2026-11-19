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

    public static function heic(): string
    {
        return "\x00\x00\x00\x18ftypheic\x00\x00\x00\x00mif1heic".random_bytes(60 * 1024);
    }
}
