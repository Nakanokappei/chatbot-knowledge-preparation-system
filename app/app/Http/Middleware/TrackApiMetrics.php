<?php

namespace App\Http\Middleware;

use App\Services\MetricsService;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

/**
 * Middleware to track API metrics for /api/retrieve and /api/chat.
 *
 * Captures latency, token usage, and errors for CloudWatch monitoring.
 * Failures in metrics recording are silently logged — never block the response.
 */
class TrackApiMetrics
{
    /**
     * Wrap the request lifecycle to measure latency, then publish
     * endpoint-specific metrics to CloudWatch after the response.
     */
    public function handle(Request $request, Closure $next): Response
    {
        $startTime = microtime(true);

        $response = $next($request);

        // Record metrics in a try/catch so failures never block the response
        try {
            $latencyMs = (microtime(true) - $startTime) * 1000;
            $metrics = new MetricsService();
            $workspaceId = $request->user()?->workspace_id ?? 0;
            $isError = $response->getStatusCode() >= 400;

            $path = $request->path();

            if (str_contains($path, 'retrieve')) {
                $data = json_decode($response->getContent(), true) ?? [];
                $resultCount = count($data['results'] ?? []);
                $topSimilarity = $resultCount > 0 ? ($data['results'][0]['similarity'] ?? 0) : 0;
                $datasetId = $request->input('dataset_id', 0);

                $metrics->recordRetrieval($workspaceId, $datasetId, $latencyMs, $resultCount, $topSimilarity);
            } elseif (str_contains($path, 'chat')) {
                $data = json_decode($response->getContent(), true) ?? [];
                $modelId = $data['model'] ?? 'unknown';
                $inputTokens = $data['usage']['input_tokens'] ?? 0;
                $outputTokens = $data['usage']['output_tokens'] ?? 0;

                $metrics->recordChat($workspaceId, $modelId, $latencyMs, $inputTokens, $outputTokens, $isError);
            }
        } catch (\Exception $e) {
            Log::warning('TrackApiMetrics failed: ' . $e->getMessage());
        }

        return $response;
    }
}
