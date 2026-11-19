<?php

namespace Database\Seeders;

use App\Actions\StoreDocument;
use App\Enums\DocumentSource;
use App\Enums\DocumentStatus;
use App\Jobs\ValidateDocument;
use App\Models\Company;
use App\Models\Document;
use App\Models\Supplier;
use App\Support\CompanyName;
use App\Support\Demo\SamplePdf;
use App\Support\Extraction\ExtractionSchema;
use App\Support\Extraction\InputMode;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;

/**
 * Facturas ficticias para probar el panel sin configurar nada: unas pasan
 * solas y otras van a revisión por motivos distintos. Las decisiones las
 * toman las reglas reales (ValidateDocument), no este seeder.
 */
class DemoInvoicesSeeder extends Seeder
{
    public function run(StoreDocument $store): void
    {
        $bakery = Company::withoutGlobalScopes()->where('tax_id', 'B34567891')->firstOrFail();
        $garage = Company::withoutGlobalScopes()->where('tax_id', 'B45678919')->firstOrFail();
        $today = Carbon::now('Europe/Madrid')->startOfDay();

        // Proveedores ya conocidos: sus facturas pasan solas si todo cuadra.
        $this->knownSupplier($bakery, 'B56789126', 'Gasolinera Ruta 66 S.L.', '6280002', 'Combustible');
        $this->knownSupplier($bakery, 'B67891234', 'Asesoría Pérez S.L.', '6230001', 'Asesoría');
        $this->knownSupplier($bakery, 'A23456783', 'Eléctrica del Sur S.A.', '6280001', 'Suministros');

        // 1. Pasa sola: ticket de gasolinera de un proveedor conocido.
        $this->invoice($store, $bakery, 'ticket-gasolinera.pdf', [
            'document_type' => 'simplified_invoice',
            'supplier' => ['name' => 'Gasolinera Ruta 66 S.L.', 'tax_id' => 'B56789126'],
            'customer' => ['name' => null, 'tax_id' => null],
            'invoice_number' => 'T-88213',
            'issue_date' => $today->copy()->subDays(3)->toDateString(),
            'tax_breakdown' => [['rate' => 21, 'base' => 49.59, 'tax_amount' => 10.41]],
            'total' => 60.00,
        ]);

        // 2. Pasa sola: asesoría con retención de IRPF.
        $this->invoice($store, $bakery, 'asesoria-noviembre.pdf', [
            'supplier' => ['name' => 'Asesoría Pérez S.L.', 'tax_id' => 'B67891234'],
            'invoice_number' => 'A-2026-0311',
            'issue_date' => $today->copy()->subDays(5)->toDateString(),
            'tax_breakdown' => [['rate' => 21, 'base' => 150, 'tax_amount' => 31.50]],
            'withholding' => ['rate' => 15, 'amount' => 22.50],
            'total' => 159.00,
        ]);

        // 3. A revisión: el total no cuadra y la IA no lo tenía claro.
        $this->invoice($store, $bakery, 'luz-octubre.pdf', [
            'supplier' => ['name' => 'Eléctrica del Sur S.A.', 'tax_id' => 'A23456783'],
            'invoice_number' => 'FE-2026-104552',
            'issue_date' => $today->copy()->subDays(2)->toDateString(),
            'due_date' => $today->copy()->addDays(18)->toDateString(),
            'tax_breakdown' => [['rate' => 21, 'base' => 86.40, 'tax_amount' => 18.14]],
            'total' => 140.54,
            'low' => ['total'],
            'notes' => [['field' => 'total', 'note' => 'El total está medio tapado por un sello de "pagado"']],
        ]);

        // 4. A revisión: proveedor nuevo.
        $this->invoice($store, $bakery, 'Factura F-2026-001.pdf', [
            'supplier' => ['name' => 'Fontanería García S.L.', 'tax_id' => 'B12345674'],
            'invoice_number' => 'F-2026-001',
            'issue_date' => $today->copy()->subDay()->toDateString(),
            'due_date' => $today->copy()->addDays(29)->toDateString(),
            'tax_breakdown' => [['rate' => 21, 'base' => 100, 'tax_amount' => 21]],
            'total' => 121.00,
        ]);

        // 5. A revisión: fecha futura (seguramente día y mes cambiados).
        $this->invoice($store, $garage, 'recambios-norte.pdf', [
            'supplier' => ['name' => 'Recambios Norte S.L.', 'tax_id' => 'B56789126'],
            'customer' => ['name' => 'Talleres Martínez S.L.', 'tax_id' => 'B45678919'],
            'invoice_number' => '2026/1187',
            'issue_date' => $today->copy()->addDays(40)->toDateString(),
            'tax_breakdown' => [['rate' => 21, 'base' => 310.00, 'tax_amount' => 65.10]],
            'total' => 375.10,
            'low' => ['issue_date'],
            'notes' => [['field' => 'issue_date', 'note' => 'La fecha podría estar en formato mes/día']],
        ]);

        // 6. A revisión: factura por enlace (el correo no traía el PDF).
        $link = $store->handle($bakery->account()->firstOrFail(), "From: facturacion@telco.test\r\nSubject: Su factura\r\n\r\nDescargue su factura en https://clientes.telco.test/facturas/2026-11", 'correo.eml', 'message/rfc822', DocumentSource::Email, $bakery, queueExtraction: false);
        $link->sendToReview('Factura por enlace: el correo no trae adjunto, solo enlaces de descarga.', ['link_invoice'], payload: [
            'links' => ['https://clientes.telco.test/facturas/2026-11'],
        ]);

        // 7. A revisión: parece un albarán.
        $this->invoice($store, $garage, 'albaran-559.pdf', [
            'document_type' => 'delivery_note',
            'supplier' => ['name' => 'Recambios Norte S.L.', 'tax_id' => 'B56789126'],
            'customer' => ['name' => 'Talleres Martínez S.L.', 'tax_id' => 'B45678919'],
            'invoice_number' => 'ALB-559',
            'issue_date' => $today->copy()->subDays(4)->toDateString(),
            'tax_breakdown' => [],
            'total' => null,
        ]);
    }

    /**
     * @param  array<string, mixed>  $spec
     */
    private function invoice(StoreDocument $store, Company $company, string $filename, array $spec): Document
    {
        $raw = $this->modelOutput($company, $spec);
        $normalized = ExtractionSchema::normalize($raw);
        $data = $normalized['data'];

        $document = $store->handle(
            $company->account()->firstOrFail(),
            SamplePdf::render($this->pdfLines($data)),
            $filename,
            'application/pdf',
            DocumentSource::Email,
            $company,
            queueExtraction: false,
        );

        $extraction = $document->extractions()->create([
            'account_id' => $company->account_id,
            'model' => 'claude-opus-5-5',
            'input_mode' => InputMode::Text,
            'document_type' => $normalized['type'],
            'data' => $data,
            'confidence' => array_map(fn ($c) => $c->value, $normalized['confidence']),
            'notes' => $normalized['notes'],
            'input_tokens' => random_int(1800, 2600),
            'output_tokens' => random_int(350, 600),
            'cache_read_tokens' => 1500,
            'cost_usd' => random_int(150, 220) / 10000,
            'duration_ms' => random_int(2500, 6000),
        ]);

        $document->transitionTo(DocumentStatus::Classified, payload: ['extraction_id' => $extraction->id]);

        if ($normalized['type']->value === 'delivery_note') {
            $document->sendToReview('Parece un albarán, no una factura.', ['not_invoice']);

            return $document;
        }

        $document->transitionTo(DocumentStatus::Extracted, payload: ['extraction_id' => $extraction->id]);
        app()->call([new ValidateDocument($document->id), 'handle']);

        return $document->refresh();
    }

    /**
     * @param  array<string, mixed>  $spec
     * @return array<string, mixed>
     */
    private function modelOutput(Company $company, array $spec): array
    {
        $confidence = array_fill_keys(ExtractionSchema::CONFIDENCE_FIELDS, 'high');
        foreach ($spec['low'] ?? [] as $field) {
            $confidence[$field] = 'low';
        }

        $rows = array_map(fn ($row) => $row + ['surcharge_rate' => null, 'surcharge_amount' => null], $spec['tax_breakdown']);

        return [
            'document_type' => $spec['document_type'] ?? 'invoice',
            'supplier' => $spec['supplier'],
            'customer' => $spec['customer'] ?? ['name' => $company->name, 'tax_id' => $company->tax_id],
            'invoice_number' => $spec['invoice_number'],
            'issue_date' => $spec['issue_date'],
            'due_date' => $spec['due_date'] ?? null,
            'currency' => 'EUR',
            'tax_breakdown' => $rows,
            'withholding' => $spec['withholding'] ?? ['rate' => null, 'amount' => null],
            'total' => $spec['total'],
            'lines' => [],
            'confidence' => $confidence,
            'notes' => $spec['notes'] ?? [],
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     * @return list<string>
     */
    private function pdfLines(array $data): array
    {
        $lines = [
            (string) $data['supplier']['name'].'   CIF '.$data['supplier']['tax_id'],
            '',
            'FACTURA N.: '.$data['invoice_number'],
            'Fecha: '.$data['issue_date'].($data['due_date'] ? '   Vencimiento: '.$data['due_date'] : ''),
            'Cliente: '.($data['customer']['name'] ?? '-').'   CIF '.($data['customer']['tax_id'] ?? '-'),
            '',
        ];

        foreach ($data['tax_breakdown'] as $row) {
            $lines[] = "Base imponible: {$row['base']} EUR   IVA {$row['rate']}%: {$row['tax_amount']} EUR";
        }

        if ($data['withholding']['amount'] !== null) {
            $lines[] = "Retencion IRPF {$data['withholding']['rate']}%: -{$data['withholding']['amount']} EUR";
        }

        $lines[] = 'TOTAL FACTURA: '.($data['total'] ?? '-').' EUR';

        return $lines;
    }

    private function knownSupplier(Company $company, string $taxId, string $name, string $ledgerAccount, string $category): void
    {
        $supplier = Supplier::withoutGlobalScopes()->firstOrNew(['company_id' => $company->id, 'tax_id' => $taxId]);
        $supplier->fill([
            'account_id' => $company->account_id,
            'name' => $name,
            'normalized_name' => CompanyName::normalize($name),
            'ledger_account' => $ledgerAccount,
            'category' => $category,
            'usual_vat_rate' => '21.00',
        ]);
        $supplier->validated_count = 3;
        $supplier->last_validated_at = now()->subWeek();
        $supplier->save();
    }
}
