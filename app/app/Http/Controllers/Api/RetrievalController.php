<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\KnowledgePackage;
use App\Services\BedrockService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Vector similarity search against published Knowledge Datasets.
 *
 * CTO-defined response format: topic, intent, summary, resolution_summary, similarity.
 * Embedding generated via Bedrock Titan Embed v2; search via pgvector cosine distance.
 */
class RetrievalController extends Controller
{
    /**
     * Retrieve similar Knowledge Units from a published dataset.
     */
    public function retrieve(Request $request): JsonResponse
    {
        $request->validate([
            'query' => 'required|string|max:2000',
            'dataset_id' => 'required|integer|exists:knowledge_packages,id',
            'top_k' => 'integer|min:1|max:20',
            'min_similarity' => 'numeric|min:0|max:1',
        ]);

        $workspaceId = $request->user()->workspace_id;
        $topK = $request->input('top_k', 5);
        $minSimilarity = $request->input('min_similarity', 0.0);
        $startTime = microtime(true);

        // Verify package is published and belongs to this workspace
        $package = KnowledgePackage::where('id', $request->dataset_id)
            ->where('workspace_id', $workspaceId)
            ->where('status', 'published')
            ->first();

        if (! $package) {
            return response()->json([
                'error' => 'Package not found or not published.',
            ], 404);
        }

        // Generate query embedding via Bedrock
        $bedrock = new BedrockService();
        $queryEmbedding = $bedrock->generateEmbedding($request->query('query', $request->input('query')));
        $vectorString = '[' . implode(',', $queryEmbedding) . ']';

        // pgvector cosine similarity search scoped to package items
        $results = DB::select("
            SELECT
                ku.id AS knowledge_unit_id,
                ku.topic,
                ku.intent,
                ku.summary,
                ku.resolution_summary,
                ku.confidence,
                1 - (ku.search_embedding <=> ?::vector) AS similarity
            FROM knowledge_units ku
            JOIN knowledge_package_items kpi
                ON kpi.knowledge_unit_id = ku.id
            WHERE kpi.knowledge_package_id = ?
              AND ku.review_status = 'approved'
              AND ku.search_embedding IS NOT NULL
            ORDER BY ku.search_embedding <=> ?::vector
            LIMIT ?
        ", [$vectorString, $package->id, $vectorString, $topK]);

        // Filter by minimum similarity if specified
        $filtered = array_values(array_filter($results, function ($row) use ($minSimilarity) {
            return (float) $row->similarity >= $minSimilarity;
        }));

        // Format results per CTO specification
        $formattedResults = array_map(function ($row) {
            return [
                'knowledge_unit_id' => $row->knowledge_unit_id,
                'topic' => $row->topic,
                'intent' => $row->intent,
                'summary' => $row->summary,
                'resolution_summary' => $row->resolution_summary,
                'similarity' => round((float) $row->similarity, 4),
                'confidence' => (float) $row->confidence,
            ];
        }, $filtered);

        $latencyMs = (int) ((microtime(true) - $startTime) * 1000);

        return response()->json([
            'query' => $request->input('query'),
            'dataset_id' => $package->id,
            'results' => $formattedResults,
            'latency_ms' => $latencyMs,
        ]);
    }
}
