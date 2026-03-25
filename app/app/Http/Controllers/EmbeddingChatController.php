<?php

namespace App\Http\Controllers;

use App\Models\Embedding;
use App\Services\BedrockService;
use App\Services\CostTrackingService;
use App\Services\RagService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * Chat with embedding clusters via RAG.
 *
 * Uses RagService for two-stage vector search (precise → broad fallback)
 * and enhanced prompt generation.
 */
class EmbeddingChatController extends Controller
{
    /**
     * Process a chat message against an embedding's approved clusters.
     *
     * 1. Two-stage vector search (precise question match, then broad fallback)
     * 2. Build RAG prompt with relevance-scored context
     * 3. Invoke LLM and return response with sources
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
        Embedding::where('tenant_id', $tenantId)->findOrFail($embeddingId);

        try {
            $rag = new RagService();

            // Step 1: Two-stage vector search
            $searchResult = $rag->searchKnowledgeUnits($request->message, $embeddingId);

            if ($searchResult['mode'] === 'none') {
                return response()->json([
                    'message' => __('ui.chat_no_match'),
                    'sources' => [],
                ]);
            }

            // Step 2: Build context and system prompt
            $context = $rag->buildContext($searchResult['results']);
            $systemPrompt = $rag->buildSystemPrompt($context);

            // Step 3: Build messages with conversation history
            $messages = [];
            if ($request->history) {
                foreach ($request->history as $historyMessage) {
                    $messages[] = ['role' => $historyMessage['role'], 'content' => $historyMessage['content']];
                }
            }
            $messages[] = ['role' => 'user', 'content' => $request->message];

            // Step 4: Resolve LLM model
            $modelId = $rag->resolveModelId($tenantId);
            if (!$modelId) {
                return response()->json(['error' => 'No LLM model configured. Add one in Settings.'], 400);
            }

            // Step 5: Invoke LLM
            $bedrock = new BedrockService();
            $llmResult = $bedrock->invokeChat($modelId, $systemPrompt, $messages);

            // Record token usage for cost tracking
            (new CostTrackingService())->recordUsage(
                $tenantId, auth()->id(), 'chat',
                $llmResult['model_id'],
                $llmResult['input_tokens'], $llmResult['output_tokens'],
            );

            return response()->json([
                'message' => $llmResult['content'],
                'sources' => $rag->buildSources($searchResult['results'], $searchResult['mode']),
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
