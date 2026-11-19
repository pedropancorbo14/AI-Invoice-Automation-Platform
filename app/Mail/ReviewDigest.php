<?php

namespace App\Mail;

use App\Models\Account;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * Resumen periódico: cuánto se procesó y cuánto espera revisión.
 */
class ReviewDigest extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public readonly Account $account,
        public readonly int $processed,
        public readonly int $automatic,
        public readonly int $pending,
        public readonly string $period,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: sprintf(
                'Se procesaron %d %s; %d %s revisión',
                $this->processed,
                $this->processed === 1 ? 'factura' : 'facturas',
                $this->pending,
                $this->pending === 1 ? 'necesita' : 'necesitan',
            ),
        );
    }

    public function content(): Content
    {
        return new Content(markdown: 'mail.review-digest', with: [
            'url' => url('/panel/revisar'),
        ]);
    }
}
