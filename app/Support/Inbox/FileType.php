<?php

namespace App\Support\Inbox;

/**
 * Tipos de fichero que aceptamos, detectados por su contenido (magic bytes),
 * no por la extensión ni el Content-Type que manda el cliente de correo.
 */
enum FileType: string
{
    case Pdf = 'pdf';
    case Jpeg = 'jpg';
    case Png = 'png';
    case Heic = 'heic';

    public static function detect(string $content): ?self
    {
        return match (true) {
            str_starts_with(ltrim(substr($content, 0, 1024)), '%PDF-') => self::Pdf,
            str_starts_with($content, "\xFF\xD8\xFF") => self::Jpeg,
            str_starts_with($content, "\x89PNG\r\n\x1A\n") => self::Png,
            self::isHeic($content) => self::Heic,
            default => null,
        };
    }

    public function mimeType(): string
    {
        return match ($this) {
            self::Pdf => 'application/pdf',
            self::Jpeg => 'image/jpeg',
            self::Png => 'image/png',
            self::Heic => 'image/heic',
        };
    }

    public function extension(): string
    {
        return $this->value;
    }

    public function isImage(): bool
    {
        return $this !== self::Pdf;
    }

    /**
     * HEIC/HEIF (fotos del iPhone): caja "ftyp" con una marca HEIF.
     */
    private static function isHeic(string $content): bool
    {
        if (substr($content, 4, 4) !== 'ftyp') {
            return false;
        }

        return in_array(substr($content, 8, 4), ['heic', 'heix', 'hevc', 'hevx', 'heim', 'heis', 'mif1', 'msf1'], true);
    }
}
