<?php

namespace App\Support\Metrics;

use App\Enums\DocumentEventType;
use App\Enums\DocumentStatus;
use App\Models\Account;
use App\Models\Document;
use App\Models\DocumentEvent;
use App\Support\Review\ReviewCode;
use Carbon\CarbonImmutable;

/**
 * Las cifras del panel del cliente: cuánto entra, cuánto sale solo y por qué
 * se revisa. El coste de la IA no se enseña al cliente: es solo para el
 * operador.
 */
class AccountMetrics
{
    /**
     * @return array<string, mixed>
     */
    public function forPeriod(Account $account, CarbonImmutable $from, CarbonImmutable $to): array
    {
        $documents = Document::withoutGlobalScopes()
            ->where('account_id', $account->id)
            ->whereBetween('created_at', [$from, $to]);

        $events = DocumentEvent::withoutGlobalScopes()
            ->where('account_id', $account->id)
            ->where('type', DocumentEventType::StatusChanged)
            ->whereBetween('created_at', [$from, $to]);

        $validated = (clone $events)->where('to_status', DocumentStatus::Validated);
        $automatic = (clone $validated)->whereNull('user_id')->count();
        $manual = (clone $validated)->whereNotNull('user_id')->count();

        $reasons = [];
        foreach ((clone $events)->where('to_status', DocumentStatus::NeedsReview)->get() as $event) {
            foreach ((array) ($event->payload['review_codes'] ?? []) as $code) {
                $reasons[(string) $code] = ($reasons[(string) $code] ?? 0) + 1;
            }
        }
        arsort($reasons);

        $byStatus = [];
        foreach ((clone $documents)->toBase()->selectRaw('status, count(*) as total')->groupBy('status')->get() as $row) {
            $byStatus[(string) $row->status] = (int) $row->total;
        }

        return [
            'from' => $from->toDateString(),
            'to' => $to->toDateString(),
            'received' => (clone $documents)->count(),
            'by_status' => $byStatus,
            'validated' => [
                'automatic' => $automatic,
                'manual' => $manual,
                'automatic_rate' => $automatic + $manual === 0 ? null : round($automatic / ($automatic + $manual) * 100, 1),
            ],
            'pending_review' => $this->pendingReview($account),
            'review_reasons' => array_map(
                fn (string $code, int $count) => ['code' => $code, 'label' => ReviewCode::label($code), 'count' => $count],
                array_keys($reasons),
                array_values($reasons),
            ),
        ];
    }

    public function pendingReview(Account $account): int
    {
        return Document::withoutGlobalScopes()
            ->where('account_id', $account->id)
            ->where('status', DocumentStatus::NeedsReview)
            ->count();
    }
}
