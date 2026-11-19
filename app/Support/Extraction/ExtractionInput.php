<?php

namespace App\Support\Extraction;

final readonly class ExtractionInput
{
    /**
     * @param  string  $content  Texto (modo texto) o binario (PDF / imagen).
     */
    public function __construct(
        public InputMode $mode,
        public string $content,
        public string $mediaType,
        public ?string $companyName = null,
        public ?string $companyTaxId = null,
    ) {}
}
