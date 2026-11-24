<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\JsonResponse;

class MeController extends Controller
{
    public function __invoke(#[CurrentUser] User $user): JsonResponse
    {
        $account = $user->account()->firstOrFail();

        return response()->json(['data' => [
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'role' => $user->role->value,
            'account' => [
                'id' => $account->id,
                'name' => $account->name,
                // Lo que llega aquí cae en "sin asignar".
                'inbox_address' => $account->inboxAddress(),
                // Autónomo o pyme sin gestoría: el panel oculta todo lo de "clientes".
                'single_company' => $account->single_company,
            ],
        ]]);
    }
}
