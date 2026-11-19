<?php

namespace App\Support\Extraction;

final readonly class ExtractionResult
{
    /**
     * @param  array<string, mixed>  $data  Campos extraídos ya normalizados (ExtractionSchema::normalize).
     * @param  array<string, Confidence>  $confidence  Confianza por campo clave.
     * @param  list<array{field: string, note: string}>  $notes  Lo que no se lee bien y por qué.
     */
    public function __construct(
        public DocumentType $documentType,
        public array $data,
        public array $confidence,
        public array $notes,
        public string $model,
        public int $inputTokens,
        public int $outputTokens,
        public int $cacheWriteTokens,
        public int $cacheReadTokens,
        public float $costUsd,
        public int $durationMs,
    ) {}

    /**
     * @return list<string>
     */
    public function lowConfidenceFields(Confidence $atOrBelow = Confidence::Medium): array
    {
        $levels = $atOrBelow === Confidence::Low ? [Confidence::Low] : [Confidence::Medium, Confidence::Low];

        return array_keys(array_filter($this->confidence, fn (Confidence $c) => in_array($c, $levels, true)));
    }
}
