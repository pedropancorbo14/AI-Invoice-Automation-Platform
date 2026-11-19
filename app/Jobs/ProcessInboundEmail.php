<?php

namespace App\Jobs;

use App\Actions\StoreDocument;
use App\Enums\AttachmentOutcome;
use App\Enums\DocumentSource;
use App\Enums\DocumentStatus;
use App\Enums\InboundEmailStatus;
use App\Models\Document;
use App\Models\InboundEmail;
use App\Support\Inbox\AttachmentFilter;
use App\Support\Inbox\DownloadLinkDetector;
use App\Support\Inbox\EmailParser;
use App\Support\Inbox\FileType;
use App\Support\Inbox\GmailForwardingConfirmation;
use App\Support\Inbox\InboxRoute;
use App\Support\Inbox\InboxRouter;
use App\Support\Inbox\ParsedEmail;
use App\Support\Tenancy\CurrentAccount;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Str;
use Throwable;

/**
 * Procesa un correo recibido: lo asigna a su cuenta y cliente, guarda los
 * adjuntos que pueden ser facturas y deja constancia de todo lo demás.
 *
 * Es idempotente: si se reintenta a medias, los documentos ya creados para
 * este correo se reconocen y no se duplican.
 */
class ProcessInboundEmail implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    /** @var list<int> */
    public array $backoff = [30, 120];

    public function __construct(public readonly int $inboundEmailId) {}

    public function handle(
        EmailParser $parser,
        InboxRouter $router,
        AttachmentFilter $filter,
        DownloadLinkDetector $links,
        StoreDocument $store,
        CurrentAccount $currentAccount,
    ): void {
        $inbound = InboundEmail::withoutGlobalScopes()->find($this->inboundEmailId);

        if ($inbound === null || $inbound->status !== InboundEmailStatus::Pending) {
            return;
        }

        $email = $parser->parse($inbound->raw());
        $route = $router->route($email);

        if ($route === null) {
            $inbound->markAs(InboundEmailStatus::Unrouted, sprintf(
                'Ninguna dirección del correo lleva un token válido (%s).',
                implode(', ', $email->recipients) ?: 'sin destinatarios',
            ));

            return;
        }

        $inbound->account_id = $route->account->id;
        $inbound->company_id = $route->company?->id;
        $inbound->recipient = $route->recipient;
        $inbound->save();

        $confirmation = GmailForwardingConfirmation::detect($email);

        if ($confirmation !== null) {
            $inbound->markAs(
                InboundEmailStatus::ForwardingConfirmation,
                'Gmail pide confirmar la regla de reenvío.',
                $confirmation,
            );

            return;
        }

        $currentAccount->run(
            $route->account,
            fn () => $this->processAttachments($inbound, $email, $route, $filter, $links, $store),
        );
    }

    private function processAttachments(
        InboundEmail $inbound,
        ParsedEmail $email,
        InboxRoute $route,
        AttachmentFilter $filter,
        DownloadLinkDetector $links,
        StoreDocument $store,
    ): void {
        $results = [];
        $stored = 0;
        $duplicates = 0;
        // sha256 → id del documento creado en este mismo correo.
        $seen = [];

        foreach ($email->attachments as $attachment) {
            $result = ['filename' => $attachment->filename, 'size' => $attachment->size()];
            $evaluation = $filter->evaluate($attachment);

            if ($evaluation instanceof AttachmentOutcome) {
                $results[] = $result + ['outcome' => $evaluation->value];

                continue;
            }

            $sha256 = hash('sha256', $attachment->content);

            // El mismo fichero adjunto dos veces en este correo.
            if (isset($seen[$sha256])) {
                $duplicates++;
                $results[] = $result + ['outcome' => AttachmentOutcome::Duplicate->value, 'document_id' => $seen[$sha256]];

                continue;
            }

            $existing = Document::query()->where('sha256', $sha256)->first();

            // Ya llegó en otro correo. Si llegó en este, es un reintento del job.
            if ($existing !== null && $existing->inbound_email_id !== $inbound->id) {
                $duplicates++;
                $results[] = $result + ['outcome' => AttachmentOutcome::Duplicate->value, 'document_id' => $existing->id];

                continue;
            }

            $document = $existing ?? $store->handle(
                account: $route->account,
                contents: $attachment->content,
                originalFilename: $this->filenameWithExtension($attachment->filename, $evaluation),
                mimeType: $evaluation->mimeType(),
                source: DocumentSource::Email,
                company: $route->company,
                inboundEmail: $inbound,
            );

            $seen[$sha256] = $document->id;
            $stored++;
            $results[] = $result + ['outcome' => AttachmentOutcome::Stored->value, 'document_id' => $document->id];
        }

        $summary = ['attachments' => $results];

        if ($stored > 0 || $duplicates > 0) {
            $inbound->markAs(InboundEmailStatus::Processed, $this->processedReason($stored, $duplicates), $summary);

            return;
        }

        $downloadLinks = $links->detect($email->textBody, $email->htmlBody);

        if ($downloadLinks !== []) {
            $this->storeLinkInvoice($inbound, $route, $store, $downloadLinks);
            $inbound->markAs(InboundEmailStatus::Processed, 'Factura por enlace: pendiente de revisión.', $summary + ['links' => $downloadLinks]);

            return;
        }

        $inbound->markAs(
            InboundEmailStatus::NoDocuments,
            $results === [] ? 'El correo no trae adjuntos ni enlaces de descarga.' : 'Ningún adjunto parece una factura.',
            $summary,
        );
    }

    /**
     * Sin adjunto pero con enlaces: guardamos el propio correo como documento
     * y lo mandamos a revisión para que alguien suba el PDF.
     *
     * @param  list<string>  $downloadLinks
     */
    private function storeLinkInvoice(InboundEmail $inbound, InboxRoute $route, StoreDocument $store, array $downloadLinks): void
    {
        $document = Document::query()->where('inbound_email_id', $inbound->id)->first()
            ?? $store->handle(
                account: $route->account,
                contents: $inbound->raw(),
                originalFilename: 'correo.eml',
                mimeType: 'message/rfc822',
                source: DocumentSource::Email,
                company: $route->company,
                inboundEmail: $inbound,
                // Es el propio correo, no una factura: no se manda a la IA.
                queueExtraction: false,
            );

        if ($document->status === DocumentStatus::Received) {
            $document->sendToReview(
                'Factura por enlace: el correo no trae adjunto, solo enlaces de descarga.',
                ['link_invoice'],
                payload: ['links' => $downloadLinks],
            );
        }
    }

    private function filenameWithExtension(string $filename, FileType $type): string
    {
        $extension = Str::lower(pathinfo($filename, PATHINFO_EXTENSION));
        $valid = $type === FileType::Jpeg ? ['jpg', 'jpeg'] : [$type->extension()];

        return in_array($extension, $valid, true) ? $filename : $filename.'.'.$type->extension();
    }

    private function processedReason(int $stored, int $duplicates): ?string
    {
        if ($duplicates === 0) {
            return null;
        }

        return $stored === 0
            ? 'Todos los adjuntos ya se habían recibido antes.'
            : sprintf('%d adjunto(s) ya se habían recibido antes.', $duplicates);
    }

    public function failed(Throwable $exception): void
    {
        InboundEmail::withoutGlobalScopes()->whereKey($this->inboundEmailId)->first()?->markAs(
            InboundEmailStatus::Failed,
            Str::limit($exception->getMessage(), 500),
        );
    }
}
