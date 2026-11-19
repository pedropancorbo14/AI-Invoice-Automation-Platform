<?php

use App\Models\Company;
use App\Models\User;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Storage;

it('shows the demo credentials on the login page only in demo mode', function () {
    $this->get('/login')->assertDontSee('revisor@demo.test');

    config(['invoices.demo' => true]);

    $this->get('/login')->assertSee('revisor@demo.test')->assertSee('Entrar como revisor');
});

it('tells the panel it is a demo', function () {
    $this->withoutVite();
    config(['invoices.demo' => true]);

    $this->actingAs(User::factory()->create())->get('/panel')->assertSee('"demo":true', false);
});

it('does not allow connecting Holded in the demo', function () {
    config(['invoices.demo' => true]);
    $admin = actingAsAdmin();
    $company = Company::factory()->create(['account_id' => $admin->account_id]);

    $this->putJson("/api/companies/{$company->id}/holded", ['api_key' => 'hk_real_key_123'])
        ->assertForbidden()
        ->assertJsonPath('message', 'La conexión con Holded está desactivada en la demo.');
});

it('refuses to reset a real installation', function () {
    User::factory()->create();

    $this->artisan('demo:reset')->assertFailed();

    expect(User::count())->toBe(1);
});

it('wipes the documents and reloads the sample data', function () {
    Storage::fake('documents');
    Storage::disk('documents')->put('accounts/1/2026/11/x.pdf', 'pdf');
    Storage::disk('documents')->put('inbound/2026/11/x.eml', 'eml');
    config(['invoices.demo' => true]);

    // migrate:fresh no puede ejecutarse dentro de la transacción de los tests:
    // lo sustituimos por uno que solo apunta con qué opciones se llamó.
    $calls = [];
    Artisan::command('migrate:fresh {--seed} {--force}', function () use (&$calls) {
        $calls[] = ['seed' => $this->option('seed'), 'force' => $this->option('force')];
    });

    $this->artisan('demo:reset')->expectsOutput('Demo reiniciada.')->assertSuccessful();

    expect($calls)->toBe([['seed' => true, 'force' => true]])
        ->and(Storage::disk('documents')->allFiles())->toBe([]);
});

it('resets every night when in demo mode', function () {
    $event = collect(app(Schedule::class)->events())
        ->first(fn ($event) => str_contains((string) $event->command, 'demo:reset'));

    expect($event->expression)->toBe('0 4 * * *')
        ->and($event->timezone)->toBe('Europe/Madrid');
});
