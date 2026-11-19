<?php

namespace App\Support\Inbox;

use Illuminate\Support\Str;

/**
 * Al crear una regla de reenvío, Gmail manda un correo con un código y un
 * enlace a la dirección de destino. Lo detectamos para enseñárselo al cliente.
 */
class GmailForwardingConfirmation
{
    /**
     * @return array{confirmation_code: string|null, confirmation_url: string|null}|null
     */
    public static function detect(ParsedEmail $email): ?array
    {
        if (Str::lower((string) $email->fromAddress) !== 'forwarding-noreply@google.com') {
            return null;
        }

        $body = $email->textBody.' '.strip_tags($email->htmlBody);

        preg_match('/(?:confirmation code|c[óo]digo de confirmaci[óo]n)\s*:\s*(\d{6,})/iu', $body, $code);
        preg_match('#https://mail(?:-settings)?\.google\.com/mail/[^\s"<>]+#i', $body, $url);

        return [
            'confirmation_code' => $code[1] ?? null,
            'confirmation_url' => $url[0] ?? null,
        ];
    }
}
