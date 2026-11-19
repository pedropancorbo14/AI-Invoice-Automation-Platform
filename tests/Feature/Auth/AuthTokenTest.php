<?php

use App\Models\User;

it('issues an API token with valid credentials', function () {
    $user = User::factory()->create(['email' => 'ana@gestoria.test']);

    $token = $this->postJson('/api/auth/token', [
        'email' => 'ana@gestoria.test',
        'password' => 'password',
        'device_name' => 'tests',
    ])->assertCreated()->json('token');

    $this->withToken($token)->getJson('/api/documents')->assertOk();

    expect($user->tokens()->count())->toBe(1);
});

it('rejects wrong credentials', function () {
    User::factory()->create(['email' => 'ana@gestoria.test']);

    $this->postJson('/api/auth/token', [
        'email' => 'ana@gestoria.test',
        'password' => 'nope',
        'device_name' => 'tests',
    ])->assertUnprocessable()->assertJsonValidationErrors('email');
});

it('revokes the current token on logout', function () {
    $user = User::factory()->create();
    $token = $user->createToken('tests')->plainTextToken;

    $this->withToken($token)->deleteJson('/api/auth/token')->assertNoContent();

    expect($user->tokens()->count())->toBe(0);
});
