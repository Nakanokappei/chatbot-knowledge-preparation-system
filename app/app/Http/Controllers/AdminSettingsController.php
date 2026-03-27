<?php

namespace App\Http\Controllers;

use App\Models\EmbeddingModel;
use App\Models\LlmModel;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\View\View;

/**
 * System administrator settings controller.
 *
 * Manages system-level model templates (workspace_id = NULL) that are
 * available to all workspaces. System admins can add models from Bedrock,
 * set pricing, but cannot set default models (workspace-level concern).
 */
class AdminSettingsController extends Controller
{
    /**
     * Display the system model template management page.
     */
    public function index(): View
    {
        // System templates: models with no workspace binding
        $models = LlmModel::whereNull('workspace_id')
            ->orderBy('sort_order')
            ->get();

        $embeddingModels = EmbeddingModel::whereNull('workspace_id')
            ->orderBy('sort_order')
            ->get();

        // Fetch available Bedrock models (reuse SettingsController method)
        $settingsCtrl = new SettingsController();
        $bedrockModels = $this->invokePrivate($settingsCtrl, 'fetchBedrockModels');
        $bedrockEmbeddingModels = $this->invokePrivate($settingsCtrl, 'fetchBedrockEmbeddingModels');
        $pricing = $this->invokePrivate($settingsCtrl, 'fetchBedrockPricing');

        return view('admin.settings', compact(
            'models', 'embeddingModels', 'bedrockModels',
            'bedrockEmbeddingModels', 'pricing',
        ));
    }

    /**
     * Add a new system-level LLM model template.
     */
    public function store(Request $request): RedirectResponse
    {
        $request->validate([
            'model_id' => 'required|string|max:200',
            'display_name' => 'nullable|string|max:100',
        ]);

        $modelId = $request->input('model_id');

        // Check for duplicate model_id in system templates
        if (LlmModel::whereNull('workspace_id')->where('model_id', $modelId)->exists()) {
            return redirect()->route('admin.settings.index')
                ->with('error', 'This model is already registered as a system template.');
        }

        $displayName = $request->input('display_name') ?: $modelId;
        $maxSort = LlmModel::whereNull('workspace_id')->max('sort_order') ?? -1;

        LlmModel::create([
            'workspace_id' => null,
            'display_name' => $displayName,
            'model_id' => $modelId,
            'is_default' => false,
            'sort_order' => $maxSort + 1,
            'is_active' => true,
        ]);

        return redirect()->route('admin.settings.index')
            ->with('success', "{$displayName} added as system template.");
    }

    /**
     * Update a system-level LLM model template.
     * Set-default is forbidden for system admins.
     */
    public function update(Request $request, LlmModel $llmModel): RedirectResponse
    {
        $action = $request->input('action');

        // System admin cannot set default models
        if ($action === 'set_default') {
            abort(403, 'System admins cannot set default models. This is a workspace-level setting.');
        }

        if ($action === 'toggle_active') {
            $llmModel->update(['is_active' => !$llmModel->is_active]);
            $status = $llmModel->is_active ? 'activated' : 'deactivated';
            return redirect()->route('admin.settings.index')
                ->with('success', "{$llmModel->display_name} {$status}.");
        }

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
            return redirect()->route('admin.settings.index')
                ->with('success', "Pricing updated for {$llmModel->display_name}.");
        }

        return redirect()->route('admin.settings.index');
    }

    /**
     * Remove a system-level LLM model template.
     */
    public function destroy(LlmModel $llmModel): RedirectResponse
    {
        $name = $llmModel->display_name;
        $llmModel->delete();
        return redirect()->route('admin.settings.index')
            ->with('success', "{$name} deleted.");
    }

    // ── Embedding Model CRUD ──

    public function storeEmbedding(Request $request): RedirectResponse
    {
        $request->validate([
            'model_id' => 'required|string|max:200',
            'display_name' => 'nullable|string|max:100',
            'dimension' => 'nullable|integer|min:1|max:8192',
        ]);

        $modelId = $request->input('model_id');

        if (EmbeddingModel::whereNull('workspace_id')->where('model_id', $modelId)->exists()) {
            return redirect()->route('admin.settings.index')
                ->with('error', 'This embedding model is already registered.');
        }

        $displayName = $request->input('display_name') ?: $modelId;
        $maxSort = EmbeddingModel::whereNull('workspace_id')->max('sort_order') ?? -1;

        EmbeddingModel::create([
            'workspace_id' => null,
            'display_name' => $displayName,
            'model_id' => $modelId,
            'dimension' => $request->input('dimension', 1024),
            'is_default' => false,
            'sort_order' => $maxSort + 1,
            'is_active' => true,
        ]);

        return redirect()->route('admin.settings.index')
            ->with('success', "{$displayName} added as system template.");
    }

    public function updateEmbedding(Request $request, EmbeddingModel $embeddingModel): RedirectResponse
    {
        $action = $request->input('action');

        if ($action === 'set_default') {
            abort(403, 'System admins cannot set default models.');
        }

        if ($action === 'toggle_active') {
            $embeddingModel->update(['is_active' => !$embeddingModel->is_active]);
            $status = $embeddingModel->is_active ? 'activated' : 'deactivated';
            return redirect()->route('admin.settings.index')
                ->with('success', "{$embeddingModel->display_name} {$status}.");
        }

        if ($action === 'update_pricing') {
            if ($request->has('input_price_per_1m')) {
                $embeddingModel->update([
                    'input_price_per_1m' => max(0, (float) $request->input('input_price_per_1m')),
                ]);
            }
            return redirect()->route('admin.settings.index')
                ->with('success', "Pricing updated for {$embeddingModel->display_name}.");
        }

        return redirect()->route('admin.settings.index');
    }

    public function destroyEmbedding(EmbeddingModel $embeddingModel): RedirectResponse
    {
        $name = $embeddingModel->display_name;
        $embeddingModel->delete();
        return redirect()->route('admin.settings.index')
            ->with('success', "{$name} deleted.");
    }

    /**
     * Helper to invoke private methods on SettingsController for Bedrock data.
     */
    private function invokePrivate(object $obj, string $method): mixed
    {
        $ref = new \ReflectionMethod($obj, $method);
        $ref->setAccessible(true);
        return $ref->invoke($obj);
    }
}
