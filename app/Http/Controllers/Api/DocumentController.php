<?php

namespace App\Http\Controllers\Api;

use App\Enums\DocumentStatus;
use App\Http\Controllers\Controller;
use App\Http\Resources\DocumentResource;
use App\Models\Document;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\StreamedResponse;

class DocumentController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        Gate::authorize('viewAny', Document::class);

        $filters = $request->validate([
            'status' => ['nullable', Rule::enum(DocumentStatus::class)],
            'company_id' => ['nullable', 'integer'],
        ]);

        $documents = Document::query()
            ->when($filters['status'] ?? null, fn ($q, $status) => $q->where('status', $status))
            ->when($filters['company_id'] ?? null, fn ($q, $companyId) => $q->where('company_id', $companyId))
            ->latest('id')
            ->paginate(50);

        return DocumentResource::collection($documents);
    }

    public function show(Document $document): DocumentResource
    {
        Gate::authorize('view', $document);

        return new DocumentResource($document->load('events'));
    }

    /**
     * Descarga el original. Los ficheros nunca son públicos: siempre pasan por aquí.
     */
    public function file(Document $document): StreamedResponse
    {
        Gate::authorize('view', $document);

        return Storage::disk($document->disk)->download($document->path, $document->original_filename);
    }
}
