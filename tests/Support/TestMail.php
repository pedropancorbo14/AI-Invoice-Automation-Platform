<?php

namespace Tests\Support;

use Symfony\Component\Mime\Email;
use Symfony\Component\Mime\Part\DataPart;

/**
 * Construye correos en bruto (RFC 822) para los tests del buzón.
 */
class TestMail
{
    private Email $email;

    /** @var list<string> */
    private array $deliveredTo = [];

    private ?string $messageId;

    public function __construct()
    {
        $this->email = (new Email)
            ->from('Proveedor <facturacion@proveedor.test>')
            ->to('cliente@empresa.test')
            ->subject('Factura')
            ->text('Adjuntamos la factura del mes.');

        $this->messageId = bin2hex(random_bytes(8)).'@proveedor.test';
    }

    public static function make(): self
    {
        return new self;
    }

    public function to(string ...$addresses): self
    {
        $this->email->to(...$addresses);

        return $this;
    }

    public function cc(string ...$addresses): self
    {
        $this->email->cc(...$addresses);

        return $this;
    }

    /** Lo que añade el servidor al entregar un reenvío automático. */
    public function deliveredTo(string $address): self
    {
        $this->deliveredTo[] = $address;

        return $this;
    }

    public function from(string $address, string $name = ''): self
    {
        $this->email->from($name !== '' ? "{$name} <{$address}>" : $address);

        return $this;
    }

    public function subject(string $subject): self
    {
        $this->email->subject($subject);

        return $this;
    }

    public function text(string $text): self
    {
        $this->email->text($text);

        return $this;
    }

    public function html(string $html): self
    {
        $this->email->html($html);

        return $this;
    }

    public function messageId(?string $messageId): self
    {
        $this->messageId = $messageId;

        return $this;
    }

    public function attach(string $content, string $filename, string $contentType = 'application/octet-stream'): self
    {
        $this->email->attach($content, $filename, $contentType);

        return $this;
    }

    public function embed(string $content, string $name, string $contentType): self
    {
        $this->email->addPart((new DataPart($content, $name, $contentType))->asInline());

        return $this;
    }

    /** Outlook: "Reenviar como datos adjuntos". */
    public function attachEmail(self $mail): self
    {
        // MessagePart de Symfony declara base64 pero no codifica: usamos DataPart.
        $this->email->attach($mail->toString(), 'reenviado.eml', 'message/rfc822');

        return $this;
    }

    public function build(): Email
    {
        $email = clone $this->email;

        if ($this->messageId !== null) {
            $email->getHeaders()->addIdHeader('Message-ID', $this->messageId);
        }

        foreach ($this->deliveredTo as $address) {
            $email->getHeaders()->addTextHeader('Delivered-To', $address);
        }

        return $email;
    }

    public function toString(): string
    {
        $raw = $this->build()->toString();

        // Symfony siempre genera un Message-ID; lo quitamos si el test lo pide.
        if ($this->messageId === null) {
            $raw = (string) preg_replace('/^Message-ID:.*\r\n/mi', '', $raw);
        }

        return $raw;
    }
}
