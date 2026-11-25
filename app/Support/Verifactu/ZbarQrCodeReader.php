<?php

namespace App\Support\Verifactu;

use App\Support\Extraction\HeicConverter;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;
use Throwable;

/**
 * Lee los QR con zbarimg (paquete zbar-tools). Los PDF se pasan antes a
 * imagen con pdftoppm (poppler-utils), solo las primeras páginas: la norma
 * pide que el QR de VERI*FACTU vaya al principio de la factura.
 *
 * Muchos PDF generados por programas de facturación llevan además la URL del
 * QR como enlace o como texto; si está, se usa sin rasterizar nada.
 */
class ZbarQrCodeReader implements QrCodeReader
{
    private const URL_PATTERN = '~https?://[A-Za-z0-9.-]+/wlpl/TIKE-CONT/ValidarQR[A-Za-z]*\?[^\s()<>"\'\\\\]+~';

    public function __construct(private readonly HeicConverter $heic = new HeicConverter) {}

    public function read(string $contents, string $mimeType): array
    {
        try {
            return match ($mimeType) {
                'application/pdf' => $this->pdf($contents),
                'image/jpeg', 'image/png' => $this->image($contents, $mimeType === 'image/png' ? 'png' : 'jpg'),
                'image/heic' => $this->image($this->heic->toJpeg($contents), 'jpg'),
                default => [],
            };
        } catch (Throwable $e) {
            Log::warning('No se pudo leer el QR del documento: '.$e->getMessage());

            return [];
        }
    }

    /**
     * @return list<string>
     */
    private function pdf(string $contents): array
    {
        // Enlace o texto sin comprimir dentro del PDF: lo más barato.
        if (preg_match_all(self::URL_PATTERN, $contents, $m) > 0) {
            return array_values(array_unique(array_map(
                fn (string $url) => html_entity_decode($url, ENT_QUOTES | ENT_XML1),
                $m[0],
            )));
        }

        $pdftoppm = $this->binary('pdftoppm');
        $zbarimg = $this->binary('zbarimg');

        if ($pdftoppm === null || $zbarimg === null) {
            return [];
        }

        return $this->inTempDir(function (string $dir) use ($contents, $pdftoppm, $zbarimg): array {
            File::put("{$dir}/in.pdf", $contents);
            $pages = (int) config('invoices.verifactu.pdf_pages');
            $process = new Process([$pdftoppm, '-r', (string) config('invoices.verifactu.pdf_dpi'), '-f', '1', '-l', (string) max(1, $pages), '-png', "{$dir}/in.pdf", "{$dir}/page"]);
            $process->setTimeout(60)->run();

            $images = array_values(File::glob("{$dir}/page*.png"));

            return $images === [] ? [] : $this->zbar($zbarimg, $images);
        });
    }

    /**
     * @return list<string>
     */
    private function image(string $contents, string $extension): array
    {
        $zbarimg = $this->binary('zbarimg');

        if ($zbarimg === null) {
            return [];
        }

        return $this->inTempDir(function (string $dir) use ($contents, $extension, $zbarimg): array {
            File::put("{$dir}/in.{$extension}", $contents);

            return $this->zbar($zbarimg, ["{$dir}/in.{$extension}"]);
        });
    }

    /**
     * @param  list<string>  $images
     * @return list<string>
     */
    private function zbar(string $binary, array $images): array
    {
        // Solo QR (más rápido y sin falsos códigos de barras); --raw da el
        // texto tal cual, uno por línea.
        $process = new Process([$binary, '--quiet', '--raw', '-Sdisable', '-Sqrcode.enable', ...$images]);
        $process->setTimeout(60)->run();

        // zbarimg sale con 4 cuando no encuentra ningún código.
        if (! in_array($process->getExitCode(), [0, 4], true)) {
            Log::warning('zbarimg falló: '.trim($process->getErrorOutput()));
        }

        return array_values(array_unique(array_filter(array_map('trim', explode("\n", $process->getOutput())))));
    }

    /**
     * @template T
     *
     * @param  callable(string): T  $callback
     * @return T
     */
    private function inTempDir(callable $callback): mixed
    {
        $dir = sys_get_temp_dir().'/qr-'.bin2hex(random_bytes(6));
        File::ensureDirectoryExists($dir);

        try {
            return $callback($dir);
        } finally {
            File::deleteDirectory($dir);
        }
    }

    private function binary(string $name): ?string
    {
        $binary = (new ExecutableFinder)->find($name);

        if ($binary === null) {
            Log::notice("No se leen los QR de las facturas: falta {$name} en el servidor.");
        }

        return $binary;
    }
}
