{{-- RAG Chat interface: standalone chat page for a published knowledge dataset.
     Sends user queries to the /web-api/chat endpoint and displays responses with source citations. --}}
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Chat — {{ $dataset->name }}</title>
    <style>
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body { font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif; background: #f5f5f5; height: 100vh; display: flex; flex-direction: column; }
        .header { background: white; padding: 12px 20px; border-bottom: 1px solid #e5e7eb; display: flex; align-items: center; gap: 12px; }
        .header a { color: #2563eb; text-decoration: none; font-size: 14px; }
        .header h2 { font-size: 16px; }
        .badge { display: inline-block; padding: 2px 8px; border-radius: 10px; font-size: 11px; font-weight: 600; background: #d1fae5; color: #065f46; }
        .chat-container { flex: 1; overflow-y: auto; padding: 20px; max-width: 800px; margin: 0 auto; width: 100%; }
        .message { margin-bottom: 16px; display: flex; gap: 10px; }
        .message-user { justify-content: flex-end; }
        .message-user .bubble { background: #2563eb; color: white; border-radius: 16px 16px 4px 16px; }
        .message-assistant .bubble { background: white; color: #111; border-radius: 16px 16px 16px 4px; box-shadow: 0 1px 3px rgba(0,0,0,0.1); }
        .bubble { padding: 12px 16px; max-width: 600px; line-height: 1.5; font-size: 14px; white-space: pre-wrap; }
        .sources { margin-top: 8px; font-size: 12px; color: #6b7280; }
        .sources a { color: #2563eb; text-decoration: none; }
        .usage { font-size: 11px; color: #9ca3af; margin-top: 4px; }
        .input-area { background: white; border-top: 1px solid #e5e7eb; padding: 16px 20px; }
        .input-row { max-width: 800px; margin: 0 auto; display: flex; gap: 8px; }
        .input-row input { flex: 1; padding: 10px 14px; border: 1px solid #d1d5db; border-radius: 8px; font-size: 14px; outline: none; }
        .input-row input:focus { border-color: #2563eb; }
        .input-row button { padding: 10px 20px; background: #2563eb; color: white; border: none; border-radius: 8px; font-size: 14px; cursor: pointer; }
        .input-row button:hover { background: #1d4ed8; }
        .input-row button:disabled { background: #93c5fd; cursor: not-allowed; }
        .typing { color: #6b7280; font-style: italic; padding: 8px 16px; }
        .empty-state { text-align: center; color: #6b7280; padding: 60px 20px; }
        .empty-state h3 { margin-bottom: 8px; }
    </style>
</head>
<body>
<div class="header">
    <a href="{{ route('kd.show', $dataset) }}">{{ __('ui.back') }}</a>
    <h2>{{ $dataset->name }} v{{ $dataset->version }}</h2>
    <span class="badge">{{ $dataset->ku_count }} KUs</span>
</div>

{{-- Chat message area: scrollable container for user/assistant message bubbles --}}
<div class="chat-container" id="chat-container">
    <div class="empty-state" id="empty-state">
        <h3>{{ __('ui.rag_chat') }}</h3>
        <p>{{ __('ui.ask_question_about') }}</p>
        <p style="font-size: 12px; margin-top: 8px;">Retrieval + Augmented Generation against {{ $dataset->ku_count }} approved Knowledge Units</p>
    </div>
</div>

{{-- Input area: text input and send button for composing messages --}}
<div class="input-area">
    <div class="input-row">
        <input type="text" id="message-input" placeholder="{{ __('ui.ask_question_placeholder') }}" autofocus
               onkeydown="if(event.key==='Enter' && !event.shiftKey) sendMessage()">
        <button id="send-btn" onclick="sendMessage()">{{ __('ui.send') }}</button>
    </div>
</div>

<script>
const datasetId = {{ $dataset->id }};
const csrfToken = '{{ csrf_token() }}';
let conversationId = null;
let sending = false;

// Send user message to the RAG chat API and display the streamed response
async function sendMessage() {
    const input = document.getElementById('message-input');
    const message = input.value.trim();
    if (!message || sending) return;

    sending = true;
    document.getElementById('send-btn').disabled = true;
    document.getElementById('empty-state')?.remove();

    // Show user message
    appendMessage('user', message);
    input.value = '';

    // Show typing indicator
    const typingEl = document.createElement('div');
    typingEl.className = 'message message-assistant';
    typingEl.innerHTML = '<div class="typing">{{ __('ui.thinking') }}</div>';
    typingEl.id = 'typing';
    document.getElementById('chat-container').appendChild(typingEl);
    scrollToBottom();

    try {
        const response = await fetch('/web-api/chat', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': csrfToken,
                'Accept': 'application/json',
            },
            body: JSON.stringify({
                message: message,
                dataset_id: datasetId,
                conversation_id: conversationId,
            }),
        });

        document.getElementById('typing')?.remove();

        if (!response.ok) {
            const err = await response.json();
            appendMessage('assistant', 'Error: ' + (err.error || err.message || 'Unknown error'));
            return;
        }

        const data = await response.json();
        conversationId = data.conversation_id;

        // Build sources HTML
        let sourcesHtml = '';
        if (data.sources && data.sources.length > 0) {
            const sourceLinks = data.sources.map(s =>
                `<a href="/knowledge-units/${s.knowledge_unit_id}">${s.topic}</a> (${(s.similarity * 100).toFixed(0)}%)`
            ).join(', ');
            sourcesHtml = `<div class="sources">{{ __('ui.sources') }}: ${sourceLinks}</div>`;
        }

        const usageHtml = `<div class="usage">${data.model} | ${data.usage.input_tokens + data.usage.output_tokens} tokens | ${data.latency_ms}ms</div>`;

        appendMessage('assistant', data.message, sourcesHtml + usageHtml);

    } catch (err) {
        document.getElementById('typing')?.remove();
        appendMessage('assistant', 'Network error: ' + err.message);
    } finally {
        sending = false;
        document.getElementById('send-btn').disabled = false;
        input.focus();
    }
}

// Append a chat bubble (user or assistant) to the message container
function appendMessage(role, text, extraHtml = '') {
    const container = document.getElementById('chat-container');
    const div = document.createElement('div');
    div.className = `message message-${role}`;
    div.innerHTML = `<div class="bubble">${escapeHtml(text)}${extraHtml}</div>`;
    container.appendChild(div);
    scrollToBottom();
}

// Escape HTML special characters to prevent XSS in chat output
function escapeHtml(text) {
    const div = document.createElement('div');
    div.textContent = text;
    return div.innerHTML;
}

function scrollToBottom() {
    const container = document.getElementById('chat-container');
    container.scrollTop = container.scrollHeight;
}
</script>
</body>
</html>
