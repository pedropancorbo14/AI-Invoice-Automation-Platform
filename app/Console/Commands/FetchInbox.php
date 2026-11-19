<?php

namespace App\Console\Commands;

use App\Actions\ReceiveInboundEmail;
use App\Support\Inbox\Mailbox;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Throwable;

#[Signature('inbox:fetch {--limit= : Máximo de correos a leer en esta ejecución}')]
#[Description('Lee los correos nuevos del buzón y encola su procesado')]
class FetchInbox extends Command
{
    public function handle(Mailbox $mailbox, ReceiveInboundEmail $receive): int
    {
        if (! config('invoices.inbox.enabled')) {
            $this->warn('El buzón está desactivado (INBOX_ENABLED=false).');

            return self::SUCCESS;
        }

        $limit = (int) ($this->option('limit') ?? config('invoices.inbox.imap.batch_size'));
        $received = $skipped = $failed = 0;

        foreach ($mailbox->fetch($limit) as $message) {
            try {
                $receive->handle($message->raw) === null ? $skipped++ : $received++;
                $mailbox->markProcessed($message);
            } catch (Throwable $e) {
                // Se queda en la bandeja de entrada y se reintenta en la próxima ejecución.
                $failed++;
                Log::error('No se pudo recibir un correo del buzón', [
                    'mailbox_id' => $message->id,
                    'exception' => $e,
                ]);
            }
        }

        $this->info("Recibidos: {$received}. Ya existentes: {$skipped}. Con error: {$failed}.");

        return $failed > 0 ? self::FAILURE : self::SUCCESS;
    }
}
