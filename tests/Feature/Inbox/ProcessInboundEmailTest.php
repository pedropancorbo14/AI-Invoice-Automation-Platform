<?php

use App\Actions\ReceiveInboundEmail;
use App\Actions\StoreDocument;
use App\Enums\DocumentSource;
use App\Enums\InboundEmailStatus;
use App\Jobs\ProcessInboundEmail;
use App\Models\Company;
use App\Models\Document;
use App\Models\InboundEmail;
use App\Support\Tenancy\CurrentAccount;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\Support\Files;
use Tests\Support\TestMail;

beforeEach(fn () => Storage::fake('documents'));

function mailTo(Company $company): TestMail
{
    return TestMail::make()->deliveredTo($company->inboxAddress());
}

it('stores the raw email and queues its processing', function () {
    Queue::fake();
    $company = Company::factory()->create();

    $inbound = app(ReceiveInboundEmail::class)->handle(
        mailTo($company)->from('facturas@luz.test', 'Luz S.A.')->subject('Factura 11/2026')->toString(),
    );

    expect($inbound->status)->toBe(InboundEmailStatus::Pending)
        ->and($inbound->from_address)->toBe('facturas@luz.test')
        ->and($inbound->from_name)->toBe('Luz S.A.')
        ->and($inbound->subject)->toBe('Factura 11/2026')
        ->and($inbound->sent_at)->not->toBeNull()
        ->and($inbound->raw_path)->toStartWith('inbound/');

    Storage::disk('documents')->assertExists($inbound->raw_path);
    Queue::assertPushed(ProcessInboundEmail::class, fn ($job) => $job->inboundEmailId === $inbound->id);
});

it('deduplicates emails without Message-ID by content', function () {
    Queue::fake();
    $raw = TestMail::make()->messageId(null)->toString();

    expect(app(ReceiveInboundEmail::class)->handle($raw))->not->toBeNull()
        ->and(app(ReceiveInboundEmail::class)->handle($raw))->toBeNull();
});

it('treats the same PDF in two different accounts as two documents', function () {
    $mine = Company::factory()->create();
    $theirs = Company::factory()->create();

    app(ReceiveInboundEmail::class)->handle(mailTo($mine)->attach(Files::pdf('igual'), 'f.pdf', 'application/pdf')->toString());
    app(ReceiveInboundEmail::class)->handle(mailTo($theirs)->attach(Files::pdf('igual'), 'f.pdf', 'application/pdf')->toString());

    expect(Document::withoutGlobalScopes()->pluck('account_id')->all())
        ->toBe([$mine->account_id, $theirs->account_id]);
});

it('does not duplicate documents when a half-done job is retried', function () {
    Queue::fake();
    $company = Company::factory()->create();
    $inbound = app(ReceiveInboundEmail::class)->handle(mailTo($company)
        ->attach(Files::pdf('a'), 'a.pdf', 'application/pdf')
        ->attach(Files::pdf('b'), 'b.pdf', 'application/pdf')
        ->toString());

    // El primer intento llegó a guardar "a.pdf" y murió.
    $inbound->account_id = $company->account_id;
    $inbound->save();
    app(StoreDocument::class)->handle($company->account, Files::pdf('a'), 'a.pdf', 'application/pdf', DocumentSource::Email, $company, $inbound);

    app()->call([new ProcessInboundEmail($inbound->id), 'handle']);

    $inbound->refresh();

    expect(Document::withoutGlobalScopes()->count())->toBe(2)
        ->and($inbound->status)->toBe(InboundEmailStatus::Processed)
        ->and(array_column($inbound->summary['attachments'], 'outcome'))->toBe(['stored', 'stored']);
});

it('does nothing with an email that was already processed', function () {
    $company = Company::factory()->create();
    $inbound = app(ReceiveInboundEmail::class)->handle(mailTo($company)->attach(Files::pdf(), 'f.pdf', 'application/pdf')->toString());

    app()->call([new ProcessInboundEmail($inbound->id), 'handle']);

    expect(Document::withoutGlobalScopes()->count())->toBe(1);
});

it('marks the email as failed when the job gives up', function () {
    $inbound = InboundEmail::factory()->create();

    (new ProcessInboundEmail($inbound->id))->failed(new RuntimeException('Disco lleno'));

    expect($inbound->fresh())
        ->status->toBe(InboundEmailStatus::Failed)
        ->status_reason->toBe('Disco lleno');
});

it('leaves no account active after processing', function () {
    $company = Company::factory()->create();

    app(ReceiveInboundEmail::class)->handle(mailTo($company)->attach(Files::pdf(), 'f.pdf', 'application/pdf')->toString());

    expect(app(CurrentAccount::class)->has())->toBeFalse();
});

it('hides emails from other accounts and unrouted emails', function () {
    $mine = Company::factory()->create();
    $theirs = Company::factory()->create();

    app(ReceiveInboundEmail::class)->handle(mailTo($mine)->toString());
    app(ReceiveInboundEmail::class)->handle(mailTo($theirs)->toString());
    app(ReceiveInboundEmail::class)->handle(TestMail::make()->to('facturas@example.com')->toString());

    app(CurrentAccount::class)->set($mine->account_id);

    expect(InboundEmail::pluck('company_id')->all())->toBe([$mine->id])
        ->and(InboundEmail::withoutGlobalScopes()->count())->toBe(3);
});
