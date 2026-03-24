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
     * Invoke a Claude model on Bedrock for chat completion.
     *
     * Returns the assistant message text and token usage.
     */
    public function invokeChat(string $modelId, string $systemPrompt, array $messages, int $maxTokens = 1024): array
    {
        $startTime = microtime(true);

        $body = [
            'anthropic_version' => 'bedrock-2023-05-31',
            'max_tokens' => $maxTokens,
            'system' => $systemPrompt,
            'messages' => $messages,
        ];

        $response = $this->client->invokeModel([
            'modelId' => $modelId,
            'contentType' => 'application/json',
            'accept' => 'application/json',
            'body' => json_encode($body),
        ]);

        $result = json_decode($response['body']->getContents(), true);
        $latencyMs = (int) ((microtime(true) - $startTime) * 1000);

        return [
            'content' => $result['content'][0]['text'] ?? '',
            'input_tokens' => $result['usage']['input_tokens'] ?? 0,
            'output_tokens' => $result['usage']['output_tokens'] ?? 0,
            'latency_ms' => $latencyMs,
            'model_id' => $modelId,
        ];
    }
}
