<?php

namespace App\Http\Controllers;

use App\Models\EmbeddingModel;
use App\Models\LlmModel;
use Aws\BedrockRuntime\BedrockRuntimeClient;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\View\View;

/**
 * Settings controller for managing LLM models.
 *
 * Provides a CRUD interface for the workspace's LLM model registry.
 * Models managed here appear in the pipeline dispatch dropdown.
 */
class SettingsController extends Controller
{
    /**
     * Display the LLM model management page.
     *
     * Fetches available models from AWS Bedrock API (cached 1 hour)
     * and shows the workspace's registered models alongside.
     */
    public function index(): View
    {
        $workspaceId = auth()->user()->workspace_id;

        $models = LlmModel::where('workspace_id', $workspaceId)
            ->orderBy('sort_order')
            ->get();

        // Embedding models
        $embeddingModels = EmbeddingModel::where('workspace_id', $workspaceId)
            ->orderBy('sort_order')
            ->get();

        // System templates: models added by system admin (workspace_id = NULL)
        $systemLlmModels = LlmModel::whereNull('workspace_id')
            ->where('is_active', true)
            ->orderBy('sort_order')
            ->get();

        $systemEmbeddingModels = EmbeddingModel::whereNull('workspace_id')
            ->where('is_active', true)
            ->orderBy('sort_order')
            ->get();

        // Fetch available Bedrock models (cached for 1 hour)
        $bedrockModels = $this->fetchBedrockModels();
        $bedrockEmbeddingModels = $this->fetchBedrockEmbeddingModels();

        // Fetch pricing from AWS Price List API (cached 24 hours)
        $pricing = $this->fetchBedrockPricing();

        return view('settings.index', compact(
            'models', 'embeddingModels', 'bedrockModels',
            'bedrockEmbeddingModels', 'pricing',
            'systemLlmModels', 'systemEmbeddingModels',
        ));
    }

    /**
     * Fetch text-capable foundation models from Bedrock API.
     *
     * Returns an array of ['model_id' => ..., 'display_name' => ..., 'provider' => ...]
     * sorted by provider then name. Results are cached for 1 hour.
     */
    private function fetchBedrockModels(): array
    {
        return Cache::remember('bedrock_models_list', 3600, function () {
            try {
                $client = new \Aws\Bedrock\BedrockClient([
                    'region' => env('AWS_DEFAULT_REGION', 'ap-northeast-1'),
                    'version' => 'latest',
                ]);

                $result = [];

                // Prefer inference profiles — these are the IDs that actually
                // work with InvokeModel for newer models (e.g. jp.anthropic...).
                try {
                    $profiles = $client->listInferenceProfiles();
                    foreach ($profiles['inferenceProfileSummaries'] ?? [] as $profile) {
                        $result[] = [
                            'model_id' => $profile['inferenceProfileId'],
                            'display_name' => $profile['inferenceProfileName'],
                            'provider' => 'Inference Profile',
                        ];
                    }
                } catch (\Exception $e) {
                    Log::debug('Could not list inference profiles: ' . $e->getMessage());
                }

                // Also list foundation models as fallback (older models work
                // with bare model IDs)
                $response = $client->listFoundationModels();
                $profileModelIds = array_column($result, 'model_id');

                // Filter foundation models to text-in/text-out only
                foreach ($response['modelSummaries'] as $modelSummary) {
                    $inputModalities = $modelSummary['inputModalities'] ?? [];
                    $outputModalities = $modelSummary['outputModalities'] ?? [];
                    // Exclude non-text models (image, audio)
                    if (!in_array('TEXT', $inputModalities) || !in_array('TEXT', $outputModalities)) {
                        continue;
                    }
                    // Exclude reranker models (not usable for generation)
                    if (str_contains(strtolower($modelSummary['modelName'] ?? ''), 'rerank')) {
                        continue;
                    }

                    // Skip if already covered by an inference profile
                    $coveredByProfile = false;
                    foreach ($profileModelIds as $profileId) {
                        if (str_contains($profileId, $modelSummary['modelId'])) {
                            $coveredByProfile = true;
                            break;
                        }
                    }
                    if ($coveredByProfile) continue;

                    $result[] = [
                        'model_id' => $modelSummary['modelId'],
                        'display_name' => $modelSummary['modelName'],
                        'provider' => $modelSummary['providerName'],
                    ];
                }

                usort($result, function ($modelA, $modelB) {
                    return [$modelA['provider'], $modelA['display_name']] <=> [$modelB['provider'], $modelB['display_name']];
                });

                return $result;
            } catch (\Exception $e) {
                Log::warning('Failed to fetch Bedrock models: ' . $e->getMessage());
                return [];
            }
        });
    }

    /**
     * Fetch embedding-capable foundation models from Bedrock API.
     *
     * Returns models where output modality is EMBEDDING.
     */
    private function fetchBedrockEmbeddingModels(): array
    {
        return Cache::remember('bedrock_embedding_models_v2', 3600, function () {
            try {
                $client = new \Aws\Bedrock\BedrockClient([
                    'region' => env('AWS_DEFAULT_REGION', 'ap-northeast-1'),
                    'version' => 'latest',
                ]);

                $response = $client->listFoundationModels();
                $result = [];

                $seen = [];
                foreach ($response['modelSummaries'] as $modelSummary) {
                    $outputModalities = $modelSummary['outputModalities'] ?? [];
                    if (!in_array('EMBEDDING', $outputModalities)) {
                        continue;
                    }

                    $modelId = $modelSummary['modelId'];

                    // Extract base model ID (strip region prefix like "ap-northeast-1.")
                    $baseModelId = preg_replace('/^[a-z]{2}-[a-z]+-\d+\./', '', $modelId);

                    // Deduplicate by base model ID, keep the region-prefixed version
                    // for display but use base for dedup
                    if (isset($seen[$baseModelId])) {
                        // If this one has a region prefix and the previous didn't, keep both
                        // Otherwise skip duplicate
                        if ($modelId === $baseModelId) {
                            continue; // Skip generic if region-specific already exists
                        }
                        // Add as variant with region info
                        $region = explode('.', $modelId)[0] ?? '';
                        $result[] = [
                            'model_id' => $modelId,
                            'display_name' => $modelSummary['modelName'] . " ({$region})",
                            'provider' => $modelSummary['providerName'],
                        ];
                        continue;
                    }

                    $seen[$baseModelId] = true;
                    $result[] = [
                        'model_id' => $modelId,
                        'display_name' => $modelSummary['modelName'],
                        'provider' => $modelSummary['providerName'],
                    ];
                }

                usort($result, function ($a, $b) {
                    return [$a['provider'], $a['display_name']] <=> [$b['provider'], $b['display_name']];
                });

                return $result;
            } catch (\Exception $e) {
                Log::warning('Failed to fetch Bedrock embedding models: ' . $e->getMessage());
                return [];
            }
        });
    }

    /**
     * Fetch Bedrock model pricing from AWS Price List API.
     *
     * Returns an associative array keyed by model display name (as used
     * in the Price List API), with input/output costs per 1K tokens and
     * the pricing unit string.
     *
     * Results are cached for 24 hours since pricing rarely changes.
     * Only us-east-1 pricing is available via the API.
     */
    private function fetchBedrockPricing(): array
    {
        return Cache::remember('bedrock_pricing', 86400, function () {
            try {
                $client = new \Aws\Pricing\PricingClient([
                    'region' => 'us-east-1',
                    'version' => 'latest',
                ]);

                $pricing = [];
                $nextToken = null;

                // Paginate through all providers
                do {
                    $params = [
                        'ServiceCode' => 'AmazonBedrock',
                        'Filters' => [
                            ['Type' => 'TERM_MATCH', 'Field' => 'regionCode', 'Value' => 'us-east-1'],
                        ],
                        'MaxResults' => 100,
                    ];
                    if ($nextToken) {
                        $params['NextToken'] = $nextToken;
                    }

                    $response = $client->getProducts($params);

                    foreach ($response['PriceList'] as $item) {
                        $data = json_decode($item, true);
                        $attrs = $data['product']['attributes'] ?? [];
                        $model = $attrs['model'] ?? null;
                        $inferenceType = $attrs['inferenceType'] ?? null;

                        if (!$model || !$inferenceType) {
                            continue;
                        }

                        // Extract price from OnDemand terms
                        $terms = $data['terms']['OnDemand'] ?? [];
                        $usd = null;
                        $unit = null;
                        foreach ($terms as $term) {
                            foreach ($term['priceDimensions'] ?? [] as $priceDimension) {
                                $usd = $priceDimension['pricePerUnit']['USD'] ?? null;
                                $unit = $priceDimension['unit'] ?? null;
                            }
                        }

                        if ($usd === null) {
                            continue;
                        }

                        $key = strtolower(trim($model));
                        if (!isset($pricing[$key])) {
                            $pricing[$key] = [
                                'model_name' => $model,
                                'input' => null,
                                'output' => null,
                                'unit' => $unit,
                            ];
                        }

                        if (str_contains(strtolower($inferenceType), 'input')) {
                            $pricing[$key]['input'] = (float) $usd;
                        } elseif (str_contains(strtolower($inferenceType), 'output')) {
                            $pricing[$key]['output'] = (float) $usd;
                        }
                    }

                    $nextToken = $response['NextToken'] ?? null;
                } while ($nextToken);

                return $pricing;
            } catch (\Exception $e) {
                Log::warning('Failed to fetch Bedrock pricing: ' . $e->getMessage());
                return [];
            }
        });
    }

    /**
     * Look up pricing for a specific model ID by fuzzy-matching model names.
     *
     * The Price List API uses display names like "Claude 3 Haiku" while
     * Bedrock uses IDs like "anthropic.claude-3-haiku-20240307-v1:0".
     * This method bridges the gap with substring matching.
     */
    public static function findPricingForModel(array $pricing, string $modelId): ?array
    {
        // Build mapping hints: model_id substring => pricing key patterns
        $hints = [
            'claude-instant' => 'claude instant',
            'claude-2.0' => 'claude 2.0',
            'claude-2.1' => 'claude 2.1',
            'claude-3-haiku' => 'claude 3 haiku',
            'claude-3-sonnet' => 'claude 3 sonnet',
            'claude-3-opus' => 'claude 3 opus',
            'claude-3-5-haiku' => 'claude 3.5 haiku',
            'claude-3-5-sonnet' => 'claude 3.5 sonnet',
            'claude-3-7-sonnet' => 'claude 3.7 sonnet',
            'claude-haiku-4' => 'claude haiku 4',
            'claude-sonnet-4' => 'claude sonnet 4',
            'claude-opus-4' => 'claude opus 4',
            'titan-embed-text-v2' => 'titan text embeddings v2',
            'titan-embed-text-v1' => 'titan embeddings g1',
            'titan-embed' => 'titan multimodal embeddings',
            'titan-text-express' => 'titan text express',
            'titan-text-lite' => 'titan text lite',
            'embed-english-v3' => 'embed english',
            'embed-multilingual-v3' => 'embed multilingual',
            'embed-v4' => 'embed v4',
            'llama3' => 'llama 3',
            'mistral-7b' => 'mistral 7b',
            'mixtral-8x7b' => 'mixtral 8x7b',
            'command-r' => 'command r',
        ];

        $modelLower = strtolower($modelId);

        foreach ($hints as $idPattern => $pricingPattern) {
            if (str_contains($modelLower, $idPattern)) {
                foreach ($pricing as $key => $info) {
                    if (str_contains($key, $pricingPattern)) {
                        return $info;
                    }
                }
            }
        }

        return null;
    }

    /**
     * Add a new LLM model to the registry.
     */
    public function store(Request $request): RedirectResponse
    {
        $request->validate([
            'model_id' => 'required|string|max:200',
            'display_name' => 'nullable|string|max:100',
        ]);

        $workspaceId = auth()->user()->workspace_id;
        $modelId = $request->input('model_id');

        // If system templates exist, owner/member can only choose from them
        $systemTemplates = LlmModel::whereNull('workspace_id')->where('is_active', true)->get();
        if ($systemTemplates->isNotEmpty()) {
            $template = $systemTemplates->firstWhere('model_id', $modelId);
            if (!$template) {
                return redirect()->route('settings.index')
                    ->with('error', __('ui.model_not_in_system_templates'));
            }
        }

        // Check for duplicate model_id within the workspace
        $exists = LlmModel::where('workspace_id', $workspaceId)
            ->where('model_id', $modelId)
            ->exists();

        if ($exists) {
            return redirect()->route('settings.index')
                ->with('error', 'This model is already registered.');
        }

        // Use system template display_name and pricing if available
        $displayName = $request->input('display_name');
        if (!$displayName && isset($template)) {
            $displayName = $template->display_name;
        }
        if (!$displayName) {
            $bedrockModels = $this->fetchBedrockModels();
            foreach ($bedrockModels as $bm) {
                if ($bm['model_id'] === $modelId) {
                    $displayName = $bm['provider'] . ' ' . $bm['display_name'];
                    break;
                }
            }
        }
        if (!$displayName) {
            $displayName = $modelId;
        }

        $maxSort = LlmModel::where('workspace_id', $workspaceId)->max('sort_order') ?? -1;
        $isFirst = LlmModel::where('workspace_id', $workspaceId)->count() === 0;

        LlmModel::create([
            'workspace_id' => $workspaceId,
            'display_name' => $displayName,
            'model_id' => $modelId,
            'is_default' => $isFirst,
            'sort_order' => $maxSort + 1,
            'is_active' => true,
            'input_price_per_1m' => isset($template) ? $template->input_price_per_1m : null,
            'output_price_per_1m' => isset($template) ? $template->output_price_per_1m : null,
        ]);

        $suffix = $isFirst ? ' (set as default)' : '';
        return redirect()->route('settings.index')
            ->with('success', "{$displayName} added{$suffix}.");
    }

    /**
     * Update an existing LLM model (toggle active or rename).
     */
    public function update(Request $request, LlmModel $llmModel): RedirectResponse
    {
        $action = $request->input('action');

        // Toggle active/inactive state
        if ($action === 'toggle_active') {
            $llmModel->update(['is_active' => !$llmModel->is_active]);
            $status = $llmModel->is_active ? 'activated' : 'deactivated';

            return redirect()->route('settings.index')
                ->with('success', "{$llmModel->display_name} {$status}.");
        }

        // Set this model as the default (exclusive — clears all others)
        if ($action === 'set_default') {
            $workspaceId = auth()->user()->workspace_id;
            LlmModel::where('workspace_id', $workspaceId)->update(['is_default' => false]);
            $llmModel->update(['is_default' => true]);

            return redirect()->route('settings.index')
                ->with('success', "{$llmModel->display_name} is now the default model.");
        }

        // Update pricing per 1M tokens
        if ($action === 'update_pricing') {
            $data = [];
            if ($request->has('input_price_per_1m')) {
                $data['input_price_per_1m'] = max(0, (float) $request->input('input_price_per_1m'));
            }
            if ($request->has('output_price_per_1m')) {
                $data['output_price_per_1m'] = max(0, (float) $request->input('output_price_per_1m'));
            }
            if (!empty($data)) {
                $llmModel->update($data);
            }

            return redirect()->route('settings.index')
                ->with('success', "Pricing updated for {$llmModel->display_name}.");
        }

        // Generic field update (display_name, sort_order)
        $request->validate([
            'display_name' => 'sometimes|string|max:100',
            'sort_order' => 'sometimes|integer|min:0',
        ]);

        $llmModel->update($request->only(['display_name', 'sort_order']));

        return redirect()->route('settings.index')
            ->with('success', 'Model updated.');
    }

    /**
     * Remove an LLM model from the registry.
     */
    public function destroy(LlmModel $llmModel): RedirectResponse
    {
        $name = $llmModel->display_name;
        $llmModel->delete();

        return redirect()->route('settings.index')
            ->with('success', "{$name} deleted.");
    }

    // ── Embedding Model CRUD ──────────────────────────────────────

    /**
     * Add a new embedding model to the registry.
     */
    public function storeEmbedding(Request $request): RedirectResponse
    {
        $request->validate([
            'model_id' => 'required|string|max:200',
            'display_name' => 'nullable|string|max:100',
            'dimension' => 'nullable|integer|min:1|max:8192',
        ]);

        $workspaceId = auth()->user()->workspace_id;
        $modelId = $request->input('model_id');

        // If system templates exist, only allow models from those templates
        $systemTemplates = EmbeddingModel::whereNull('workspace_id')->where('is_active', true)->get();
        $template = null;
        if ($systemTemplates->isNotEmpty()) {
            $template = $systemTemplates->firstWhere('model_id', $modelId);
            if (!$template) {
                return redirect()->route('settings.index')
                    ->with('error', __('ui.model_not_in_system_templates'));
            }
        }

        $exists = EmbeddingModel::where('workspace_id', $workspaceId)
            ->where('model_id', $modelId)
            ->exists();

        if ($exists) {
            return redirect()->route('settings.index')
                ->with('error', 'This embedding model is already registered.');
        }

        // Use system template display_name if available
        $displayName = $request->input('display_name');
        if (!$displayName && $template) {
            $displayName = $template->display_name;
        }
        if (!$displayName) {
            $bedrockModels = $this->fetchBedrockEmbeddingModels();
            foreach ($bedrockModels as $bm) {
                if ($bm['model_id'] === $modelId) {
                    $displayName = $bm['provider'] . ' ' . $bm['display_name'];
                    break;
                }
            }
        }
        if (!$displayName) {
            $displayName = $modelId;
        }

        // Use dimension from system template when available, otherwise from request
        $dimension = $template ? $template->dimension : $request->input('dimension', 1024);

        $maxSort = EmbeddingModel::where('workspace_id', $workspaceId)->max('sort_order') ?? -1;
        $isFirst = EmbeddingModel::where('workspace_id', $workspaceId)->count() === 0;

        EmbeddingModel::create([
            'workspace_id' => $workspaceId,
            'display_name' => $displayName,
            'model_id' => $modelId,
            'dimension' => $dimension,
            'is_default' => $isFirst,
            'sort_order' => $maxSort + 1,
            'is_active' => true,
            'input_price_per_1m' => $template ? $template->input_price_per_1m : null,
        ]);

        $suffix = $isFirst ? ' (set as default)' : '';
        return redirect()->route('settings.index')
            ->with('success', "{$displayName} added{$suffix}.");
    }

    /**
     * Update an existing embedding model.
     */
    public function updateEmbedding(Request $request, EmbeddingModel $embeddingModel): RedirectResponse
    {
        $action = $request->input('action');

        if ($action === 'toggle_active') {
            $embeddingModel->update(['is_active' => !$embeddingModel->is_active]);
            $status = $embeddingModel->is_active ? 'activated' : 'deactivated';
            return redirect()->route('settings.index')
                ->with('success', "{$embeddingModel->display_name} {$status}.");
        }

        if ($action === 'set_default') {
            $workspaceId = auth()->user()->workspace_id;
            EmbeddingModel::where('workspace_id', $workspaceId)->update(['is_default' => false]);
            $embeddingModel->update(['is_default' => true]);
            return redirect()->route('settings.index')
                ->with('success', "{$embeddingModel->display_name} is now the default embedding model.");
        }

        if ($action === 'update_pricing') {
            if ($request->has('input_price_per_1m')) {
                $embeddingModel->update([
                    'input_price_per_1m' => max(0, (float) $request->input('input_price_per_1m')),
                ]);
            }
            return redirect()->route('settings.index')
                ->with('success', "Pricing updated for {$embeddingModel->display_name}.");
        }

        return redirect()->route('settings.index');
    }

    /**
     * Remove an embedding model from the registry.
     */
    public function destroyEmbedding(EmbeddingModel $embeddingModel): RedirectResponse
    {
        $name = $embeddingModel->display_name;
        $embeddingModel->delete();
        return redirect()->route('settings.index')
            ->with('success', "{$name} deleted.");
    }
}
