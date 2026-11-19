<?php

namespace App\Support\Export\Holded;

use App\Support\Export\Exceptions\ExportFailed;
use App\Support\Export\Exceptions\ExportUnavailable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/**
 * Cliente mínimo de la API de facturación de Holded (v1).
 *
 * Autenticación con la cabecera "key". Pendiente de verificar contra una
 * cuenta de prueba real: ver docs/holded.md.
 */
class HoldedClient
{
    public function __construct(
        private readonly string $apiKey,
        private readonly string $baseUrl,
        private readonly int $timeout = 30,
    ) {}

    /**
     * Crea un documento (purchase, purchaserefund...) y devuelve su id.
     *
     * @param  array<string, mixed>  $payload
     */
    public function createDocument(string $docType, array $payload): string
    {
        $response = $this->send(fn () => $this->request()->post("documents/{$docType}", $payload));
        $id = $response->json('id');

        if (! is_string($id) || $id === '') {
            throw new ExportFailed('Holded no devolvió el id del documento creado.');
        }

        return $id;
    }

    public function attachFile(string $docType, string $documentId, string $contents, string $filename): void
    {
        $this->send(fn () => $this->request()
            ->attach('file', $contents, $filename)
            ->post("documents/{$docType}/{$documentId}/attach", ['setMain' => 'true']));
    }

    private function request(): PendingRequest
    {
        return Http::baseUrl(rtrim($this->baseUrl, '/').'/')
            ->withHeaders(['key' => $this->apiKey])
            ->acceptJson()
            ->timeout($this->timeout);
    }

    /**
     * @param  callable(): Response  $call
     */
    private function send(callable $call): Response
    {
        try {
            $response = $call();
        } catch (ConnectionException $e) {
            throw new ExportUnavailable('No se pudo conectar con Holded: '.$e->getMessage(), previous: $e);
        }

        $info = Str::limit((string) ($response->json('info') ?? $response->json('message') ?? $response->body()), 200);

        return match (true) {
            in_array($response->status(), [401, 403], true) => throw new ExportFailed('La API key de Holded no es válida o ha caducado.'),
            $response->status() === 429 || $response->serverError() => throw new ExportUnavailable("Holded no está disponible ({$response->status()})."),
            $response->clientError() => throw new ExportFailed("Holded rechazó la factura: {$info}"),
            // Holded a veces responde 200 con {"status": 0, "info": "..."}.
            $response->json('status') === 0 => throw new ExportFailed("Holded rechazó la factura: {$info}"),
            default => $response,
        };
    }
}
