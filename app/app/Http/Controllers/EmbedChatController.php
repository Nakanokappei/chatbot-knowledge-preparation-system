<?php

namespace App\Http\Controllers;

use App\Models\ChatConversation;
use App\Models\ChatMessage;
use App\Services\BedrockService;
use App\Services\CostTrackingService;
use App\Services\RagService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Chat API for embedded widget/iframe — authenticated via EmbedApiKeyAuth middleware.
 *
 * Reuses RagService and BedrockService from the existing chat implementation.
 * Conversations are anonymous (no user_id) since end-users are unauthenticated.
 */
class EmbedChatController extends Controller
{
    // Maximum conversation history messages to include in LLM context
    private const MAX_HISTORY_MESSAGES = 10;

    /**
     * Process a chat message against the package linked to the API key.
     */
    public function chat(Request $request): JsonResponse
    {
        $request->validate([
            'message' => 'required|string|max:4000',
            'conversation_id' => 'nullable|uuid',
        ]);

        $packageId = $request->attributes->get('embed_package_id');
        $workspaceId = $request->attributes->get('embed_workspace_id');

        $rag = new RagService();
        $bedrock = new BedrockService();

        // Two-stage vector search against the published package
        $searchResult = $rag->searchPackageKnowledgeUnits($request->message, $packageId, 5);

        if (empty($searchResult['results'])) {
            return response()->json([
                'message' => 'No matching knowledge found for your question.',
                'sources' => [],
            ]);
        }

        $retrievedKUs = $searchResult['results'];
        $package = $request->attributes->get('embed_package');

        // Determine response strategy (link guidance mode)
        $responseStrategy = $rag->buildResponseStrategy($retrievedKUs, $package->response_mode ?? 'default_answer');

        $sources = array_map(fn($ku) => [
            'knowledge_unit_id' => $ku->id,
            'topic' => $ku->topic,
            'similarity' => round((float) $ku->similarity, 4),
        ], $retrievedKUs);

        // Get or create anonymous conversation
        $conversation = $this->getOrCreateConversation(
            $request->conversation_id, $workspaceId, $packageId
        );

        // Save user message
        ChatMessage::create([
            'conversation_id' => $conversation->id,
            'role' => 'user',
            'content' => $request->message,
        ]);

        // Link-only mode — return links without LLM invocation
        if ($responseStrategy['skip_llm']) {
            $linksMessage = $rag->formatLinksOnlyMessage($responseStrategy['links']);

            ChatMessage::create([
                'conversation_id' => $conversation->id,
                'role' => 'assistant',
                'content' => $linksMessage,
                'sources_json' => $sources,
            ]);

            return response()->json([
                'conversation_id' => $conversation->id,
                'message' => $linksMessage,
                'links' => $responseStrategy['links'],
                'sources' => $sources,
                'latency_ms' => 0,
            ]);
        }

        // Normal / mixed mode — invoke LLM
        $contextKUs = !empty($responseStrategy['answer_kus']) ? $responseStrategy['answer_kus'] : $retrievedKUs;
        $knowledgeContext = $rag->buildContext($contextKUs);
        $messages = $this->buildMessagesArray($conversation, $request->message);

        $systemPrompt = !empty($responseStrategy['links'])
            ? $rag->buildSystemPromptWithLinks($knowledgeContext, $responseStrategy['links'])
            : $rag->buildSystemPrompt($knowledgeContext);

        $modelId = $rag->resolveModelId($workspaceId)
            ?? 'ap-northeast-1.anthropic.claude-3-5-haiku-20251001-v1:0';

        try {
            $llmResult = $bedrock->invokeChat($modelId, $systemPrompt, $messages);
        } catch (\Exception $e) {
            return response()->json(['error' => 'Service temporarily unavailable.'], 503);
        }

        // Save assistant message with metadata
        ChatMessage::create([
            'conversation_id' => $conversation->id,
            'role' => 'assistant',
            'content' => $llmResult['content'],
            'sources_json' => $sources,
            'input_tokens' => $llmResult['input_tokens'],
            'output_tokens' => $llmResult['output_tokens'],
            'latency_ms' => $llmResult['latency_ms'],
        ]);

        // Track cost under 'embed_chat' endpoint category
        (new CostTrackingService())->recordUsage(
            $workspaceId, null, 'embed_chat',
            $llmResult['model_id'],
            $llmResult['input_tokens'], $llmResult['output_tokens'],
        );

        return response()->json([
            'conversation_id' => $conversation->id,
            'message' => $llmResult['content'],
            'links' => $responseStrategy['links'],
            'sources' => $sources,
            'latency_ms' => $llmResult['latency_ms'],
        ]);
    }

    /**
     * Get existing conversation or create a new anonymous one.
     */
    private function getOrCreateConversation(?string $conversationId, int $workspaceId, int $packageId): ChatConversation
    {
        if ($conversationId) {
            $existing = ChatConversation::where('id', $conversationId)
                ->where('workspace_id', $workspaceId)
                ->where('knowledge_package_id', $packageId)
                ->first();

            if ($existing) {
                return $existing;
            }
        }

        // Anonymous conversation (user_id = null for embed users)
        return ChatConversation::create([
            'workspace_id' => $workspaceId,
            'knowledge_package_id' => $packageId,
            'user_id' => null,
        ]);
    }

    /**
     * Build the messages array including conversation history.
     */
    private function buildMessagesArray(ChatConversation $conversation, string $currentMessage): array
    {
        $history = $conversation->messages()
            ->orderByDesc('created_at')
            ->limit(self::MAX_HISTORY_MESSAGES + 1)
            ->get()
            ->reverse()
            ->values();

        $messages = [];

        // Add history messages (skip the last one, which is the current user message)
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
}
