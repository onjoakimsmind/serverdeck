<?php

namespace App\Providers;

use App\Models\User;
use App\Services\Host\NetworkConnectors\TailscaleConnector;
use App\Services\Host\NetworkConnectors\TwingateConnector;
use App\Services\HubReportingService;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->when(HubReportingService::class)
            ->needs('$connectors')
            ->give(fn () => [
                $this->app->make(TailscaleConnector::class),
                $this->app->make(TwingateConnector::class),
            ]);
    }

    public function boot(): void
    {
        Gate::define('operate', fn (User $user) => $user->canOperate());
        Gate::define('manage-users', fn (User $user) => $user->isAdmin());
        Gate::define('view-audit', fn (User $user) => $user->isAdmin());
        Gate::define('use-terminal', fn (User $user) => $user->canUseTerminal());
    }
}
