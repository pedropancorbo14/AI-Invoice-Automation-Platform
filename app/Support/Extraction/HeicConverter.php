<?php

namespace App\Support\Extraction;

use App\Support\Extraction\Exceptions\UnsupportedInput;
use Illuminate\Support\Facades\File;
use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;

/**
 * Convierte las fotos HEIC del iPhone a JPG con heif-convert (paquete
 * libheif-examples, ya en la imagen Docker): ni la API ni el lector de QR
 * entienden HEIC.
 */
class HeicConverter
{
    /**
     * @throws UnsupportedInput
     */
    public function toJpeg(string $contents): string
    {
        $binary = (new ExecutableFinder)->find('heif-convert');

        if ($binary === null) {
            throw new UnsupportedInput('Las fotos HEIC necesitan heif-convert (libheif-examples) instalado en el servidor.');
        }

        $dir = sys_get_temp_dir().'/heic-'.bin2hex(random_bytes(6));
        File::ensureDirectoryExists($dir);

        try {
            File::put("{$dir}/in.heic", $contents);
            $process = new Process([$binary, '-q', '90', "{$dir}/in.heic", "{$dir}/out.jpg"]);
            $process->setTimeout(60)->run();

            if (! $process->isSuccessful() || ! File::exists("{$dir}/out.jpg")) {
                throw new UnsupportedInput('No se pudo convertir la foto HEIC a JPG.');
            }

            return File::get("{$dir}/out.jpg");
        } finally {
            File::deleteDirectory($dir);
        }
    }
}
