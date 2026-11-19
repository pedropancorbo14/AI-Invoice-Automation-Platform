<?php

namespace App\Actions;

use App\Enums\DocumentSource;
use App\Jobs\ExtractDocument;
use App\Models\Account;
use App\Models\Company;
use App\Models\Document;
use App\Models\InboundEmail;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Throwable;

/**
 * Guarda un fichero recibido en el disco privado y crea su Document en
 * estado RECEIVED. Es el punto de entrada común para email y subida manual.
 *
 * Ruta: accounts/{account}/{yyyy}/{mm}/{ulid}.{ext}
 *
 * Al guardarlo encola su extracción con IA (fase 3).
 */
class StoreDocument
{
    public function handle(
        Account $account,
        string $contents,
        string $originalFilename,
        string $mimeType,
        DocumentSource $source,
        ?Company $company = null,
        ?InboundEmail $inboundEmail = null,
        bool $queueExtraction = true,
    ): Document {
        if ($company !== null && $company->account_id !== $account->getKey()) {
            throw new InvalidArgumentException('La empresa no pertenece a la cuenta.');
        }

        if ($inboundEmail !== null && $inboundEmail->account_id !== $account->getKey()) {
            throw new InvalidArgumentException('El correo no pertenece a la cuenta.');
        }

        [$disk, $path] = self::putFile($account, $contents, $originalFilename);

        try {
            return DB::transaction(function () use ($account, $company, $inboundEmail, $queueExtraction, $source, $disk, $path, $originalFilename, $mimeType, $contents): Document {
                $document = new Document([
                    'company_id' => $company?->getKey(),
                    'inbound_email_id' => $inboundEmail?->getKey(),
                    'source' => $source,
                    'disk' => $disk,
                    'path' => $path,
                    'original_filename' => $originalFilename,
                    'mime_type' => $mimeType,
                    'size' => strlen($contents),
                    'sha256' => hash('sha256', $contents),
                ]);
                $document->account_id = $account->getKey();
                $document->save();

                if ($queueExtraction && config('invoices.extraction.enabled')) {
                    ExtractDocument::dispatch($document->id)->afterCommit();
                }

                return $document;
            });
        } catch (Throwable $e) {
            Storage::disk($disk)->delete($path);

            throw $e;
        }
    }

    /**
     * Guarda el fichero en el disco privado, en la carpeta de la cuenta.
     *
     * @return array{0: string, 1: string} [disco, ruta]
     */
    public static function putFile(Account $account, string $contents, string $originalFilename): array
    {
        $disk = (string) config('invoices.documents_disk');
        $extension = Str::lower(pathinfo($originalFilename, PATHINFO_EXTENSION)) ?: 'bin';
        $path = sprintf(
            'accounts/%d/%s/%s.%s',
            $account->getKey(),
            now()->format('Y/m'),
            Str::lower((string) Str::ulid()),
            $extension,
        );

        Storage::disk($disk)->put($path, $contents);

        return [$disk, $path];
    }
}
