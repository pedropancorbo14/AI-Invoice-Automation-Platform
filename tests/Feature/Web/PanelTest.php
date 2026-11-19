<?php

use App\Models\User;
use Tests\Support\Reviewable;

beforeEach(fn () => $this->withoutVite());

it('serves the panel for any of its routes', function (string $path) {
    $user = User::factory()->create(['name' => 'Ana Revisora']);

    $this->actingAs($user)->get($path)
        ->assertOk()
        ->assertSee('id="panel"', false)
        ->assertSee('Ana Revisora');
})->with(['/panel', '/panel/revisar', '/panel/revisar/12', '/panel/metricas', '/panel/exportar']);

it('sends guests to the login page', function () {
    $this->get('/panel/revisar')->assertRedirect('/login');
});

it('lets the panel call the API with the browser session', function () {
    $user = User::factory()->create();
    $company = App\Models\Company::factory()->create(['account_id' => $user->account_id]);
    Reviewable::document($company);

    // Petición del propio panel: misma web, cookie de sesión y sin token.
    $this->actingAs($user, 'web')
        ->withHeader('Referer', config('app.url').'/panel/revisar')
        ->getJson('/api/documents?status=needs_review')
        ->assertOk()
        ->assertJsonCount(1, 'data');
});

it('takes the user to the panel after logging in', function () {
    User::factory()->create(['email' => 'ana@gestoria.test']);

    $this->post('/login', ['email' => 'ana@gestoria.test', 'password' => 'password'])->assertRedirect('/panel');
});
