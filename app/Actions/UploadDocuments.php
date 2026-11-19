<?php

namespace App\Actions;

use App\Enums\DocumentSource;
use App\Models\Account;
use App\Models\Company;
use App\Models\Document;
use App\Support\Inbox\FileType;
use Illuminate\Http\UploadedFile;

/**
 * Subida manual (segunda vía de entrada). Mismas reglas que el email:
 * solo PDF/JPG/PNG/HEIC detectados por contenido y sin duplicados.
 */
class UploadDocuments
{
    public function __construct(private readonly StoreDocument $store) {}

    /**
     * @param  list<UploadedFile>  $files
     * @return array{
     *     created: list<Document>,
     *     duplicates: list<array{filename: string, document_id: int}>,
     *     rejected: list<array{filename: string, reason: string}>
     * }
     */
    public function handle(Account $account, array $files, ?Company $company = null): array
    {
        $result = ['created' => [], 'duplicates' => [], 'rejected' => []];
        $seen = [];

        foreach ($files as $file) {
            $filename = $file->getClientOriginalName();
            $contents = (string) $file->get();
            $type = FileType::detect($contents);

            if ($type === null) {
                $result['rejected'][] = ['filename' => $filename, 'reason' => 'No es un PDF ni una imagen (JPG, PNG o HEIC).'];

                continue;
            }

            $sha256 = hash('sha256', $contents);
            $existingId = $seen[$sha256] ?? Document::query()
                ->where('account_id', $account->id)
                ->where('sha256', $sha256)
                ->value('id');

            if ($existingId !== null) {
                $result['duplicates'][] = ['filename' => $filename, 'document_id' => (int) $existingId];

                continue;
            }

            $document = $this->store->handle($account, $contents, $filename, $type->mimeType(), DocumentSource::Upload, $company);
            $seen[$sha256] = $document->id;
            $result['created'][] = $document;
        }

        return $result;
    }
}
