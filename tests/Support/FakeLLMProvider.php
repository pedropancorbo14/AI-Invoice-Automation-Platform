<?php

namespace Tests\Support;

use App\Support\Extraction\ExtractionInput;
use App\Support\Extraction\ExtractionResult;
use App\Support\Extraction\ExtractionSchema;
use App\Support\Extraction\LLMProvider;
use Closure;
use Throwable;

/**
 * Proveedor de IA para tests: devuelve lo que se le prepare y guarda lo que recibió.
 */
class FakeLLMProvider implements LLMProvider
{
    /** @var list<ExtractionInput> */
    public array $inputs = [];

    /** @var array<string, mixed>|Throwable|Closure(ExtractionInput): (array<string, mixed>|Throwable) */
    private array|Throwable|Closure $next;

    public function __construct()
    {
        $this->next = FakeAnthropicApi::invoiceOutput();
    }

    /**
     * @param  array<string, mixed>|Throwable|Closure(ExtractionInput): (array<string, mixed>|Throwable)  $output
     */
    public function returns(array|Throwable|Closure $output): self
    {
        $this->next = $output;

        return $this;
    }

    public function extract(ExtractionInput $input): ExtractionResult
    {
        $this->inputs[] = $input;
        $output = $this->next instanceof Closure ? ($this->next)($input) : $this->next;

        if ($output instanceof Throwable) {
            throw $output;
        }

        $normalized = ExtractionSchema::normalize($output);

        return new ExtractionResult(
            documentType: $normalized['type'],
            data: $normalized['data'],
            confidence: $normalized['confidence'],
            notes: $normalized['notes'],
            model: 'fake-model',
            inputTokens: 1000,
            outputTokens: 300,
            cacheWriteTokens: 0,
            cacheReadTokens: 0,
            costUsd: 0.01,
            durationMs: 1500,
        );
    }
}
