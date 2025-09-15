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
        if ($event instanceof SharedBeat &&
            $event->time->minute !== 0 &&
            $event->time->second !== 0
        ) {
            return;
        }

        $provider = config('pulse-s3-metrics.provider', 'aws');

        if ($provider === 'oci') {
            $this->recordOCIMetrics();
        } else {
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
        // Configure and instantiate the CloudWatch client for OCI.
        // OCI uses AWS CloudWatch compatible API with different endpoint
        $region = config('pulse-s3-metrics.oci.region');
        $cloudWatch = new \Aws\CloudWatch\CloudWatchClient([
            'version' => 'latest',
            'region' => $region,
            'endpoint' => sprintf('https://telemetry-ingestion.%s.oraclecloud.com', $region),
            'credentials' => [
                'key' => config('pulse-s3-metrics.oci.key'),
                'secret' => config('pulse-s3-metrics.oci.secret'),
            ],
        ]);

        $bucket = config('pulse-s3-metrics.oci.bucket');
        $storageClass = config('pulse-s3-metrics.oci.class');
        $namespace = config('pulse-s3-metrics.oci.namespace');

        // Create a slugged named for the bucket.
        $slug = sprintf('oci.%s.%s', $bucket, $storageClass);

        // OCI uses a different namespace format
        $ociNamespace = sprintf('oci_objectstorage_%s', $namespace);
        
        $this->fetchAndRecordMetrics($cloudWatch, $bucket, $storageClass, $slug, $ociNamespace);
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