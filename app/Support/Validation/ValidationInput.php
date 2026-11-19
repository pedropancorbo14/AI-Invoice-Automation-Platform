<?php

namespace App\Support\Validation;

use App\Support\Extraction\Confidence;
use App\Support\Extraction\DocumentType;
use Carbon\CarbonImmutable;

/**
 * Todo lo que necesitan las reglas. Sin cuenta ni documento (p. ej. en
 * invoices:eval) solo se aplican las reglas que no consultan la base de datos.
 */
final readonly class ValidationInput
{
    /**
     * @param  array<string, mixed>  $data  Datos normalizados de la extracción.
     * @param  array<string, string>  $confidence  Campo → high|medium|low.
     * @param  list<array{field: string, note: string}>  $notes
     */
    public function __construct(
        public DocumentType $documentType,
        public array $data,
        public array $confidence,
        public array $notes,
        public ?string $companyTaxId,
        public ?string $companyName,
        public Confidence $minConfidence,
        public CarbonImmutable $today,
        public ?int $accountId = null,
        public ?int $companyId = null,
        public ?int $documentId = null,
    ) {}

    public function supplierTaxId(): ?string
    {
        return $this->data['supplier']['tax_id'] ?? null;
    }

    public function customerTaxId(): ?string
    {
        return $this->data['customer']['tax_id'] ?? null;
    }

    public function isSimplified(): bool
    {
        return $this->documentType === DocumentType::SimplifiedInvoice;
    }

    /**
     * Una nota de la IA sobre un campo, si la hay.
     */
    public function noteFor(string $field): ?string
    {
        foreach ($this->notes as $note) {
            if ($note['field'] === $field) {
                return $note['note'];
            }
        }

        return null;
    }
}
