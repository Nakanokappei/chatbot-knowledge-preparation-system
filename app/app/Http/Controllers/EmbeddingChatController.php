<?php

namespace App\Http\Controllers;

use App\Models\Embedding;
use App\Models\KnowledgeUnit;
use App\Models\LlmModel;
use App\Services\BedrockService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Chat with embedding clusters via RAG.
 *
 * Retrieves the most relevant approved clusters (KUs) for a user query
 * using vector similarity search, then generates a response via LLM.
 */
class EmbeddingChatController extends Controller
{
    /**
     * Process a chat message against an embedding's approved clusters.
     *
     * 1. Generate embedding for user query
     * 2. Vector search against approved KUs in this embedding
     * 3. Build RAG prompt with retrieved context
     * 4. Invoke LLM and return response with sources
     */
    public function chat(Request $request, int $embeddingId): JsonResponse
    {
        $request->validate([
            'message' => 'required|string|max:4000',
            'history' => 'nullable|array',
            'history.*.role' => 'in:user,assistant',
            'history.*.content' => 'string',
        ]);

        $tenantId = auth()->user()->tenant_id;

        // Verify embedding belongs to tenant
        $embedding = Embedding::where('tenant_id', $tenantId)
            ->findOrFail($embeddingId);

        try {
            $bedrock = new BedrockService();

            // Step 1: Generate query embedding
            $queryEmbedding = $bedrock->generateEmbedding($request->message);
            $vectorString = '[' . implode(',', $queryEmbedding) . ']';

            // Step 2: Vector search against this embedding's approved KUs
            $topK = 5;
            $retrievedKUs = DB::select("
                SELECT
                    id, topic, intent, summary,
                    1 - (embedding <=> ?::vector) AS similarity
                FROM knowledge_units
                WHERE embedding_id = ?
                  AND review_status = 'approved'
                  AND embedding IS NOT NULL
                ORDER BY embedding <=> ?::vector
                LIMIT ?
            ", [$vectorString, $embeddingId, $vectorString, $topK]);

            if (empty($retrievedKUs)) {
                return response()->json([
                    'message' => 'No approved clusters found for this embedding. Please approve some clusters first.',
                    'sources' => [],
                ]);
            }

            // Step 3: Build RAG context
            $contextSections = [];
            foreach ($retrievedKUs as $ku) {
                $section = "### {$ku->topic} ({$ku->intent})\n{$ku->summary}";
                $contextSections[] = $section;
            }
            $knowledgeContext = implode("\n\n", $contextSections);

            $systemPrompt = <<<PROMPT
You are a helpful assistant. Answer the user's question based ONLY on the following knowledge base articles derived from cluster analysis.

If the knowledge base does not contain relevant information, say "I don't have information about that in the current clusters."

## Knowledge Base
{$knowledgeContext}

## Instructions
- Answer concisely and helpfully
- Mention which topic(s) your answer is based on
- If uncertain, say so
PROMPT;

            // Step 4: Build messages with history
            $messages = [];
            if ($request->history) {
                foreach ($request->history as $h) {
                    $messages[] = ['role' => $h['role'], 'content' => $h['content']];
                }
            }
            $messages[] = ['role' => 'user', 'content' => $request->message];

            // Step 5: Get LLM model
            $modelId = LlmModel::where('tenant_id', $tenantId)
                ->where('is_active', true)
                ->orderBy('sort_order')
                ->value('model_id');

            if (!$modelId) {
                return response()->json(['error' => 'No LLM model configured. Add one in Settings.'], 400);
            }

            // Step 6: Invoke LLM
            $llmResult = $bedrock->invokeChat($modelId, $systemPrompt, $messages);

            // Build sources
            $sources = array_map(fn($ku) => [
                'topic' => $ku->topic,
                'intent' => $ku->intent,
                'similarity' => round((float) $ku->similarity, 4),
            ], $retrievedKUs);

            return response()->json([
                'message' => $llmResult['content'],
                'sources' => $sources,
                'model' => $llmResult['model_id'],
                'usage' => [
                    'input_tokens' => $llmResult['input_tokens'],
                    'output_tokens' => $llmResult['output_tokens'],
                ],
                'latency_ms' => $llmResult['latency_ms'],
            ]);

        } catch (\Exception $e) {
            Log::error('Embedding chat failed', [
                'embedding_id' => $embeddingId,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'error' => 'Chat failed: ' . $e->getMessage(),
            ], 500);
        }
    }
}
