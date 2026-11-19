<?php

namespace Tests\Support;

use App\Models\Company;
use App\Models\Document;
use App\Support\Extraction\Confidence;
use App\Support\Extraction\ExtractionSchema;
use App\Support\Extraction\InputMode;

/**
 * Documentos pendientes de revisión, con su extracción, para los tests.
 */
class Reviewable
{
    /**
     * @param  array<string, mixed>  $output  Cambios sobre la respuesta del modelo.
     * @param  list<string>  $codes
     */
    public static function document(Company $company, array $output = [], array $codes = ['confidence'], string $reason = 'Dato dudoso en total.'): Document
    {
        $document = Document::factory()->forCompany($company)->create();
        $normalized = ExtractionSchema::normalize(FakeAnthropicApi::invoiceOutput($output));

        $document->extractions()->create([
            'account_id' => $company->account_id,
            'model' => 'fake-model',
            'input_mode' => InputMode::Text,
            'document_type' => $normalized['type'],
            'data' => $normalized['data'],
            'confidence' => array_map(fn (Confidence $c) => $c->value, $normalized['confidence']),
            'notes' => $normalized['notes'],
            'input_tokens' => 1000,
            'output_tokens' => 300,
            'cost_usd' => 0.01,
            'duration_ms' => 1500,
        ]);

        $document->sendToReview($reason, $codes);

        return $document->refresh();
    }

    /**
     * Datos tal como los manda el panel al aprobar.
     *
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    public static function formData(array $overrides = []): array
    {
        $data = ExtractionSchema::normalize(FakeAnthropicApi::invoiceOutput())['data'];

        return array_replace($data, $overrides);
    }
}
