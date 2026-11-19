<?php

use App\Models\Account;
use App\Models\Company;
use App\Models\Document;
use App\Models\User;
use App\Support\Tenancy\CurrentAccount;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;

/*
| Criterio de salida de la fase 1: un usuario nunca puede ver ni tocar
| documentos ni clientes de otra cuenta.
*/

beforeEach(function () {
    Storage::fake('documents');

    $this->mine = Account::factory()->create();
    $this->theirs = Account::factory()->create();

    $this->myCompany = Company::factory()->for($this->mine)->create();
    $this->theirCompany = Company::factory()->for($this->theirs)->create();

    $this->myDocument = Document::factory()->forCompany($this->myCompany)->create();
    $this->theirDocument = Document::factory()->forCompany($this->theirCompany)->create();

    Storage::disk('documents')->put($this->theirDocument->path, 'secreto');

    $this->user = User::factory()->forAccount($this->mine)->create();
});

describe('API', function () {
    it('lists only documents from the user account', function () {
        actingAsUser($this->user);

        $this->getJson('/api/documents')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $this->myDocument->id);
    });

    it('cannot filter its way into another account', function () {
        actingAsUser($this->user);

        $this->getJson('/api/documents?company_id='.$this->theirCompany->id)
            ->assertOk()
            ->assertJsonCount(0, 'data');
    });

    it('returns 404 for a document from another account', function () {
        actingAsUser($this->user);

        $this->getJson("/api/documents/{$this->theirDocument->id}")->assertNotFound();
    });

    it('does not let users download files from another account', function () {
        actingAsUser($this->user);

        $this->get("/api/documents/{$this->theirDocument->id}/file")->assertNotFound();
    });

    it('shows the latest extraction of a document', function () {
        actingAsUser($this->user);
        $this->myDocument->extractions()->create([
            'account_id' => $this->mine->id, 'model' => 'm', 'input_mode' => 'text', 'document_type' => 'invoice',
            'data' => ['total' => '121.00'], 'confidence' => ['total' => 'low'], 'notes' => [],
            'input_tokens' => 1, 'output_tokens' => 1, 'cost_usd' => 0.01, 'duration_ms' => 10,
        ]);

        $this->getJson("/api/documents/{$this->myDocument->id}")
            ->assertOk()
            ->assertJsonPath('data.extraction.data.total', '121.00')
            ->assertJsonPath('data.extraction.doubtful_fields', ['total']);
    });

    it('shows a document from the own account with its audit trail', function () {
        actingAsUser($this->user);

        $this->getJson("/api/documents/{$this->myDocument->id}")
            ->assertOk()
            ->assertJsonPath('data.id', $this->myDocument->id)
            ->assertJsonPath('data.events.0.type', 'created');
    });

    it('lists only companies from the user account', function () {
        actingAsUser($this->user);

        $this->getJson('/api/companies')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $this->myCompany->id);

        $this->getJson("/api/companies/{$this->theirCompany->id}")->assertNotFound();
    });

    it('never exposes the inbox token', function () {
        actingAsUser($this->user);

        $this->getJson("/api/companies/{$this->myCompany->id}")
            ->assertOk()
            ->assertJsonMissingPath('data.inbox_token');
    });

    it('rejects unauthenticated requests', function () {
        $this->getJson('/api/documents')->assertUnauthorized();
    });
});

describe('global scope', function () {
    it('filters queries by the current account', function () {
        app(CurrentAccount::class)->set($this->mine);

        expect(Document::count())->toBe(1)
            ->and(Document::find($this->theirDocument->id))->toBeNull()
            ->and(Company::pluck('id')->all())->toBe([$this->myCompany->id]);
    });

    it('assigns the current account when creating', function () {
        app(CurrentAccount::class)->set($this->mine);

        $company = Company::create(['name' => 'Nueva S.L.', 'tax_id' => 'b-1234567-4']);

        expect($company->account_id)->toBe($this->mine->id)
            ->and($company->tax_id)->toBe('B12345674')
            ->and($company->inbox_token)->toHaveLength(24);
    });

    it('restores the previous account after run()', function () {
        $current = app(CurrentAccount::class);
        $current->set($this->mine);

        $count = $current->run($this->theirs, fn () => Document::count());

        expect($count)->toBe(1)
            ->and($current->id())->toBe($this->mine->id);
    });
});

describe('policies', function () {
    it('deny access even if the global scope is bypassed', function () {
        $document = Document::withoutGlobalScopes()->findOrFail($this->theirDocument->id);
        $company = Company::withoutGlobalScopes()->findOrFail($this->theirCompany->id);

        expect(Gate::forUser($this->user)->denies('view', $document))->toBeTrue()
            ->and(Gate::forUser($this->user)->denies('update', $document))->toBeTrue()
            ->and(Gate::forUser($this->user)->denies('view', $company))->toBeTrue();
    });

    it('allow access to the own account', function () {
        expect(Gate::forUser($this->user)->allows('view', $this->myDocument))->toBeTrue()
            ->and(Gate::forUser($this->user)->allows('update', $this->myDocument))->toBeTrue();
    });
});

it('protects every authenticated route with the tenant middleware', function () {
    $unprotected = collect(app('router')->getRoutes()->getRoutes())
        ->filter(fn ($route) => array_intersect(['auth', 'auth:sanctum'], $route->gatherMiddleware()) !== [])
        ->reject(fn ($route) => in_array('tenant', $route->gatherMiddleware(), true))
        ->map(fn ($route) => $route->uri())
        ->values()
        ->all();

    expect($unprotected)->toBe([]);
});
