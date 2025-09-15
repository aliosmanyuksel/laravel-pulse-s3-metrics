![Screenshot of the S3 Metrics Pulse card](art%2Fheader.png)

# S3 Metrics Card for Laravel Pulse (Laravel 12 Compatible)

[![Latest Version on Packagist](https://img.shields.io/packagist/v/aliosmanyuksel/laravel-pulse-s3-metrics.svg?style=flat-square)](https://packagist.org/packages/aliosmanyuksel/laravel-pulse-s3-metrics)
[![GitHub Tests Action Status](https://img.shields.io/github/actions/workflow/status/aliosmanyuksel/laravel-pulse-s3-metrics/run-tests.yml?branch=main&label=tests&style=flat-square)](https://github.com/aliosmanyuksel/laravel-pulse-s3-metrics/actions?query=workflow%3Arun-tests+branch%3Amain)
[![GitHub Code Style Action Status](https://img.shields.io/github/actions/workflow/status/aliosmanyuksel/laravel-pulse-s3-metrics/fix-php-code-style-issues.yml?branch=main&label=code%20style&style=flat-square)](https://github.com/aliosmanyuksel/laravel-pulse-s3-metrics/actions?query=workflow%3A"Fix+PHP+code+style+issues"+branch%3Amain)
[![Total Downloads](https://img.shields.io/packagist/dt/aliosmanyuksel/laravel-pulse-s3-metrics.svg?style=flat-square)](https://packagist.org/packages/aliosmanyuksel/laravel-pulse-s3-metrics)

Fetch existing data usage and storage metrics from AWS CloudWatch for your S3 buckets and display them in a card on your [Laravel Pulse](https://pulse.laravel.com/) dashboard.

**This is a Laravel 12 compatible fork of the original package by Arcana Softworks.**

![Screenshot of the S3 Metrics Pulse card](art%2Fscreenshot1.png)

## Original Package

This package is a Laravel 12 compatible fork of [arcana-softworks/laravel-pulse-s3-metrics](https://github.com/arcana-softworks/laravel-pulse-s3-metrics). 

Developers at [Arcana Softworks](https://arcana-softworks.co.uk) have been building business-class PHP applications for more than 10 years. [Learn more about what they can do for you or your business](https://arcana-softworks.co.uk).

## Installation

You can install the package via composer:

```bash
composer require aliosmanyuksel/laravel-pulse-s3-metrics
```

You can optionally publish the config file with:

```bash
php artisan vendor:publish --tag="laravel-pulse-s3-metrics-config"
```

This is the contents of the published config file:

```php
return [

    'enabled' => env('PULSE_S3_METRICS_ENABLED', true),

    'key' => env('AWS_ACCESS_KEY_ID'),

    'secret' => env('AWS_SECRET_ACCESS_KEY'),

    'region' => env('AWS_DEFAULT_REGION'),

    'bucket' => env('AWS_BUCKET'),

    'class' => env('AWS_STORAGE_CLASS', 'StandardStorage'),
    
];
```

By default, this package will fetch metrics for the S3 bucket specified in your `AWS_BUCKET` environment variable. You can override this by setting the `bucket` config value.

One variable you may be missing is the `AWS_STORAGE_CLASS` variable, which has been introduced by this package. This should be set to the storage class of your S3 bucket. The default value is `StandardStorage`, which is the default storage class for S3 buckets. If you have a different storage class, you should set this variable to the appropriate value.

The region should be the region where your S3 metrics are stored on CloudWatch.

### Install the Recorder

Publish the Laravel Pulse config so that you may add the S3 Metrics recorder:

```bash
php artisan vendor:publish --tag=pulse-config
```

This will publish the Pulse config to `config/pulse.php`

Add the S3 Metrics recorder to the `recorders` section of the Pulse config:

```php
'recorders' => [
    
    // Existing recorders...
    // ...
    
    \AliOsmanYuksel\PulseS3Metrics\Recorders\S3Metrics::class => [
        'enabled' => env('PULSE_S3_METRICS_ENABLED', true),
    ],
    
],
```

### Add the card to your Laravel Pulse dashboard

Publish the Laravel Pulse dashboard view:

```bash
php artisan vendor:publish --tag=pulse-dashboard
```

This will publish the Pulse dashboard view to `resources/views/vendor/pulse/dashboard.blade.php`

Add the S3 Metrics card to your dashboard (the card looks best at full width):

```blade
<x-pulse>

    <livewire:pulse-s3-metrics cols="full" />
    
    {{-- Existing cards... --}}
    
</x-pulse>
```

## Usage

The S3 Metrics card may not show metrics from your S3 bucket immediately. It may take up to 24 hours for metrics to be available on CloudWatch.

The recorder will run periodically whilst `php artisan pulse:work` is running. You can run this command in a terminal window to start the recorder:

```bash
php artisan pulse:check
```

## Laravel 12 Compatibility

This package has been specifically adapted for Laravel 12 compatibility:

- Updated to `illuminate/contracts: ^12.0`
- Updated to `laravel/pulse: ^1.4`
- Updated to `livewire/livewire: ^3.4`
- Updated to `php: ^8.4`
- Updated dev dependencies for Laravel 12

## Testing

```bash
composer test
```

## Changelog

Please see [CHANGELOG](CHANGELOG.md) for more information on what has changed recently.

## Contributing

Please see [CONTRIBUTING](CONTRIBUTING.md) for details.

## Security Vulnerabilities

Please review our security policy on how to report security vulnerabilities.

## Credits

- [Liam Anderson](https://github.com/liam-anderson) - Original Developer
- [Arcana Softworks](https://github.com/arcana-softworks) - Original Package
- [Ali Osman Yüksel](https://github.com/aliosmanyuksel) - Laravel 12 Adapter
- All Contributors

## License

The MIT License (MIT). Please see [License File](LICENSE.md) for more information.

## About

Display S3 bucket metrics on your Laravel Pulse dashboard - Laravel 12 compatible version