<?php

namespace App\Providers;

use App\Models\User;
use Illuminate\Support\Facades\Gate;
use Laravel\Horizon\Horizon;
use Laravel\Horizon\HorizonApplicationServiceProvider;

class HorizonServiceProvider extends HorizonApplicationServiceProvider
{
    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        parent::boot();

        // Horizon::routeSmsNotificationsTo('15556667777');
        // Horizon::routeMailNotificationsTo('example@example.com');
        // Horizon::routeSlackNotificationsTo('slack-webhook-url', '#channel');
    }

    /**
     * Register the Horizon gate.
     *
     * This gate determines who can access Horizon in non-local environments.
     */
    protected function gate(): void
    {
        // Horizon es para quien opera la plataforma, no para los clientes:
        // solo los emails de OPERATOR_EMAILS, sea cual sea su cuenta o rol.
        Gate::define('viewHorizon', function (?User $user = null): bool {
            return $user !== null
                && in_array($user->email, (array) config('invoices.operator_emails'), true);
        });
    }
}
