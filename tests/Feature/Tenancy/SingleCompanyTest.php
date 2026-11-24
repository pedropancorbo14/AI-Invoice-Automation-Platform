<?php

use App\Actions\StoreDocument;
use App\Enums\DocumentSource;
use App\Models\Account;
use App\Models\Company;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\Support\Files;

beforeEach(fn () => Storage::fake('documents'));

it('assigns what arrives without a company to the only company', function () {
    $account = Account::factory()->singleCompany()->create();
    $company = Company::factory()->create(['account_id' => $account->id]);

    // Lo que llega a la dirección general de la cuenta (sin token de empresa).
    $document = app(StoreDocument::class)->handle($account, 'x', 'ticket.jpg', 'image/jpeg', DocumentSource::Email);

    expect($document->company_id)->toBe($company->id);
});

it('keeps the unassigned inbox for accounts with several clients', function () {
    $account = Account::factory()->create();
    Company::factory()->create(['account_id' => $account->id]);

    $document = app(StoreDocument::class)->handle($account, 'x', 'ticket.jpg', 'image/jpeg', DocumentSource::Email);

    expect($document->company_id)->toBeNull();
});

it('leaves documents unassigned while the single company does not exist yet', function () {
    $account = Account::factory()->singleCompany()->create();

    $document = app(StoreDocument::class)->handle($account, 'x', 'ticket.jpg', 'image/jpeg', DocumentSource::Email);

    expect($document->company_id)->toBeNull();
});

it('assigns manual uploads to the only company', function () {
    $account = Account::factory()->singleCompany()->create();
    $company = Company::factory()->create(['account_id' => $account->id]);
    actingAsUser(User::factory()->forAccount($account)->create());

    $this->postJson('/api/documents', ['files' => [UploadedFile::fake()->createWithContent('f.pdf', Files::pdf())]])
        ->assertCreated()
        ->assertJsonPath('data.created.0.company_id', $company->id);
});

it('does not let a single-company account add a second company', function () {
    $account = Account::factory()->singleCompany()->create();
    Company::factory()->create(['account_id' => $account->id]);
    actingAsAdmin($account);

    $this->postJson('/api/companies', ['name' => 'Otra', 'tax_id' => 'B12345674'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('name');

    expect(Company::withoutGlobalScopes()->where('account_id', $account->id)->count())->toBe(1);
});

it('lets the single company be created first', function () {
    $account = Account::factory()->singleCompany()->create();
    actingAsAdmin($account);

    $this->postJson('/api/companies', ['name' => 'Panadería Lola', 'tax_id' => 'B12345674'])->assertCreated();
});

it('lets admins switch the mode on while there is at most one company', function () {
    $admin = actingAsAdmin();
    Company::factory()->create(['account_id' => $admin->account_id]);

    $this->patchJson('/api/account/settings', ['single_company' => true])
        ->assertOk()
        ->assertJsonPath('data.single_company', true);

    expect($admin->account->fresh()->single_company)->toBeTrue();
});

it('refuses the mode when the account already has several clients', function () {
    $admin = actingAsAdmin();
    Company::factory()->count(2)->create(['account_id' => $admin->account_id]);

    $this->patchJson('/api/account/settings', ['single_company' => true])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('single_company');
});

it('does not let reviewers change the mode', function () {
    actingAsUser();

    $this->patchJson('/api/account/settings', ['single_company' => true])->assertForbidden();
});

it('tells the panel about the mode', function () {
    $this->withoutVite();
    $account = Account::factory()->singleCompany()->create();
    $user = User::factory()->forAccount($account)->create();

    $this->actingAs($user)->get('/panel')
        ->assertOk()
        ->assertViewHas('boot', fn (array $boot) => $boot['account']['single_company'] === true);

    actingAsUser($user);
    $this->getJson('/api/me')->assertOk()->assertJsonPath('data.account.single_company', true);
});
