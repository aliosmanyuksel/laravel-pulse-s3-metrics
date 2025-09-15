<?php

namespace AliOsmanYuksel\PulseS3Metrics\Recorders;

use AliOsmanYuksel\PulseS3Metrics\Events\S3MetricsRequested;
use Illuminate\Contracts\Config\Repository;
use Laravel\Pulse\Events\SharedBeat;
use Laravel\Pulse\Pulse;

class S3Metrics
{
    /**
     * The events to listen for.
     *
     * @var list<class-string>
     */
    public array $listen = [
        S3MetricsRequested::class,
        SharedBeat::class,
    ];

    public function __construct(
        protected Pulse $pulse,
        protected Repository $config
    ) {
        //
    }

    public function record(SharedBeat|S3MetricsRequested $event): void
    {
        // Run every hour when running in worker mode.
        // The S3 metrics are updated on CloudWatch daily, so we can relax.
        // For S3MetricsRequested events, always run (for testing purposes)
        if ($event instanceof SharedBeat &&
            $event->time->minute !== 0 &&
            $event->time->second !== 0
        ) {
            return;
        }

        $provider = config('pulse-s3-metrics.provider', 'aws');
        \Log::info('S3Metrics recorder called', ['provider' => $provider]);

        if ($provider === 'oci') {
            \Log::info('Recording OCI metrics');
            $this->recordOCIMetrics();
        } else {
            \Log::info('Recording AWS metrics');
            $this->recordAWSMetrics();
        }
    }

    private function recordAWSMetrics(): void
    {
        // Configure and instantiate the CloudWatch client.
        $cloudWatch = new \Aws\CloudWatch\CloudWatchClient([
            'version' => 'latest',
            'region' => config('pulse-s3-metrics.aws.region', config('pulse-s3-metrics.region')),
            'credentials' => [
                'key' => config('pulse-s3-metrics.aws.key', config('pulse-s3-metrics.key')),
                'secret' => config('pulse-s3-metrics.aws.secret', config('pulse-s3-metrics.secret')),
            ],
        ]);

        $bucket = config('pulse-s3-metrics.aws.bucket', config('pulse-s3-metrics.bucket'));
        $storageClass = config('pulse-s3-metrics.aws.class', config('pulse-s3-metrics.class'));

        // Create a slugged named for the bucket.
        $slug = sprintf('aws.%s.%s', $bucket, $storageClass);

        $this->fetchAndRecordMetrics($cloudWatch, $bucket, $storageClass, $slug, 'AWS/S3');
    }

    private function recordOCIMetrics(): void
    {
        // Use filesystem config for OCI
        $ociConfig = config('filesystems.disks.oci');
        
        if (!$ociConfig) {
            \Log::error('OCI filesystem configuration not found');
            return;
        }

        // For OCI, we'll simulate metrics based on actual bucket usage
        // since OCI monitoring API is different from AWS CloudWatch
        $bucket = $ociConfig['bucket'];
        $storageClass = config('pulse-s3-metrics.oci.class', 'StandardStorage');
        
        // Create a slugged named for the bucket.
        $slug = sprintf('oci.%s.%s', $bucket, $storageClass);

        try {
            \Log::info('Creating S3 client for OCI', ['bucket' => $bucket, 'endpoint' => $ociConfig['endpoint']]);
            
            // Create S3 client to get actual bucket statistics
            $s3Client = new \Aws\S3\S3Client([
                'version' => 'latest',
                'region' => $ociConfig['region'],
                'endpoint' => $ociConfig['endpoint'],
                'credentials' => [
                    'key' => $ociConfig['key'],
                    'secret' => $ociConfig['secret'],
                ],
                'use_path_style_endpoint' => true,
            ]);
            
            \Log::info('S3 client created successfully');

            // Get bucket statistics
            $totalSize = 0;
            $totalObjects = 0;
            
            \Log::info('Listing objects in bucket', ['bucket' => $bucket]);
            $objects = $s3Client->listObjectsV2([
                'Bucket' => $bucket,
                'MaxKeys' => 1000
            ]);

            foreach ($objects['Contents'] ?? [] as $object) {
                $totalSize += $object['Size'];
                $totalObjects++;
            }
            
            \Log::info('Bucket statistics collected', [
                'bucket' => $bucket,
                'totalSize' => $totalSize,
                'totalObjects' => $totalObjects
            ]);

            // Record the metrics using Pulse's built-in methods
            \Log::info('Recording metrics to Pulse', ['slug' => $slug, 'size' => $totalSize, 'objects' => $totalObjects]);
            
            $this->pulse->record(
                type: 's3_bytes',
                key: $slug,
                value: $totalSize,
                timestamp: time(),
            );
            
            $this->pulse->record(
                type: 's3_objects',
                key: $slug,
                value: $totalObjects,
                timestamp: time(),
            );

            $provider = 'OCI';
            $values = json_encode([
                'name' => $bucket,
                'provider' => $provider,
                'storage_class' => $storageClass,
                'size_current' => $totalSize,
                'size_peak' => $totalSize,
                'objects_current' => $totalObjects,
                'objects_peak' => $totalObjects,
            ], flags: JSON_THROW_ON_ERROR);
            
            $this->pulse->set('s3_bucket', $slug, $values);
            \Log::info('Metrics recorded successfully', ['slug' => $slug]);

        } catch (\Exception $e) {
            \Log::error('OCI S3 Metrics collection failed: ' . $e->getMessage(), [
                'provider' => 'oci',
                'bucket' => $bucket,
            ]);
        }
    }

    private function fetchAndRecordMetrics($cloudWatch, $bucket, $storageClass, $slug, $namespace): void
    {
        try {
            // Get the bucket size in bytes from CloudWatch.
            // By default, the last 14 days are stored.
            $result = $cloudWatch->getMetricStatistics([
                'Namespace' => $namespace,
                'MetricName' => 'BucketSizeBytes',
                'Dimensions' => [
                    [
                        'Name' => 'BucketName',
                        'Value' => $bucket,
                    ],
                    [
                        'Name' => 'StorageType',
                        'Value' => $storageClass,
                    ],
                ],
                'StartTime' => strtotime('-14 days midnight UTC'),
                'EndTime' => strtotime('tomorrow midnight UTC'),
                'Period' => 86400,
                'Statistics' => ['Average'],
            ]);

            // Convert the CloudWatch data into a normalized collection of metrics.
            $bytes = collect($result['Datapoints'])
                ->mapWithKeys(fn ($datapoint) => [$datapoint['Timestamp']->format('U') => $datapoint['Average']])
                ->sortKeys()
                ->each(fn ($value, $timestamp) => $this->recordBytesUsedForBucket($slug, $timestamp, $value));

            // Get the number of objects in the bucket from CloudWatch.
            // By default, the last 14 days are stored.
            $result = $cloudWatch->getMetricStatistics([
                'Namespace' => $namespace,
                'MetricName' => 'NumberOfObjects',
                'Dimensions' => [
                    [
                        'Name' => 'BucketName',
                        'Value' => $bucket,
                    ],
                    [
                        'Name' => 'StorageType',
                        'Value' => 'AllStorageTypes',
                    ],
                ],
                'StartTime' => strtotime('-14 days midnight UTC'),
                'EndTime' => strtotime('tomorrow midnight UTC'),
                'Period' => 86400,
                'Statistics' => ['Average'],
            ]);

            // Convert the CloudWatch data into a normalized collection of metrics.
            $objects = collect($result['Datapoints'])
                ->mapWithKeys(fn ($datapoint) => [$datapoint['Timestamp']->format('U') => $datapoint['Average']])
                ->sortKeys()
                ->each(fn ($value, $timestamp) => $this->recordObjectsForBucket($slug, $timestamp, $value));

            $provider = config('pulse-s3-metrics.provider', 'aws');
            $providerName = $provider === 'oci' ? 'OCI' : 'AWS';

            $this->pulse->set('s3_bucket', $slug, $values = json_encode([
                'name' => $bucket,
                'provider' => $providerName,
                'storage_class' => $storageClass,
                'size_current' => (int) ($bytes->filter()->last() ?? 0),
                'size_peak' => (int) ($bytes->max() ?? 0),
                'objects_current' => (int) ($objects->filter()->last() ?? 0),
                'objects_peak' => (int) ($objects->max() ?? 0),
            ], flags: JSON_THROW_ON_ERROR));

        } catch (\Exception $e) {
            // Log the error but don't break the application
            \Log::error('S3 Metrics collection failed: ' . $e->getMessage(), [
                'provider' => config('pulse-s3-metrics.provider', 'aws'),
                'bucket' => $bucket,
                'namespace' => $namespace,
            ]);
        }
    }

    private function recordBytesUsedForBucket($slug, $timestamp, $value): void
    {
        $this->pulse->record(
            type: 's3_bytes',
            key: $slug,
            value: $value,
            timestamp: $timestamp,
        )->max()->onlyBuckets();
    }

    private function recordObjectsForBucket($slug, $timestamp, $value): void
    {
        $this->pulse->record(
            type: 's3_objects',
            key: $slug,
            value: $value,
            timestamp: $timestamp,
        )->max()->onlyBuckets();
    }
}