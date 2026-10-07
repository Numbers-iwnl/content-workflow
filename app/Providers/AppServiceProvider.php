<?php

namespace App\Providers;

use App\Models\Setting;
use App\Services\Drive\DriveApi;
use App\Services\Drive\GoogleDrive;
use App\Support\Pipeline;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\View;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->singleton(DriveApi::class, fn () => GoogleDrive::fromConfig());
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Contadores do menu lateral da área da Ana.
        View::composer('components.layouts.workspace', fn ($view) => $view->with([
            'stageCounts' => Pipeline::counts(),
            'lastSync' => ($at = Setting::get('drive.last_sync_at')) ? Carbon::parse($at) : null,
        ]));
    }
}
