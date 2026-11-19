<?php

namespace App\Actions;

use App\Enums\DocumentSource;
use App\Models\Account;
use App\Models\Company;
use App\Models\Document;
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
    ): Document {
        if ($company !== null && $company->account_id !== $account->getKey()) {
            throw new InvalidArgumentException('La empresa no pertenece a la cuenta.');
        }

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

        try {
            return DB::transaction(function () use ($account, $company, $source, $disk, $path, $originalFilename, $mimeType, $contents): Document {
                $document = new Document([
                    'company_id' => $company?->getKey(),
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

                return $document;
            });
        } catch (Throwable $e) {
            Storage::disk($disk)->delete($path);

            throw $e;
        }
    }
}
