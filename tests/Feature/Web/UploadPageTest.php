<?php

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

it('keeps the short upload address, now inside the panel', function () {
    $this->withoutVite();
    $this->actingAs(User::factory()->create())->get('/subir')->assertRedirect('/panel/subir');
    $this->get('/panel/subir')->assertOk();
});

it('uploads from the panel with the session', function () {
    Storage::fake('documents');
    $user = User::factory()->create();

    $this->actingAs($user)
        ->postJson('/api/documents', ['files' => [UploadedFile::fake()->createWithContent('f.pdf', Files::pdf())]])
        ->assertCreated();

    expect(Document::withoutGlobalScopes()->sole()->account_id)->toBe($user->account_id);
});

it('logs out', function () {
    $this->actingAs(User::factory()->create())->post('/logout')->assertRedirect('/login');

    $this->assertGuest();
});
