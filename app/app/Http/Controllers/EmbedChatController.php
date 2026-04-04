<?php

namespace App\Http\Controllers;

use App\Models\ChatSession;
use App\Models\ChatTurn;
use App\Models\KnowledgeUnit;
use App\Services\BedrockService;
use App\Services\CostTrackingService;
use App\Services\RagService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * Chat API for embedded widget/iframe — uses the SAME engine as workspace chat.
 *
 * Authenticated via EmbedApiKeyAuth middleware (not Sanctum/session).
 * Uses RagService::processChat() with scopeType='package' for identical
 * behavior: slot-filling, input gate, clarification questions, rejection jokes.
 *
 * Conversations are stored in ChatSession/ChatTurn (same as workspace chat)
 * with knowledge_package_id set instead of embedding_id.
 */
class EmbedChatController extends Controller
{
    /**
     * Process a chat message using the same conversational engine as workspace chat.
     */
    public function chat(Request $request): JsonResponse
    {
        $request->validate([
            'message' => 'required|string|max:4000',
            'session_id' => 'nullable|integer',
        ]);

        $packageId = $request->attributes->get('embed_package_id');
        $workspaceId = $request->attributes->get('embed_workspace_id');
        $package = $request->attributes->get('embed_package');

        try {
            // Load or create session (scoped to package, not embedding)
            $session = $this->resolveSession($request, $workspaceId, $packageId);
            $contextFromDb = $session->buildContext();
            $contextFromDb['state'] = $session->state;

            $rag = new RagService();

            // Process chat with the SAME engine as workspace chat,
            // but scoped to package instead of embedding
            $chatResult = $rag->processChat(
                $request->message,
                $packageId,
                $workspaceId,
                $contextFromDb,
                [],
                'package',  // scope type
            );

            $action = $chatResult['action'];
            $context = $chatResult['context'];
            $modelId = $chatResult['model_id'];
            $searchMode = $chatResult['search_mode'] ?? 'none';

            // Update session state
            $session->updateFromResult($context, $action);

            // Action: input gate rejected the message
            if ($action === 'rejected') {
                $joke = $this->generateRejectionJoke($request->message, $modelId);
                $this->persistTurns($session, $request->message, $joke, 'rejected', $context, [], $searchMode);

                return response()->json([
                    'message' => $joke,
                    'action' => 'rejected',
                    'context' => ['primary_filter' => null, 'question' => null],
                    'sources' => [],
                    'session_id' => $session->id,
                ]);
            }

            // Action: ask primary filter
            if ($action === 'ask_primary_filter') {
                $clarification = $this->generateClarificationQuestion(
                    $context['question'] ?? $request->message, $modelId
                );
                $this->persistTurns($session, $request->message, $clarification, 'ask_primary_filter', $context, [], $searchMode);

                return response()->json([
                    'message' => $clarification,
                    'action' => 'ask_primary_filter',
                    'context' => $context,
                    'sources' => [],
                    'session_id' => $session->id,
                ]);
            }

            // Action: no matching knowledge
            if ($action === 'no_match') {
                $noMatchMsg = __('ui.chat_no_match');
                $this->persistTurns($session, $request->message, $noMatchMsg, 'no_match', $context, [], $searchMode);

                return response()->json([
                    'message' => $noMatchMsg,
                    'action' => 'no_match',
                    'context' => $context,
                    'sources' => [],
                    'session_id' => $session->id,
                ]);
            }

            // Actions: answer / answer_broad — generate LLM response
            if (!$modelId) {
                return response()->json(['error' => 'No LLM model configured.'], 400);
            }

            $bedrock = new BedrockService();

            // Check link guidance mode
            $responseStrategy = $rag->buildResponseStrategy(
                $chatResult['results'],
                $package->response_mode ?? 'default_answer'
            );

            // Link-only mode: skip LLM
            if ($responseStrategy['skip_llm']) {
                $linksMessage = $rag->formatLinksOnlyMessage($responseStrategy['links']);
                $sources = $rag->buildSources($chatResult['results'], $searchMode);
                $this->persistTurns($session, $request->message, $linksMessage, $action, $context, $sources, $searchMode);

                return response()->json([
                    'message' => $linksMessage,
                    'action' => $action,
                    'links' => $responseStrategy['links'],
                    'sources' => $sources,
                    'session_id' => $session->id,
                    'latency_ms' => 0,
                ]);
            }

            // Build RAG context and invoke LLM
            $contextKUs = !empty($responseStrategy['answer_kus']) ? $responseStrategy['answer_kus'] : $chatResult['results'];
            $knowledgeContext = $rag->buildContext($contextKUs);

            $systemPrompt = !empty($responseStrategy['links'])
                ? $rag->buildSystemPromptWithLinks($knowledgeContext, $responseStrategy['links'])
                : $rag->buildSystemPrompt($knowledgeContext);

            if ($action === 'answer_broad') {
                $systemPrompt .= "\n\nIMPORTANT: The user asked about \"{$context['primary_filter']}\" but no exact match was found. The knowledge below is from other entries and may be useful as reference. Clearly note this in your response.";
            }

            $messages = [['role' => 'user', 'content' => $context['question'] ?? $request->message]];
            $llmResult = $bedrock->invokeChat($modelId, $systemPrompt, $messages);

            // Track cost
            $costTracker = new CostTrackingService();
            $costTracker->recordUsage(
                $workspaceId, null, 'embed_chat',
                $llmResult['model_id'],
                $llmResult['input_tokens'], $llmResult['output_tokens'],
            );

            // Increment usage_count on cited KUs
            $sourceKuIds = collect($chatResult['results'])->pluck('id')->filter()->values()->toArray();
            if (!empty($sourceKuIds)) {
                KnowledgeUnit::whereIn('id', $sourceKuIds)->increment('usage_count');
            }
            $costTracker->recordChatAnswer($workspaceId);

            $sources = $rag->buildSources($chatResult['results'], $searchMode);

            $this->persistTurns(
                $session, $request->message, $llmResult['content'],
                $action, $context, $sources, $searchMode,
                (int) ($llmResult['latency_ms'] ?? 0),
            );

            return response()->json([
                'message' => $llmResult['content'],
                'action' => $action,
                'context' => $context,
                'links' => $responseStrategy['links'],
                'sources' => $sources,
                'session_id' => $session->id,
                'latency_ms' => $llmResult['latency_ms'],
            ]);

        } catch (\Exception $e) {
            Log::error('Embed chat failed', ['error' => $e->getMessage()]);
            return response()->json(['error' => 'Service temporarily unavailable.'], 503);
        }
    }

    /**
     * Resolve or create a ChatSession for package-scoped chat.
     */
    private function resolveSession(Request $request, int $workspaceId, int $packageId): ChatSession
    {
        $sessionId = $request->input('session_id');

        if ($sessionId) {
            $session = ChatSession::where('workspace_id', $workspaceId)
                ->where('knowledge_package_id', $packageId)
                ->find($sessionId);
            if ($session) {
                return $session;
            }
        }

        return ChatSession::create([
            'workspace_id' => $workspaceId,
            'user_id' => null,  // Anonymous embed user
            'embedding_id' => null,
            'knowledge_package_id' => $packageId,
            'title' => mb_substr($request->message, 0, 60),
            'state' => 'idle',
        ]);
    }

    /**
     * Persist user + assistant turn pair with search metadata.
     */
    private function persistTurns(
        ChatSession $session,
        string $userContent,
        string $assistantContent,
        string $action,
        array $context,
        array $sources,
        string $searchMode = 'none',
        int $responseMs = 0,
    ): void {
        try {
            $extractedSlots = [
                'primary_filter' => $context['primary_filter'] ?? null,
                'question' => $context['question'] ?? null,
            ];

            ChatTurn::create([
                'session_id' => $session->id,
                'role' => 'user',
                'content' => $userContent,
                'context' => $context,
                'extracted_slots' => $extractedSlots,
            ]);

            ChatTurn::create([
                'session_id' => $session->id,
                'role' => 'assistant',
                'content' => $assistantContent,
                'context' => $context,
                'action' => $action,
                'sources' => $sources,
                'search_mode' => $searchMode,
                'extracted_slots' => $extractedSlots,
                'response_ms' => $responseMs > 0 ? $responseMs : null,
            ]);
        } catch (\Exception $e) {
            Log::warning('Embed chat history persist failed: ' . $e->getMessage());
        }
    }

    /**
     * Generate a clarification question asking which product/entity.
     */
    private function generateClarificationQuestion(string $userQuestion, string $modelId): string
    {
        $bedrock = new BedrockService();
        $systemPrompt = 'You are a helpful customer support chatbot. Generate ONLY a short clarification question, nothing else.';
        $userPrompt = "The user asked: \"{$userQuestion}\"\n\nWe need to know which specific product or entity they are referring to. Generate a short, friendly question asking them to specify — in the SAME LANGUAGE as the user's question. Output ONLY the question.";

        try {
            $response = $bedrock->invokeChat($modelId, $systemPrompt, [
                ['role' => 'user', 'content' => $userPrompt],
            ], 100);
            $text = trim($response['content'] ?? '');
            if (!empty($text)) {
                return $text;
            }
        } catch (\Exception $e) {
            Log::warning('Embed clarification LLM failed: ' . $e->getMessage());
        }

        return __('ui.chat_ask_filter');
    }

    /**
     * Generate a witty rejection response for off-topic messages.
     */
    private function generateRejectionJoke(string $userMessage, string $modelId): string
    {
        $bedrock = new BedrockService();
        $systemPrompt = 'You are a witty product support chatbot. You ONLY answer product/service support questions.';
        $userPrompt = "The user sent a non-support message: \"{$userMessage}\"\n\nGenerate a short (1-2 sentences), humorous response that reminds them you only handle product support — in the SAME LANGUAGE as the user's message. Output ONLY the response.";

        try {
            $response = $bedrock->invokeChat($modelId, $systemPrompt, [
                ['role' => 'user', 'content' => $userPrompt],
            ], 150);
            $text = trim($response['content'] ?? '');
            if (!empty($text)) {
                return $text;
            }
        } catch (\Exception $e) {
            Log::warning('Embed rejection joke LLM failed: ' . $e->getMessage());
        }

        $jokes = __('ui.chat_rejection_jokes');
        return is_array($jokes) ? $jokes[array_rand($jokes)] : $jokes;
    }
}
