<?php

namespace App\Services;

use App\Models\LlmModel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Retrieval-Augmented Generation service for knowledge-based chat.
 *
 * Implements two-stage vector search:
 *   Stage 1 (precise): search_embedding (question only), threshold 0.3
 *   Stage 2 (broad):   broad_embedding (question+topic+symptoms+summary), threshold 0.15
 * Falls back to broad search only when precise search yields no results.
 */
class RagService
{
    private const PRECISE_THRESHOLD = 0.3;
    private const BROAD_THRESHOLD = 0.15;
    private const TOP_K = 5;

    private BedrockService $bedrock;

    public function __construct()
    {
        $this->bedrock = new BedrockService();
    }

    /**
     * Two-stage vector search: precise first, then broad fallback.
     *
     * Returns an object with 'results' (array of KU rows) and 'mode' ('precise'|'broad'|'none').
     */
    public function searchKnowledgeUnits(
        string $queryText,
        int $embeddingId,
        int $topK = self::TOP_K,
    ): array {
        $queryEmbedding = $this->bedrock->generateEmbedding($queryText);
        $vectorString = '[' . implode(',', $queryEmbedding) . ']';

        // Stage 1: Precise search using question-only embedding
        $results = $this->vectorSearch(
            $vectorString, $embeddingId, 'search_embedding',
            self::PRECISE_THRESHOLD, $topK,
        );

        if (!empty($results)) {
            Log::debug("RAG precise search hit: " . count($results) . " KUs");
            return ['results' => $results, 'mode' => 'precise'];
        }

        // Stage 2: Broad search using enriched embedding
        $results = $this->vectorSearch(
            $vectorString, $embeddingId, 'broad_embedding',
            self::BROAD_THRESHOLD, $topK,
        );

        if (!empty($results)) {
            Log::debug("RAG broad search hit: " . count($results) . " KUs");
            return ['results' => $results, 'mode' => 'broad'];
        }

        Log::debug("RAG search: no results for embedding {$embeddingId}");
        return ['results' => [], 'mode' => 'none'];
    }

    /**
     * Execute a vector similarity search against a specific embedding column.
     */
    private function vectorSearch(
        string $vectorString,
        int $embeddingId,
        string $embeddingColumn,
        float $threshold,
        int $topK,
    ): array {
        // Validate column name to prevent SQL injection
        if (!in_array($embeddingColumn, ['search_embedding', 'broad_embedding'])) {
            throw new \InvalidArgumentException("Invalid embedding column: {$embeddingColumn}");
        }

        return DB::select("
            SELECT
                id, topic, intent, summary, question, product,
                symptoms, root_cause, resolution_summary,
                1 - ({$embeddingColumn} <=> ?::vector) AS similarity
            FROM knowledge_units
            WHERE embedding_id = ?
              AND review_status = 'approved'
              AND {$embeddingColumn} IS NOT NULL
              AND 1 - ({$embeddingColumn} <=> ?::vector) >= ?
            ORDER BY {$embeddingColumn} <=> ?::vector
            LIMIT ?
        ", [$vectorString, $embeddingId, $vectorString, $threshold, $vectorString, $topK]);
    }

    /**
     * Two-stage vector search for published KnowledgeDataset (API chat).
     *
     * Searches KUs linked to a dataset via knowledge_dataset_items JOIN.
     */
    public function searchDatasetKnowledgeUnits(
        string $queryText,
        int $datasetId,
        int $topK = self::TOP_K,
    ): array {
        $queryEmbedding = $this->bedrock->generateEmbedding($queryText);
        $vectorString = '[' . implode(',', $queryEmbedding) . ']';

        // Stage 1: Precise search
        $results = $this->datasetVectorSearch(
            $vectorString, $datasetId, 'search_embedding',
            self::PRECISE_THRESHOLD, $topK,
        );

        if (!empty($results)) {
            return ['results' => $results, 'mode' => 'precise'];
        }

        // Stage 2: Broad fallback
        $results = $this->datasetVectorSearch(
            $vectorString, $datasetId, 'broad_embedding',
            self::BROAD_THRESHOLD, $topK,
        );

        if (!empty($results)) {
            return ['results' => $results, 'mode' => 'broad'];
        }

        return ['results' => [], 'mode' => 'none'];
    }

    /**
     * Vector search against KUs linked to a knowledge dataset.
     */
    private function datasetVectorSearch(
        string $vectorString,
        int $datasetId,
        string $embeddingColumn,
        float $threshold,
        int $topK,
    ): array {
        if (!in_array($embeddingColumn, ['search_embedding', 'broad_embedding'])) {
            throw new \InvalidArgumentException("Invalid embedding column: {$embeddingColumn}");
        }

        return DB::select("
            SELECT
                ku.id, ku.topic, ku.intent, ku.summary, ku.question,
                ku.symptoms, ku.root_cause, ku.cause_summary, ku.resolution_summary,
                1 - (ku.{$embeddingColumn} <=> ?::vector) AS similarity
            FROM knowledge_units ku
            JOIN knowledge_dataset_items kdi ON kdi.knowledge_unit_id = ku.id
            WHERE kdi.knowledge_dataset_id = ?
              AND ku.review_status = 'approved'
              AND ku.{$embeddingColumn} IS NOT NULL
              AND 1 - (ku.{$embeddingColumn} <=> ?::vector) >= ?
            ORDER BY ku.{$embeddingColumn} <=> ?::vector
            LIMIT ?
        ", [$vectorString, $datasetId, $vectorString, $threshold, $vectorString, $topK]);
    }

    /**
     * Build knowledge context from retrieved KUs for the LLM prompt.
     *
     * Includes similarity scores so the LLM can weigh relevance.
     */
    public function buildContext(array $knowledgeUnits): string
    {
        $sections = [];
        foreach ($knowledgeUnits as $ku) {
            $similarity = round((float) $ku->similarity * 100);
            $section = "### {$ku->topic} [relevance: {$similarity}%]";
            if ($ku->question) $section .= "\nQuestion: {$ku->question}";
            if ($this->isUsefulText($ku->symptoms)) $section .= "\nSymptoms: {$ku->symptoms}";
            if ($this->isUsefulText($ku->root_cause)) $section .= "\nRoot Cause: {$ku->root_cause}";
            if ($this->isUsefulText($ku->resolution_summary)) $section .= "\nResolution: {$ku->resolution_summary}";
            if (!$ku->question && !$this->isUsefulText($ku->symptoms)) {
                $section .= "\nSummary: {$ku->summary}";
            }
            $sections[] = $section;
        }
        return implode("\n\n", $sections);
    }

    /**
     * Build the system prompt with knowledge context and enhanced instructions.
     */
    public function buildSystemPrompt(string $knowledgeContext): string
    {
        return <<<PROMPT
You are an expert support assistant. Answer the user's question based ONLY on the knowledge base below.

## Knowledge Base
{$knowledgeContext}

## Response Guidelines
- Respond in the SAME LANGUAGE as the user's question
- Format in Markdown: use **bold** for key terms, bullet lists for steps, headings for sections
- Structure your answer as: **Cause** → **Resolution steps** → **Additional notes** (when applicable)
- Synthesize information from multiple knowledge entries when relevant — do NOT simply repeat them
- Prioritize entries with higher relevance scores
- When multiple causes are possible, list them from most to least likely
- Include actionable steps or workarounds if available
- If the knowledge base does not contain relevant information, clearly state that no matching information was found
- Do NOT fabricate information not present in the knowledge base
PROMPT;
    }

    /**
     * Build sources array from retrieved KUs for the response.
     */
    public function buildSources(array $knowledgeUnits, string $searchMode): array
    {
        return array_map(fn($ku) => [
            'topic' => $ku->topic,
            'intent' => $ku->intent,
            'similarity' => round((float) $ku->similarity, 4),
            'search_mode' => $searchMode,
        ], $knowledgeUnits);
    }

    /**
     * Resolve the active LLM model ID for the given tenant.
     */
    public function resolveModelId(int $tenantId): ?string
    {
        return LlmModel::where('tenant_id', $tenantId)
            ->where('is_active', true)
            ->where('is_default', true)
            ->value('model_id')
            ?? LlmModel::where('tenant_id', $tenantId)
                ->where('is_active', true)
                ->orderBy('sort_order')
                ->value('model_id');
    }

    // ── Conversational Chat Flow ────────────────────────────────

    /**
     * Process a chat message with product extraction and multi-stage search.
     *
     * Flow:
     *   1. Extract product name and question from user input via LLM
     *   2. Merge with existing context (product/question carried across turns)
     *   3. If product missing → ask the user which product
     *   4. If product+question ready → search with product filter
     *   5. No filtered results → broad search as reference
     *   6. Nothing found → "no knowledge available"
     *
     * Returns: ['action' => string, 'message' => string|null, 'context' => array,
     *           'results' => array, 'search_mode' => string, 'model_id' => string|null]
     */
    public function processChat(
        string $userMessage,
        int $embeddingId,
        int $tenantId,
        array $existingContext = [],
        array $history = [],
    ): array {
        $modelId = $this->resolveModelId($tenantId);

        // Step 1: Extract product name and question from user input
        $extracted = $this->extractProductAndQuestion($userMessage, $existingContext, $modelId);

        // Step 2: Merge extracted values with existing session context
        $context = [
            'product' => $extracted['product'] ?? $existingContext['product'] ?? null,
            'question' => $extracted['question'] ?? $existingContext['question'] ?? null,
        ];

        // Special case: we previously asked for product name (question exists, product missing).
        // If the user replied and LLM didn't detect a product, treat the entire reply as a product name.
        $wasAskingForProduct = !empty($existingContext['question']) && empty($existingContext['product']);
        if ($wasAskingForProduct && empty($extracted['product'])) {
            $context['product'] = $userMessage;
            $context['question'] = $existingContext['question'];
        }

        // Step 3: If product is still missing, ask the user
        if (empty($context['product']) && !empty($context['question'])) {
            return [
                'action' => 'ask_product',
                'message' => null,
                'context' => $context,
                'results' => [],
                'search_mode' => 'none',
                'model_id' => $modelId,
            ];
        }

        // If we have neither product nor question, ask for more detail
        if (empty($context['product']) && empty($context['question'])) {
            return [
                'action' => 'ask_product',
                'message' => null,
                'context' => $context,
                'results' => [],
                'search_mode' => 'none',
                'model_id' => $modelId,
            ];
        }

        // Step 4: Vector search by question (no product filter), then LLM product filtering
        $searchQuery = $context['question'] ?? $userMessage;
        $queryEmbedding = $this->bedrock->generateEmbedding($searchQuery);
        $vectorString = '[' . implode(',', $queryEmbedding) . ']';

        // Retrieve candidate KUs: collect from both embeddings, merge, deduplicate, sort by similarity
        $allCandidates = [];
        $seenIds = [];
        $searchMode = 'none';
        $minThreshold = 0.08;

        // Gather candidates from both embedding columns with relaxed threshold
        foreach (['search_embedding', 'broad_embedding'] as $column) {
            $results = $this->vectorSearch(
                $vectorString, $embeddingId, $column, $minThreshold, self::TOP_K * 2,
            );
            foreach ($results as $ku) {
                // Keep the highest similarity score per KU
                if (!isset($seenIds[$ku->id]) || (float)$ku->similarity > (float)$seenIds[$ku->id]->similarity) {
                    $seenIds[$ku->id] = $ku;
                }
            }
        }

        // Sort merged results by similarity descending
        $candidateKUs = array_values($seenIds);
        usort($candidateKUs, fn($a, $b) => (float)$b->similarity <=> (float)$a->similarity);
        $candidateKUs = array_slice($candidateKUs, 0, self::TOP_K * 2);

        if (!empty($candidateKUs)) {
            $topSimilarity = (float)$candidateKUs[0]->similarity;
            $searchMode = $topSimilarity >= self::PRECISE_THRESHOLD ? 'precise'
                : ($topSimilarity >= self::BROAD_THRESHOLD ? 'broad' : 'relaxed');
        }

        if (empty($candidateKUs)) {
            return [
                'action' => 'no_match',
                'message' => null,
                'context' => $context,
                'results' => [],
                'search_mode' => 'none',
                'model_id' => $modelId,
            ];
        }

        // Step 5: LLM-based product filtering on retrieved candidates
        $filteredKUs = $this->filterKUsByProduct(
            $candidateKUs, $context['product'], $modelId
        );

        if (!empty($filteredKUs)) {
            // Product-matched KUs found → direct answer
            return [
                'action' => 'answer',
                'message' => null,
                'context' => $context,
                'results' => array_slice($filteredKUs, 0, self::TOP_K),
                'search_mode' => $searchMode,
                'model_id' => $modelId,
            ];
        }

        // No product match → return all candidates as reference
        return [
            'action' => 'answer_broad',
            'message' => null,
            'context' => $context,
            'results' => array_slice($candidateKUs, 0, self::TOP_K),
            'search_mode' => 'broad_unfiltered',
            'model_id' => $modelId,
        ];
        return [
            'action' => 'no_match',
            'message' => null,
            'context' => $context,
            'results' => [],
            'search_mode' => 'none',
            'model_id' => $modelId,
        ];
    }

    /**
     * Extract product name and question from user input using LLM.
     *
     * Uses a lightweight prompt to classify the user's message into
     * a product identifier and a question/symptom description.
     */
    private function extractProductAndQuestion(
        string $userMessage,
        array $existingContext,
        ?string $modelId,
    ): array {
        if (!$modelId) {
            return ['product' => null, 'question' => $userMessage];
        }

        $contextHint = '';
        if (!empty($existingContext['product'])) {
            $contextHint = "\nNote: The user previously mentioned the product \"{$existingContext['product']}\".";
        }

        $prompt = <<<PROMPT
Extract the product/device name and the user's question from this support message.
{$contextHint}

User message: "{$userMessage}"

Respond with JSON only, no explanation:
{"product": "product name or null", "question": "the question or symptom description or null"}

Rules:
- "product" must be a specific brand, model, or product name (e.g. "LG Smart TV", "PlayStation", "iPhone 15", "Canon EOS", "Nest Thermostat")
- Generic category words are NOT product names: テレビ, パソコン, カメラ, スマホ, TV, computer, phone → set "product" to null
- The question should include the full symptom description, keeping general device words in it
- If the message contains BOTH a specific product and a question, extract both
- If the message is just a product/brand name (answering a follow-up), set "question" to null
- If the message has no specific brand/model, set "product" to null
- Keep the question in the original language

Examples:
- "LGテレビの画面がちらつく" → {"product": "LG TV", "question": "画面がちらつく"}
- "テレビの画面がちらつく" → {"product": null, "question": "テレビの画面がちらつく"}
- "My PlayStation screen flickers" → {"product": "PlayStation", "question": "screen flickers"}
- "画面が映らない" → {"product": null, "question": "画面が映らない"}
- "Canon EOS" → {"product": "Canon EOS", "question": null}
- "パソコンが起動しない" → {"product": null, "question": "パソコンが起動しない"}
PROMPT;

        try {
            $parsed = $this->bedrock->invokeJson($modelId, $prompt);

            if ($parsed && is_array($parsed)) {
                return [
                    'product' => (!empty($parsed['product']) && $parsed['product'] !== 'null') ? $parsed['product'] : null,
                    'question' => (!empty($parsed['question']) && $parsed['question'] !== 'null') ? $parsed['question'] : null,
                ];
            }
        } catch (\Exception $e) {
            Log::warning("Product extraction failed: " . $e->getMessage());
        }

        // Fallback: treat entire message as the question
        return ['product' => null, 'question' => $userMessage];
    }

    /**
     * Filter retrieved KU candidates by product relevance using LLM.
     *
     * Given a list of KUs from vector search and a user-specified product name,
     * ask LLM: "Which of these KUs are about this product?"
     * Returns only the matching KUs, preserving their original order and data.
     */
    private function filterKUsByProduct(array $candidateKUs, string $userProduct, ?string $modelId): array
    {
        if (!$modelId || empty($candidateKUs)) {
            return [];
        }

        // Build a concise list of KU IDs with their product/topic for LLM
        $kuList = [];
        foreach ($candidateKUs as $index => $ku) {
            $kuList[] = [
                'index' => $index,
                'topic' => $ku->topic,
                'product' => $ku->product ?? 'unknown',
            ];
        }

        $kuJson = json_encode($kuList, JSON_UNESCAPED_UNICODE);
        $prompt = <<<PROMPT
The user is asking about: "{$userProduct}"

Here are knowledge base entries retrieved by similarity search:
{$kuJson}

Which entries are relevant to the user's product "{$userProduct}"?
Account for abbreviations, nicknames, and language variations.
Examples: "プレステ" matches "PlayStation", "LGテレビ" matches "LG Smart TV" or "LG OLED".

Respond with JSON only: {"matched_indices": [0, 2, 5]}
Return an empty array if none match.
PROMPT;

        try {
            $parsed = $this->bedrock->invokeJson($modelId, $prompt);

            if ($parsed && isset($parsed['matched_indices']) && is_array($parsed['matched_indices'])) {
                $filtered = [];
                foreach ($parsed['matched_indices'] as $idx) {
                    if (isset($candidateKUs[$idx])) {
                        $filtered[] = $candidateKUs[$idx];
                    }
                }
                return $filtered;
            }
        } catch (\Exception $e) {
            Log::warning("KU product filtering failed: " . $e->getMessage());
        }

        // Fallback: simple string match on product field
        return array_values(array_filter($candidateKUs, fn($ku) =>
            $ku->product && (
                stripos($ku->product, $userProduct) !== false ||
                stripos($userProduct, $ku->product) !== false
            )
        ));
    }

    /**
     * Match a user-provided product name against known products in KU data.
     *
     * Uses LLM to handle name variations (e.g. "プレステ" ≒ "PlayStation").
     * Returns array of matched product strings from the database.
     */
    private function matchProductNames(
        string $userProductName,
        int $embeddingId,
        ?string $modelId,
    ): array {
        // Collect all distinct product names from approved KUs in this embedding
        $knownProducts = DB::select("
            SELECT DISTINCT product FROM knowledge_units
            WHERE embedding_id = ? AND review_status = 'approved'
              AND product IS NOT NULL AND product != ''
        ", [$embeddingId]);

        $productList = array_map(fn($row) => $row->product, $knownProducts);

        if (empty($productList)) {
            return [];
        }

        // Use LLM to match with fuzzy logic
        if (!$modelId) {
            // Fallback: simple substring match
            return array_filter($productList, fn($p) =>
                stripos($p, $userProductName) !== false ||
                stripos($userProductName, $p) !== false
            );
        }

        $productJson = json_encode($productList, JSON_UNESCAPED_UNICODE);
        $prompt = <<<PROMPT
Given the user's product name and a list of known products, identify which known products match.
Account for abbreviations, nicknames, and language variations.
Examples: "プレステ" matches "PlayStation", "PS5" matches "PlayStation 5", "iPhone" matches "Apple iPhone 15".

User's product name: "{$userProductName}"
Known products: {$productJson}

Respond with JSON only: {"matched": ["product1", "product2"]}
Return an empty array if no match found.
PROMPT;

        try {
            $parsed = $this->bedrock->invokeJson($modelId, $prompt);

            if ($parsed && isset($parsed['matched']) && is_array($parsed['matched'])) {
                // Validate that returned products actually exist in our list
                return array_values(array_intersect($parsed['matched'], $productList));
            }
        } catch (\Exception $e) {
            Log::warning("Product matching failed: " . $e->getMessage());
        }

        // Fallback: simple substring match
        return array_values(array_filter($productList, fn($p) =>
            stripos($p, $userProductName) !== false ||
            stripos($userProductName, $p) !== false
        ));
    }

    /**
     * Vector search with product name filter.
     */
    private function filteredVectorSearch(
        string $vectorString,
        int $embeddingId,
        array $productNames,
        string $embeddingColumn,
        float $threshold,
        int $topK,
    ): array {
        if (!in_array($embeddingColumn, ['search_embedding', 'broad_embedding'])) {
            throw new \InvalidArgumentException("Invalid embedding column: {$embeddingColumn}");
        }

        // Build product filter placeholders
        $placeholders = implode(',', array_fill(0, count($productNames), '?'));

        $params = array_merge(
            [$vectorString, $embeddingId],
            $productNames,
            [$vectorString, $threshold, $vectorString, $topK],
        );

        return DB::select("
            SELECT
                id, topic, intent, summary, question, product,
                symptoms, root_cause, resolution_summary,
                1 - ({$embeddingColumn} <=> ?::vector) AS similarity
            FROM knowledge_units
            WHERE embedding_id = ?
              AND review_status = 'approved'
              AND product IN ({$placeholders})
              AND {$embeddingColumn} IS NOT NULL
              AND 1 - ({$embeddingColumn} <=> ?::vector) >= ?
            ORDER BY {$embeddingColumn} <=> ?::vector
            LIMIT ?
        ", $params);
    }

    /**
     * Check if a text field contains meaningful content (not CSV garbage).
     */
    private function isUsefulText(?string $text): bool
    {
        if (!$text || strlen(trim($text)) < 10) return false;
        if (preg_match('/^(\w+\s+){1,4}\w+\.;\s/', $text)) return false;
        return true;
    }
}
