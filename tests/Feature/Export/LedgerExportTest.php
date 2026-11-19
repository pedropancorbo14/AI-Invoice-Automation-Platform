<?php

use App\Enums\DocumentStatus;
use App\Mail\LedgerExport;
use App\Models\Company;
use App\Models\Supplier;
use App\Support\Validation\Direction;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Mail;
use OpenSpout\Reader\XLSX\Reader;
use Tests\Support\Reviewable;

beforeEach(function () {
    $this->user = actingAsUser();
    $this->company = Company::factory()->create(['account_id' => $this->user->account_id, 'name' => 'Panadería La Espiga S.L.', 'tax_id' => 'B87654323']);

    // Recibida con IRPF y dos tipos de IVA (noviembre), proveedor con cuenta contable.
    $received = Reviewable::document($this->company, [
        'tax_breakdown' => [
            ['rate' => 21, 'base' => 100, 'tax_amount' => 21, 'surcharge_rate' => null, 'surcharge_amount' => null],
            ['rate' => 10, 'base' => 1000, 'tax_amount' => 100, 'surcharge_rate' => null, 'surcharge_amount' => null],
        ],
        'withholding' => ['rate' => 15, 'amount' => 165],
        'total' => 1056,
    ]);
    $received->direction = Direction::Received;
    $received->supplier()->associate(Supplier::factory()->create([
        'company_id' => $this->company->id, 'ledger_account' => '6230001', 'category' => 'Asesoría',
    ]));
    $received->save();
    $received->transitionTo(DocumentStatus::Validated);

    // Emitida (noviembre).
    $issued = Reviewable::document($this->company, [
        'invoice_number' => 'V-0007',
        'issue_date' => '2026-11-03',
        'supplier' => ['name' => 'Panadería La Espiga S.L.', 'tax_id' => 'B87654323'],
        'customer' => ['name' => 'Bar Pepe', 'tax_id' => 'B12345674'],
    ]);
    $issued->direction = Direction::Issued;
    $issued->save();
    $issued->transitionTo(DocumentStatus::Validated);

    // Fuera del periodo y otra pendiente: no salen.
    Reviewable::document($this->company, ['issue_date' => '2026-10-31'])->transitionTo(DocumentStatus::Validated);
    Reviewable::document($this->company);
});

it('downloads the invoice ledger as a CSV that Spanish Excel opens', function () {
    $response = $this->get("/api/companies/{$this->company->id}/export?period=2026-11&format=csv")->assertOk();

    expect($response->headers->get('content-disposition'))->toBe('attachment; filename="facturas-panaderia-la-espiga-sl-2026-11.csv"')
        ->and($response->getContent())->toStartWith("\u{FEFF}Fecha;Número;Tipo;Documento;")
        ->and(explode("\n", trim($response->getContent())))->toHaveCount(4)
        ->and($response->getContent())->toContain(
            '03/11/2026;V-0007;Emitida;Factura;"Bar Pepe";B12345674;100,00;21;21,00;;;;;121,00;EUR;;;',
            '15/11/2026;F-2026-001;Recibida;Factura;"Fontanería García S.L.";B12345674;100,00;21;21,00;;;15;165,00;1056,00;EUR;6230001;Asesoría;',
            '15/11/2026;F-2026-001;Recibida;Factura;"Fontanería García S.L.";B12345674;1000,00;10;100,00;;;;;;EUR;6230001;Asesoría;',
        );
});

it('generates a real Excel file with numeric amounts', function () {
    $response = $this->get("/api/companies/{$this->company->id}/export?period=2026-T4&format=xlsx")->assertOk();

    $path = tempnam(sys_get_temp_dir(), 'test').'.xlsx';
    File::put($path, $response->getContent());
    $reader = new Reader;
    $reader->open($path);
    $rows = [];
    foreach ($reader->getSheetIterator() as $sheet) {
        foreach ($sheet->getRowIterator() as $row) {
            $rows[] = $row->toArray();
        }
    }
    $reader->close();
    @unlink($path);

    expect($rows)->toHaveCount(5)
        ->and($rows[0][0])->toBe('Fecha')
        ->and($rows[1][0])->toBe('31/10/2026')
        ->and($rows[1][6])->toEqual(100)
        ->and($rows[1][13])->toEqual(121);
});

it('emails the ledger with the file attached', function () {
    Mail::fake();

    $this->postJson("/api/companies/{$this->company->id}/export/email", ['period' => '2026-11', 'format' => 'xlsx', 'email' => 'contable@gestoria.test'])
        ->assertOk()
        ->assertJsonPath('data.sent_to', 'contable@gestoria.test')
        ->assertJsonPath('data.rows', 3);

    Mail::assertSent(LedgerExport::class, fn (LedgerExport $mail) => $mail->hasTo('contable@gestoria.test')
        && $mail->envelope()->subject === 'Facturas de Panadería La Espiga S.L. · noviembre de 2026'
        && $mail->attachments()[0]->as === 'facturas-panaderia-la-espiga-sl-2026-11.xlsx');
});

it('sends it to the user by default', function () {
    Mail::fake();

    $this->postJson("/api/companies/{$this->company->id}/export/email", ['period' => '2026-11'])->assertOk();

    Mail::assertSent(LedgerExport::class, fn ($mail) => $mail->hasTo($this->user->email));
});

it('validates the period and format', function () {
    $this->getJson("/api/companies/{$this->company->id}/export?period=noviembre")->assertUnprocessable()->assertJsonValidationErrors('period');
    $this->getJson("/api/companies/{$this->company->id}/export?period=2026-11&format=pdf")->assertUnprocessable()->assertJsonValidationErrors('format');
});

it('cannot export a company from another account', function () {
    $foreign = Company::factory()->create();

    $this->get("/api/companies/{$foreign->id}/export?period=2026-11")->assertNotFound();
});

it('can be generated from the command line', function () {
    $dir = sys_get_temp_dir().'/ledger-'.bin2hex(random_bytes(4));

    $this->artisan('invoices:export-ledger', ['company' => $this->company->id, 'period' => '2026-11', '--output' => $dir])
        ->expectsOutputToContain('facturas-panaderia-la-espiga-sl-2026-11.csv (3 líneas)')
        ->assertSuccessful();

    expect(File::exists("{$dir}/facturas-panaderia-la-espiga-sl-2026-11.csv"))->toBeTrue();
    File::deleteDirectory($dir);
});
