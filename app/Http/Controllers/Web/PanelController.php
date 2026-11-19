<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\View\View;

/**
 * Sirve el panel (Vue). Los datos los pide a la API con la sesión del navegador.
 */
class PanelController extends Controller
{
    public function __invoke(#[CurrentUser] User $user): View
    {
        $account = $user->account()->firstOrFail();

        return view('panel', ['boot' => [
            'user' => ['name' => $user->name, 'role' => $user->role->value],
            'account' => ['name' => $account->name],
        ]]);
    }
}
