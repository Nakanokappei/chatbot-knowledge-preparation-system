<?php

namespace App\Http\Controllers;

use App\Models\Embedding;
use App\Models\KnowledgeUnit;
use App\Models\KnowledgeUnitVersion;
use App\Services\BedrockService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * Manual QA registration — create Knowledge Units without the pipeline.
 *
 * Generates search_embedding and broad_embedding on save via Bedrock,
 * then enters the standard review workflow (draft -> approved).
 */
class ManualKnowledgeUnitController extends Controller
{
    /**
     * Show the QA registration form.
     */
    public function create(Request $request): View
    {
        $workspaceId = auth()->user()->workspace_id;

        // List embeddings with status='ready' for the selector
        $embeddings = Embedding::where('workspace_id', $workspaceId)
            ->where('status', 'ready')
            ->with('dataset:id,name')
            ->orderByDesc('created_at')
            ->get();

        // Pre-fill from query params (linked from workspace view or Question Insights)
        $prefillQuestion = substr(trim($request->query('question', '')), 0, 2000);
        $prefillEmbeddingId = $request->query('embedding_id');

        return view('dashboard.knowledge_units.create', [
            'embeddings' => $embeddings,
            'prefillQuestion' => $prefillQuestion,
            'prefillEmbeddingId' => $prefillEmbeddingId,
        ]);
    }

    /**
     * Validate input, generate embeddings, and store the KU.
     */
    public function store(Request $request): RedirectResponse
    {
        $workspaceId = auth()->user()->workspace_id;

        $request->validate([
            'embedding_id' => [
                'required', 'integer',
                Rule::exists('embeddings', 'id')->where('workspace_id', $workspaceId),
            ],
            'question' => 'required|string|max:2000',
            'resolution_summary' => 'required|string|max:5000',
            'topic' => 'required|string|max:200',
            'intent' => 'required|string|max:200',
            'summary' => 'required|string|max:5000',
            'primary_filter' => 'nullable|string|max:255',
            'category' => 'nullable|string|max:255',
            'symptoms' => 'nullable|string|max:5000',
            'root_cause' => 'nullable|string|max:5000',
            'cause_summary' => 'nullable|string|max:5000',
            'notes' => 'nullable|string|max:5000',
            'reference_url' => 'nullable|url|max:2048',
            'response_mode' => 'nullable|string|in:answer,link_only',
            'keywords' => 'nullable|string|max:1000',
        ]);

        $userId = auth()->id();

        // Verify the embedding belongs to this workspace (defense-in-depth)
        $embedding = Embedding::where('id', $request->embedding_id)
            ->where('workspace_id', $workspaceId)
            ->firstOrFail();

        // Generate embedding vectors via Bedrock Titan
        $bedrock = new BedrockService();
        $question = $request->input('question');

        try {
            $searchEmbedding = $bedrock->generateEmbedding($question);

            // Broad embedding: enriched text combining multiple fields
            $broadText = collect([
                $question,
                $request->input('topic'),
                $request->input('symptoms'),
                $request->input('summary'),
            ])->filter()->implode(' ');
            $broadEmbedding = $bedrock->generateEmbedding($broadText);
        } catch (\Exception $e) {
            return back()->withInput()
                ->withErrors(['embedding_id' => __('ui.bedrock_unavailable')]);
        }

        // Validate embedding dimensions and coerce to float for pgvector safety
        $searchEmbedding = array_map('floatval', $searchEmbedding);
        $broadEmbedding = array_map('floatval', $broadEmbedding);

        if (count($searchEmbedding) !== 1024 || count($broadEmbedding) !== 1024) {
            return back()->withInput()
                ->withErrors(['embedding_id' => 'Unexpected embedding dimension from Bedrock.']);
        }

        // Parse comma-separated keywords into array
        $keywords = null;
        if ($request->filled('keywords')) {
            $keywords = array_map('trim', explode(',', $request->input('keywords')));
            $keywords = array_values(array_filter($keywords));
        }

        $ku = DB::transaction(function () use (
            $request, $embedding, $workspaceId, $userId,
            $searchEmbedding, $broadEmbedding, $keywords
        ) {
            // Create the KU record
            $ku = KnowledgeUnit::create([
                'workspace_id' => $workspaceId,
                'dataset_id' => $embedding->dataset_id,
                'embedding_id' => $embedding->id,
                'pipeline_job_id' => null,
                'cluster_id' => null,
                'source_type' => 'manual',
                'topic' => $request->input('topic'),
                'intent' => $request->input('intent'),
                'summary' => $request->input('summary'),
                'question' => $request->input('question'),
                'symptoms' => $request->input('symptoms'),
                'root_cause' => $request->input('root_cause'),
                'primary_filter' => $request->input('primary_filter'),
                'category' => $request->input('category'),
                'cause_summary' => $request->input('cause_summary'),
                'resolution_summary' => $request->input('resolution_summary'),
                'notes' => $request->input('notes'),
                'reference_url' => $request->input('reference_url'),
                'response_mode' => $request->input('response_mode', 'answer'),
                'keywords_json' => $keywords,
                'row_count' => 0,
                'confidence' => 1.00,
                'review_status' => 'draft',
                'version' => 1,
                'edited_by_user_id' => $userId,
                'edited_at' => now(),
                'edit_comment' => 'Manual QA registration',
            ]);

            // Store embedding vectors via raw SQL (pgvector columns)
            $searchVector = '[' . implode(',', $searchEmbedding) . ']';
            $broadVector = '[' . implode(',', $broadEmbedding) . ']';

            DB::statement(
                'UPDATE knowledge_units SET search_embedding = ?::vector, broad_embedding = ?::vector WHERE id = ?',
                [$searchVector, $broadVector, $ku->id]
            );

            // Create version snapshot (v1)
            KnowledgeUnitVersion::create([
                'knowledge_unit_id' => $ku->id,
                'version' => 1,
                'snapshot_json' => [
                    'topic' => $ku->topic,
                    'intent' => $ku->intent,
                    'summary' => $ku->summary,
                    'question' => $ku->question,
                    'resolution_summary' => $ku->resolution_summary,
                    'cause_summary' => $ku->cause_summary,
                    'notes' => $ku->notes,
                    'keywords' => $keywords,
                    'review_status' => 'draft',
                    'source_type' => 'manual',
                    'edited_by_user_id' => $userId,
                ],
            ]);

            return $ku;
        });

        return redirect()->route('knowledge-units.show', $ku)
            ->with('success', __('ui.manual_qa_saved'));
    }
}
