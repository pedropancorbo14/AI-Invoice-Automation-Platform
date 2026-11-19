<?php

namespace App\Mail;

use App\Models\Company;
use App\Support\Export\Ledger\Period;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Attachment;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class LedgerExport extends Mailable
{
    use Queueable, SerializesModels;

    /**
     * @param  array{filename: string, contents: string, mime: string, rows: int}  $file
     */
    public function __construct(
        public readonly Company $company,
        public readonly Period $period,
        public readonly array $file,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: "Facturas de {$this->company->name} · {$this->period->label}");
    }

    public function content(): Content
    {
        return new Content(markdown: 'mail.ledger-export');
    }

    /**
     * @return list<Attachment>
     */
    public function attachments(): array
    {
        return [Attachment::fromData(fn () => $this->file['contents'], $this->file['filename'])->withMime($this->file['mime'])];
    }
}
