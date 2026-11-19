<?php

namespace App\Console\Commands;

use App\Enums\DocumentStatus;
use App\Mail\ReviewDigest;
use App\Models\Account;
use App\Models\Document;
use App\Models\DocumentEvent;
use App\Support\Metrics\AccountMetrics;
use Carbon\CarbonImmutable;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Mail;

#[Signature('invoices:send-digests {--force : Enviar aunque hoy no toque}')]
#[Description('Manda el resumen diario o semanal a cada cuenta (solo si hay pendientes)')]
class SendReviewDigests extends Command
{
    public function handle(AccountMetrics $metrics): int
    {
        $now = CarbonImmutable::now((string) config('invoices.validation.timezone'));
        $sent = 0;

        foreach (Account::query()->where('digest_frequency', '!=', 'off')->with('users')->get() as $account) {
            if (! $this->option('force') && ! $this->isDue($account, $now)) {
                continue;
            }

            $from = $account->last_digest_sent_at !== null
                ? CarbonImmutable::parse($account->last_digest_sent_at)
                : $now->sub($account->digest_frequency === 'weekly' ? '7 days' : '1 day');

            $pending = $metrics->pendingReview($account);
            $account->forceFill(['last_digest_sent_at' => $now])->save();

            // Si no hay nada que revisar no molestamos a nadie.
            if ($pending === 0 || $account->users->isEmpty()) {
                continue;
            }

            Mail::to($account->users)->send(new ReviewDigest(
                account: $account,
                processed: Document::withoutGlobalScopes()->where('account_id', $account->id)->where('created_at', '>=', $from)->count(),
                automatic: DocumentEvent::withoutGlobalScopes()
                    ->where('account_id', $account->id)
                    ->where('to_status', DocumentStatus::Validated)
                    ->whereNull('user_id')
                    ->where('created_at', '>=', $from)
                    ->count(),
                pending: $pending,
                period: $account->digest_frequency === 'weekly' ? 'semanal' : 'diario',
            ));
            $sent++;
        }

        $this->info("Resúmenes enviados: {$sent}.");

        return self::SUCCESS;
    }

    private function isDue(Account $account, CarbonImmutable $now): bool
    {
        $last = $account->last_digest_sent_at !== null ? CarbonImmutable::parse($account->last_digest_sent_at) : null;

        return match ($account->digest_frequency) {
            'weekly' => $now->isMonday() && ($last === null || $last->lessThan($now->startOfWeek())),
            default => $last === null || $last->lessThan($now->startOfDay()),
        };
    }
}
