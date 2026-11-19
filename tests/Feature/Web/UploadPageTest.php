<?php

use App\Models\Company;
use App\Models\Document;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\Support\Files;

it('redirects guests to the login page', function () {
    $this->get('/subir')->assertRedirect('/login');
});

it('logs in with email and password', function () {
    User::factory()->create(['email' => 'ana@gestoria.test']);

    $this->post('/login', ['email' => 'ana@gestoria.test', 'password' => 'password'])
        ->assertRedirect('/panel');

    $this->assertAuthenticated();
});

it('rejects wrong credentials', function () {
    User::factory()->create(['email' => 'ana@gestoria.test']);

    $this->post('/login', ['email' => 'ana@gestoria.test', 'password' => 'mal'])
        ->assertSessionHasErrors('email');

    $this->assertGuest();
});

it('shows the upload page with only the account companies and addresses', function () {
    $user = User::factory()->create();
    $mine = Company::factory()->create(['account_id' => $user->account_id, 'name' => 'Mía S.L.']);
    Company::factory()->create(['name' => 'Ajena S.L.']);

    $this->actingAs($user)->get('/subir')
        ->assertOk()
        ->assertSee('Mía S.L.')
        ->assertSee($mine->inboxAddress())
        ->assertSee($user->account->inboxAddress())
        ->assertDontSee('Ajena S.L.');
});

it('uploads from the page with the session', function () {
    Storage::fake('documents');
    $user = User::factory()->create();

    $this->actingAs($user)
        ->postJson('/subir', ['files' => [UploadedFile::fake()->createWithContent('f.pdf', Files::pdf())]])
        ->assertCreated();

    expect(Document::withoutGlobalScopes()->sole()->account_id)->toBe($user->account_id);
});

it('logs out', function () {
    $this->actingAs(User::factory()->create())->post('/logout')->assertRedirect('/login');

    $this->assertGuest();
});
