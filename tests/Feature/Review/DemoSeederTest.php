<?php

use App\Enums\DocumentStatus;
use App\Models\Document;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Support\Facades\Storage;

it('seeds a demo account where some invoices pass and others wait for review', function () {
    Storage::fake('documents');

    $this->seed(DatabaseSeeder::class);

    $documents = Document::withoutGlobalScopes()->orderBy('id')->get();
    $codes = $documents->mapWithKeys(fn (Document $d) => [$d->original_filename => $d->review_codes]);

    expect($documents->where('status', DocumentStatus::Validated))->toHaveCount(2)
        ->and($documents->where('status', DocumentStatus::NeedsReview))->toHaveCount(5)
        ->and($codes['luz-octubre.pdf'])->toBe(['arithmetic', 'confidence'])
        ->and($codes['Factura F-2026-001.pdf'])->toBe(['new_supplier'])
        ->and($codes['recambios-norte.pdf'])->toContain('dates')
        ->and($codes['correo.eml'])->toBe(['link_invoice'])
        ->and($codes['albaran-559.pdf'])->toBe(['not_invoice']);

    foreach ($documents as $document) {
        Storage::disk('documents')->assertExists($document->path);
    }
});
