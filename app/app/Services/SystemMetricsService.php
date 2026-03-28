<?php

namespace App\Services;

use Aws\CloudWatch\CloudWatchClient;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Collects system health metrics for the admin dashboard.
 *
 * Pulls infrastructure metrics (CPU, memory, DB connections) from CloudWatch
 * and application metrics (chat latency, pipeline duration, error rate) from
 * the database. All data is scoped to the past 24 hours in 1-hour intervals.
 */
class SystemMetricsService
{
    private CloudWatchClient $cloudWatch;

    /** JST offset used when formatting PostgreSQL timestamp results. */
    private const TZ = 'Asia/Tokyo';

    public function __construct()
    {
        $this->cloudWatch = new CloudWatchClient([
            'region'  => config('services.bedrock.region', 'ap-northeast-1'),
            'version' => 'latest',
        ]);
    }

    /**
     * Collect all system metrics for the past 24 hours.
     *
     * Returns a keyed array of metric series. Each series is an array of
     * {key: 'Y-m-d H', hour: 'HH:00', value: ...} objects that the view
     * aligns to a 24-slot skeleton.
     *
     * CloudWatch fetch failures are caught and logged; the method always
     * returns a complete structure (with empty arrays for unavailable metrics).
     */
    public function getLast24Hours(): array
    {
        return [
            'ecs'        => $this->fetchEcsMetrics(),
            'rds'        => $this->fetchRdsConnections(),
            'chat'       => $this->fetchChatMetrics(),
            'pipeline'   => $this->fetchPipelineMetrics(),
            'error_rate' => $this->fetchErrorRate(),
        ];
    }

    // ── CloudWatch: ECS ───────────────────────────────────────────────────────

    /**
     * Fetch ECS CPU and Memory utilization from CloudWatch (1-hour averages).
     */
    private function fetchEcsMetrics(): array
    {
        $cluster = config('services.system_metrics.ecs_cluster');
        $service = config('services.system_metrics.ecs_service');

        if (!$cluster || !$service) {
            return ['cpu' => [], 'memory' => []];
        }

        try {
            $result = $this->cloudWatch->getMetricData([
                'StartTime'          => now()->subHours(25)->toIso8601String(),
                'EndTime'            => now()->toIso8601String(),
                'ScanBy'             => 'TimestampAscending',
                'MetricDataQueries'  => [
                    [
                        'Id'         => 'cpu',
                        'MetricStat' => [
                            'Metric' => [
                                'Namespace'  => 'AWS/ECS',
                                'MetricName' => 'CPUUtilization',
                                'Dimensions' => [
                                    ['Name' => 'ClusterName', 'Value' => $cluster],
                                    ['Name' => 'ServiceName', 'Value' => $service],
                                ],
                            ],
                            'Period' => 3600,
                            'Stat'   => 'Average',
                        ],
                    ],
                    [
                        'Id'         => 'memory',
                        'MetricStat' => [
                            'Metric' => [
                                'Namespace'  => 'AWS/ECS',
                                'MetricName' => 'MemoryUtilization',
                                'Dimensions' => [
                                    ['Name' => 'ClusterName', 'Value' => $cluster],
                                    ['Name' => 'ServiceName', 'Value' => $service],
                                ],
                            ],
                            'Period' => 3600,
                            'Stat'   => 'Average',
                        ],
                    ],
                ],
            ]);

            return $this->parseCloudWatchResult($result['MetricDataResults'], ['cpu', 'memory']);
        } catch (\Exception $e) {
            Log::warning('CloudWatch ECS metrics failed: ' . $e->getMessage());
            return ['cpu' => [], 'memory' => []];
        }
    }

    // ── CloudWatch: RDS ───────────────────────────────────────────────────────

    /**
     * Fetch RDS DatabaseConnections from CloudWatch (1-hour averages).
     */
    private function fetchRdsConnections(): array
    {
        $instance = config('services.system_metrics.rds_instance');

        if (!$instance) {
            return ['connections' => []];
        }

        try {
            $result = $this->cloudWatch->getMetricData([
                'StartTime'         => now()->subHours(25)->toIso8601String(),
                'EndTime'           => now()->toIso8601String(),
                'ScanBy'            => 'TimestampAscending',
                'MetricDataQueries' => [
                    [
                        'Id'         => 'connections',
                        'MetricStat' => [
                            'Metric' => [
                                'Namespace'  => 'AWS/RDS',
                                'MetricName' => 'DatabaseConnections',
                                'Dimensions' => [
                                    ['Name' => 'DBInstanceIdentifier', 'Value' => $instance],
                                ],
                            ],
                            'Period' => 3600,
                            'Stat'   => 'Average',
                        ],
                    ],
                ],
            ]);

            return $this->parseCloudWatchResult($result['MetricDataResults'], ['connections']);
        } catch (\Exception $e) {
            Log::warning('CloudWatch RDS metrics failed: ' . $e->getMessage());
            return ['connections' => []];
        }
    }

    // ── Database: Chat latency ────────────────────────────────────────────────

    /**
     * Aggregate average LLM response latency per hour for the past 24 hours.
     */
    private function fetchChatMetrics(): array
    {
        $rows = DB::table('chat_turns')
            ->where('role', 'assistant')
            ->whereNotNull('response_ms')
            ->where('created_at', '>=', now()->subHours(24))
            ->selectRaw("
                DATE_TRUNC('hour', created_at AT TIME ZONE '" . self::TZ . "') as hour,
                AVG(response_ms) as avg_ms,
                COUNT(*) as count
            ")
            ->groupBy(DB::raw("DATE_TRUNC('hour', created_at AT TIME ZONE '" . self::TZ . "')"))
            ->orderBy('hour')
            ->get();

        return $rows->map(fn($r) => [
            'key'    => $this->hourKey($r->hour),
            'hour'   => $this->hourLabel($r->hour),
            'avg_ms' => (int) round((float) $r->avg_ms),
            'count'  => (int) $r->count,
        ])->toArray();
    }

    // ── Database: Pipeline duration ───────────────────────────────────────────

    /**
     * Aggregate average pipeline completion time per hour for the past 24 hours.
     *
     * Duration is calculated from started_at to completed_at (existing columns).
     */
    private function fetchPipelineMetrics(): array
    {
        $rows = DB::table('pipeline_jobs')
            ->where('status', 'completed')
            ->whereNotNull('started_at')
            ->whereNotNull('completed_at')
            ->where('completed_at', '>=', now()->subHours(24))
            ->selectRaw("
                DATE_TRUNC('hour', completed_at AT TIME ZONE '" . self::TZ . "') as hour,
                AVG(EXTRACT(EPOCH FROM (completed_at - started_at))) as avg_seconds,
                COUNT(*) as count
            ")
            ->groupBy(DB::raw("DATE_TRUNC('hour', completed_at AT TIME ZONE '" . self::TZ . "')"))
            ->orderBy('hour')
            ->get();

        return $rows->map(fn($r) => [
            'key'         => $this->hourKey($r->hour),
            'hour'        => $this->hourLabel($r->hour),
            'avg_seconds' => (int) round((float) $r->avg_seconds),
            'count'       => (int) $r->count,
        ])->toArray();
    }

    // ── Database: Error rate ──────────────────────────────────────────────────

    /**
     * Calculate the fraction of assistant turns that ended with no_match
     * or rejected action, per hour, for the past 24 hours.
     */
    private function fetchErrorRate(): array
    {
        $rows = DB::table('chat_turns')
            ->where('role', 'assistant')
            ->where('created_at', '>=', now()->subHours(24))
            ->selectRaw("
                DATE_TRUNC('hour', created_at AT TIME ZONE '" . self::TZ . "') as hour,
                COUNT(*) FILTER (WHERE action IN ('no_match', 'rejected')) as errors,
                COUNT(*) as total
            ")
            ->groupBy(DB::raw("DATE_TRUNC('hour', created_at AT TIME ZONE '" . self::TZ . "')"))
            ->orderBy('hour')
            ->get();

        return $rows->map(fn($r) => [
            'key'    => $this->hourKey($r->hour),
            'hour'   => $this->hourLabel($r->hour),
            'errors' => (int) $r->errors,
            'total'  => (int) $r->total,
            'rate'   => $r->total > 0 ? round(($r->errors / $r->total) * 100, 1) : 0,
        ])->toArray();
    }

    // ── CloudWatch parsing helpers ─────────────────────────────────────────────

    /**
     * Normalize a CloudWatch GetMetricData response into {key, hour, value} arrays.
     *
     * Timestamps from CloudWatch are UTC DateTime objects. We convert them to
     * Asia/Tokyo before building the hour key so they align with the DB queries.
     */
    private function parseCloudWatchResult(array $results, array $ids): array
    {
        $data = array_fill_keys($ids, []);

        foreach ($results as $result) {
            $id         = $result['Id'];
            $timestamps = $result['Timestamps'] ?? [];
            $values     = $result['Values'] ?? [];

            // Sort ascending (CloudWatch may return in any order)
            array_multisort($timestamps, SORT_ASC, $values);

            foreach ($timestamps as $i => $ts) {
                $carbon       = Carbon::instance($ts)->setTimezone(self::TZ);
                $data[$id][]  = [
                    'key'   => $carbon->format('Y-m-d H'),
                    'hour'  => $carbon->format('H:00'),
                    'value' => round((float) $values[$i], 1),
                ];
            }
        }

        return $data;
    }

    // ── Formatting helpers ─────────────────────────────────────────────────────

    /**
     * Extract a sortable hour key ("Y-m-d H") from a PostgreSQL TIMESTAMP string.
     *
     * PostgreSQL DATE_TRUNC returns strings like "2026-03-28 14:00:00".
     * We take the first 13 characters to get "2026-03-28 14".
     */
    private function hourKey(string $pgTimestamp): string
    {
        return substr($pgTimestamp, 0, 13);
    }

    /**
     * Extract a display hour label ("HH:00") from a PostgreSQL TIMESTAMP string.
     */
    private function hourLabel(string $pgTimestamp): string
    {
        return substr($pgTimestamp, 11, 2) . ':00';
    }
}
