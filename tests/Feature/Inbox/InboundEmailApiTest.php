<?php

use App\Enums\InboundEmailStatus;
use App\Models\Account;
use App\Models\Company;
use App\Models\InboundEmail;

it('lists only the emails of the user account', function () {
    $user = actingAsUser();
    $mine = InboundEmail::factory()->create(['account_id' => $user->account_id]);
    InboundEmail::factory()->create(['account_id' => Account::factory()]);
    InboundEmail::factory()->create(['account_id' => null]);

    $this->getJson('/api/inbound-emails')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.id', $mine->id);
});

it('returns 404 for emails of other accounts and unrouted emails', function () {
    actingAsUser();
    $foreign = InboundEmail::factory()->create(['account_id' => Account::factory()]);
    $unrouted = InboundEmail::factory()->create(['account_id' => null]);

    $this->getJson("/api/inbound-emails/{$foreign->id}")->assertNotFound();
    $this->getJson("/api/inbound-emails/{$unrouted->id}")->assertNotFound();
});

it('shows the Gmail confirmation code to the client', function () {
    $user = actingAsUser();
    $email = InboundEmail::factory()->create(['account_id' => $user->account_id]);
    $email->markAs(InboundEmailStatus::ForwardingConfirmation, null, ['confirmation_code' => '123456789', 'confirmation_url' => null]);

    $this->getJson('/api/inbound-emails?status=forwarding_confirmation')
        ->assertOk()
        ->assertJsonPath('data.0.summary.confirmation_code', '123456789');
});

it('tells the user the forwarding addresses', function () {
    $user = actingAsUser();
    $company = Company::factory()->create(['account_id' => $user->account_id]);

    $this->getJson('/api/me')
        ->assertOk()
        ->assertJsonPath('data.account.inbox_address', "facturas+{$user->account->inbox_token}@example.com");

    $this->getJson("/api/companies/{$company->id}")
        ->assertJsonPath('data.inbox_address', "facturas+{$company->inbox_token}@example.com");
});
