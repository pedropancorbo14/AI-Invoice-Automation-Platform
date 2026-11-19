<?php

namespace App\Providers;

use App\Support\Inbox\ImapMailbox;
use App\Support\Inbox\Mailbox;
use App\Support\Tenancy\CurrentAccount;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // "scoped": se reinicia en cada petición y en cada job de la cola,
        // así una cuenta nunca se filtra al siguiente job del worker.
        $this->app->scoped(CurrentAccount::class);

        $this->app->bind(Mailbox::class, fn () => new ImapMailbox((array) config('invoices.inbox.imap')));
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Model::shouldBeStrict(! $this->app->isProduction());
    }
}
