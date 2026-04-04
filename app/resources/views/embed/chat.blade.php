{{-- Standalone embedded chat page — loaded inside an iframe on customer websites.
     Does NOT extend layouts.app. No topbar, sidebar, or navigation.
     All CSS/JS is inline to avoid Vite build dependency for external sites. --}}
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="referrer" content="no-referrer">
    <title>{{ $title }}</title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            height: 100vh; display: flex; flex-direction: column;
            background: {{ $theme === 'dark' ? '#1a1a1a' : '#ffffff' }};
            color: {{ $theme === 'dark' ? '#e0e0e0' : '#1d1d1f' }};
        }

        /* Header */
        .chat-header {
            padding: 14px 16px; border-bottom: 1px solid {{ $theme === 'dark' ? '#333' : '#e5e5e7' }};
            display: flex; align-items: center; gap: 10px; flex-shrink: 0;
            background: {{ $theme === 'dark' ? '#222' : '#fafafa' }};
        }
        .chat-header-dot { width: 8px; height: 8px; border-radius: 50%; background: #34c759; }
        .chat-header-title { font-size: 14px; font-weight: 600; }

        /* Messages area */
        .chat-body { flex: 1; overflow-y: auto; padding: 16px; display: flex; flex-direction: column; gap: 12px; }

        .msg { max-width: 85%; padding: 10px 14px; border-radius: 16px; font-size: 14px; line-height: 1.5; word-wrap: break-word; }
        .msg-user {
            align-self: flex-end; background: {{ $accent_color }}; color: #fff;
            border-bottom-right-radius: 4px;
        }
        .msg-assistant {
            align-self: flex-start;
            background: {{ $theme === 'dark' ? '#2a2a2a' : '#f0f0f2' }};
            color: {{ $theme === 'dark' ? '#e0e0e0' : '#1d1d1f' }};
            border-bottom-left-radius: 4px;
        }
        .msg-assistant a { color: {{ $accent_color }}; }

        /* Sources */
        .msg-sources { margin-top: 8px; padding-top: 6px; border-top: 1px solid {{ $theme === 'dark' ? '#444' : '#e0e0e0' }}; }
        .msg-source { font-size: 11px; color: {{ $theme === 'dark' ? '#888' : '#86868b' }}; }

        /* Typing indicator */
        .typing { align-self: flex-start; padding: 10px 18px; }
        .typing span { display: inline-block; width: 6px; height: 6px; border-radius: 50%; background: {{ $theme === 'dark' ? '#666' : '#bbb' }}; animation: bounce 1.2s infinite; }
        .typing span:nth-child(2) { animation-delay: 0.2s; }
        .typing span:nth-child(3) { animation-delay: 0.4s; }
        @keyframes bounce { 0%,60%,100% { transform: translateY(0); } 30% { transform: translateY(-6px); } }

        /* Bot avatar next to assistant messages */
        .msg-row { display: flex; gap: 8px; align-items: flex-start; }
        .msg-avatar { width: 28px; height: 28px; border-radius: 50%; flex-shrink: 0; object-fit: cover; }

        /* Empty state */
        .empty-state { flex: 1; display: flex; flex-direction: column; align-items: center; justify-content: center; text-align: center; padding: 24px; color: {{ $theme === 'dark' ? '#666' : '#86868b' }}; }
        .empty-state p { font-size: 13px; line-height: 1.6; }

        /* Opener buttons */
        .openers { display: flex; flex-direction: column; gap: 8px; margin-top: 16px; width: 100%; max-width: 300px; }
        .opener-btn {
            padding: 8px 14px; border-radius: 18px; font-size: 13px; cursor: pointer;
            border: 1px solid {{ $theme === 'dark' ? '#444' : '#d2d2d7' }};
            background: {{ $theme === 'dark' ? '#2a2a2a' : '#fff' }};
            color: {{ $theme === 'dark' ? '#e0e0e0' : '#1d1d1f' }};
            text-align: left; transition: background 0.15s;
        }
        .opener-btn:hover { background: {{ $theme === 'dark' ? '#333' : '#f0f0f2' }}; }

        /* Input area */
        .chat-input-area {
            padding: 12px 16px; border-top: 1px solid {{ $theme === 'dark' ? '#333' : '#e5e5e7' }};
            display: flex; gap: 8px; flex-shrink: 0;
            background: {{ $theme === 'dark' ? '#222' : '#fafafa' }};
        }
        .chat-input {
            flex: 1; padding: 10px 14px; border: 1px solid {{ $theme === 'dark' ? '#444' : '#d2d2d7' }};
            border-radius: 20px; font-size: 14px; font-family: inherit; outline: none;
            background: {{ $theme === 'dark' ? '#1a1a1a' : '#fff' }};
            color: {{ $theme === 'dark' ? '#e0e0e0' : '#1d1d1f' }};
        }
        .chat-input:focus { border-color: {{ $accent_color }}; }
        .chat-input::placeholder { color: {{ $theme === 'dark' ? '#555' : '#a0a0a5' }}; }
        .send-btn {
            width: 38px; height: 38px; border-radius: 50%; border: none;
            background: {{ $accent_color }}; color: #fff; cursor: pointer;
            display: flex; align-items: center; justify-content: center; flex-shrink: 0;
        }
        .send-btn:disabled { opacity: 0.5; cursor: not-allowed; }
        .send-btn svg { width: 18px; height: 18px; }

        /* Powered-by footer */
        .powered-by { text-align: center; padding: 6px; font-size: 10px; color: {{ $theme === 'dark' ? '#555' : '#bbb' }}; }
    </style>
</head>
<body>
    <div class="chat-header">
        @if(!empty($icon_url))
            <img src="{{ $icon_url }}" class="msg-avatar" alt="" onerror="this.outerHTML='<div class=\'chat-header-dot\'></div>'">
        @else
            <div class="chat-header-dot"></div>
        @endif
        <div class="chat-header-title">{{ $title }}</div>
    </div>

    <div class="chat-body" id="chatBody">
        @if($initial_message)
            <div class="msg msg-assistant">{{ $initial_message }}</div>
            @if(!empty($openers))
            <div class="openers" id="openerButtons">
                @foreach($openers as $opener)
                <button class="opener-btn" onclick="submitOpener(this)">{{ $opener }}</button>
                @endforeach
            </div>
            @endif
        @else
            <div class="empty-state" id="emptyState">
                <p>{{ $package_name }}<br>Ask a question to get started.</p>
                @if(!empty($openers))
                <div class="openers">
                    @foreach($openers as $opener)
                    <button class="opener-btn" onclick="submitOpener(this)">{{ $opener }}</button>
                    @endforeach
                </div>
                @endif
            </div>
        @endif
    </div>

    <div class="chat-input-area">
        <input type="text" class="chat-input" id="chatInput"
               placeholder="{{ $placeholder }}"
               maxlength="4000" autocomplete="off">
        <button class="send-btn" id="sendBtn" onclick="sendMessage()">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                <line x1="22" y1="2" x2="11" y2="13"></line>
                <polygon points="22 2 15 22 11 13 2 9 22 2"></polygon>
            </svg>
        </button>
    </div>

    <div class="powered-by">Powered by KPS</div>

<script>
(function() {
    'use strict';

    var API_KEY = @json($api_key);
    var CHAT_ENDPOINT = @json($chat_endpoint);
    var sessionId = null;
    var isLoading = false;

    var ICON_URL = @json($icon_url ?? '');
    var chatBody = document.getElementById('chatBody');
    var chatInput = document.getElementById('chatInput');
    var sendBtn = document.getElementById('sendBtn');

    // Submit an opener button question as a user message
    window.submitOpener = function(btn) {
        chatInput.value = btn.textContent;
        // Remove all opener buttons
        document.querySelectorAll('.openers').forEach(function(el) { el.remove(); });
        sendMessage();
    };

    // Send on Enter key
    chatInput.addEventListener('keydown', function(e) {
        if (e.key === 'Enter' && !e.shiftKey && !isLoading) {
            e.preventDefault();
            sendMessage();
        }
    });

    // Expose sendMessage to onclick
    window.sendMessage = function() {
        var message = chatInput.value.trim();
        if (!message || isLoading) return;

        // Remove empty state and opener buttons
        var empty = document.getElementById('emptyState');
        if (empty) empty.remove();
        document.querySelectorAll('.openers').forEach(function(el) { el.remove(); });

        // Add user message bubble
        appendMessage('user', escapeHtml(message));
        chatInput.value = '';
        chatInput.focus();

        // Show typing indicator
        var typing = document.createElement('div');
        typing.className = 'typing';
        typing.id = 'typingIndicator';
        typing.innerHTML = '<span></span><span></span><span></span>';
        chatBody.appendChild(typing);
        scrollToBottom();

        isLoading = true;
        sendBtn.disabled = true;

        // Call the embed chat API
        fetch(CHAT_ENDPOINT, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'Authorization': 'Bearer ' + API_KEY,
                'Accept': 'application/json',
            },
            body: JSON.stringify({
                message: message,
                session_id: sessionId,
            }),
        })
        .then(function(res) { return res.json(); })
        .then(function(data) {
            // Remove typing indicator
            var ti = document.getElementById('typingIndicator');
            if (ti) ti.remove();

            if (data.error) {
                appendMessage('assistant', escapeHtml(data.error));
            } else {
                // Update conversation ID for multi-turn
                if (data.session_id) {
                    sessionId = data.session_id;
                }

                // Render assistant response
                var html = formatMarkdown(data.message || 'No response.');

                // Append source citations
                if (data.sources && data.sources.length > 0) {
                    html += '<div class="msg-sources">';
                    data.sources.forEach(function(s) {
                        html += '<div class="msg-source">' + escapeHtml(s.topic) +
                                ' (' + Math.round(s.similarity * 100) + '%)</div>';
                    });
                    html += '</div>';
                }

                appendMessage('assistant', html, true);
            }
        })
        .catch(function() {
            var ti = document.getElementById('typingIndicator');
            if (ti) ti.remove();
            appendMessage('assistant', 'Connection error. Please try again.');
        })
        .finally(function() {
            isLoading = false;
            sendBtn.disabled = false;
        });
    };

    function appendMessage(role, content, isHtml) {
        var msgDiv = document.createElement('div');
        msgDiv.className = 'msg msg-' + role;
        if (isHtml) {
            msgDiv.innerHTML = content;
        } else {
            msgDiv.textContent = content;
        }

        // Wrap assistant messages with avatar if icon is configured
        if (role === 'assistant' && ICON_URL) {
            var row = document.createElement('div');
            row.className = 'msg-row';
            var img = document.createElement('img');
            img.className = 'msg-avatar';
            img.src = ICON_URL;
            img.alt = '';
            img.onerror = function() { this.style.display = 'none'; };
            row.appendChild(img);
            row.appendChild(msgDiv);
            chatBody.appendChild(row);
        } else {
            chatBody.appendChild(msgDiv);
        }
        scrollToBottom();
    }

    function scrollToBottom() {
        chatBody.scrollTop = chatBody.scrollHeight;
    }

    function escapeHtml(str) {
        var div = document.createElement('div');
        div.textContent = str;
        return div.innerHTML;
    }

    // Safe text formatting: split into paragraphs, no innerHTML injection.
    // Bold markers (**text**) are rendered as <strong> after escaping.
    function formatMarkdown(text) {
        var escaped = escapeHtml(text);
        // Only allow **bold** after escaping (capture group is already escaped)
        return escaped
            .replace(/\*\*([^*]+)\*\*/g, '<strong>$1</strong>')
            .replace(/\n- /g, '<br>&bull; ')
            .replace(/\n/g, '<br>');
    }
})();
</script>
</body>
</html>
