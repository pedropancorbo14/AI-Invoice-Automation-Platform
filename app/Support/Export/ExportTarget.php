<?php

namespace App\Support\Export;

use App\Models\Company;
use App\Models\Document;
use App\Models\DocumentExport;
use App\Models\DocumentExtraction;
use App\Support\Export\Exceptions\ExportFailed;
use App\Support\Export\Exceptions\ExportSkipped;
use App\Support\Export\Exceptions\ExportUnavailable;

/**
 * Un sistema al que se mandan las facturas validadas, una a una y en el
 * momento (Holded, otros programas contables...). Añadir uno nuevo no debe
 * tocar nada más: se registra en config('invoices.export.targets').
 */
interface ExportTarget
{
    /** Identificador estable: se guarda en document_exports.target. */
    public function key(): string;

    public function label(): string;

    /** ¿Está configurado para esta empresa (p. ej. tiene su API key)? */
    public function enabledFor(Company $company): bool;

    /**
     * Exporta y devuelve el id en el destino. $record trae el progreso de un
     * intento anterior (p. ej. external_id si se creó pero falló el adjunto).
     *
     * @throws ExportSkipped
     * @throws ExportFailed
     * @throws ExportUnavailable
     */
    public function export(Document $document, DocumentExtraction $extraction, DocumentExport $record): string;
}
