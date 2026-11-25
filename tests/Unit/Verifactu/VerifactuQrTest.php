<?php

use App\Support\Verifactu\VerifactuQr;
use Tests\Support\FakeQrCodeReader;

describe('parse', function () {
    it('reads the example URL of the AEAT specification', function () {
        $qr = VerifactuQr::parse('https://www2.agenciatributaria.gob.es/wlpl/TIKE-CONT/ValidarQR?nif=89890001K&numserie=12345678-G33&fecha=01-09-2024&importe=241.4');

        expect($qr?->toArray())->toBe([
            'tax_id' => '89890001K',
            'number' => '12345678-G33',
            'date' => '2024-09-01',
            'total' => '241.40',
            'verifactu' => true,
            'test_environment' => false,
        ]);
    });

    it('decodes the invoice number and accepts "No VERI*FACTU" systems', function () {
        $qr = VerifactuQr::parse(FakeQrCodeReader::url(number: 'A/2026 0001', path: 'ValidarQRNoVerifactu'));

        expect($qr?->number)->toBe('A/2026 0001')
            ->and($qr?->verifactu)->toBeFalse();
    });

    it('flags the test environment of the AEAT', function () {
        expect(VerifactuQr::parse(FakeQrCodeReader::url(host: 'prewww2.aeat.es'))?->testEnvironment)->toBeTrue();
    });

    it('keeps negative totals of credit notes', function () {
        expect(VerifactuQr::parse(FakeQrCodeReader::url(total: '-121'))?->total)->toBe('-121.00');
    });

    it('rejects anything that is not an invoice QR of the AEAT', function (string $text) {
        expect(VerifactuQr::parse($text))->toBeNull();
    })->with([
        'otro dominio' => ['https://agenciatributaria.example.com/wlpl/TIKE-CONT/ValidarQR?nif=B12345674&numserie=1&fecha=15-11-2026&importe=1'],
        'otra ruta' => ['https://www2.agenciatributaria.gob.es/otra?nif=B12345674&numserie=1&fecha=15-11-2026&importe=1'],
        'sin importe' => ['https://www2.agenciatributaria.gob.es/wlpl/TIKE-CONT/ValidarQR?nif=B12345674&numserie=1&fecha=15-11-2026'],
        'fecha imposible' => [FakeQrCodeReader::url(date: '31-02-2026')],
        'fecha ISO' => [FakeQrCodeReader::url(date: '2026-11-15')],
        'importe con coma' => [FakeQrCodeReader::url(total: '121,00')],
        'texto suelto' => ['Gracias por su compra'],
        'TicketBAI' => ['https://batuz.eus/QRTBAI/?id=TBAI-B12345674-151126-abc-123&s=A&nf=1&i=121.00&cr=123'],
    ]);

    it('takes the first invoice QR among several codes', function () {
        $qr = VerifactuQr::first(['https://tienda.example.com/encuesta', FakeQrCodeReader::url(number: 'F-9')]);

        expect($qr?->number)->toBe('F-9');
    });

    it('round-trips through what is stored', function () {
        $qr = VerifactuQr::parse(FakeQrCodeReader::url());

        expect(VerifactuQr::fromArray($qr?->toArray()))->toEqual($qr)
            ->and(VerifactuQr::fromArray(null))->toBeNull();
    });
});

describe('compare', function () {
    $data = fn (array $overrides = []) => array_replace([
        'supplier' => ['name' => 'Fontanería García S.L.', 'tax_id' => 'B-12345674'],
        'invoice_number' => 'F-2026-001',
        'issue_date' => '2026-11-15',
        'total' => '121.00',
    ], $overrides);

    it('confirms every field that matches, ignoring formatting of the tax id and the number', function () use ($data) {
        $qr = VerifactuQr::parse(FakeQrCodeReader::url(number: 'F 2026/001'));

        expect($qr?->compare($data()))->toBe(['supplier_tax_id' => true, 'invoice_number' => true, 'issue_date' => true, 'total' => true])
            ->and($qr?->confirmedFields($data()))->toBe(['supplier_tax_id', 'invoice_number', 'issue_date', 'total']);
    });

    it('spots the fields that differ and the ones that were not read', function () use ($data) {
        $qr = VerifactuQr::parse(FakeQrCodeReader::url());

        expect($qr?->compare($data(['total' => '112.00', 'issue_date' => null, 'invoice_number' => 'F-2026-007'])))
            ->toBe(['supplier_tax_id' => true, 'invoice_number' => false, 'issue_date' => null, 'total' => false]);
    });
});
