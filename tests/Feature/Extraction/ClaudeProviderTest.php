<?php

use Anthropic\Core\Exceptions\RateLimitException;
use App\Support\Extraction\ClaudeProvider;
use App\Support\Extraction\Confidence;
use App\Support\Extraction\DocumentType;
use App\Support\Extraction\Exceptions\ExtractionFailed;
use App\Support\Extraction\Exceptions\ExtractionRefused;
use App\Support\Extraction\ExtractionInput;
use App\Support\Extraction\InputMode;
use Tests\Support\FakeAnthropicApi;
use Tests\Support\Files;

beforeEach(function () {
    $this->api = new FakeAnthropicApi;
    $this->provider = new ClaudeProvider($this->api->client(), array_replace(config('invoices.extraction'), [
        'model' => 'claude-opus-5-5',
        'effort' => 'medium',
    ]));
});

function textInput(): ExtractionInput
{
    return new ExtractionInput(InputMode::Text, 'FACTURA F-2026-001 ...', 'text/plain', 'Panadería La Espiga S.L.', 'B87654323');
}

it('sends a structured-output request with fallbacks and a cached system prompt', function () {
    $this->api->respondWith(FakeAnthropicApi::invoiceOutput());

    $this->provider->extract(textInput());

    $body = $this->api->lastRequestBody();

    expect($body['model'])->toBe('claude-opus-5-5')
        ->and($body['max_tokens'])->toBe(16000)
        ->and($body['fallbacks'])->toBe('default')
        ->and($body['output_config']['effort'])->toBe('medium')
        ->and($body['output_config']['format']['type'])->toBe('json_schema')
        ->and($body['output_config']['format']['schema']['required'])->toContain('confidence', 'tax_breakdown', 'total')
        ->and($body['system'][0]['cache_control'])->toBe(['type' => 'ephemeral'])
        ->and($body['messages'][0]['content'][0]['text'])->toStartWith('Texto extraído del PDF:')
        ->and($body['messages'][0]['content'][1]['text'])->toContain('CIF B87654323')
        ->and($body)->not->toHaveKey('thinking')
        ->and($this->api->lastRequestHeaders()['anthropic-beta'][0])->toContain('server-side-fallback-2026-07-01');
});

it('sends scanned PDFs as a document block', function () {
    $this->api->respondWith(FakeAnthropicApi::invoiceOutput());

    $this->provider->extract(new ExtractionInput(InputMode::Pdf, Files::pdf(), 'application/pdf'));

    $block = $this->api->lastRequestBody()['messages'][0]['content'][0];

    expect($block['type'])->toBe('document')
        ->and($block['source'])->toMatchArray(['type' => 'base64', 'media_type' => 'application/pdf'])
        ->and(base64_decode($block['source']['data']))->toBe(Files::pdf());
});

it('sends photos as an image block', function () {
    $this->api->respondWith(FakeAnthropicApi::invoiceOutput());

    $this->provider->extract(new ExtractionInput(InputMode::Image, Files::photo(), 'image/jpeg'));

    $block = $this->api->lastRequestBody()['messages'][0]['content'][0];

    expect($block['type'])->toBe('image')
        ->and($block['source']['media_type'])->toBe('image/jpeg');
});

it('returns normalized data, confidence, usage and cost', function () {
    $this->api->respondWith(FakeAnthropicApi::invoiceOutput([
        'confidence' => array_replace(FakeAnthropicApi::invoiceOutput()['confidence'], ['total' => 'low']),
        'notes' => [['field' => 'total', 'note' => 'El total está tachado.']],
    ]));

    $result = $this->provider->extract(textInput());

    expect($result->documentType)->toBe(DocumentType::Invoice)
        ->and($result->data['supplier']['tax_id'])->toBe('B12345674')
        ->and($result->data['total'])->toBe('121.00')
        ->and($result->data['tax_breakdown'][0])->toMatchArray(['rate' => '21.00', 'base' => '100.00', 'tax_amount' => '21.00'])
        ->and($result->confidence['total'])->toBe(Confidence::Low)
        ->and($result->lowConfidenceFields())->toBe(['total'])
        ->and($result->notes)->toBe([['field' => 'total', 'note' => 'El total está tachado.']])
        ->and($result->model)->toBe('claude-opus-5-5')
        ->and($result->inputTokens)->toBe(1000)
        ->and($result->outputTokens)->toBe(400)
        ->and($result->cacheReadTokens)->toBe(1500)
        // 1000 × 4 + 400 × 20 + 1500 × 0,20 = 12 300 por millón → 0,0123 $
        ->and($result->costUsd)->toBe(0.0123);
});

it('turns a refusal into ExtractionRefused instead of reading the content', function () {
    $this->api->respondRaw([
        'id' => 'msg_r', 'type' => 'message', 'role' => 'assistant', 'model' => 'claude-opus-5-5',
        'content' => [], 'stop_reason' => 'refusal', 'stop_sequence' => null,
        'stop_details' => ['type' => 'refusal', 'category' => null, 'explanation' => null],
        'usage' => ['input_tokens' => 10, 'output_tokens' => 0],
    ]);

    $this->provider->extract(textInput());
})->throws(ExtractionRefused::class);

it('fails without retrying when the response was cut off', function () {
    $this->api->respondWith(FakeAnthropicApi::invoiceOutput(), ['stop_reason' => 'max_tokens']);

    $this->provider->extract(textInput());
})->throws(ExtractionFailed::class, 'max_tokens');

it('fails without retrying on a 400 from the API', function () {
    $this->api->respondRaw(['type' => 'error', 'error' => ['type' => 'invalid_request_error', 'message' => 'PDF dañado']], 400);

    $this->provider->extract(textInput());
})->throws(ExtractionFailed::class, 'La API de Claude rechazó la petición');

it('lets rate limits bubble up so the queue retries them', function () {
    $this->api->respondRaw(['type' => 'error', 'error' => ['type' => 'rate_limit_error', 'message' => 'Despacio']], 429);

    $this->provider->extract(textInput());
})->throws(RateLimitException::class);

it('rejects an invalid effort setting', function () {
    $provider = new ClaudeProvider($this->api->client(), array_replace(config('invoices.extraction'), ['effort' => 'turbo']));

    $provider->extract(textInput());
})->throws(ExtractionFailed::class, 'EXTRACTION_EFFORT');
