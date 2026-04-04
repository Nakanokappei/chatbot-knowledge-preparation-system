{{-- Question Insights dashboard: stats cards, tabs (all/unanswered/frequent), and question table. --}}
@extends('layouts.app')
@section('title', __('ui.question_insights'))

@section('extra-styles')
    .stats-grid { display: grid; grid-template-columns: repeat(4, 1fr); gap: 16px; margin-bottom: 20px; }
    .stat-card { background: #fff; border-radius: 12px; padding: 16px 20px; box-shadow: 0 1px 3px rgba(0,0,0,0.08); }
    .stat-label { font-size: 11px; color: #5f6368; text-transform: uppercase; letter-spacing: 0.5px; margin-bottom: 4px; }
    .stat-value { font-size: 28px; font-weight: 700; color: #1d1d1f; }
    .stat-value.warning { color: #ff9500; }
    .stat-value.danger { color: #ff3b30; }
    .stat-value.success { color: #34c759; }
    .tabs { display: flex; gap: 4px; margin-bottom: 16px; }
    .tab { padding: 8px 16px; border-radius: 8px; font-size: 14px; font-weight: 500; color: #5f6368; text-decoration: none; transition: all 0.15s; }
    .tab:hover { background: #f5f5f7; color: #1d1d1f; }
    .tab.active { background: #0071e3; color: #fff; }
    .days-select { display: flex; gap: 8px; align-items: center; margin-bottom: 16px; }
    .days-select select { padding: 6px 10px; border: 1px solid #d2d2d7; border-radius: 8px; font-size: 13px; }
    .q-text { max-width: 400px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
    .q-text:hover { white-space: normal; overflow: visible; }
    .status-pill { display: inline-block; padding: 2px 8px; border-radius: 10px; font-size: 11px; font-weight: 500; }
    .status-answered { background: #d4edda; color: #155724; }
    .status-unanswered { background: #f8d7da; color: #721c24; }
    .status-rejected { background: #f0f0f2; color: #5f6368; }
    .status-low_confidence { background: #fff3cd; color: #856404; }
    .channel-pill { display: inline-block; padding: 2px 8px; border-radius: 10px; font-size: 11px; font-weight: 500; background: #e8f0fe; color: #1a73e8; }
    .empty-state { text-align: center; padding: 40px; color: #86868b; font-size: 14px; }
    .create-qa-link { color: #0071e3; text-decoration: none; font-size: 12px; font-weight: 500; white-space: nowrap; }
    .create-qa-link:hover { text-decoration: underline; }
    .freq-bar { height: 6px; background: #e5e5e7; border-radius: 3px; overflow: hidden; }
    .freq-bar-fill { height: 100%; background: #0071e3; border-radius: 3px; }
@endsection

@section('body')
<div class="page-content">
    <div class="page-container">

        <h1 style="font-size: 22px; font-weight: 600; margin-bottom: 4px;">{{ __('ui.question_insights') }}</h1>
        <p style="color: #5f6368; font-size: 13px; margin-bottom: 20px;">{{ __('ui.question_insights_description') }}</p>

        {{-- Stats cards --}}
        <div class="stats-grid">
            <div class="stat-card">
                <div class="stat-label">{{ __('ui.total_questions') }}</div>
                <div class="stat-value">{{ number_format($stats['totalQuestions']) }}</div>
            </div>
            <div class="stat-card">
                <div class="stat-label">{{ __('ui.answer_rate') }}</div>
                <div class="stat-value {{ $stats['answerRate'] >= 80 ? 'success' : ($stats['answerRate'] >= 50 ? 'warning' : 'danger') }}">{{ $stats['answerRate'] }}%</div>
            </div>
            <div class="stat-card">
                <div class="stat-label">{{ __('ui.unanswered_questions') }}</div>
                <div class="stat-value {{ $stats['unanswered'] > 0 ? 'warning' : '' }}">{{ number_format($stats['unanswered']) }}</div>
            </div>
            <div class="stat-card">
                <div class="stat-label">{{ __('ui.downvoted_answers') }}</div>
                <div class="stat-value {{ $stats['downvoted'] > 0 ? 'danger' : '' }}">{{ number_format($stats['downvoted']) }}</div>
            </div>
        </div>

        {{-- Period selector --}}
        <div class="days-select">
            <span style="font-size: 13px; color: #5f6368;">{{ __('ui.period') }}:</span>
            <select onchange="window.location.href='?tab={{ $tab }}&days=' + this.value">
                @foreach([7, 30, 90] as $d)
                    <option value="{{ $d }}" {{ $days == $d ? 'selected' : '' }}>{{ __('ui.last_n_days', ['days' => $d]) }}</option>
                @endforeach
            </select>
        </div>

        {{-- Tabs --}}
        <div class="tabs">
            <a href="?tab=all&days={{ $days }}" class="tab {{ $tab === 'all' ? 'active' : '' }}">{{ __('ui.tab_all_questions') }}</a>
            <a href="?tab=unanswered&days={{ $days }}" class="tab {{ $tab === 'unanswered' ? 'active' : '' }}">{{ __('ui.tab_unanswered') }} ({{ $stats['unanswered'] }})</a>
            <a href="?tab=frequent&days={{ $days }}" class="tab {{ $tab === 'frequent' ? 'active' : '' }}">{{ __('ui.tab_frequent') }}</a>
        </div>

        {{-- Content --}}
        <div class="card" style="padding: 0; overflow: hidden;">
            @if($tab === 'frequent')
                {{-- Frequent questions table --}}
                @if(empty($questions))
                    <div class="empty-state">{{ __('ui.no_frequent_questions') }}</div>
                @else
                    <table>
                        <thead>
                            <tr>
                                <th>{{ __('ui.question_column') }}</th>
                                <th style="width: 80px;">{{ __('ui.occurrence_count') }}</th>
                                <th style="width: 120px;">{{ __('ui.answer_rate') }}</th>
                                <th style="width: 120px;">{{ __('ui.last_asked') }}</th>
                                <th style="width: 100px;"></th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach($questions as $group)
                                <tr>
                                    <td class="q-text">{{ $group['question'] }}</td>
                                    <td style="text-align: center; font-weight: 600;">{{ $group['count'] }}</td>
                                    <td>
                                        <div class="freq-bar" style="width: 80px; display: inline-block; vertical-align: middle;">
                                            <div class="freq-bar-fill" style="width: {{ $group['answer_rate'] }}%; background: {{ $group['answer_rate'] >= 80 ? '#34c759' : ($group['answer_rate'] >= 50 ? '#ff9500' : '#ff3b30') }};"></div>
                                        </div>
                                        <span style="font-size: 12px; color: #5f6368; margin-left: 4px;">{{ $group['answer_rate'] }}%</span>
                                    </td>
                                    <td style="font-size: 12px; color: #5f6368;">
                                        <time datetime="{{ $group['last_asked'] }}">{{ \Carbon\Carbon::parse($group['last_asked'])->format('m/d H:i') }}</time>
                                    </td>
                                    <td>
                                        <a href="{{ route('knowledge-units.create', ['question' => $group['question']]) }}" class="create-qa-link">{{ __('ui.create_qa_from_question') }}</a>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                @endif
            @else
                {{-- All / Unanswered questions table --}}
                @if(empty($questions))
                    <div class="empty-state">{{ __('ui.no_questions_yet') }}</div>
                @else
                    <table>
                        <thead>
                            <tr>
                                <th>{{ __('ui.question_column') }}</th>
                                <th style="width: 70px;">{{ __('ui.turns') }}</th>
                                <th style="width: 110px;">{{ __('ui.channel_column') }}</th>
                                <th style="width: 130px;">{{ __('ui.source') }}</th>
                                <th style="width: 110px;">{{ __('ui.status_column') }}</th>
                                <th style="width: 120px;">{{ __('ui.date') }}</th>
                                <th style="width: 80px;"></th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach($questions as $row)
                                <tr>
                                    <td class="q-text">{{ $row->question }}</td>
                                    <td style="text-align: center; font-size: 12px;">
                                        @if($row->turn_count > 1)
                                            <span style="color: #ff9500;" title="{{ __('ui.thread_had_followups', ['count' => $row->turn_count - 1]) }}">{{ $row->turn_count }} {{ __('ui.turns_label') }}</span>
                                        @else
                                            <span style="color: #86868b;">1</span>
                                        @endif
                                    </td>
                                    <td><span class="channel-pill">{{ $row->channel === 'package' ? __('ui.channel_package') : __('ui.channel_workspace') }}</span></td>
                                    <td style="font-size: 12px; color: #5f6368;">{{ $row->source_name }}</td>
                                    <td><span class="status-pill status-{{ $row->status }}">{{ __('ui.status_' . $row->status) }}</span></td>
                                    <td style="font-size: 12px; color: #5f6368;">
                                        <time datetime="{{ $row->created_at }}">{{ \Carbon\Carbon::parse($row->created_at)->format('m/d H:i') }}</time>
                                    </td>
                                    <td>
                                        @if(in_array($row->status, ['unanswered', 'low_confidence']))
                                            <a href="{{ route('knowledge-units.create', ['question' => $row->question]) }}" class="create-qa-link">{{ __('ui.create_qa_from_question') }}</a>
                                        @endif
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                @endif
            @endif
        </div>

        @if($tab === 'frequent')
            <p style="font-size: 12px; color: #86868b; margin-top: 12px;">{{ __('ui.frequent_questions_hint') }}</p>
        @endif

    </div>
</div>
@endsection
