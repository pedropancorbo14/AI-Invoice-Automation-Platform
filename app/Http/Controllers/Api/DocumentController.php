<?php

namespace App\Http\Controllers\Api;

use App\Enums\DocumentStatus;
use App\Http\Controllers\Controller;
use App\Http\Resources\DocumentResource;
use App\Models\Company;
use App\Models\Document;
use App\Models\User;
use Illuminate\Container\Attributes\CurrentUser;
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
            'unassigned' => ['nullable', 'boolean'],
        ]);

        $documents = Document::query()
            ->when($filters['status'] ?? null, fn ($q, $status) => $q->where('status', $status))
            ->when($filters['company_id'] ?? null, fn ($q, $companyId) => $q->where('company_id', $companyId))
            ->when($request->boolean('unassigned'), fn ($q) => $q->whereNull('company_id'))
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
     * Asigna un documento de la bandeja "sin asignar" a un cliente.
     */
    public function assign(Request $request, Document $document, #[CurrentUser] User $user): DocumentResource
    {
        Gate::authorize('update', $document);

        $data = $request->validate([
            'company_id' => [
                'required', 'integer',
                Rule::exists('companies', 'id')->where('account_id', $document->account_id),
            ],
        ]);

        $document->assignTo(Company::query()->findOrFail((int) $data['company_id']), $user);

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
