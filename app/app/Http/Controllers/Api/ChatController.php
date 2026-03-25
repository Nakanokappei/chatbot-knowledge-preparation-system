<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ChatConversation;
use App\Models\ChatMessage;
use App\Models\KnowledgeDataset;
use App\Models\LlmModel;
use App\Services\BedrockService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Minimal RAG Chat API — Phase 4 verification endpoint.
 *
 * CTO directive: this is a RAG verification API, not a full conversational UI.
 * Process: Retrieve top-K KUs → Build augmented prompt → Generate LLM response.
 */
class ChatController extends Controller
{
    // Maximum conversation history messages to include in context
    private const MAX_HISTORY_MESSAGES = 10;

    /**
     * Process a chat message using RAG against a published dataset.
     */
    public function chat(Request $request): JsonResponse
    {
        $request->validate([
            'message' => 'required|string|max:4000',
            'dataset_id' => 'required|integer|exists:knowledge_datasets,id',
            'conversation_id' => 'nullable|uuid',
            'top_k' => 'integer|min:1|max:10',
        ]);

        $tenantId = $request->user()->tenant_id;
        $topK = $request->input('top_k', 5);

        // Verify dataset is published
        $dataset = KnowledgeDataset::where('id', $request->dataset_id)
            ->where('tenant_id', $tenantId)
            ->where('status', 'published')
            ->first();

        if (! $dataset) {
            return response()->json(['error' => 'Dataset not found or not published.'], 404);
        }

        $bedrock = new BedrockService();

        // Step 1: Retrieve relevant KUs via vector search
        $queryEmbedding = $bedrock->generateEmbedding($request->message);
        $vectorString = '[' . implode(',', $queryEmbedding) . ']';

        $retrievedKUs = DB::select("
            SELECT
                ku.id, ku.topic, ku.intent, ku.summary,
                ku.cause_summary, ku.resolution_summary,
                1 - (ku.search_embedding <=> ?::vector) AS similarity
            FROM knowledge_units ku
            JOIN knowledge_dataset_items kdi ON kdi.knowledge_unit_id = ku.id
            WHERE kdi.knowledge_dataset_id = ?
              AND ku.review_status = 'approved'
              AND ku.search_embedding IS NOT NULL
            ORDER BY ku.search_embedding <=> ?::vector
            LIMIT ?
        ", [$vectorString, $dataset->id, $vectorString, $topK]);

        // Step 2: Build RAG context from retrieved KUs
        $knowledgeContext = $this->buildKnowledgeContext($retrievedKUs);

        // Step 3: Get or create conversation
        $conversation = $this->getOrCreateConversation(
            $request->conversation_id, $tenantId, $dataset->id, $request->user()->id
        );

        // Save user message
        ChatMessage::create([
            'conversation_id' => $conversation->id,
            'role' => 'user',
            'content' => $request->message,
        ]);

        // Step 4: Build messages array with conversation history
        $messages = $this->buildMessagesArray($conversation, $request->message);

        // Step 5: Invoke LLM
        $systemPrompt = $this->buildSystemPrompt($knowledgeContext);
        $modelId = $this->getActiveModelId($tenantId);

        $llmResult = $bedrock->invokeChat($modelId, $systemPrompt, $messages);

        // Step 6: Save assistant message with source attribution
        $sources = array_map(function ($ku) {
            return [
                'knowledge_unit_id' => $ku->id,
                'topic' => $ku->topic,
                'similarity' => round((float) $ku->similarity, 4),
            ];
        }, $retrievedKUs);

        ChatMessage::create([
            'conversation_id' => $conversation->id,
            'role' => 'assistant',
            'content' => $llmResult['content'],
            'sources_json' => $sources,
            'input_tokens' => $llmResult['input_tokens'],
            'output_tokens' => $llmResult['output_tokens'],
            'latency_ms' => $llmResult['latency_ms'],
        ]);

        return response()->json([
            'conversation_id' => $conversation->id,
            'message' => $llmResult['content'],
            'sources' => $sources,
            'model' => $llmResult['model_id'],
            'usage' => [
                'input_tokens' => $llmResult['input_tokens'],
                'output_tokens' => $llmResult['output_tokens'],
            ],
            'latency_ms' => $llmResult['latency_ms'],
        ]);
    }

    /**
     * Build the knowledge base context string from retrieved KUs.
     */
    private function buildKnowledgeContext(array $kus): string
    {
        $sections = [];
        foreach ($kus as $ku) {
            $section = "### {$ku->topic} ({$ku->intent})\n";
            $section .= "{$ku->summary}\n";
            if ($ku->cause_summary) {
                $section .= "Cause: {$ku->cause_summary}\n";
            }
            if ($ku->resolution_summary) {
                $section .= "Resolution: {$ku->resolution_summary}\n";
            }
            $sections[] = $section;
        }

        return implode("\n", $sections);
    }

    /**
     * Build the RAG system prompt with knowledge context.
     */
    private function buildSystemPrompt(string $knowledgeContext): string
    {
        return <<<PROMPT
You are a customer support assistant. Answer the user's question based ONLY on the following knowledge base articles.

If the knowledge base does not contain relevant information, say "I don't have information about that topic."

## Knowledge Base
{$knowledgeContext}

## Instructions
- Answer concisely and helpfully
- Cite which topic(s) you used
- If uncertain, say so
PROMPT;
    }

    /**
     * Get existing conversation or create a new one.
     */
    private function getOrCreateConversation(?string $conversationId, int $tenantId, int $datasetId, int $userId): ChatConversation
    {
        if ($conversationId) {
            $existing = ChatConversation::where('id', $conversationId)
                ->where('tenant_id', $tenantId)
                ->first();

            if ($existing) {
                return $existing;
            }
        }

        return ChatConversation::create([
            'tenant_id' => $tenantId,
            'knowledge_dataset_id' => $datasetId,
            'user_id' => $userId,
        ]);
    }

    /**
     * Build the messages array including conversation history.
     */
    private function buildMessagesArray(ChatConversation $conversation, string $currentMessage): array
    {
        // Load recent history (excluding the message we just saved)
        $history = $conversation->messages()
            ->orderByDesc('created_at')
            ->limit(self::MAX_HISTORY_MESSAGES + 1) // +1 for the user message we just saved
            ->get()
            ->reverse()
            ->values();

        $messages = [];

        // Add history messages (skip the last one which is the current user message)
        foreach ($history->slice(0, -1) as $msg) {
            $messages[] = [
                'role' => $msg->role,
                'content' => $msg->content,
            ];
        }

        // Add current user message
        $messages[] = [
            'role' => 'user',
            'content' => $currentMessage,
        ];

        return $messages;
    }

    /**
     * Get the active LLM model ID for this tenant.
     */
    private function getActiveModelId(int $tenantId): string
    {
        $model = LlmModel::where('tenant_id', $tenantId)
            ->where('is_active', true)
            ->orderBy('sort_order')
            ->first();

        // Default fallback if no model is configured
        return $model?->model_id ?? 'ap-northeast-1.anthropic.claude-3-5-haiku-20251001-v1:0';
    }
}
