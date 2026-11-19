<?php

use App\Support\Extraction\Exceptions\ExtractionFailed;
use App\Support\Extraction\ExtractionInput;
use Illuminate\Support\Facades\File;
use Tests\Support\FakeAnthropicApi;
use Tests\Support\Files;

beforeEach(function () {
    $this->dataset = sys_get_temp_dir().'/eval-'.bin2hex(random_bytes(4));
    File::ensureDirectoryExists($this->dataset);
    File::deleteDirectory(storage_path('app/eval/reports'));

    $truth = fn (array $expected, array $extra = []) => json_encode(['expected' => $expected] + $extra);
    $base = [
        'supplier' => ['tax_id' => 'B12345674'],
        'issue_date' => '2026-11-15',
        'tax_breakdown' => [['rate' => 21, 'base' => 100, 'tax_amount' => 21]],
        'total' => 121,
    ];

    // a: todo bien. b: el total anotado es otro. c: foto que en realidad no es factura.
    File::put("{$this->dataset}/a.pdf", Files::invoicePdf('A-1'));
    File::put("{$this->dataset}/a.json", $truth($base, ['tags' => ['pdf-digital'], 'company' => ['name' => 'Panadería', 'tax_id' => 'B87654323']]));
    File::put("{$this->dataset}/b.pdf", Files::invoicePdf('B-1'));
    File::put("{$this->dataset}/b.json", $truth(['total' => 130] + $base));
    File::put("{$this->dataset}/c.jpg", Files::photo());
    File::put("{$this->dataset}/c.json", $truth(['document_type' => 'other']));
    File::put("{$this->dataset}/notas.txt", 'no es un caso');
});

afterEach(function () {
    File::deleteDirectory($this->dataset);
    File::deleteDirectory(storage_path('app/eval/reports'));
});

function latestReport(): array
{
    $files = File::files(storage_path('app/eval/reports'));

    return json_decode(File::get(end($files)->getPathname()), true);
}

it('measures accuracy per field, cost and time', function () {
    $this->artisan('invoices:eval', ['--dataset' => $this->dataset, '--yes' => true])
        ->expectsOutputToContain('3 facturas · modelo claude-opus-5-5 · esfuerzo medium')
        ->expectsOutputToContain('Por debajo del objetivo: total')
        ->assertFailed();

    $summary = latestReport()['summary'];

    expect($summary['cases'])->toBe(3)
        ->and($summary['fields']['supplier_tax_id'])->toMatchArray(['correct' => 2, 'total' => 2, 'accuracy' => 100.0, 'key' => true])
        ->and($summary['fields']['total'])->toMatchArray(['correct' => 1, 'total' => 2, 'accuracy' => 50.0])
        ->and($summary['fields']['document_type'])->toMatchArray(['correct' => 0, 'total' => 1])
        ->and($summary['key_fields_below_target'])->toBe(['total'])
        ->and($summary['avg_cost_usd'])->toBe(0.01)
        ->and($summary['avg_duration_ms'])->toBe(1500)
        ->and($summary['modes'])->toBe(['text' => 2, 'image' => 1]);
});

it('passes the company context of each case to the model', function () {
    $this->artisan('invoices:eval', ['--dataset' => $this->dataset, '--only' => ['a'], '--yes' => true])->assertSuccessful();

    expect($this->llm->inputs[0]->companyTaxId)->toBe('B87654323');
});

it('passes when every key field reaches the target', function () {
    $this->artisan('invoices:eval', ['--dataset' => $this->dataset, '--only' => ['a'], '--yes' => true])
        ->expectsOutputToContain('Los campos clave superan el 95 %')
        ->assertSuccessful();
});

it('counts technical errors without stopping', function () {
    $this->llm->returns(fn (ExtractionInput $input) => $input->mode->value === 'image'
        ? new ExtractionFailed('PDF dañado')
        : FakeAnthropicApi::invoiceOutput());

    $this->artisan('invoices:eval', ['--dataset' => $this->dataset, '--yes' => true])->assertFailed();

    expect(latestReport()['summary']['errors'])->toBe(1)
        ->and(latestReport()['results'][2]['error'])->toBe('ExtractionFailed: PDF dañado');
});

it('can override the model and effort to compare them', function () {
    $this->artisan('invoices:eval', [
        '--dataset' => $this->dataset, '--model' => 'claude-haiku-5-5', '--effort' => 'low', '--limit' => 1, '--yes' => true,
    ])->expectsOutputToContain('1 facturas · modelo claude-haiku-5-5 · esfuerzo low');

    expect(File::files(storage_path('app/eval/reports'))[0]->getFilename())->toContain('claude-haiku-5-5-low');
});

it('asks before spending money', function () {
    $this->artisan('invoices:eval', ['--dataset' => $this->dataset])
        ->expectsConfirmation('Cada factura es una llamada de pago a la API. ¿Continuar?', 'no')
        ->assertSuccessful();

    expect($this->llm->inputs)->toBeEmpty();
});

it('explains an empty or missing dataset', function () {
    $empty = sys_get_temp_dir().'/eval-empty-'.bin2hex(random_bytes(4));
    File::ensureDirectoryExists($empty);

    $this->artisan('invoices:eval', ['--dataset' => $empty, '--yes' => true])
        ->expectsOutputToContain('El dataset está vacío')
        ->assertExitCode(2);

    $this->artisan('invoices:eval', ['--dataset' => '/no/existe', '--yes' => true])
        ->expectsOutputToContain('No existe la carpeta del dataset')
        ->assertExitCode(2);

    File::deleteDirectory($empty);
});

it('measures what the phase 4 rules would do', function () {
    // b: la IA lee 121 (coherente) pero la verdad es 130 → pasaría solo: falso aprobado.
    $this->artisan('invoices:eval', ['--dataset' => $this->dataset, '--yes' => true])
        ->expectsOutputToContain('Hay facturas con datos mal que pasarían sin revisión: b')
        ->assertFailed();

    $validation = latestReport()['summary']['validation'];

    expect($validation['cases'])->toBe(3)
        ->and($validation['false_accepts'])->toBe(['b'])
        ->and($validation['auto_rate'])->toEqual(100)
        ->and(latestReport()['results'][0]['validation'])->toMatchArray(['passed' => true, 'key_correct' => true]);
});

it('counts the errors the rules catch', function () {
    // La IA se equivoca en el total y la aritmética lo detecta.
    $this->llm->returns(FakeAnthropicApi::invoiceOutput(['total' => 130]));

    $this->artisan('invoices:eval', ['--dataset' => $this->dataset, '--only' => ['a'], '--yes' => true])
        ->expectsOutputToContain('Ninguna factura con datos mal pasaría sola')
        ->assertFailed();

    $validation = latestReport()['summary']['validation'];

    expect($validation['caught_errors'])->toBe(1)
        ->and($validation['auto_rate'])->toEqual(0)
        ->and($validation['rules'])->toBe(['arithmetic' => 1])
        ->and(latestReport()['results'][0]['validation']['reason'])->toBe('El total no cuadra: 130,00 ≠ 100,00 + 21,00.');
});

it('leaves documents decided by their type out of the rule metrics', function () {
    $this->llm->returns(FakeAnthropicApi::invoiceOutput(['document_type' => 'quote']));

    $this->artisan('invoices:eval', ['--dataset' => $this->dataset, '--only' => ['a'], '--yes' => true]);

    expect(latestReport()['summary']['validation']['cases'])->toBe(0)
        ->and(latestReport()['results'][0]['validation'])->toBeNull();
});
