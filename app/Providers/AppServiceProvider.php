<?php

namespace App\Providers;

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
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Model::shouldBeStrict(! $this->app->isProduction());
    }
}
