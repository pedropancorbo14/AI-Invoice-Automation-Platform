<?php

namespace App\Console\Commands;

use App\Enums\DocumentStatus;
use App\Jobs\ExtractDocument;
use App\Models\Document;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('invoices:extract
    {ids?* : Documentos concretos}
    {--received : Encola todos los RECEIVED (p. ej. tras activar la extracción)}
    {--failed : Reintenta los FAILED (pasan a RECEIVED y se encolan)}')]
#[Description('Encola la extracción con IA de documentos')]
class ExtractDocuments extends Command
{
    public function handle(): int
    {
        $ids = array_map('intval', (array) $this->argument('ids'));

        if ($ids === [] && ! $this->option('received') && ! $this->option('failed')) {
            $this->error('Indica documentos o usa --received / --failed.');

            return self::INVALID;
        }

        $documents = Document::withoutGlobalScopes()
            ->where(function ($query) use ($ids) {
                $query->whereIn('id', $ids)
                    ->when($this->option('received'), fn ($q) => $q->orWhere('status', DocumentStatus::Received))
                    ->when($this->option('failed'), fn ($q) => $q->orWhere('status', DocumentStatus::Failed));
            })
            ->orderBy('id')
            ->get();

        $queued = 0;

        foreach ($documents as $document) {
            if ($document->status === DocumentStatus::Failed) {
                $document->transitionTo(DocumentStatus::Received, 'Reintento manual de la extracción.');
            }

            if ($document->status !== DocumentStatus::Received) {
                $this->warn("Documento {$document->id}: está en {$document->status->value}, no se puede extraer.");

                continue;
            }

            ExtractDocument::dispatch($document->id);
            $queued++;
        }

        $this->info("Documentos encolados: {$queued}.");

        return self::SUCCESS;
    }
}
