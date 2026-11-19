<?php

it('lets admins relax the confidence threshold', function () {
    $admin = actingAsAdmin();

    $this->getJson('/api/account/settings')->assertOk()->assertJsonPath('data.min_confidence', 'high');

    $this->patchJson('/api/account/settings', ['min_confidence' => 'medium'])
        ->assertOk()
        ->assertJsonPath('data.min_confidence', 'medium');

    expect($admin->account->fresh()->min_confidence)->toBe('medium');
});

it('only accepts high or medium', function () {
    actingAsAdmin();

    $this->patchJson('/api/account/settings', ['min_confidence' => 'low'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('min_confidence');
});

it('does not let reviewers change it', function () {
    actingAsUser();

    $this->getJson('/api/account/settings')->assertOk();
    $this->patchJson('/api/account/settings', ['min_confidence' => 'medium'])->assertForbidden();
});
