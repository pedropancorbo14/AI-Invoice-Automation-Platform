<?php

namespace App\Http\Middleware;

use App\Models\User;
use App\Support\Tenancy\CurrentAccount;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Activa la cuenta del usuario autenticado para el resto de la petición.
 * Debe ir después de la autenticación y antes del route model binding.
 */
class SetCurrentAccount
{
    public function __construct(private readonly CurrentAccount $currentAccount) {}

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        abort_unless($user instanceof User, Response::HTTP_UNAUTHORIZED);

        $this->currentAccount->set($user->account_id);

        return $next($request);
    }
}
