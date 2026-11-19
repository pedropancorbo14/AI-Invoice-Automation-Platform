<?php

namespace App\Support\Inbox;

use App\Models\Account;
use App\Models\Company;

/**
 * Decide a qué cuenta y cliente va un correo según el token de la dirección:
 * - facturas+<token de cliente>  → ese cliente.
 * - facturas+<token de cuenta>   → la cuenta, bandeja "sin asignar".
 * - sin token válido             → null (no se asigna a nadie).
 */
class InboxRouter
{
    public function route(ParsedEmail $email): ?InboxRoute
    {
        foreach ($email->recipients as $recipient) {
            $token = InboxAddress::extractToken($recipient);

            if ($token === null) {
                continue;
            }

            // Sin scopes: aquí todavía no hay cuenta activa, es contexto del sistema.
            $company = Company::withoutGlobalScopes()->with('account')->where('inbox_token', $token)->first();

            if ($company?->account !== null) {
                return new InboxRoute($company->account, $company, $recipient);
            }

            $account = Account::query()->where('inbox_token', $token)->first();

            if ($account !== null) {
                return new InboxRoute($account, null, $recipient);
            }
        }

        return null;
    }
}
