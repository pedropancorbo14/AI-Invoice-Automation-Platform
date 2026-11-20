<?php

namespace App\Http\Resources;

use App\Models\DocumentExtraction;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin DocumentExtraction
 */
class DocumentExtractionResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'document_type' => $this->document_type->value,
            'data' => $this->data,
            'confidence' => $this->confidence,
            'doubtful_fields' => $this->doubtfulFields(),
            'notes' => $this->notes,
            'model' => $this->model,
            'input_mode' => $this->input_mode->value,
            'duration_ms' => $this->duration_ms,
            'created_at' => $this->created_at->toIso8601String(),
        ];
    }
}
