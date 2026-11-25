<?php

use App\Support\Validation\RuleFailure;
use App\Support\Validation\Rules\ConfidenceRule;
use App\Support\Validation\Rules\VerifactuRule;
use App\Support\Validation\Validator;
use App\Support\Verifactu\VerifactuQr;
use Tests\Support\FakeAnthropicApi;
use Tests\Support\FakeQrCodeReader;
use Tests\Support\Validation;

/**
 * @return list<string>
 */
function qrMessages(string $rule, array $output = [], ?string $url = null): array
{
    $input = Validation::input($output, ['verifactu' => VerifactuQr::parse($url ?? FakeQrCodeReader::url())]);

    return array_map(fn (RuleFailure $f) => $f->message, app($rule)->check($input));
}

it('passes when the QR says the same as the AI', function () {
    expect(qrMessages(VerifactuRule::class))->toBe([]);
});

it('does nothing for invoices without QR', function () {
    expect(app(VerifactuRule::class)->check(Validation::input(['total' => 999])))->toBe([]);
});

it('explains every field that does not match, with both values', function () {
    expect(qrMessages(VerifactuRule::class, [], FakeQrCodeReader::url(nif: 'B87654323', number: 'F-2026-011', date: '16-11-2026', total: '1121.00')))->toBe([
        'El CIF del proveedor no coincide con el QR de VERI*FACTU: la IA leyó B12345674 y el QR dice B87654323.',
        'El número de factura no coincide con el QR de VERI*FACTU: la IA leyó F-2026-001 y el QR dice F-2026-011.',
        'La fecha de emisión no coincide con el QR de VERI*FACTU: la IA leyó 15/11/2026 y el QR dice 16/11/2026.',
        'El total no coincide con el QR de VERI*FACTU: la IA leyó 121,00 € y el QR dice 1.121,00 €.',
    ]);
});

it('warns about QR codes of the AEAT test environment', function () {
    expect(qrMessages(VerifactuRule::class, [], FakeQrCodeReader::url(host: 'prewww2.aeat.es')))
        ->toBe(['El QR de VERI*FACTU apunta al entorno de pruebas de la AEAT: una factura real no lo lleva.']);
});

describe('con la regla de confianza', function () {
    $doubtful = fn () => [
        'confidence' => array_replace(FakeAnthropicApi::invoiceOutput()['confidence'], ['total' => 'low', 'invoice_number' => 'medium', 'customer_tax_id' => 'low']),
    ];

    it('does not doubt what the QR confirms', function () use ($doubtful) {
        // El CIF del cliente no va en el QR: esa duda se mantiene.
        expect(qrMessages(ConfidenceRule::class, $doubtful()))->toBe(['Dato dudoso en CIF del cliente.']);
    });

    it('keeps doubting a field the QR contradicts', function () use ($doubtful) {
        // El número sí lo confirma el QR; el total no.
        expect(qrMessages(ConfidenceRule::class, $doubtful(), FakeQrCodeReader::url(total: '130.00')))
            ->toBe(['Dato dudoso en CIF del cliente.', 'Dato dudoso en total.']);
    });

    it('lets a doubtful invoice through when the QR confirms its key fields', function () {
        $output = ['confidence' => array_replace(FakeAnthropicApi::invoiceOutput()['confidence'], ['total' => 'low', 'issue_date' => 'medium'])];

        expect((new Validator)->validate(Validation::input($output))->rules())->toBe(['confidence'])
            ->and((new Validator)->validate(Validation::input($output, ['verifactu' => VerifactuQr::parse(FakeQrCodeReader::url())]))->passed())->toBeTrue();
    });
});
