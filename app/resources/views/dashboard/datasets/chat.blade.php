{{-- RAG Chat interface: full-height chat page for a published dataset with source citations. --}}
@extends('layouts.app')
@section('title', 'Chat — ' . $package->name)

@section('extra-styles')
    .chat-layout { flex: 1; display: flex; flex-direction: column; overflow: hidden; background: #fff; border-radius: 12px 0 0 0; }
    .chat-header { padding: 12px 20px; border-bottom: 1px solid #f0f0f2; display: flex; align-items: center; gap: 12px; flex-shrink: 0; }
    .chat-header h2 { font-size: 15px; font-weight: 600; }
    .chat-body { flex: 1; overflow-y: auto; padding: 20px; }
    .chat-inner { max-width: 760px; margin: 0 auto; }
    .message { margin-bottom: 16px; display: flex; }
    .message-user { justify-content: flex-end; }
    .bubble { padding: 11px 15px; max-width: 600px; line-height: 1.55; font-size: 14px; white-space: pre-wrap; border-radius: 14px; }
    .message-user .bubble { background: #0071e3; color: #fff; border-radius: 14px 14px 4px 14px; }
    .message-assistant .bubble { background: #f5f5f7; color: #1d1d1f; border-radius: 14px 14px 14px 4px; }
    .sources { margin-top: 6px; font-size: 12px; color: #5f6368; }
    .sources a { color: #0071e3; text-decoration: none; }
    .usage { font-size: 11px; color: #a0a0a5; margin-top: 3px; }
    .chat-input-area { padding: 14px 20px; border-top: 1px solid #f0f0f2; flex-shrink: 0; }
    .input-row { max-width: 760px; margin: 0 auto; display: flex; gap: 8px; }
    .input-row input { flex: 1; padding: 9px 13px; border: 1px solid #d2d2d7; border-radius: 8px; font-size: 14px; font-family: inherit; outline: none; }
    .input-row input:focus { border-color: #0071e3; }
    .send-btn { padding: 9px 18px; background: #0071e3; color: #fff; border: none; border-radius: 8px; font-size: 14px; cursor: pointer; font-family: inherit; }
    .send-btn:hover { background: #0077ed; }
    .send-btn:disabled { background: #a0a0a5; cursor: not-allowed; }
    .typing { color: #5f6368; font-style: italic; font-size: 14px; }
    .empty-state { text-align: center; color: #5f6368; padding: 60px 20px; }
    .empty-state h3 { font-size: 17px; font-weight: 600; margin-bottom: 8px; color: #1d1d1f; }
@endsection

@section('body')
<div class="chat-layout">
    <div class="chat-header">
        <a href="{{ route('kp.show', $package) }}" style="color: #0071e3; text-decoration: none; font-size: 13px;">← {{ __('ui.back') }}</a>
        <h2>{{ $package->name }} v{{ $package->version }}</h2>
        <span class="badge badge-published">{{ $package->ku_count }} KUs</span>
    </div>

    <div class="chat-body" id="chat-container">
        <div class="chat-inner">
            <div class="empty-state" id="empty-state">
                <h3>{{ __('ui.rag_chat') }}</h3>
                <p>{{ __('ui.ask_question_about') }}</p>
                <p style="font-size: 12px; margin-top: 8px; color: #a0a0a5;">Retrieval + Augmented Generation against {{ $package->ku_count }} approved Knowledge Units</p>
            </div>
        </div>
    </div>

    <div class="chat-input-area">
        <div class="input-row">
            <input type="text" id="message-input" placeholder="{{ __('ui.ask_question_placeholder') }}" autofocus
                   onkeydown="if(event.key==='Enter' && !event.shiftKey) sendMessage()">
            <button class="send-btn" id="send-btn" onclick="sendMessage()">{{ __('ui.send') }}</button>
        </div>
    </div>
</div>
@endsection

@section('scripts')
const datasetId = {{ $package->id }};
const csrfToken = '{{ csrf_token() }}';
let conversationId = null;
let sending = false;

async function sendMessage() {
    const input = document.getElementById('message-input');
    const message = input.value.trim();
    if (!message || sending) return;
    sending = true;
    document.getElementById('send-btn').disabled = true;
    document.getElementById('empty-state')?.remove();

    appendMessage('user', message);
    input.value = '';

    const typingEl = document.createElement('div');
    typingEl.className = 'message message-assistant';
    typingEl.id = 'typing';
    typingEl.innerHTML = '<div class="bubble"><span class="typing">{{ __('ui.thinking') }}</span></div>';
    document.querySelector('#chat-container .chat-inner').appendChild(typingEl);
    scrollToBottom();

    try {
        const res = await fetch('/web-api/chat', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': csrfToken, 'Accept': 'application/json' },
            body: JSON.stringify({ message, dataset_id: datasetId, conversation_id: conversationId }),
        });
        document.getElementById('typing')?.remove();

        if (!res.ok) {
            const err = await res.json();
            appendMessage('assistant', 'Error: ' + (err.error || err.message || 'Unknown error'));
            return;
        }

        const data = await res.json();
        conversationId = data.conversation_id;

        let extra = '';
        if (data.sources?.length > 0) {
            const links = data.sources.map(s => `<a href="/knowledge-units/${s.knowledge_unit_id}">${escapeHtml(s.topic)}</a> (${(s.similarity*100).toFixed(0)}%)`).join(', ');
            extra += `<div class="sources">{{ __('ui.sources') }}: ${links}</div>`;
        }
        extra += `<div class="usage">${data.model} | ${data.usage.input_tokens + data.usage.output_tokens} tokens | ${data.latency_ms}ms</div>`;
        appendMessage('assistant', data.message, extra);
    } catch (err) {
        document.getElementById('typing')?.remove();
        appendMessage('assistant', 'Network error: ' + err.message);
    } finally {
        sending = false;
        document.getElementById('send-btn').disabled = false;
        input.focus();
    }
}

function appendMessage(role, text, extraHtml = '') {
    const inner = document.querySelector('#chat-container .chat-inner');
    const div = document.createElement('div');
    div.className = `message message-${role}`;
    div.innerHTML = `<div class="bubble">${escapeHtml(text)}${extraHtml}</div>`;
    inner.appendChild(div);
    scrollToBottom();
}

function escapeHtml(text) { const d = document.createElement('div'); d.textContent = text; return d.innerHTML; }
function scrollToBottom() { const c = document.getElementById('chat-container'); c.scrollTop = c.scrollHeight; }
@endsection
