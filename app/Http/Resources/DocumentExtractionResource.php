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
            // Lo que dice el QR de VERI*FACTU, si la factura lo lleva.
            'verifactu' => $this->verifactu,
            'model' => $this->model,
            'input_mode' => $this->input_mode->value,
            'duration_ms' => $this->duration_ms,
            'created_at' => $this->created_at->toIso8601String(),
        ];
    }
}
