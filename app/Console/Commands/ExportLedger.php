<?php

namespace App\Console\Commands;

use App\Mail\LedgerExport;
use App\Models\Company;
use App\Support\Export\Ledger\LedgerFile;
use App\Support\Export\Ledger\Period;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Mail;
use InvalidArgumentException;

#[Signature('invoices:export-ledger
    {company : Id del cliente}
    {period : 2026-11, 2026-T4 o 2026}
    {--format=csv : csv o xlsx}
    {--email= : Enviarlo a este email}
    {--output= : Guardarlo en esta carpeta (por defecto storage/app/exports)}')]
#[Description('Genera el libro de facturas de un cliente en un periodo (CSV o Excel)')]
class ExportLedger extends Command
{
    public function handle(LedgerFile $files): int
    {
        $company = Company::withoutGlobalScopes()->find((int) $this->argument('company'));

        if ($company === null) {
            $this->error('No existe ese cliente.');

            return self::INVALID;
        }

        try {
            $period = Period::parse((string) $this->argument('period'));
            $file = $files->build($company, $period, (string) $this->option('format'));
        } catch (InvalidArgumentException $e) {
            $this->error($e->getMessage());

            return self::INVALID;
        }

        if ($this->option('email')) {
            Mail::to((string) $this->option('email'))->send(new LedgerExport($company, $period, $file));
            $this->info("Enviado a {$this->option('email')}: {$file['filename']} ({$file['rows']} líneas).");

            return self::SUCCESS;
        }

        $dir = (string) ($this->option('output') ?: storage_path('app/exports'));
        File::ensureDirectoryExists($dir);
        File::put("{$dir}/{$file['filename']}", $file['contents']);
        $this->info("Guardado: {$dir}/{$file['filename']} ({$file['rows']} líneas).");

        return self::SUCCESS;
    }
}
