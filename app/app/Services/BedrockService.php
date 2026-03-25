<?php

namespace App\Services;

use Aws\BedrockRuntime\BedrockRuntimeClient;
use Illuminate\Support\Facades\Log;

/**
 * Bedrock service for embedding generation and LLM invocation.
 *
 * Used by Laravel controllers for real-time retrieval and chat.
 * Reuses the same models as the Python worker:
 * - Embedding: Amazon Titan Embed v2 (1024 dimensions)
 * - LLM: Configurable via llm_models table
 */
class BedrockService
{
    private BedrockRuntimeClient $client;
    private string $region;

    // Titan Embed v2 configuration — must match worker/src/bedrock_client.py
    private const EMBEDDING_MODEL = 'amazon.titan-embed-text-v2:0';
    private const EMBEDDING_DIMENSIONS = 1024;

    /**
     * Initialize the Bedrock Runtime client using the configured AWS region.
     */
    public function __construct()
    {
        $this->region = config('services.bedrock.region', 'ap-northeast-1');

        $this->client = new BedrockRuntimeClient([
            'region' => $this->region,
            'version' => 'latest',
        ]);
    }

    /**
     * Generate an embedding vector for a text query.
     *
     * Returns a 1024-dimensional float array compatible with pgvector.
     */
    public function generateEmbedding(string $text): array
    {
        $response = $this->client->invokeModel([
            'modelId' => self::EMBEDDING_MODEL,
            'contentType' => 'application/json',
            'accept' => 'application/json',
            'body' => json_encode([
                'inputText' => $text,
                'dimensions' => self::EMBEDDING_DIMENSIONS,
                'normalize' => true,
            ]),
        ]);

        $result = json_decode($response['body']->getContents(), true);

        return $result['embedding'];
    }

    /**
     * Invoke an LLM on Bedrock for chat completion via Converse API.
     *
     * Uses the model-agnostic Converse API so any Bedrock model works
     * (Claude, Llama, Mistral, OpenAI-compatible, etc.).
     * Returns the assistant message text and token usage.
     */
    public function invokeChat(string $modelId, string $systemPrompt, array $messages, int $maxTokens = 1024): array
    {
        $startTime = microtime(true);

        // Convert simple {role, content} messages to Converse API format
        $converseMessages = array_map(function ($msg) {
            $content = is_string($msg['content'])
                ? [['text' => $msg['content']]]
                : $msg['content'];
            return ['role' => $msg['role'], 'content' => $content];
        }, $messages);

        $params = [
            'modelId' => $modelId,
            'messages' => $converseMessages,
            'inferenceConfig' => [
                'maxTokens' => $maxTokens,
            ],
        ];

        // Add system prompt if provided
        if (!empty($systemPrompt)) {
            $params['system'] = [['text' => $systemPrompt]];
        }

        $response = $this->client->converse($params);
        $latencyMs = (int) ((microtime(true) - $startTime) * 1000);

        // Extract text from Converse API response
        $contentText = '';
        foreach ($response['output']['message']['content'] ?? [] as $block) {
            if (isset($block['text'])) {
                $contentText .= $block['text'];
            }
        }

        $usage = $response['usage'] ?? [];

        return [
            'content' => $contentText,
            'input_tokens' => $usage['inputTokens'] ?? 0,
            'output_tokens' => $usage['outputTokens'] ?? 0,
            'latency_ms' => $latencyMs,
            'model_id' => $modelId,
        ];
    }

    /**
     * Send a single prompt and parse a JSON response from the LLM.
     *
     * Strips markdown fences and returns the parsed array, or null on failure.
     * Used for structured data extraction (dataset metadata, etc.).
     */
    public function invokeJson(string $modelId, string $prompt, int $maxTokens = 2048): ?array
    {
        $result = $this->invokeChat($modelId, '', [
            ['role' => 'user', 'content' => $prompt],
        ], $maxTokens);

        $jsonText = trim($result['content']);
        // Strip markdown code fences that LLMs sometimes wrap around JSON
        if (str_starts_with($jsonText, '```')) {
            $lines = explode("\n", $jsonText);
            $lines = array_filter($lines, fn($l) => !str_starts_with(trim($l), '```'));
            $jsonText = implode("\n", $lines);
        }

        $parsed = json_decode($jsonText, true);
        return is_array($parsed) ? $parsed : null;
    }
}
