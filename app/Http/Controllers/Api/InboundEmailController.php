<?php

namespace App\Http\Controllers\Api;

use App\Enums\InboundEmailStatus;
use App\Http\Controllers\Controller;
use App\Http\Resources\InboundEmailResource;
use App\Models\InboundEmail;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

/**
 * Correos recibidos: para ver qué llegó y qué se hizo con cada adjunto
 * (y el código de confirmación de Gmail).
 */
class InboundEmailController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        Gate::authorize('viewAny', InboundEmail::class);

        $filters = $request->validate([
            'status' => ['nullable', Rule::enum(InboundEmailStatus::class)],
            'company_id' => ['nullable', 'integer'],
        ]);

        $emails = InboundEmail::query()
            ->when($filters['status'] ?? null, fn ($q, $status) => $q->where('status', $status))
            ->when($filters['company_id'] ?? null, fn ($q, $companyId) => $q->where('company_id', $companyId))
            ->latest('id')
            ->paginate(50);

        return InboundEmailResource::collection($emails);
    }

    public function show(InboundEmail $inboundEmail): InboundEmailResource
    {
        Gate::authorize('view', $inboundEmail);

        return new InboundEmailResource($inboundEmail->load('documents'));
    }
}
