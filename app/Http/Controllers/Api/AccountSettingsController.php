<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Account;
use App\Models\User;
use App\Support\Extraction\Confidence;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Ajustes de la cuenta que cambian cómo se decide (solo administradores).
 */
class AccountSettingsController extends Controller
{
    public function show(#[CurrentUser] User $user): JsonResponse
    {
        $account = $user->account()->firstOrFail();
        Gate::authorize('view', $account);

        return $this->json($account);
    }

    public function update(Request $request, #[CurrentUser] User $user): JsonResponse
    {
        $account = $user->account()->firstOrFail();
        Gate::authorize('update', $account);

        $data = $request->validate([
            'min_confidence' => ['sometimes', Rule::in([Confidence::High->value, Confidence::Medium->value])],
            'digest_frequency' => ['sometimes', Rule::in(['daily', 'weekly', 'off'])],
            'single_company' => ['sometimes', 'boolean'],
        ]);

        if (($data['single_company'] ?? false) && $account->companies()->count() > 1) {
            throw ValidationException::withMessages([
                'single_company' => 'La cuenta tiene varios clientes: el modo empresa única es para una sola empresa.',
            ]);
        }

        $account->update($data);

        return $this->json($account);
    }

    private function json(Account $account): JsonResponse
    {
        return response()->json(['data' => [
            // high: un campo clave con confianza media ya va a revisión (lo más prudente).
            // medium: solo va a revisión con confianza baja.
            'min_confidence' => $account->min_confidence,
            // Resumen por email de pendientes: daily, weekly (lunes) u off.
            'digest_frequency' => $account->digest_frequency,
            // Una sola empresa (sin gestoría): todo se le asigna y no se pueden añadir más.
            'single_company' => $account->single_company,
        ]]);
    }
}
