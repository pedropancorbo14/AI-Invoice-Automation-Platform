<?php

namespace Tests\Support;

use Anthropic\Client;
use GuzzleHttp\Client as Guzzle;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;

/**
 * API de Anthropic simulada a nivel HTTP: comprueba el JSON exacto que
 * manda el SDK y devuelve respuestas preparadas. No sale nada a internet.
 */
class FakeAnthropicApi
{
    public MockHandler $handler;

    /** @var list<array{request: \Psr\Http\Message\RequestInterface}> */
    public array $history = [];

    public function __construct()
    {
        $this->handler = new MockHandler;
    }

    public function client(): Client
    {
        $stack = HandlerStack::create($this->handler);
        $stack->push(Middleware::history($this->history));

        return new Client(apiKey: 'sk-test', baseUrl: 'https://api.anthropic.test', requestOptions: [
            'transporter' => new Guzzle(['handler' => $stack]),
            'maxRetries' => 0,
        ]);
    }

    /**
     * @param  array<string, mixed>  $output  Lo que "contesta" el modelo (se serializa a JSON).
     * @param  array<string, mixed>  $overrides
     */
    public function respondWith(array $output, array $overrides = []): self
    {
        return $this->respondRaw(array_replace([
            'id' => 'msg_test',
            'type' => 'message',
            'role' => 'assistant',
            'model' => 'claude-opus-5-5',
            'content' => [['type' => 'text', 'text' => json_encode($output)]],
            'stop_reason' => 'end_turn',
            'stop_sequence' => null,
            'usage' => [
                'input_tokens' => 1000,
                'output_tokens' => 400,
                'cache_creation_input_tokens' => 0,
                'cache_read_input_tokens' => 1500,
            ],
        ], $overrides));
    }

    /**
     * @param  array<string, mixed>  $body
     */
    public function respondRaw(array $body, int $status = 200): self
    {
        $this->handler->append(new Response($status, ['Content-Type' => 'application/json'], (string) json_encode($body)));

        return $this;
    }

    /**
     * @return array<string, mixed>
     */
    public function lastRequestBody(): array
    {
        $request = end($this->history)['request'];

        return json_decode((string) $request->getBody(), true);
    }

    /**
     * @return array<string, list<string>>
     */
    public function lastRequestHeaders(): array
    {
        return end($this->history)['request']->getHeaders();
    }

    /**
     * Respuesta válida de una factura sencilla de 121,00 €.
     *
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    public static function invoiceOutput(array $overrides = []): array
    {
        return array_replace([
            'document_type' => 'invoice',
            'supplier' => ['name' => 'Fontanería García S.L.', 'tax_id' => 'B-12345674'],
            'customer' => ['name' => 'Panadería La Espiga S.L.', 'tax_id' => 'B87654321'],
            'invoice_number' => 'F-2026-001',
            'issue_date' => '2026-11-15',
            'due_date' => '2026-12-15',
            'currency' => 'EUR',
            'tax_breakdown' => [['rate' => 21, 'base' => 100, 'tax_amount' => 21, 'surcharge_rate' => null, 'surcharge_amount' => null]],
            'withholding' => ['rate' => null, 'amount' => null],
            'total' => 121,
            'lines' => [['description' => 'Reparación de caldera', 'quantity' => 1, 'unit_price' => 100, 'amount' => 100, 'tax_rate' => 21]],
            'confidence' => array_fill_keys([
                'document_type', 'supplier_name', 'supplier_tax_id', 'customer_tax_id',
                'invoice_number', 'issue_date', 'due_date', 'tax_breakdown', 'withholding', 'total',
            ], 'high'),
            'notes' => [],
        ], $overrides);
    }
}
