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
                id, topic, intent, summary, question,
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
