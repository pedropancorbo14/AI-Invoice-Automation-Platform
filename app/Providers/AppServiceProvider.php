<?php

namespace App\Providers;

use Anthropic\Client;
use App\Support\Extraction\ClaudeProvider;
use App\Support\Extraction\LLMProvider;
use App\Support\Inbox\ImapMailbox;
use App\Support\Inbox\Mailbox;
use App\Support\Tenancy\CurrentAccount;
use App\Support\Verifactu\QrCodeReader;
use App\Support\Verifactu\ZbarQrCodeReader;
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

        // Claude lee ANTHROPIC_API_KEY del entorno. Se crea solo cuando un job lo necesita.
        $this->app->bind(LLMProvider::class, fn () => new ClaudeProvider(
            new Client(requestOptions: ['maxRetries' => 2]),
            (array) config('invoices.extraction'),
        ));

        $this->app->bind(QrCodeReader::class, ZbarQrCodeReader::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Model::shouldBeStrict(! $this->app->isProduction());
    }
}
