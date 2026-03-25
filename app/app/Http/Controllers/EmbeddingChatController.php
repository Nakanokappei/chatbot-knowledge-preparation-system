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
 * Chat with embedding clusters via conversational RAG.
 *
 * Manages a session-based conversation flow:
 *   1. Extract product name and question from user input
 *   2. Ask back if product name is missing
 *   3. Search with product filter when both are available
 *   4. Fall back to broad search or "no knowledge" as needed
 *
 * Session state (product, question, asked_product) is managed client-side
 * and passed with each request — no full conversation history needed.
 */
class EmbeddingChatController extends Controller
{
    /**
     * Process a chat message with product-aware conversational flow.
     */
    public function chat(Request $request, int $embeddingId): JsonResponse
    {
        $request->validate([
            'message' => 'required|string|max:4000',
            'context' => 'nullable|array',
            'context.product' => 'nullable|string',
            'context.question' => 'nullable|string',
        ]);

        $tenantId = auth()->user()->tenant_id;
        $embedding = Embedding::where('tenant_id', $tenantId)->findOrFail($embeddingId);

        try {
            $rag = new RagService();

            // Process chat with product extraction and multi-stage search
            $chatResult = $rag->processChat(
                $request->message,
                $embeddingId,
                $tenantId,
                $request->input('context', []),
            );

            $action = $chatResult['action'];
            $context = $chatResult['context'];
            $modelId = $chatResult['model_id'];

            // Action: ask the user which primary filter value they mean
            if ($action === 'ask_product') {
                // Resolve the CSV column name assigned to Primary Filter
                $filterLabel = $this->resolvePrimaryFilterLabel($embedding);

                return response()->json([
                    'message' => $filterLabel
                        ? __('ui.chat_ask_filter_named', ['name' => $filterLabel])
                        : __('ui.chat_ask_filter'),
                    'action' => 'ask_product',
                    'context' => $context,
                    'sources' => [],
                ]);
            }

            // Action: no matching knowledge at all
            if ($action === 'no_match') {
                return response()->json([
                    'message' => 'ご質問に対応する知識を持ち合わせていません。',
                    'action' => 'no_match',
                    'context' => $context,
                    'sources' => [],
                ]);
            }

            // Actions: answer or answer_broad — generate LLM response
            if (!$modelId) {
                return response()->json(['error' => 'No LLM model configured.'], 400);
            }

            $bedrock = new BedrockService();
            $knowledgeContext = $rag->buildContext($chatResult['results']);

            // Build system prompt — add note for broad/reference results
            $systemPrompt = $rag->buildSystemPrompt($knowledgeContext);
            if ($action === 'answer_broad') {
                $systemPrompt .= "\n\nIMPORTANT: The user asked about \"{$context['product']}\" but no exact match was found. The knowledge below is from other products and may be useful as reference. Clearly note this in your response.";
            }

            $messages = [['role' => 'user', 'content' => $context['question'] ?? $request->message]];
            $llmResult = $bedrock->invokeChat($modelId, $systemPrompt, $messages);

            // Record token usage
            $costTracker = new CostTrackingService();
            $costTracker->recordUsage(
                $tenantId, auth()->id(), 'chat',
                $llmResult['model_id'],
                $llmResult['input_tokens'], $llmResult['output_tokens'],
            );

            return response()->json([
                'message' => $llmResult['content'],
                'action' => $action,
                'context' => $context,
                'sources' => $rag->buildSources($chatResult['results'], $chatResult['search_mode']),
                'model' => $llmResult['model_id'],
                'usage' => [
                    'input_tokens' => $llmResult['input_tokens'],
                    'output_tokens' => $llmResult['output_tokens'],
                ],
                'latency_ms' => $llmResult['latency_ms'],
            ]);

        } catch (\Exception $e) {
            Log::error('Chat failed', [
                'embedding_id' => $embeddingId,
                'error' => $e->getMessage(),
            ]);
            return response()->json(['error' => 'Chat failed: ' . $e->getMessage()], 500);
        }
    }

    /**
     * Resolve the CSV column name assigned to the Primary Filter field.
     *
     * Looks up the dataset's knowledge_mapping_json for the 'product' slot,
     * then maps its column index back to the column name from schema_json.
     * Returns null if the mapping is LLM-generated or missing.
     */
    private function resolvePrimaryFilterLabel(Embedding $embedding): ?string
    {
        $dataset = $embedding->dataset;
        if (!$dataset) {
            return null;
        }

        $mapping = $dataset->knowledge_mapping_json ?? [];
        $source = $mapping['product'] ?? null;

        // Only resolve if mapped to a specific CSV column (numeric index)
        if (!$source || !is_numeric($source)) {
            return null;
        }

        $columns = $dataset->schema_json['columns'] ?? [];
        $colIndex = (int) $source;

        return $columns[$colIndex] ?? null;
    }
}
