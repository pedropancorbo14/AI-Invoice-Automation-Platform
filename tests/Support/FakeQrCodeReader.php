<?php

namespace Tests\Support;

use App\Support\Verifactu\QrCodeReader;

/**
 * Lector de QR para tests: devuelve los textos que se le preparen (por
 * defecto, ninguno) sin tocar zbarimg ni pdftoppm.
 */
class FakeQrCodeReader implements QrCodeReader
{
    /** @var list<string> */
    private array $texts = [];

    /** @var list<string> Tipo MIME de cada documento leído. */
    public array $reads = [];

    /**
     * @param  list<string>  $texts
     */
    public function returns(array $texts): self
    {
        $this->texts = $texts;

        return $this;
    }

    public function read(string $contents, string $mimeType): array
    {
        $this->reads[] = $mimeType;

        return $this->texts;
    }

    /** URL de un QR de VERI*FACTU como la imprime un programa de facturación. */
    public static function url(
        string $nif = 'B12345674',
        string $number = 'F-2026-001',
        string $date = '15-11-2026',
        string $total = '121.00',
        string $host = 'www2.agenciatributaria.gob.es',
        string $path = 'ValidarQR',
    ): string {
        return "https://{$host}/wlpl/TIKE-CONT/{$path}?".http_build_query([
            'nif' => $nif, 'numserie' => $number, 'fecha' => $date, 'importe' => $total,
        ], encoding_type: PHP_QUERY_RFC3986);
    }
}
