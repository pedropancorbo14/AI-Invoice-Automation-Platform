<?php

namespace App\Http\Controllers\Api;

use App\Enums\DocumentStatus;
use App\Http\Controllers\Controller;
use App\Http\Resources\DocumentResource;
use App\Models\Company;
use App\Models\Document;
use App\Models\User;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Database\Eloquent\Builder;
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
            'review_code' => ['nullable', 'string', 'max:30'],
        ]);

        $scoped = Document::query()
            ->when($filters['company_id'] ?? null, fn ($q, $companyId) => $q->where('company_id', $companyId))
            ->when($request->boolean('unassigned'), fn ($q) => $q->whereNull('company_id'));

        $documents = (clone $scoped)
            ->when($filters['status'] ?? null, fn ($q, $status) => $q->where('status', $status))
            ->when($filters['review_code'] ?? null, fn ($q, $code) => $q->whereJsonContains('review_codes', $code))
            // La bandeja de revisión va por orden de llegada; el resto, lo último primero.
            ->orderBy('id', ($filters['status'] ?? null) === DocumentStatus::NeedsReview->value ? 'asc' : 'desc')
            ->with(['latestExtraction', 'company'])
            ->paginate(50);

        return DocumentResource::collection($documents)->additional([
            'meta' => ['status_counts' => $this->statusCounts($scoped)],
        ]);
    }

    /**
     * Cuántos documentos hay en cada estado (con el filtro de cliente, sin el de
     * estado): alimenta las pestañas del listado. Siempre con todos los estados.
     *
     * @param  Builder<Document>  $query
     * @return array<string, int>
     */
    private function statusCounts(Builder $query): array
    {
        $counts = $query->toBase()
            ->selectRaw('status, count(*) as total')
            ->groupBy('status')
            ->pluck('total', 'status');

        $result = [];
        foreach (DocumentStatus::cases() as $status) {
            $result[$status->value] = (int) ($counts[$status->value] ?? 0);
        }

        return $result;
    }

    public function show(Document $document): DocumentResource
    {
        Gate::authorize('view', $document);

        return new DocumentResource($document->load(['events', 'latestExtraction', 'company', 'supplier', 'exports']));
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

        $disk = Storage::disk($document->disk);

        // En línea para verlo en el panel de revisión; ?download=1 para descargarlo.
        return request()->boolean('download')
            ? $disk->download($document->path, $document->original_filename)
            : $disk->response($document->path, $document->original_filename, ['Content-Type' => $document->mime_type]);
    }
}
