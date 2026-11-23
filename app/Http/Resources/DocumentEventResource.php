<?php

namespace App\Http\Resources;

use App\Models\DocumentEvent;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin DocumentEvent
 */
class DocumentEventResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'type' => $this->type->value,
            'from_status' => $this->from_status?->value,
            'to_status' => $this->to_status?->value,
            'reason' => $this->reason,
            'user_id' => $this->user_id,
            'user_name' => $this->whenLoaded('user', fn () => $this->user?->name),
            'payload' => $this->payload,
            'created_at' => $this->created_at->toIso8601String(),
        ];
    }
}
