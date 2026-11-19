<?php

namespace App\Support\Inbox;

use Illuminate\Support\Str;

/**
 * Direcciones con subdirección sobre el buzón configurado:
 * facturas@dominio.com → facturas+<token>@dominio.com
 */
class InboxAddress
{
    public static function for(string $token): string
    {
        [$local, $domain] = self::parts();

        return "{$local}+{$token}@{$domain}";
    }

    /**
     * Extrae el token si la dirección es una subdirección de nuestro buzón.
     */
    public static function extractToken(string $email): ?string
    {
        [$local, $domain] = self::parts();

        $pattern = sprintf('/^%s\+([a-z0-9]+)@%s$/i', preg_quote($local, '/'), preg_quote($domain, '/'));

        if (preg_match($pattern, trim($email), $matches) !== 1) {
            return null;
        }

        return Str::lower($matches[1]);
    }

    /**
     * @return array{0: string, 1: string}
     */
    private static function parts(): array
    {
        $address = Str::lower((string) config('invoices.inbox.address'));

        return [Str::before($address, '@'), Str::after($address, '@')];
    }
}
