<?php

namespace AliOsmanYuksel\PulseS3Metrics;

use AliOsmanYuksel\PulseS3Metrics\Events\S3MetricsRequested;
use AliOsmanYuksel\PulseS3Metrics\Livewire\PulseS3Metrics;
use AliOsmanYuksel\PulseS3Metrics\Recorders\S3Metrics;
use Illuminate\Foundation\Application;
use Livewire\LivewireManager;
use Spatie\LaravelPackageTools\Package;
use Spatie\LaravelPackageTools\PackageServiceProvider;

class PulseS3MetricsServiceProvider extends PackageServiceProvider
{
    public function configurePackage(Package $package): void
    {
        /*
         * This class is a Package Service Provider
         *
         * More info: https://github.com/spatie/laravel-package-tools
         */
        $package
            ->name('laravel-pulse-s3-metrics')
            ->hasConfigFile()
            ->hasViews();
    }

    public function bootingPackage(): void
    {
        $this->callAfterResolving('livewire', function (LivewireManager $livewire, Application $app) {
            $livewire->component('pulse-s3-metrics', PulseS3Metrics::class);
        });
        
        // Register event listener for S3MetricsRequested
        $this->callAfterResolving('events', function ($events, Application $app) {
            $events->listen(S3MetricsRequested::class, function (S3MetricsRequested $event) use ($app) {
                $recorder = $app->make(S3Metrics::class);
                $recorder->record($event);
            });
        });
    }
}
