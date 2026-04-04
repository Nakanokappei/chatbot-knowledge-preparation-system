<?php

namespace App\Http\Controllers;

use App\Models\AnswerFeedback;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

/**
 * Question Aggregation Dashboard — Phase A.
 *
 * Provides visibility into chat questions across both package chat
 * (chat_messages) and workspace embedding chat (chat_turns).
 * Identifies unanswered, low-confidence, and frequently asked questions.
 */
class QuestionInsightsController extends Controller
{
    /**
     * Main dashboard view with tabs: All / Unanswered / Frequent.
     */
    public function index(Request $request): View
    {
        $workspaceId = auth()->user()->workspace_id;
        $tab = $request->query('tab', 'all');
        $days = (int) $request->query('days', 30);
        $since = now()->subDays($days);

        // Summary statistics
        $stats = $this->getStats($workspaceId, $since);

        // Tab-specific data with pagination
        $data = match ($tab) {
            'unanswered' => $this->getUnanswered($workspaceId, $since),
            'frequent' => $this->getFrequent($workspaceId, $since),
            default => $this->getAll($workspaceId, $since),
        };

        return view('dashboard.question_insights.index', [
            'tab' => $tab,
            'days' => $days,
            'stats' => $stats,
            'questions' => $data,
        ]);
    }

    /**
     * Compute summary stats: total questions, answer rate, unanswered, downvoted.
     */
    private function getStats(int $workspaceId, Carbon $since): array
    {
        // Package chat questions
        $packageQuestions = DB::table('chat_messages')
            ->join('chat_conversations', 'chat_messages.conversation_id', '=', 'chat_conversations.id')
            ->where('chat_conversations.workspace_id', $workspaceId)
            ->where('chat_messages.role', 'user')
            ->where('chat_messages.created_at', '>=', $since)
            ->count();

        // Workspace chat questions
        $workspaceQuestions = DB::table('chat_turns')
            ->join('chat_sessions', 'chat_turns.session_id', '=', 'chat_sessions.id')
            ->where('chat_sessions.workspace_id', $workspaceId)
            ->where('chat_turns.role', 'user')
            ->where('chat_turns.created_at', '>=', $since)
            ->count();

        $totalQuestions = $packageQuestions + $workspaceQuestions;

        // Unanswered: package chat with empty sources + workspace chat with no_match action
        $packageUnanswered = DB::table('chat_messages')
            ->join('chat_conversations', 'chat_messages.conversation_id', '=', 'chat_conversations.id')
            ->where('chat_conversations.workspace_id', $workspaceId)
            ->where('chat_messages.role', 'assistant')
            ->where('chat_messages.created_at', '>=', $since)
            ->where(function ($q) {
                $q->whereNull('chat_messages.sources_json')
                  ->orWhereRaw("chat_messages.sources_json::text = '[]'");
            })
            ->count();

        $workspaceUnanswered = DB::table('chat_turns')
            ->join('chat_sessions', 'chat_turns.session_id', '=', 'chat_sessions.id')
            ->where('chat_sessions.workspace_id', $workspaceId)
            ->where('chat_turns.role', 'assistant')
            ->where('chat_turns.action', 'no_match')
            ->where('chat_turns.created_at', '>=', $since)
            ->count();

        $unanswered = $packageUnanswered + $workspaceUnanswered;

        // Downvoted answers
        $downvoted = AnswerFeedback::where('workspace_id', $workspaceId)
            ->where('vote', 'down')
            ->where('created_at', '>=', $since)
            ->count();

        $answerRate = $totalQuestions > 0
            ? round(($totalQuestions - $unanswered) / $totalQuestions * 100)
            : 0;

        return compact('totalQuestions', 'unanswered', 'downvoted', 'answerRate');
    }

    /**
     * All questions: unified view across package chat and workspace chat.
     */
    private function getAll(int $workspaceId, Carbon $since): array
    {
        // Package chat questions (with next assistant message for status)
        $packageRows = DB::select("
            SELECT
                cm_user.content AS question,
                'package' AS channel,
                kp.name AS source_name,
                CASE
                    WHEN cm_asst.sources_json IS NULL OR cm_asst.sources_json::text = '[]' THEN 'unanswered'
                    ELSE 'answered'
                END AS status,
                cm_user.created_at
            FROM chat_messages cm_user
            JOIN chat_conversations cc ON cm_user.conversation_id = cc.id
            LEFT JOIN knowledge_packages kp ON cc.knowledge_package_id = kp.id
            LEFT JOIN LATERAL (
                SELECT sources_json FROM chat_messages cm2
                WHERE cm2.conversation_id = cm_user.conversation_id
                  AND cm2.role = 'assistant'
                  AND cm2.created_at > cm_user.created_at
                ORDER BY cm2.created_at ASC LIMIT 1
            ) cm_asst ON true
            WHERE cc.workspace_id = ?
              AND cm_user.role = 'user'
              AND cm_user.created_at >= ?
        ", [$workspaceId, $since]);

        // Workspace chat questions
        $workspaceRows = DB::select("
            SELECT
                ct.content AS question,
                'workspace' AS channel,
                COALESCE(e.name, 'Unknown') AS source_name,
                CASE
                    WHEN ct.action = 'no_match' THEN 'unanswered'
                    WHEN ct.action = 'rejected' THEN 'rejected'
                    WHEN ct.search_mode IN ('broad_unfiltered', 'none') THEN 'low_confidence'
                    ELSE 'answered'
                END AS status,
                ct.created_at
            FROM chat_turns ct
            JOIN chat_sessions cs ON ct.session_id = cs.id
            LEFT JOIN embeddings e ON cs.embedding_id = e.id
            WHERE cs.workspace_id = ?
              AND ct.role = 'user'
              AND ct.created_at >= ?
        ", [$workspaceId, $since]);

        // Merge, sort by date descending, limit to 200
        $all = array_merge($packageRows, $workspaceRows);
        usort($all, fn($a, $b) => strcmp($b->created_at, $a->created_at));

        return array_slice($all, 0, 200);
    }

    /**
     * Unanswered questions: no_match, empty sources, or downvoted.
     */
    private function getUnanswered(int $workspaceId, Carbon $since): array
    {
        $all = $this->getAll($workspaceId, $since);

        return array_values(array_filter($all, fn($row) =>
            in_array($row->status, ['unanswered', 'low_confidence'])
        ));
    }

    /**
     * Frequent questions: grouped by normalized text, sorted by count.
     */
    private function getFrequent(int $workspaceId, Carbon $since): array
    {
        $all = $this->getAll($workspaceId, $since);

        // Group by normalized text (lowercase, trimmed, first 100 chars)
        $groups = [];
        foreach ($all as $row) {
            $key = mb_strtolower(mb_substr(trim($row->question), 0, 100));
            if (!isset($groups[$key])) {
                $groups[$key] = [
                    'question' => $row->question,
                    'count' => 0,
                    'answered' => 0,
                    'unanswered' => 0,
                    'last_asked' => $row->created_at,
                ];
            }
            $groups[$key]['count']++;
            if ($row->status === 'answered') {
                $groups[$key]['answered']++;
            } else {
                $groups[$key]['unanswered']++;
            }
            // Keep the most recent date
            if (strcmp($row->created_at, $groups[$key]['last_asked']) > 0) {
                $groups[$key]['last_asked'] = $row->created_at;
            }
        }

        // Filter to groups with count > 1, sort by count descending
        $frequent = array_values(array_filter($groups, fn($g) => $g['count'] > 1));
        usort($frequent, fn($a, $b) => $b['count'] <=> $a['count']);

        // Add answer rate
        foreach ($frequent as &$group) {
            $group['answer_rate'] = $group['count'] > 0
                ? round($group['answered'] / $group['count'] * 100)
                : 0;
        }

        return array_slice($frequent, 0, 50);
    }
}
