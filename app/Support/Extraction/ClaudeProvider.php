<?php

namespace App\Support\Extraction;

use Anthropic\Beta\Messages\BetaBase64ImageSource;
use Anthropic\Beta\Messages\BetaBase64ImageSource\MediaType;
use Anthropic\Beta\Messages\BetaBase64PDFSource;
use Anthropic\Beta\Messages\BetaCacheControlEphemeral;
use Anthropic\Beta\Messages\BetaImageBlockParam;
use Anthropic\Beta\Messages\BetaMessage;
use Anthropic\Beta\Messages\BetaOutputConfig\Effort;
use Anthropic\Beta\Messages\BetaRequestDocumentBlock;
use Anthropic\Beta\Messages\BetaTextBlockParam;
use Anthropic\Client;
use Anthropic\Core\Exceptions\AuthenticationException;
use Anthropic\Core\Exceptions\BadRequestException;
use Anthropic\Core\Exceptions\ContentTooLargeException;
use Anthropic\Core\Exceptions\NotFoundException;
use Anthropic\Core\Exceptions\PaymentRequiredException;
use Anthropic\Core\Exceptions\PermissionDeniedException;
use Anthropic\Core\Exceptions\UnprocessableEntityException;
use App\Support\Extraction\Exceptions\ExtractionFailed;
use App\Support\Extraction\Exceptions\ExtractionRefused;
use Illuminate\Support\Str;

/**
 * Extracción con Claude (API de Anthropic) y salida estructurada.
 *
 * - El esquema garantiza un JSON válido; ExtractionSchema lo normaliza.
 * - Las instrucciones fijas van en el system con caché.
 * - Fallback en el servidor: si el modelo rechaza la petición por sus
 *   salvaguardas, la API la reintenta en el modelo recomendado.
 * - Los errores 429/5xx/red se relanzan tal cual: los reintenta la cola.
 */
class ClaudeProvider implements LLMProvider
{
    private const FALLBACK_BETA = 'server-side-fallback-2026-07-01';

    /**
     * @param  array<string, mixed>  $config  config('invoices.extraction')
     */
    public function __construct(
        private readonly Client $client,
        private readonly array $config,
    ) {}

    public function extract(ExtractionInput $input): ExtractionResult
    {
        $start = hrtime(true);

        try {
            $message = $this->client->beta->messages->create(
                model: (string) $this->config['model'],
                maxTokens: (int) $this->config['max_tokens'],
                system: [BetaTextBlockParam::with(
                    text: ExtractionSchema::systemPrompt(),
                    cacheControl: BetaCacheControlEphemeral::with(),
                )],
                messages: [['role' => 'user', 'content' => $this->content($input)]],
                outputConfig: [
                    'effort' => $this->effort(),
                    'format' => ['type' => 'json_schema', 'schema' => ExtractionSchema::jsonSchema()],
                ],
                fallbacks: 'default',
                betas: [self::FALLBACK_BETA],
                requestOptions: ['timeout' => (float) $this->config['timeout']],
            );
        } catch (BadRequestException|AuthenticationException|PermissionDeniedException|NotFoundException|UnprocessableEntityException|ContentTooLargeException|PaymentRequiredException $e) {
            throw new ExtractionFailed('La API de Claude rechazó la petición: '.Str::limit($e->getMessage(), 300), previous: $e);
        }

        $durationMs = (int) round((hrtime(true) - $start) / 1_000_000);

        return $this->result($message, $durationMs);
    }

    private function effort(): Effort
    {
        $effort = (string) $this->config['effort'];

        return Effort::tryFrom($effort)
            ?? throw new ExtractionFailed("EXTRACTION_EFFORT no es válido: «{$effort}» (low, medium, high, xhigh o max).");
    }

    /**
     * @return list<BetaTextBlockParam|BetaRequestDocumentBlock|BetaImageBlockParam>
     */
    private function content(ExtractionInput $input): array
    {
        $document = match ($input->mode) {
            InputMode::Text => BetaTextBlockParam::with(text: "Texto extraído del PDF:\n\n".$input->content),
            InputMode::Pdf => BetaRequestDocumentBlock::with(source: BetaBase64PDFSource::with(data: base64_encode($input->content))),
            InputMode::Image => BetaImageBlockParam::with(source: BetaBase64ImageSource::with(
                data: base64_encode($input->content),
                mediaType: MediaType::tryFrom($input->mediaType)
                    ?? throw new ExtractionFailed("Tipo de imagen no admitido por la API: {$input->mediaType}."),
            )),
        };

        return [$document, BetaTextBlockParam::with(text: ExtractionSchema::userPrompt($input))];
    }

    private function result(BetaMessage $message, int $durationMs): ExtractionResult
    {
        // Siempre se mira stop_reason antes de leer el contenido.
        if ($message->stopReason === 'refusal') {
            $category = $message->stopDetails?->category;

            throw new ExtractionRefused('El modelo no ha querido procesar el documento'.($category ? " ({$category})." : '.'));
        }

        if ($message->stopReason === 'max_tokens') {
            throw new ExtractionFailed('La respuesta del modelo se cortó por longitud (max_tokens).');
        }

        $json = null;
        foreach ($message->content as $block) {
            if ($block->type === 'text' && property_exists($block, 'text')) {
                $json = json_decode((string) $block->text, true);
                break;
            }
        }

        if (! is_array($json)) {
            throw new ExtractionFailed('La respuesta del modelo no es un JSON válido.');
        }

        $normalized = ExtractionSchema::normalize($json);
        $usage = $message->usage;
        $cacheWrite = (int) $usage->cacheCreationInputTokens;
        $cacheRead = (int) $usage->cacheReadInputTokens;

        // Si actuó el fallback, la respuesta trae el modelo que contestó de verdad.
        $model = CostCalculator::knows($message->model) ? $message->model : (string) $this->config['model'];

        return new ExtractionResult(
            documentType: $normalized['type'],
            data: $normalized['data'],
            confidence: $normalized['confidence'],
            notes: $normalized['notes'],
            model: $message->model,
            inputTokens: $usage->inputTokens,
            outputTokens: $usage->outputTokens,
            cacheWriteTokens: $cacheWrite,
            cacheReadTokens: $cacheRead,
            costUsd: CostCalculator::cost($model, $usage->inputTokens, $usage->outputTokens, $cacheWrite, $cacheRead),
            durationMs: $durationMs,
        );
    }
}
