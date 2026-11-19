<?php

use App\Models\Account;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    ->in('Feature');

pest()->extend(TestCase::class)
    ->in('Unit');

/**
 * Autentica un usuario por API (Sanctum) y lo devuelve.
 */
function actingAsUser(?User $user = null): User
{
    $user ??= User::factory()->create();
    Sanctum::actingAs($user);

    return $user;
}

function actingAsAdmin(?Account $account = null): User
{
    $factory = User::factory()->admin();

    return actingAsUser(($account ? $factory->forAccount($account) : $factory)->create());
}
