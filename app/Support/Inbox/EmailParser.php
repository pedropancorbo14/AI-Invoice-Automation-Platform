<?php

namespace App\Support\Inbox;

use Illuminate\Support\Str;
use ZBateson\MailMimeParser\Header\AddressHeader;
use ZBateson\MailMimeParser\Header\DateHeader;
use ZBateson\MailMimeParser\IMessage;
use ZBateson\MailMimeParser\MailMimeParser;
use ZBateson\MailMimeParser\Message\IMessagePart;

/**
 * Convierte un correo en bruto (RFC 822) en un ParsedEmail.
 */
class EmailParser
{
    /**
     * Cabeceras donde puede aparecer nuestra dirección, de más a menos fiable.
     * Al reenviar con una regla, "To" sigue siendo la dirección del cliente y
     * la nuestra solo aparece en Delivered-To / X-Original-To / X-Forwarded-To.
     */
    private const RECIPIENT_HEADERS = [
        'Delivered-To', 'X-Original-To', 'X-Forwarded-To', 'Envelope-To', 'To', 'Cc', 'Bcc',
    ];

    /** Profundidad máxima al abrir correos reenviados como adjunto. */
    private const MAX_NESTING = 3;

    public function __construct(private readonly MailMimeParser $parser = new MailMimeParser) {}

    public function parse(string $raw): ParsedEmail
    {
        $message = $this->parser->parse($raw, false);

        $from = $message->getHeader('From');
        $date = $message->getHeader('Date');

        return new ParsedEmail(
            messageId: $message->getMessageId(),
            fromAddress: $from instanceof AddressHeader ? $from->getEmail() : null,
            fromName: $from instanceof AddressHeader ? $from->getPersonName() : null,
            subject: $message->getSubject(),
            sentAt: $date instanceof DateHeader ? $date->getDateTimeImmutable() : null,
            recipients: $this->recipients($message),
            textBody: (string) $message->getTextContent(),
            htmlBody: (string) $message->getHtmlContent(),
            attachments: $this->attachments($message, 0),
        );
    }

    /**
     * @return list<string>
     */
    private function recipients(IMessage $message): array
    {
        $addresses = [];

        foreach (self::RECIPIENT_HEADERS as $name) {
            foreach ($message->getAllHeadersByName($name) as $header) {
                preg_match_all('/[^\s<>,;"\'()]+@[^\s<>,;"\'()]+/', $header->getRawValue(), $matches);
                array_push($addresses, ...array_map(Str::lower(...), $matches[0]));
            }
        }

        return array_values(array_unique($addresses));
    }

    /**
     * @return list<EmailAttachment>
     */
    private function attachments(IMessage $message, int $depth): array
    {
        $attachments = [];

        foreach ($message->getAllAttachmentParts() as $index => $part) {
            $contentType = Str::lower($part->getContentType('application/octet-stream'));

            // Outlook "reenviar como adjunto": abrimos el correo y sacamos sus adjuntos.
            if ($contentType === 'message/rfc822') {
                if ($depth < self::MAX_NESTING) {
                    $nested = $this->parser->parse((string) $part->getContent(), false);
                    array_push($attachments, ...$this->attachments($nested, $depth + 1));
                }

                continue;
            }

            $content = $this->binaryContent($part);

            if ($content === '') {
                continue;
            }

            $attachments[] = new EmailAttachment(
                filename: $this->filename($part, $index),
                contentType: $contentType,
                content: $content,
                inline: Str::lower((string) $part->getContentDisposition()) === 'inline',
            );
        }

        return $attachments;
    }

    private function binaryContent(IMessagePart $part): string
    {
        $stream = $part->getBinaryContentStream();

        return $stream === null ? '' : $stream->getContents();
    }

    private function filename(IMessagePart $part, int $index): string
    {
        $name = trim((string) $part->getFilename());
        $name = (string) preg_replace('/[\x00-\x1F\x7F\/\\\\]/u', '', basename(str_replace('\\', '/', $name)));

        return $name !== '' ? Str::limit($name, 200, '') : 'adjunto-'.($index + 1);
    }
}
