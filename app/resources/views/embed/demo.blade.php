{{-- Demo inquiry page: a fictional company website with the chat widget embedded.
     Standalone HTML — does not extend any layout. Loads widget.js for the chat button. --}}
<!DOCTYPE html>
<html lang="ja">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="referrer" content="no-referrer">
    <title>{{ $theme['company'] }} - {{ __('ui.demo_inquiry_title') }}</title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, 'Hiragino Sans', 'Noto Sans JP', sans-serif; color: #333; line-height: 1.6; background: #f8f9fa; }

        /* Header */
        .site-header { background: #fff; border-bottom: 1px solid #e5e7eb; padding: 0 24px; height: 56px; display: flex; align-items: center; justify-content: space-between; }
        .site-logo { display: flex; align-items: center; gap: 10px; font-size: 18px; font-weight: 700; color: {{ $theme['color'] }}; text-decoration: none; }
        .site-logo-icon { font-size: 24px; }
        .site-nav { display: flex; gap: 24px; }
        .site-nav a { font-size: 14px; color: #666; text-decoration: none; }
        .site-nav a:hover { color: {{ $theme['color'] }}; }

        /* Hero */
        .hero { background: linear-gradient(135deg, {{ $theme['color'] }}dd, {{ $theme['color'] }}88); color: #fff; padding: 60px 24px; text-align: center; }
        .hero h1 { font-size: 32px; font-weight: 700; margin-bottom: 12px; }
        .hero p { font-size: 16px; opacity: 0.9; max-width: 600px; margin: 0 auto; }

        /* Main content */
        .container { max-width: 800px; margin: 0 auto; padding: 40px 24px; }

        /* FAQ section */
        .section-title { font-size: 20px; font-weight: 700; margin-bottom: 20px; color: #1a1a1a; }
        .faq-list { display: flex; flex-direction: column; gap: 12px; margin-bottom: 40px; }
        .faq-item { background: #fff; border: 1px solid #e5e7eb; border-radius: 10px; padding: 16px 20px; }
        .faq-q { font-weight: 600; font-size: 15px; color: #1a1a1a; margin-bottom: 4px; }
        .faq-a { font-size: 14px; color: #666; }

        /* Contact form */
        .contact-card { background: #fff; border: 1px solid #e5e7eb; border-radius: 12px; padding: 32px; margin-bottom: 40px; }
        .contact-card h2 { font-size: 18px; font-weight: 700; margin-bottom: 20px; }
        .form-group { margin-bottom: 16px; }
        .form-label { display: block; font-size: 13px; font-weight: 600; color: #555; margin-bottom: 4px; }
        .form-input { width: 100%; padding: 10px 14px; border: 1px solid #d1d5db; border-radius: 8px; font-size: 14px; font-family: inherit; }
        .form-input:focus { outline: none; border-color: {{ $theme['color'] }}; box-shadow: 0 0 0 3px {{ $theme['color'] }}22; }
        textarea.form-input { min-height: 100px; resize: vertical; }
        .form-submit { background: {{ $theme['color'] }}; color: #fff; border: none; padding: 12px 28px; border-radius: 8px; font-size: 15px; font-weight: 600; cursor: pointer; }
        .form-submit:hover { opacity: 0.9; }

        /* Chat prompt banner */
        .chat-prompt { background: {{ $theme['color'] }}0a; border: 1px dashed {{ $theme['color'] }}44; border-radius: 10px; padding: 16px 20px; text-align: center; margin-bottom: 40px; }
        .chat-prompt p { font-size: 14px; color: #555; }
        .chat-prompt strong { color: {{ $theme['color'] }}; }

        /* Footer */
        .site-footer { background: #fff; border-top: 1px solid #e5e7eb; padding: 20px 24px; text-align: center; font-size: 12px; color: #999; }
        .demo-badge { display: inline-block; background: #fff3cd; color: #856404; font-size: 11px; padding: 4px 12px; border-radius: 20px; margin-bottom: 8px; }
    </style>
</head>
<body>

    {{-- Demo badge --}}
    <div style="text-align: center; padding: 6px; background: #fff3cd;">
        <span class="demo-badge">DEMO - {{ $package_name }}</span>
    </div>

    {{-- Header --}}
    <header class="site-header">
        <a href="#" class="site-logo">
            <span class="site-logo-icon">{{ $theme['icon'] }}</span>
            {{ $theme['company'] }}
        </a>
        <nav class="site-nav">
            <a href="#">{{ __('ui.demo_nav_home') }}</a>
            <a href="#">{{ __('ui.demo_nav_services') }}</a>
            <a href="#contact" style="color: {{ $theme['color'] }}; font-weight: 600;">{{ __('ui.demo_nav_contact') }}</a>
        </nav>
    </header>

    {{-- Hero --}}
    <div class="hero">
        <h1>{{ __('ui.demo_hero_title') }}</h1>
        <p>{{ $theme['tagline'] }}</p>
    </div>

    {{-- Main content --}}
    <div class="container">

        {{-- Chat prompt --}}
        <div class="chat-prompt">
            <p>{{ __('ui.demo_chat_prompt') }}</p>
        </div>

        {{-- FAQ from actual KU topics --}}
        @if(!empty($topics))
        <h2 class="section-title">{{ __('ui.demo_faq_title') }}</h2>
        <div class="faq-list">
            @foreach(array_slice($topics, 0, 5) as $topic)
            <div class="faq-item">
                <div class="faq-q">{{ $topic }}</div>
                <div class="faq-a">{{ __('ui.demo_faq_answer_hint') }}</div>
            </div>
            @endforeach
        </div>
        @endif

        {{-- Contact form --}}
        <div class="contact-card" id="contact">
            <h2>{{ __('ui.demo_contact_title') }}</h2>
            <form onsubmit="event.preventDefault(); alert('{{ __("ui.demo_form_submitted") }}');">
                <div class="form-group">
                    <label class="form-label">{{ __('ui.demo_name') }}</label>
                    <input class="form-input" type="text" placeholder="{{ __('ui.demo_name_placeholder') }}">
                </div>
                <div class="form-group">
                    <label class="form-label">{{ __('ui.demo_email') }}</label>
                    <input class="form-input" type="email" placeholder="email@example.com">
                </div>
                <div class="form-group">
                    <label class="form-label">{{ __('ui.demo_message') }}</label>
                    <textarea class="form-input" placeholder="{{ __('ui.demo_message_placeholder') }}"></textarea>
                </div>
                <button type="submit" class="form-submit">{{ __('ui.demo_send') }}</button>
            </form>
        </div>

    </div>

    {{-- Footer --}}
    <footer class="site-footer">
        <p>&copy; 2026 {{ $theme['company'] }} &mdash; {{ $theme['industry'] }}</p>
        <p style="margin-top: 4px; font-size: 11px; color: #bbb;">This is a demo page generated by KPS. The company is fictional.</p>
    </footer>

    {{-- Chat widget --}}
    <script src="{{ $widget_url }}"
            data-key="{{ $api_key }}"
            data-title="{{ $theme['company'] }} Support"
            data-theme="light"
            data-color="{{ $theme['color'] }}"
            data-greeting="{{ __('ui.demo_greeting', ['company' => $theme['company']]) }}">
    </script>

</body>
</html>
