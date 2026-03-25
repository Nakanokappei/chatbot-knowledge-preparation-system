<?php

namespace App\Http\Controllers;

use App\Models\Embedding;
use App\Models\KnowledgeUnit;
use App\Models\LlmModel;
use App\Services\BedrockService;
use App\Services\CostTrackingService;
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
            // Similarity threshold: only include KUs with cosine similarity >= 0.5
            $topK = 5;
            $similarityThreshold = 0.1;
            $retrievedKUs = DB::select("
                SELECT
                    id, topic, intent, summary, question,
                    symptoms, root_cause, resolution_summary,
                    1 - (search_embedding <=> ?::vector) AS similarity
                FROM knowledge_units
                WHERE embedding_id = ?
                  AND review_status = 'approved'
                  AND search_embedding IS NOT NULL
                  AND 1 - (search_embedding <=> ?::vector) >= ?
                ORDER BY search_embedding <=> ?::vector
                LIMIT ?
            ", [$vectorString, $embeddingId, $vectorString, $similarityThreshold, $vectorString, $topK]);

            // Return early if no KUs meet the similarity threshold
            if (empty($retrievedKUs)) {
                return response()->json([
                    'message' => __('ui.chat_no_match') ?: 'No matching knowledge found for your question.',
                    'sources' => [],
                ]);
            }

            // Step 3: Build RAG context with full KU details
            // Helper: check if a text field contains meaningful content (not CSV garbage)
            $isUseful = function(?string $text): bool {
                if (!$text || strlen(trim($text)) < 10) return false;
                // Detect CSV garbage: short sentences joined by semicolons with no real content
                if (preg_match('/^(\w+\s+){1,4}\w+\.;\s/', $text)) return false;
                return true;
            };

            $contextSections = [];
            foreach ($retrievedKUs as $ku) {
                $section = "### {$ku->topic}";
                if ($ku->question) $section .= "\nQuestion: {$ku->question}";
                if ($isUseful($ku->symptoms)) $section .= "\nSymptoms: {$ku->symptoms}";
                if ($isUseful($ku->root_cause)) $section .= "\nRoot Cause: {$ku->root_cause}";
                if ($isUseful($ku->resolution_summary)) $section .= "\nResolution: {$ku->resolution_summary}";
                if (!$ku->question && !$isUseful($ku->symptoms)) $section .= "\nSummary: {$ku->summary}";
                $contextSections[] = $section;
            }
            $knowledgeContext = implode("\n\n", $contextSections);

            $systemPrompt = <<<PROMPT
You are a support assistant. Answer the user's question based ONLY on the knowledge base below.

## Knowledge Base
{$knowledgeContext}

## Rules
- Respond in the SAME LANGUAGE as the user's question
- Format your response in Markdown: use **bold** for key terms, bullet lists for steps, and headings if needed
- Provide a concise, direct answer to the question
- Include actionable steps or workarounds if available in the knowledge base
- Do NOT simply repeat the knowledge base entries; synthesize a helpful response
- If the knowledge base does not contain relevant information, respond that no matching information was found
PROMPT;

            // Step 4: Build messages with history
            $messages = [];
            if ($request->history) {
                foreach ($request->history as $h) {
                    $messages[] = ['role' => $h['role'], 'content' => $h['content']];
                }
            }
            $messages[] = ['role' => 'user', 'content' => $request->message];

            // Step 5: Get LLM model (prefer default, fall back to first active)
            $modelId = LlmModel::where('tenant_id', $tenantId)
                ->where('is_active', true)
                ->where('is_default', true)
                ->value('model_id')
                ?? LlmModel::where('tenant_id', $tenantId)
                    ->where('is_active', true)
                    ->orderBy('sort_order')
                    ->value('model_id');

            // Guard: tenant must have at least one LLM model configured
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

            // Record token usage for cost tracking
            (new CostTrackingService())->recordUsage(
                auth()->user()->tenant_id, auth()->id(), 'chat',
                $llmResult['model_id'],
                $llmResult['input_tokens'], $llmResult['output_tokens'],
            );

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
