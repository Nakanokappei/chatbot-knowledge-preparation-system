<?php

namespace App\Http\Controllers;

use App\Models\LlmModel;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
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
     */
    public function index(): View
    {
        $tenantId = auth()->user()->tenant_id;

        $models = LlmModel::where('tenant_id', $tenantId)
            ->orderBy('sort_order')
            ->get();

        return view('settings.models', compact('models'));
    }

    /**
     * Add a new LLM model to the registry.
     */
    public function store(Request $request): RedirectResponse
    {
        $request->validate([
            'display_name' => 'required|string|max:100',
            'model_id' => 'required|string|max:200',
        ]);

        $tenantId = auth()->user()->tenant_id;

        // Determine the next sort_order value
        $maxSort = LlmModel::where('tenant_id', $tenantId)->max('sort_order') ?? -1;

        // Check for duplicate model_id within the tenant
        $exists = LlmModel::where('tenant_id', $tenantId)
            ->where('model_id', $request->input('model_id'))
            ->exists();

        if ($exists) {
            return redirect()->route('settings.models')
                ->with('error', 'This Model ID already exists.');
        }

        // If this is the first model, make it the default
        $isFirst = LlmModel::where('tenant_id', $tenantId)->count() === 0;

        LlmModel::create([
            'tenant_id' => $tenantId,
            'display_name' => $request->input('display_name'),
            'model_id' => $request->input('model_id'),
            'is_default' => $isFirst,
            'sort_order' => $maxSort + 1,
            'is_active' => true,
        ]);

        return redirect()->route('settings.models')
            ->with('success', 'Model added successfully.');
    }

    /**
     * Update an existing LLM model (toggle default, toggle active, or rename).
     */
    public function update(Request $request, LlmModel $llmModel): RedirectResponse
    {
        $tenantId = auth()->user()->tenant_id;

        $action = $request->input('action');

        if ($action === 'set_default') {
            // Clear all defaults for this tenant, then set the selected one
            DB::transaction(function () use ($tenantId, $llmModel) {
                LlmModel::where('tenant_id', $tenantId)
                    ->update(['is_default' => false]);
                $llmModel->update(['is_default' => true, 'is_active' => true]);
            });

            return redirect()->route('settings.models')
                ->with('success', "{$llmModel->display_name} set as default.");
        }

        if ($action === 'toggle_active') {
            // Prevent deactivating the default model
            if ($llmModel->is_default && $llmModel->is_active) {
                return redirect()->route('settings.models')
                    ->with('error', 'Cannot deactivate the default model. Set another default first.');
            }

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
        // Prevent deleting the default model
        if ($llmModel->is_default) {
            return redirect()->route('settings.models')
                ->with('error', 'Cannot delete the default model. Set another default first.');
        }

        $name = $llmModel->display_name;
        $llmModel->delete();

        return redirect()->route('settings.models')
            ->with('success', "{$name} deleted.");
    }
}
