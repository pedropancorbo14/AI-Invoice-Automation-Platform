<?php

use App\Support\Demo\SamplePdf;
use App\Support\Verifactu\ZbarQrCodeReader;
use Symfony\Component\Process\ExecutableFinder;
use Tests\Support\FakeQrCodeReader;
use Tests\Support\Files;

/**
 * El lector de verdad, con zbarimg y pdftoppm (en la imagen Docker y en la
 * CI). Sin ellos, los tests que los necesitan se saltan.
 */
$missing = fn (string ...$binaries) => collect($binaries)->contains(fn (string $b) => (new ExecutableFinder)->find($b) === null);

it('reads the QR of a photo', function (string $format, string $mime) {
    $url = FakeQrCodeReader::url(number: 'A/2026/0001');

    expect((new ZbarQrCodeReader)->read(Files::qrImage($url, $format), $mime))->toBe([$url]);
})->with([['png', 'image/png'], ['jpg', 'image/jpeg']])
    ->skip(fn () => $missing('zbarimg'), 'falta zbarimg');

it('reads the QR of a PDF that only has it as an image', function () {
    $url = FakeQrCodeReader::url();
    $pdf = Files::invoicePdfWithQr($url);

    expect($pdf)->not->toContain('ValidarQR')
        ->and((new ZbarQrCodeReader)->read($pdf, 'application/pdf'))->toBe([$url]);
})->skip(fn () => $missing('zbarimg', 'pdftoppm'), 'faltan zbarimg o pdftoppm');

it('finds the URL inside the PDF without rasterizing it', function () {
    $url = FakeQrCodeReader::url();
    $pdf = SamplePdf::render(['FACTURA F-2026-001', "Factura verificable en la sede electronica de la AEAT: {$url}"]);

    expect((new ZbarQrCodeReader)->read($pdf, 'application/pdf'))->toBe([$url]);
});

it('returns nothing for documents without QR or that cannot be read', function () {
    $reader = new ZbarQrCodeReader;

    expect($reader->read(Files::invoicePdf(), 'application/pdf'))->toBe([])
        ->and($reader->read('esto no es una imagen', 'image/png'))->toBe([])
        ->and($reader->read(Files::pdf(), 'application/zip'))->toBe([]);
});
