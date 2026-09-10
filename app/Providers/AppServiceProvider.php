<?php

namespace App\Providers;

use App\Contracts\SentimentClassifier;
use App\Services\FakeFlakyClassifier;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(SentimentClassifier::class, FakeFlakyClassifier::class);
    }

    public function boot(): void
    {
        //
    }
}
