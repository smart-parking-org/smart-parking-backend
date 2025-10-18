<?php

namespace App\Providers;

use App\Services\SlotAllocationService;
use Illuminate\Support\ServiceProvider;

class SlotAllocationServiceProvider extends ServiceProvider
{
    /**
     * Register services.
     */
    public function register(): void
    {
        $this->app->singleton(SlotAllocationService::class, function ($app) {
            return new SlotAllocationService();
        });
    }

    /**
     * Bootstrap services.
     */
    public function boot(): void
    {
        //
    }
}
