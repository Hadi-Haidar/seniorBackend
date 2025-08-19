<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Register middleware aliases programmatically to ensure availability
        $router = $this->app['router'];
        $router->aliasMiddleware('admin', \App\Http\Middleware\AdminMiddleware::class);
    }
}
