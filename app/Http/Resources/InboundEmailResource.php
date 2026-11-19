<?php

namespace App\Http\Resources;

use App\Models\InboundEmail;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin InboundEmail
 */
class InboundEmailResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'company_id' => $this->company_id,
            'status' => $this->status->value,
            'status_label' => $this->status->label(),
            'status_reason' => $this->status_reason,
            'from_address' => $this->from_address,
            'from_name' => $this->from_name,
            'recipient' => $this->recipient,
            'subject' => $this->subject,
            'sent_at' => $this->sent_at?->toIso8601String(),
            'received_at' => $this->created_at?->toIso8601String(),
            'summary' => $this->summary,
            'documents' => DocumentResource::collection($this->whenLoaded('documents')),
        ];
    }
}
