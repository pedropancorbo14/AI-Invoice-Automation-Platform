<?php

use App\Models\Account;
use App\Models\Company;

it('lets admins create companies in their account', function () {
    $admin = actingAsAdmin();

    $this->postJson('/api/companies', ['name' => 'Bar Pepe S.L.', 'tax_id' => 'b12345674'])
        ->assertCreated()
        ->assertJsonPath('data.tax_id', 'B12345674');

    expect(Company::withoutGlobalScopes()->sole()->account_id)->toBe($admin->account_id);
});

it('forbids reviewers from creating companies', function () {
    actingAsUser();

    $this->postJson('/api/companies', ['name' => 'Bar Pepe S.L.', 'tax_id' => 'B12345674'])
        ->assertForbidden();
});

it('rejects a duplicated tax id within the same account', function () {
    $admin = actingAsAdmin();
    Company::factory()->create(['account_id' => $admin->account_id, 'tax_id' => 'B12345674']);

    $this->postJson('/api/companies', ['name' => 'Otra', 'tax_id' => 'B-12345674'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('tax_id');
});

it('allows the same tax id in different accounts', function () {
    Company::factory()->for(Account::factory())->create(['tax_id' => 'B12345674']);
    actingAsAdmin();

    $this->postJson('/api/companies', ['name' => 'Bar Pepe S.L.', 'tax_id' => 'B12345674'])
        ->assertCreated();
});
