<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ChatConversation;
use App\Models\ChatMessage;
use App\Models\KnowledgeDataset;
use App\Models\LlmModel;
use App\Services\BedrockService;
use App\Services\CostTrackingService;
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

        $workspaceId = $request->user()->workspace_id;
        $topK = $request->input('top_k', 5);

        // Verify dataset is published
        $dataset = KnowledgeDataset::where('id', $request->dataset_id)
            ->where('workspace_id', $workspaceId)
            ->where('status', 'published')
            ->first();

        if (! $dataset) {
            return response()->json(['error' => 'Dataset not found or not published.'], 404);
        }

        $rag = new \App\Services\RagService();
        $bedrock = new BedrockService();

        // Step 1: Two-stage vector search (precise → broad fallback)
        $searchResult = $rag->searchDatasetKnowledgeUnits($request->message, $dataset->id, $topK);
        $retrievedKUs = $searchResult['results'];

        if (empty($retrievedKUs)) {
            return response()->json([
                'message' => 'No matching knowledge found for your question.',
                'sources' => [],
            ]);
        }

        // Step 2: Build RAG context from retrieved KUs
        $knowledgeContext = $rag->buildContext($retrievedKUs);

        // Step 3: Get or create conversation
        $conversation = $this->getOrCreateConversation(
            $request->conversation_id, $workspaceId, $dataset->id, $request->user()->id
        );

        // Save user message
        ChatMessage::create([
            'conversation_id' => $conversation->id,
            'role' => 'user',
            'content' => $request->message,
        ]);

        // Step 4: Build messages array with conversation history
        $messages = $this->buildMessagesArray($conversation, $request->message);

        // Step 5: Invoke LLM with unified system prompt
        $systemPrompt = $rag->buildSystemPrompt($knowledgeContext);
        $modelId = $rag->resolveModelId($workspaceId) ?? $this->getActiveModelId($workspaceId);

        $llmResult = $bedrock->invokeChat($modelId, $systemPrompt, $messages);

        // Step 6: Save assistant message with source attribution
        $sources = array_map(function ($ku) use ($searchResult) {
            return [
                'knowledge_unit_id' => $ku->id,
                'topic' => $ku->topic,
                'similarity' => round((float) $ku->similarity, 4),
                'search_mode' => $searchResult['mode'],
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

        // Record token usage for cost tracking
        (new CostTrackingService())->recordUsage(
            $workspaceId, $request->user()->id, 'chat',
            $llmResult['model_id'],
            $llmResult['input_tokens'], $llmResult['output_tokens'],
        );

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
     * Get existing conversation or create a new one.
     */
    private function getOrCreateConversation(?string $conversationId, int $workspaceId, int $datasetId, int $userId): ChatConversation
    {
        // Attempt to resume an existing conversation if an ID was provided
        if ($conversationId) {
            $existing = ChatConversation::where('id', $conversationId)
                ->where('workspace_id', $workspaceId)
                ->first();

            if ($existing) {
                return $existing;
            }
        }

        return ChatConversation::create([
            'workspace_id' => $workspaceId,
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
     * Get the active LLM model ID for this workspace.
     */
    private function getActiveModelId(int $workspaceId): string
    {
        $model = LlmModel::where('workspace_id', $workspaceId)
            ->where('is_active', true)
            ->orderBy('sort_order')
            ->first();

        // Default fallback if no model is configured
        return $model?->model_id ?? 'ap-northeast-1.anthropic.claude-3-5-haiku-20251001-v1:0';
    }
}
