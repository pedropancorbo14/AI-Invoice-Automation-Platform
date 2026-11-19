<?php

namespace App\Support\Inbox;

use DateTimeImmutable;

final readonly class ParsedEmail
{
    /**
     * @param  list<string>  $recipients  Direcciones candidatas, por orden de prioridad.
     * @param  list<EmailAttachment>  $attachments  Incluye los de correos reenviados como adjunto.
     */
    public function __construct(
        public ?string $messageId,
        public ?string $fromAddress,
        public ?string $fromName,
        public ?string $subject,
        public ?DateTimeImmutable $sentAt,
        public array $recipients,
        public string $textBody,
        public string $htmlBody,
        public array $attachments,
    ) {}
}
