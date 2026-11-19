<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Support\Metrics\AccountMetrics;
use Carbon\CarbonImmutable;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class MetricsController extends Controller
{
    public function __invoke(Request $request, AccountMetrics $metrics, #[CurrentUser] User $user): JsonResponse
    {
        $account = $user->account()->firstOrFail();
        Gate::authorize('view', $account);

        $data = $request->validate(['month' => ['nullable', 'date_format:Y-m']]);
        $timezone = (string) config('invoices.validation.timezone');
        $month = isset($data['month'])
            ? CarbonImmutable::createFromFormat('!Y-m', (string) $data['month'], $timezone)
            : CarbonImmutable::now($timezone);

        abort_if($month === null, 422);

        return response()->json(['data' => $metrics->forPeriod($account, $month->startOfMonth(), $month->endOfMonth())]);
    }
}
