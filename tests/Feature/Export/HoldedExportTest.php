<?php

use App\Enums\DocumentStatus;
use App\Jobs\ExportDocument;
use App\Models\Company;
use App\Models\Document;
use App\Models\DocumentExport;
use App\Support\Validation\Direction;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\Support\Files;
use Tests\Support\Reviewable;

const HOLDED = 'https://api.holded.com/api/invoicing/v1/';

beforeEach(function () {
    Storage::fake('documents');
    config(['invoices.export.enabled' => true]);

    $this->company = Company::factory()->create(['tax_id' => 'B87654323']);
    $this->company->forceFill(['holded_api_key' => 'hk_test_123'])->save();
});

/**
 * Factura recibida, revisada y validada: al validarse se exporta (cola síncrona).
 *
 * @param  array<string, mixed>  $output
 */
function validated(Company $company, array $output = [], Direction $direction = Direction::Received): Document
{
    $document = Reviewable::document($company, $output);
    Storage::disk('documents')->put($document->path, Files::invoicePdf());
    $document->direction = $direction;
    $document->save();
    $document->transitionTo(DocumentStatus::Validated);

    return $document->refresh();
}

function holdedOk(): void
{
    Http::fake([
        HOLDED.'documents/*/*/attach' => Http::response(['status' => 1, 'info' => 'Uploaded']),
        HOLDED.'documents/*' => Http::response(['status' => 1, 'info' => 'Created', 'id' => 'hd_5f1a']),
    ]);
}

it('creates the invoice in Holded as an expense, with its PDF, as soon as it is validated', function () {
    holdedOk();

    $document = validated($this->company);

    expect($document->status)->toBe(DocumentStatus::Exported)
        ->and($document->exports()->sole())
        ->target->toBe('holded')
        ->status->toBe(DocumentExport::EXPORTED)
        ->external_id->toBe('hd_5f1a');

    Http::assertSent(fn (Request $r) => $r->url() === HOLDED.'documents/purchase'
        && $r->method() === 'POST'
        && $r->hasHeader('key', 'hk_test_123')
        && $r['contactCode'] === 'B12345674'
        && $r['contactName'] === 'Fontanería García S.L.'
        && $r['invoiceNum'] === 'F-2026-001'
        && $r['date'] === Carbon\CarbonImmutable::parse('2026-11-15', 'Europe/Madrid')->getTimestamp()
        && $r['currency'] === 'eur'
        && $r['items'] === [['name' => 'Fontanería García S.L. · F-2026-001', 'units' => 1, 'subtotal' => 100.0, 'tax' => 21.0]]);

    Http::assertSent(fn (Request $r) => $r->url() === HOLDED.'documents/purchase/hd_5f1a/attach'
        && $r->isMultipart()
        && collect($r->data())->contains(fn ($part) => $part['name'] === 'file' && $part['contents'] === Files::invoicePdf()));
});

it('sends the IRPF withholding, several VAT rates, tickets and credit notes', function () {
    holdedOk();

    validated($this->company, [
        'withholding' => ['rate' => 15, 'amount' => 15],
        'tax_breakdown' => [
            ['rate' => 21, 'base' => 100, 'tax_amount' => 21, 'surcharge_rate' => null, 'surcharge_amount' => null],
            ['rate' => 10, 'base' => 50, 'tax_amount' => 5, 'surcharge_rate' => null, 'surcharge_amount' => null],
        ],
        'total' => 161,
    ]);
    validated($this->company, ['document_type' => 'simplified_invoice', 'invoice_number' => 'T-1']);
    validated($this->company, ['document_type' => 'credit_note', 'invoice_number' => 'R-1', 'tax_breakdown' => [
        ['rate' => 21, 'base' => -100, 'tax_amount' => -21, 'surcharge_rate' => null, 'surcharge_amount' => null],
    ], 'total' => -121]);

    Http::assertSent(fn (Request $r) => $r->url() === HOLDED.'documents/purchase'
        && count($r['items'] ?? []) === 2
        && $r['items'][1] === ['name' => 'Fontanería García S.L. · F-2026-001 · IVA 10.00%', 'units' => 1, 'subtotal' => 50.0, 'tax' => 10.0, 'retention' => 15.0]);
    Http::assertSent(fn (Request $r) => $r->url() === HOLDED.'documents/purchase' && ($r['isReceipt'] ?? false) === true);
    Http::assertSent(fn (Request $r) => $r->url() === HOLDED.'documents/purchaserefund' && $r['items'][0]['subtotal'] === 100.0);
});

it('stays validated when the company has no Holded connection', function () {
    Http::fake();
    $this->company->forceFill(['holded_api_key' => null])->save();

    expect(validated($this->company)->status)->toBe(DocumentStatus::Validated);
    Http::assertNothingSent();
});

it('skips issued invoices: Holded only gets expenses', function () {
    Http::fake();

    $document = validated($this->company, direction: Direction::Issued);

    expect($document->status)->toBe(DocumentStatus::Validated)
        ->and($document->exports()->sole()->status)->toBe(DocumentExport::SKIPPED);
    Http::assertNothingSent();
});

it('sends the invoice to review when the API key is no longer valid', function () {
    Http::fake([HOLDED.'*' => Http::response(['status' => 0, 'info' => 'Unauthorized'], 401)]);

    $document = validated($this->company);

    expect($document->status)->toBe(DocumentStatus::NeedsReview)
        ->and($document->status_reason)->toBe('No se pudo exportar a Holded: La API key de Holded no es válida o ha caducado.')
        ->and($document->review_codes)->toBe(['export_failed'])
        ->and($document->exports()->sole()->status)->toBe(DocumentExport::FAILED);
});

it('sends the invoice to review when Holded rejects the data', function (int $status) {
    Http::fake([HOLDED.'*' => Http::response(['status' => 0, 'info' => 'Invalid contact code'], $status)]);

    expect(validated($this->company)->status_reason)->toBe('No se pudo exportar a Holded: Holded rechazó la factura: Invalid contact code');
})->with([422, 200]);

it('does not export invoices with equivalence surcharge automatically', function () {
    Http::fake();

    $document = validated($this->company, ['tax_breakdown' => [
        ['rate' => 21, 'base' => 100, 'tax_amount' => 21, 'surcharge_rate' => 5.2, 'surcharge_amount' => 5.2],
    ], 'total' => 126.2]);

    expect($document->status)->toBe(DocumentStatus::NeedsReview)
        ->and($document->status_reason)->toContain('recargo de equivalencia');
    Http::assertNothingSent();
});

it('retries when Holded is down and gives up into review after the last attempt', function () {
    Http::fake([HOLDED.'*' => Http::response('Bad gateway', 502)]);
    config(['invoices.export.enabled' => false]);
    $document = validated($this->company);

    expect(fn () => app()->call([new ExportDocument($document->id), 'handle']))
        ->toThrow(App\Support\Export\Exceptions\ExportUnavailable::class);

    expect($document->fresh()->status)->toBe(DocumentStatus::Validated)
        ->and($document->exports()->sole())->attempts->toBe(1)->error->toBe('Holded no está disponible (502).');

    (new ExportDocument($document->id))->failed(new RuntimeException('Holded no está disponible (502).'));

    expect($document->fresh())
        ->status->toBe(DocumentStatus::NeedsReview)
        ->status_reason->toBe('No se pudo exportar tras varios intentos: Holded no está disponible (502).');
});

it('never creates the same invoice twice, even if attaching the PDF failed', function () {
    config(['invoices.export.enabled' => false]);
    $document = validated($this->company);

    Http::fakeSequence(HOLDED.'*')
        ->push(['status' => 1, 'id' => 'hd_once'])   // crear: bien
        ->push('Bad gateway', 502)                    // adjuntar: falla
        ->push(['status' => 1]);                      // reintento: solo adjuntar

    expect(fn () => app()->call([new ExportDocument($document->id), 'handle']))->toThrow(App\Support\Export\Exceptions\ExportUnavailable::class);
    app()->call([new ExportDocument($document->id), 'handle']);

    $creates = collect(Http::recorded())->filter(fn ($pair) => $pair[0]->url() === HOLDED.'documents/purchase');

    expect($creates)->toHaveCount(1)
        ->and($document->fresh()->status)->toBe(DocumentStatus::Exported)
        ->and($document->exports()->sole()->external_id)->toBe('hd_once');
});

it('does nothing for a document that was already exported', function () {
    holdedOk();
    $document = validated($this->company);
    Http::fake();

    app()->call([new ExportDocument($document->id), 'handle']);

    Http::assertNothingSent();
});

it('exports again after the problem is fixed and the invoice is approved', function () {
    // Holded acepta solo la clave nueva.
    Http::fake(fn (Request $r) => $r->hasHeader('key', 'hk_new')
        ? Http::response(['status' => 1, 'id' => 'hd_ok'])
        : Http::response(['status' => 0, 'info' => 'Unauthorized'], 401));

    $document = validated($this->company);
    expect($document->status)->toBe(DocumentStatus::NeedsReview);

    $this->company->forceFill(['holded_api_key' => 'hk_new'])->save();
    actingAsUser(App\Models\User::factory()->forAccount($this->company->account)->create());
    Illuminate\Support\Carbon::setTestNow('2026-11-20');

    $this->postJson("/api/documents/{$document->id}/approve", ['data' => Reviewable::formData()])->assertOk();

    expect($document->fresh()->status)->toBe(DocumentStatus::Exported)
        ->and($document->exports()->sole())->status->toBe(DocumentExport::EXPORTED)->external_id->toBe('hd_ok');

    Illuminate\Support\Carbon::setTestNow();
});

it('stores the API key encrypted', function () {
    $raw = DB::table('companies')->where('id', $this->company->id)->value('holded_api_key');

    expect($raw)->not->toBe('hk_test_123')
        ->and($this->company->fresh()->holded_api_key)->toBe('hk_test_123')
        ->and($this->company->toArray())->not->toHaveKey('holded_api_key');
});

describe('conexión con Holded', function () {
    it('lets admins connect and disconnect a company without ever returning the key', function () {
        actingAsAdmin($this->company->account);

        $this->putJson("/api/companies/{$this->company->id}/holded", ['api_key' => 'hk_live_abcdef123'])
            ->assertOk()
            ->assertJsonPath('data.holded_connected', true)
            ->assertJsonMissingPath('data.holded_api_key');

        expect($this->company->fresh()->holded_api_key)->toBe('hk_live_abcdef123');

        $this->deleteJson("/api/companies/{$this->company->id}/holded")->assertJsonPath('data.holded_connected', false);
    });

    it('does not let reviewers or other accounts touch it', function () {
        actingAsUser(App\Models\User::factory()->forAccount($this->company->account)->create());
        $this->putJson("/api/companies/{$this->company->id}/holded", ['api_key' => 'hk_live_abcdef123'])->assertForbidden();

        actingAsAdmin();
        $this->putJson("/api/companies/{$this->company->id}/holded", ['api_key' => 'hk_live_abcdef123'])->assertNotFound();
    });

    it('shows where a document was exported', function () {
        holdedOk();
        $document = validated($this->company);
        actingAsUser(App\Models\User::factory()->forAccount($this->company->account)->create());

        $this->getJson("/api/documents/{$document->id}")
            ->assertJsonPath('data.exports.0.target', 'holded')
            ->assertJsonPath('data.exports.0.external_id', 'hd_5f1a');
    });
});
