<?php

namespace App\Actions;

use App\Jobs\ProcessInboundEmail;
use App\Models\InboundEmail;
use App\Support\Inbox\EmailParser;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Primer paso de cualquier correo: guarda el original, registra sus
 * cabeceras y encola su procesado. Si el correo ya llegó antes (mismo
 * Message-ID), no hace nada y devuelve null.
 */
class ReceiveInboundEmail
{
    public function __construct(private readonly EmailParser $parser) {}

    public function handle(string $raw): ?InboundEmail
    {
        $email = $this->parser->parse($raw);
        $fingerprint = InboundEmail::fingerprintFor($email->messageId, $raw);

        if (InboundEmail::withoutGlobalScopes()->where('fingerprint', $fingerprint)->exists()) {
            return null;
        }

        $disk = (string) config('invoices.documents_disk');
        $path = sprintf('inbound/%s/%s.eml', now()->format('Y/m'), Str::lower((string) Str::ulid()));

        Storage::disk($disk)->put($path, $raw);

        try {
            $inbound = InboundEmail::create([
                'fingerprint' => $fingerprint,
                'message_id' => $email->messageId,
                'from_address' => $email->fromAddress !== null ? Str::limit($email->fromAddress, 250, '') : null,
                'from_name' => $email->fromName !== null ? Str::limit($email->fromName, 250, '') : null,
                'subject' => $email->subject,
                'sent_at' => $email->sentAt,
                'disk' => $disk,
                'raw_path' => $path,
                'size' => strlen($raw),
            ]);
        } catch (UniqueConstraintViolationException) {
            // Otro proceso lo registró a la vez que nosotros.
            Storage::disk($disk)->delete($path);

            return null;
        }

        ProcessInboundEmail::dispatch($inbound->id);

        return $inbound;
    }
}
