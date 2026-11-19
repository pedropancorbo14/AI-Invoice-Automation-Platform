<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Support\Extraction\Confidence;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

/**
 * Ajustes de la cuenta que cambian cómo se decide (solo administradores).
 */
class AccountSettingsController extends Controller
{
    public function show(#[CurrentUser] User $user): JsonResponse
    {
        $account = $user->account()->firstOrFail();
        Gate::authorize('view', $account);

        return $this->json($account->min_confidence);
    }

    public function update(Request $request, #[CurrentUser] User $user): JsonResponse
    {
        $account = $user->account()->firstOrFail();
        Gate::authorize('update', $account);

        $data = $request->validate([
            'min_confidence' => ['required', Rule::in([Confidence::High->value, Confidence::Medium->value])],
        ]);

        $account->update($data);

        return $this->json($account->min_confidence);
    }

    private function json(string $minConfidence): JsonResponse
    {
        return response()->json(['data' => [
            // high: un campo clave con confianza media ya va a revisión (lo más prudente).
            // medium: solo va a revisión con confianza baja.
            'min_confidence' => $minConfidence,
        ]]);
    }
}
