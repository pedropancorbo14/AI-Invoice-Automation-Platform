<?php

namespace App\Http\Resources;

use App\Models\Company;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Company
 */
class CompanyResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'tax_id' => $this->tax_id,
            // Dirección a la que el cliente reenvía sus facturas.
            'inbox_address' => $this->inboxAddress(),
            // Solo si está conectado; la clave nunca sale de la base de datos.
            'holded_connected' => filled($this->holded_api_key),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
