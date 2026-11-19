<?php

namespace App\Http\Resources;

use App\Models\Document;
use App\Support\Review\ReviewCode;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Document
 */
class DocumentResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'company_id' => $this->company_id,
            'inbound_email_id' => $this->inbound_email_id,
            'status' => $this->status->value,
            'status_label' => $this->status->label(),
            'status_reason' => $this->status_reason,
            'direction' => $this->direction?->value,
            'review_reasons' => array_map(
                fn (string $code) => ['code' => $code, 'label' => ReviewCode::label($code)],
                $this->review_codes ?? [],
            ),
            'company' => $this->whenLoaded('company', fn () => $this->company === null ? null : [
                'id' => $this->company->id,
                'name' => $this->company->name,
                'tax_id' => $this->company->tax_id,
            ]),
            'supplier_id' => $this->supplier_id,
            'supplier' => $this->whenLoaded('supplier', fn () => $this->supplier === null ? null : [
                'id' => $this->supplier->id,
                'name' => $this->supplier->name,
                'ledger_account' => $this->supplier->ledger_account,
                'category' => $this->supplier->category,
                'validated_count' => $this->supplier->validated_count,
            ]),
            'source' => $this->source->value,
            'original_filename' => $this->original_filename,
            'mime_type' => $this->mime_type,
            'size' => $this->size,
            'sha256' => $this->sha256,
            'created_at' => $this->created_at?->toIso8601String(),
            'extraction' => new DocumentExtractionResource($this->whenLoaded('latestExtraction')),
            'events' => DocumentEventResource::collection($this->whenLoaded('events')),
        ];
    }
}
