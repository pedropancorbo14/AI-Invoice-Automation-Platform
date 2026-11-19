<?php

namespace App\Http\Resources;

use App\Models\Document;
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
