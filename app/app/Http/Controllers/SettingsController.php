<?php

namespace App\Http\Controllers;

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
 * Provides a CRUD interface for the tenant's LLM model registry.
 * Models managed here appear in the pipeline dispatch dropdown.
 */
class SettingsController extends Controller
{
    /**
     * Display the LLM model management page.
     *
     * Fetches available models from AWS Bedrock API (cached 1 hour)
     * and shows the tenant's registered models alongside.
     */
    public function index(): View
    {
        $tenantId = auth()->user()->tenant_id;

        $models = LlmModel::where('tenant_id', $tenantId)
            ->orderBy('sort_order')
            ->get();

        // Fetch available Bedrock models (cached for 1 hour)
        $bedrockModels = $this->fetchBedrockModels();

        // Fetch pricing from AWS Price List API (cached 24 hours)
        $pricing = $this->fetchBedrockPricing();

        return view('settings.models', compact('models', 'bedrockModels', 'pricing'));
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
                    foreach ($profiles['inferenceProfileSummaries'] ?? [] as $p) {
                        $result[] = [
                            'model_id' => $p['inferenceProfileId'],
                            'display_name' => $p['inferenceProfileName'],
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

                foreach ($response['modelSummaries'] as $m) {
                    $input = $m['inputModalities'] ?? [];
                    $output = $m['outputModalities'] ?? [];
                    if (!in_array('TEXT', $input) || !in_array('TEXT', $output)) {
                        continue;
                    }
                    if (str_contains(strtolower($m['modelName'] ?? ''), 'rerank')) {
                        continue;
                    }

                    // Skip if already covered by an inference profile
                    $dominated = false;
                    foreach ($profileModelIds as $pid) {
                        if (str_contains($pid, $m['modelId'])) {
                            $dominated = true;
                            break;
                        }
                    }
                    if ($dominated) continue;

                    $result[] = [
                        'model_id' => $m['modelId'],
                        'display_name' => $m['modelName'],
                        'provider' => $m['providerName'],
                    ];
                }

                usort($result, function ($a, $b) {
                    return [$a['provider'], $a['display_name']] <=> [$b['provider'], $b['display_name']];
                });

                return $result;
            } catch (\Exception $e) {
                Log::warning('Failed to fetch Bedrock models: ' . $e->getMessage());
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
                            foreach ($term['priceDimensions'] ?? [] as $dim) {
                                $usd = $dim['pricePerUnit']['USD'] ?? null;
                                $unit = $dim['unit'] ?? null;
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
            'titan-embed' => 'titan multimodal embeddings',
            'titan-text-express' => 'titan text express',
            'titan-text-lite' => 'titan text lite',
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

        $tenantId = auth()->user()->tenant_id;
        $modelId = $request->input('model_id');

        // Bedrock on-demand inference for newer models requires an inference
        // profile ID (e.g. "jp.anthropic.claude-...") rather than the bare
        // model ID. The model_id from the Bedrock dropdown already includes
        // the correct prefix, so we use it as-is.

        // Check for duplicate model_id within the tenant
        $exists = LlmModel::where('tenant_id', $tenantId)
            ->where('model_id', $modelId)
            ->exists();

        if ($exists) {
            return redirect()->route('settings.models')
                ->with('error', 'This model is already registered.');
        }

        // Use provided display_name, or look up from Bedrock API cache
        $displayName = $request->input('display_name');
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

        $maxSort = LlmModel::where('tenant_id', $tenantId)->max('sort_order') ?? -1;

        LlmModel::create([
            'tenant_id' => $tenantId,
            'display_name' => $displayName,
            'model_id' => $modelId,
            'is_default' => false,
            'sort_order' => $maxSort + 1,
            'is_active' => true,
        ]);

        return redirect()->route('settings.models')
            ->with('success', "{$displayName} added.");
    }

    /**
     * Update an existing LLM model (toggle active or rename).
     */
    public function update(Request $request, LlmModel $llmModel): RedirectResponse
    {
        $action = $request->input('action');

        if ($action === 'toggle_active') {
            $llmModel->update(['is_active' => !$llmModel->is_active]);
            $status = $llmModel->is_active ? 'activated' : 'deactivated';

            return redirect()->route('settings.models')
                ->with('success', "{$llmModel->display_name} {$status}.");
        }

        // Generic field update (display_name, sort_order)
        $request->validate([
            'display_name' => 'sometimes|string|max:100',
            'sort_order' => 'sometimes|integer|min:0',
        ]);

        $llmModel->update($request->only(['display_name', 'sort_order']));

        return redirect()->route('settings.models')
            ->with('success', 'Model updated.');
    }

    /**
     * Remove an LLM model from the registry.
     */
    public function destroy(LlmModel $llmModel): RedirectResponse
    {
        $name = $llmModel->display_name;
        $llmModel->delete();

        return redirect()->route('settings.models')
            ->with('success', "{$name} deleted.");
    }
}
