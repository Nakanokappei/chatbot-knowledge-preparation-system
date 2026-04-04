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
 * Groups questions by conversation thread (session/conversation), not by
 * individual turns. Follow-up messages (e.g. slot-filling responses) are
 * counted as part of the parent thread, not as separate questions.
 *
 * Thread resolution:
 *   - Workspace chat: one row per chat_session (grouped by session_id)
 *   - Package chat: one row per chat_conversation (grouped by conversation_id)
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

        $stats = $this->getStats($workspaceId, $since);

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
     * Compute summary stats at thread level.
     */
    private function getStats(int $workspaceId, Carbon $since): array
    {
        // Count distinct conversation threads (not individual messages)
        $packageThreads = DB::table('chat_conversations')
            ->where('workspace_id', $workspaceId)
            ->where('created_at', '>=', $since)
            ->count();

        $workspaceThreads = DB::table('chat_sessions')
            ->where('workspace_id', $workspaceId)
            ->where('created_at', '>=', $since)
            ->count();

        $totalQuestions = $packageThreads + $workspaceThreads;

        // Unanswered threads: workspace sessions that ended with no_match
        $workspaceUnanswered = DB::table('chat_sessions')
            ->where('workspace_id', $workspaceId)
            ->where('created_at', '>=', $since)
            ->whereExists(function ($q) {
                $q->select(DB::raw(1))
                  ->from('chat_turns')
                  ->whereColumn('chat_turns.session_id', 'chat_sessions.id')
                  ->where('chat_turns.role', 'assistant')
                  ->where('chat_turns.action', 'no_match');
            })
            ->count();

        // Package conversations where the last assistant message had no sources
        $packageUnanswered = (int) DB::selectOne("
            SELECT COUNT(DISTINCT cc.id) AS cnt
            FROM chat_conversations cc
            WHERE cc.workspace_id = ?
              AND cc.created_at >= ?
              AND NOT EXISTS (
                  SELECT 1 FROM chat_messages cm
                  WHERE cm.conversation_id = cc.id
                    AND cm.role = 'assistant'
                    AND cm.sources_json IS NOT NULL
                    AND cm.sources_json::text != '[]'
              )
              AND EXISTS (
                  SELECT 1 FROM chat_messages cm2
                  WHERE cm2.conversation_id = cc.id
                    AND cm2.role = 'user'
              )
        ", [$workspaceId, $since])->cnt;

        $unanswered = $packageUnanswered + $workspaceUnanswered;

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
     * All questions: one row per conversation thread.
     *
     * Each row shows:
     *   - question: the FIRST user message in the thread (the actual question)
     *   - turn_count: total user messages in the thread (1 = direct answer, 2+ = had follow-ups)
     *   - status: determined by the thread's final assistant response
     */
    private function getAll(int $workspaceId, Carbon $since): array
    {
        // Package chat: group by conversation, take first user message as the question
        $packageRows = DB::select("
            SELECT
                first_msg.content AS question,
                'package' AS channel,
                COALESCE(kp.name, 'Unknown') AS source_name,
                thread_stats.turn_count,
                CASE
                    WHEN thread_stats.has_sourced_answer THEN 'answered'
                    ELSE 'unanswered'
                END AS status,
                cc.created_at
            FROM chat_conversations cc
            -- First user message in the conversation (the actual question)
            JOIN LATERAL (
                SELECT content FROM chat_messages
                WHERE conversation_id = cc.id AND role = 'user'
                ORDER BY created_at ASC LIMIT 1
            ) first_msg ON true
            -- Thread statistics: count of user turns and whether any answer had sources
            JOIN LATERAL (
                SELECT
                    COUNT(*) FILTER (WHERE role = 'user') AS turn_count,
                    BOOL_OR(role = 'assistant' AND sources_json IS NOT NULL AND sources_json::text != '[]') AS has_sourced_answer
                FROM chat_messages WHERE conversation_id = cc.id
            ) thread_stats ON true
            LEFT JOIN knowledge_packages kp ON cc.knowledge_package_id = kp.id
            WHERE cc.workspace_id = ?
              AND cc.created_at >= ?
            ORDER BY cc.created_at DESC
        ", [$workspaceId, $since]);

        // Workspace chat: group by session, take first user turn as the question
        $workspaceRows = DB::select("
            SELECT
                first_turn.content AS question,
                'workspace' AS channel,
                COALESCE(e.name, 'Unknown') AS source_name,
                thread_stats.turn_count,
                CASE
                    WHEN thread_stats.has_no_match AND NOT thread_stats.has_answer THEN 'unanswered'
                    WHEN thread_stats.has_rejected AND NOT thread_stats.has_answer THEN 'rejected'
                    WHEN thread_stats.has_broad_only THEN 'low_confidence'
                    WHEN thread_stats.has_answer THEN 'answered'
                    ELSE 'unanswered'
                END AS status,
                cs.created_at
            FROM chat_sessions cs
            -- First user turn in the session (the actual question)
            JOIN LATERAL (
                SELECT content FROM chat_turns
                WHERE session_id = cs.id AND role = 'user'
                ORDER BY created_at ASC LIMIT 1
            ) first_turn ON true
            -- Thread statistics: aggregate assistant actions across all turns
            JOIN LATERAL (
                SELECT
                    COUNT(*) FILTER (WHERE role = 'user') AS turn_count,
                    BOOL_OR(role = 'assistant' AND action IN ('answer', 'answer_broad')) AS has_answer,
                    BOOL_OR(role = 'assistant' AND action = 'no_match') AS has_no_match,
                    BOOL_OR(role = 'assistant' AND action = 'rejected') AS has_rejected,
                    BOOL_OR(role = 'assistant' AND action = 'answer_broad' AND search_mode = 'broad_unfiltered') AS has_broad_only
                FROM chat_turns WHERE session_id = cs.id
            ) thread_stats ON true
            LEFT JOIN embeddings e ON cs.embedding_id = e.id
            WHERE cs.workspace_id = ?
              AND cs.created_at >= ?
            ORDER BY cs.created_at DESC
        ", [$workspaceId, $since]);

        // Merge and sort by date descending
        $all = array_merge($packageRows, $workspaceRows);
        usort($all, fn($a, $b) => strcmp($b->created_at, $a->created_at));

        return array_slice($all, 0, 200);
    }

    /**
     * Unanswered threads: threads where the bot could not provide a satisfactory answer.
     */
    private function getUnanswered(int $workspaceId, Carbon $since): array
    {
        $all = $this->getAll($workspaceId, $since);

        return array_values(array_filter($all, fn($row) =>
            in_array($row->status, ['unanswered', 'low_confidence'])
        ));
    }

    /**
     * Frequent questions: first-messages grouped by normalized text.
     *
     * Since we already de-duplicated to thread level, each entry represents
     * a unique conversation thread. Grouping by text similarity identifies
     * the same question asked across different sessions.
     */
    private function getFrequent(int $workspaceId, Carbon $since): array
    {
        $all = $this->getAll($workspaceId, $since);

        // Group by normalized text of the first question (lowercase, trimmed, first 100 chars)
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
            if (strcmp($row->created_at, $groups[$key]['last_asked']) > 0) {
                $groups[$key]['last_asked'] = $row->created_at;
            }
        }

        // Filter to groups with count > 1, sort by count descending
        $frequent = array_values(array_filter($groups, fn($g) => $g['count'] > 1));
        usort($frequent, fn($a, $b) => $b['count'] <=> $a['count']);

        foreach ($frequent as &$group) {
            $group['answer_rate'] = $group['count'] > 0
                ? round($group['answered'] / $group['count'] * 100)
                : 0;
        }

        return array_slice($frequent, 0, 50);
    }
}
