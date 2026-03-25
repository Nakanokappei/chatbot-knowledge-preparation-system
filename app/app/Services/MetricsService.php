<?php

namespace App\Services;

use Aws\CloudWatch\CloudWatchClient;
use Illuminate\Support\Facades\Log;

/**
 * CloudWatch custom metrics for CKPS platform.
 *
 * Publishes operational, quality, cost, and growth metrics.
 * CTO-required metrics: latency, quality, cost, growth.
 */
class MetricsService
{
    private CloudWatchClient $client;
    private const NAMESPACE = 'CKPS';

    /**
     * Initialize the CloudWatch client for the configured AWS region.
     */
    public function __construct()
    {
        $this->client = new CloudWatchClient([
            'region' => config('services.bedrock.region', 'ap-northeast-1'),
            'version' => 'latest',
        ]);
    }

    /**
     * Record retrieval API metrics.
     */
    public function recordRetrieval(int $tenantId, int $datasetId, float $latencyMs, int $resultCount, float $topSimilarity): void
    {
        $this->putMetrics([
            ['Name' => 'RetrievalLatency', 'Value' => $latencyMs, 'Unit' => 'Milliseconds',
             'Dimensions' => [['Name' => 'tenant_id', 'Value' => (string) $tenantId]]],
            ['Name' => 'RetrievalResultCount', 'Value' => $resultCount, 'Unit' => 'Count',
             'Dimensions' => [['Name' => 'tenant_id', 'Value' => (string) $tenantId]]],
            ['Name' => 'RetrievalHitRate', 'Value' => $topSimilarity, 'Unit' => 'None',
             'Dimensions' => [['Name' => 'dataset_id', 'Value' => (string) $datasetId]]],
        ]);
    }

    /**
     * Record chat API metrics.
     */
    public function recordChat(int $tenantId, string $modelId, float $latencyMs, int $inputTokens, int $outputTokens, bool $isError = false): void
    {
        $metrics = [
            ['Name' => 'ChatLatency', 'Value' => $latencyMs, 'Unit' => 'Milliseconds',
             'Dimensions' => [['Name' => 'tenant_id', 'Value' => (string) $tenantId]]],
            ['Name' => 'ChatTokensUsed', 'Value' => $inputTokens + $outputTokens, 'Unit' => 'Count',
             'Dimensions' => [['Name' => 'tenant_id', 'Value' => (string) $tenantId],
                              ['Name' => 'model_id', 'Value' => $modelId]]],
        ];

        if ($isError) {
            $metrics[] = ['Name' => 'ChatErrorRate', 'Value' => 1, 'Unit' => 'Count',
                          'Dimensions' => [['Name' => 'tenant_id', 'Value' => (string) $tenantId]]];
        }

        $this->putMetrics($metrics);
    }

    /**
     * Record Bedrock LLM invocation latency.
     */
    public function recordBedrockLatency(float $latencyMs, string $modelId): void
    {
        $this->putMetrics([
            ['Name' => 'BedrockLatency', 'Value' => $latencyMs, 'Unit' => 'Milliseconds',
             'Dimensions' => [['Name' => 'model_id', 'Value' => $modelId]]],
        ]);
    }

    /**
     * Record pgvector query execution time.
     */
    public function recordPgVectorQueryTime(float $queryTimeMs, int $datasetId): void
    {
        $this->putMetrics([
            ['Name' => 'PgVectorQueryTime', 'Value' => $queryTimeMs, 'Unit' => 'Milliseconds',
             'Dimensions' => [['Name' => 'dataset_id', 'Value' => (string) $datasetId]]],
        ]);
    }

    /**
     * Record embedding generation latency.
     */
    public function recordEmbeddingLatency(int $tenantId, float $latencyMs): void
    {
        $this->putMetrics([
            ['Name' => 'EmbeddingLatency', 'Value' => $latencyMs, 'Unit' => 'Milliseconds',
             'Dimensions' => [['Name' => 'tenant_id', 'Value' => (string) $tenantId]]],
        ]);
    }

    /**
     * Record daily cost metric.
     */
    public function recordTokenCost(float $costUsd): void
    {
        $this->putMetrics([
            ['Name' => 'TokenCostPerDay', 'Value' => $costUsd, 'Unit' => 'None',
             'Dimensions' => []],
        ]);
    }

    /**
     * Batch publish metrics to CloudWatch.
     */
    private function putMetrics(array $metricData): void
    {
        try {
            $this->client->putMetricData([
                'Namespace' => self::NAMESPACE,
                'MetricData' => array_map(function ($metric) {
                    return [
                        'MetricName' => $metric['Name'],
                        'Value' => $metric['Value'],
                        'Unit' => $metric['Unit'],
                        'Dimensions' => $metric['Dimensions'] ?? [],
                        'Timestamp' => now()->toIso8601String(),
                    ];
                }, $metricData),
            ]);
        } catch (\Exception $e) {
            // Metrics failure should never break the application
            Log::warning('CloudWatch metrics publish failed: ' . $e->getMessage());
        }
    }
}
