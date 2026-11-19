<?php

use App\Actions\ReceiveInboundEmail;
use App\Jobs\ProcessInboundEmail;
use App\Models\Company;
use App\Models\InboundEmail;
use App\Support\Inbox\Mailbox;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\Support\FakeMailbox;
use Tests\Support\TestMail;

beforeEach(function () {
    Storage::fake('documents');
    Queue::fake();
    config(['invoices.inbox.enabled' => true]);

    $this->mailbox = new FakeMailbox;
    $this->app->instance(Mailbox::class, $this->mailbox);
});

it('receives every new email, queues it and moves it out of the inbox', function () {
    $company = Company::factory()->create();
    $this->mailbox->add(TestMail::make()->deliveredTo($company->inboxAddress())->toString())
        ->add(TestMail::make()->deliveredTo($company->inboxAddress())->toString());

    $this->artisan('inbox:fetch')
        ->expectsOutput('Recibidos: 2. Ya existentes: 0. Con error: 0.')
        ->assertSuccessful();

    expect(InboundEmail::withoutGlobalScopes()->count())->toBe(2)
        ->and($this->mailbox->inbox)->toBeEmpty()
        ->and($this->mailbox->processed)->toBe(['1', '2']);

    Queue::assertPushed(ProcessInboundEmail::class, 2);
});

it('moves emails that were already received without creating them again', function () {
    $raw = TestMail::make()->messageId('dup@x.test')->toString();
    $this->mailbox->add($raw)->add($raw);

    $this->artisan('inbox:fetch')
        ->expectsOutput('Recibidos: 1. Ya existentes: 1. Con error: 0.')
        ->assertSuccessful();

    expect($this->mailbox->inbox)->toBeEmpty();
});

it('respects the batch limit', function () {
    foreach (range(1, 5) as $i) {
        $this->mailbox->add(TestMail::make()->toString());
    }

    $this->artisan('inbox:fetch', ['--limit' => 2])->assertSuccessful();

    expect($this->mailbox->inbox)->toHaveCount(3);
});

it('leaves an email in the inbox when it cannot be received', function () {
    $this->mailbox->add(TestMail::make()->toString());
    $this->mock(ReceiveInboundEmail::class)
        ->shouldReceive('handle')->andThrow(new RuntimeException('BD caída'));

    $this->artisan('inbox:fetch')
        ->expectsOutput('Recibidos: 0. Ya existentes: 0. Con error: 1.')
        ->assertFailed();

    expect($this->mailbox->inbox)->toHaveCount(1);
});

it('does nothing when the inbox is disabled', function () {
    config(['invoices.inbox.enabled' => false]);
    $this->mailbox->add(TestMail::make()->toString());

    $this->artisan('inbox:fetch')->assertSuccessful();

    expect($this->mailbox->inbox)->toHaveCount(1);
});

it('is scheduled every two minutes', function () {
    $event = collect(app(Schedule::class)->events())
        ->first(fn ($event) => str_contains((string) $event->command, 'inbox:fetch'));

    expect($event)->not->toBeNull()
        ->and($event->expression)->toBe('*/2 * * * *');
});
