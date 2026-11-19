<?php

use App\Enums\DocumentStatus;
use App\Mail\ReviewDigest;
use App\Models\Company;
use App\Models\DocumentExtraction;
use App\Models\User;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Tests\Support\Reviewable;

beforeEach(function () {
    Storage::fake('documents');
    Carbon::setTestNow('2026-11-20 09:00:00');
    Mail::fake();
});

afterEach(fn () => Carbon::setTestNow());

/**
 * Cuenta con: 1 validada sola, 1 aprobada por una persona y 2 pendientes.
 */
function accountWithActivity(): array
{
    $admin = User::factory()->admin()->create();
    $company = Company::factory()->create(['account_id' => $admin->account_id, 'tax_id' => 'B87654323']);

    $auto = Reviewable::document($company, codes: ['new_supplier']);
    $auto->transitionTo(DocumentStatus::Validated);

    $manual = Reviewable::document($company, codes: ['arithmetic', 'dates']);
    $manual->transitionTo(DocumentStatus::Validated, by: $admin);

    Reviewable::document($company, codes: ['arithmetic']);
    Reviewable::document($company, codes: ['link_invoice']);

    return [$admin, $company];
}

it('summarizes the month: received, automatic rate, reasons and AI cost', function () {
    [$admin] = accountWithActivity();
    actingAsUser($admin);

    $this->getJson('/api/metrics?month=2026-11')
        ->assertOk()
        ->assertJsonPath('data.received', 4)
        ->assertJsonPath('data.validated', ['automatic' => 1, 'manual' => 1, 'automatic_rate' => 50])
        ->assertJsonPath('data.pending_review', 2)
        ->assertJsonPath('data.review_reasons.0', ['code' => 'arithmetic', 'label' => 'El total no cuadra', 'count' => 2])
        ->assertJsonPath('data.ai.extractions', 4)
        ->assertJsonPath('data.ai.cost_usd', 0.04)
        ->assertJsonPath('data.ai.avg_cost_usd', 0.01);
});

it('does not count manual corrections as AI cost', function () {
    [$admin] = accountWithActivity();
    DocumentExtraction::withoutGlobalScopes()->first()->replicate()->fill(['input_mode' => 'manual', 'cost_usd' => 5])->save();
    actingAsUser($admin);

    $this->getJson('/api/metrics?month=2026-11')->assertJsonPath('data.ai.cost_usd', 0.04);
});

it('only shows the metrics of the own account', function () {
    accountWithActivity();
    actingAsUser();

    $this->getJson('/api/metrics?month=2026-11')
        ->assertOk()
        ->assertJsonPath('data.received', 0)
        ->assertJsonPath('data.pending_review', 0);
});

it('mails the digest to the account users when there is something to review', function () {
    [$admin] = accountWithActivity();
    $reviewer = User::factory()->forAccount($admin->account)->create();

    $this->artisan('invoices:send-digests')->expectsOutput('Resúmenes enviados: 1.')->assertSuccessful();

    Mail::assertSent(ReviewDigest::class, function (ReviewDigest $mail) use ($admin, $reviewer) {
        return $mail->hasTo($admin->email)
            && $mail->hasTo($reviewer->email)
            && $mail->pending === 2
            && $mail->processed === 4
            && $mail->automatic === 1
            && $mail->envelope()->subject === 'Se procesaron 4 facturas; 2 necesitan revisión';
    });
});

it('renders the digest with a direct link', function () {
    [$admin] = accountWithActivity();

    $html = (new ReviewDigest($admin->account, 4, 1, 2, 'diario'))->render();

    expect(strip_tags($html))->toContain('Pendientes de revisar: 2')
        ->and($html)->toContain(url('/panel/revisar'));
});

it('sends nothing when nothing is pending', function () {
    User::factory()->create();

    $this->artisan('invoices:send-digests')->expectsOutput('Resúmenes enviados: 0.');

    Mail::assertNothingSent();
});

it('sends the daily digest once a day', function () {
    accountWithActivity();

    $this->artisan('invoices:send-digests');
    $this->artisan('invoices:send-digests');
    Carbon::setTestNow('2026-11-21 08:00:00');
    $this->artisan('invoices:send-digests');

    Mail::assertSent(ReviewDigest::class, 2);
});

it('sends the weekly digest only on mondays', function () {
    [$admin] = accountWithActivity();
    $admin->account->update(['digest_frequency' => 'weekly']);

    $this->artisan('invoices:send-digests');
    Mail::assertNothingSent();

    Carbon::setTestNow('2026-11-23 08:00:00');
    $this->artisan('invoices:send-digests');
    Mail::assertSent(ReviewDigest::class, 1);
});

it('can be turned off per account', function () {
    [$admin] = accountWithActivity();
    actingAsUser($admin);

    $this->patchJson('/api/account/settings', ['digest_frequency' => 'off'])
        ->assertOk()
        ->assertJsonPath('data.digest_frequency', 'off')
        ->assertJsonPath('data.min_confidence', 'high');

    $this->artisan('invoices:send-digests');

    Mail::assertNothingSent();
});

it('is scheduled every morning in Madrid', function () {
    $event = collect(app(Schedule::class)->events())
        ->first(fn ($event) => str_contains((string) $event->command, 'invoices:send-digests'));

    expect($event->expression)->toBe('0 8 * * *')
        ->and($event->timezone)->toBe('Europe/Madrid');
});
