<?php

namespace App\Console\Commands;

use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

#[Signature('demo:reset')]
#[Description('Borra todo y vuelve a cargar la cuenta demo (solo con DEMO_MODE=true)')]
class ResetDemo extends Command
{
    public function handle(): int
    {
        // Nunca en una instalación con datos reales.
        if (! config('invoices.demo')) {
            $this->error('Solo se puede usar con DEMO_MODE=true: borra toda la base de datos.');

            return self::FAILURE;
        }

        $disk = Storage::disk((string) config('invoices.documents_disk'));
        foreach (['accounts', 'inbound'] as $directory) {
            $disk->deleteDirectory($directory);
        }

        $this->call('migrate:fresh', ['--seed' => true, '--force' => true]);
        $this->info('Demo reiniciada.');

        return self::SUCCESS;
    }
}
