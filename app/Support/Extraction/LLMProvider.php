<?php

namespace App\Support\Extraction;

use App\Support\Extraction\Exceptions\ExtractionFailed;
use App\Support\Extraction\Exceptions\ExtractionRefused;

/**
 * Cualquier modelo que convierta un documento en datos estructurados.
 * Cambiar de proveedor o de modelo no debe tocar nada más.
 */
interface LLMProvider
{
    /**
     * @throws ExtractionRefused Si el modelo se niega a procesar el documento.
     * @throws ExtractionFailed Si falla de forma no recuperable (no tiene sentido reintentar).
     */
    public function extract(ExtractionInput $input): ExtractionResult;
}
