/**
 * KPS Embed Widget Loader
 *
 * Adds a floating chat button and iframe to the customer's page.
 * Usage:
 *   <script src="https://demo02.poc-pxt.com/widget.js"
 *           data-key="kps_..."
 *           data-title="Support"
 *           data-theme="light"
 *           data-position="bottom-right"
 *           data-color="#0071e3"
 *           data-greeting="How can I help you?">
 *   </script>
 */
(function() {
    'use strict';

    // Read configuration from script tag data attributes
    var script = document.currentScript;
    if (!script) return;

    var apiKey      = script.getAttribute('data-key');
    var host        = script.getAttribute('data-host') || script.src.replace(/\/widget\.js.*$/, '');
    var position    = script.getAttribute('data-position') || 'bottom-right';
    var title       = script.getAttribute('data-title') || 'Support';
    var theme       = script.getAttribute('data-theme') || 'light';
    var color       = script.getAttribute('data-color') || '#0071e3';
    var greeting    = script.getAttribute('data-greeting') || '';
    var placeholder = script.getAttribute('data-placeholder') || '';
    var iconUrl     = script.getAttribute('data-icon') || '';
    var openers     = script.getAttribute('data-openers') || '';

    if (!apiKey) {
        console.error('[KPS Widget] data-key attribute is required.');
        return;
    }

    // Build iframe URL with customization params
    var params = new URLSearchParams({
        title: title,
        theme: theme,
        color: color,
    });
    if (greeting) params.set('greeting', greeting);
    if (placeholder) params.set('placeholder', placeholder);
    if (iconUrl) params.set('icon', iconUrl);
    if (openers) params.set('openers', openers);
    var iframeUrl = host + '/embed/chat/' + encodeURIComponent(apiKey) + '?' + params.toString();

    // Determine position styles
    var isLeft = position.indexOf('left') >= 0;
    var posH = isLeft ? 'left:20px;' : 'right:20px;';

    // Create floating toggle button
    var btn = document.createElement('div');
    btn.id = 'kps-widget-btn';
    btn.setAttribute('aria-label', 'Open chat');
    btn.innerHTML = '<svg width="24" height="24" viewBox="0 0 24 24" fill="white" xmlns="http://www.w3.org/2000/svg">'
        + '<path d="M20 2H4c-1.1 0-2 .9-2 2v18l4-4h14c1.1 0 2-.9 2-2V4c0-1.1-.9-2-2-2z"/>'
        + '</svg>';
    btn.style.cssText = 'position:fixed;bottom:20px;' + posH
        + 'width:56px;height:56px;border-radius:28px;background:' + color
        + ';display:flex;align-items:center;justify-content:center;cursor:pointer;'
        + 'box-shadow:0 4px 12px rgba(0,0,0,0.15);z-index:999998;'
        + 'transition:transform 0.2s;';

    // Create iframe container (hidden initially)
    var container = document.createElement('div');
    container.id = 'kps-widget-container';
    container.style.cssText = 'position:fixed;bottom:88px;' + posH
        + 'width:380px;height:560px;max-height:calc(100vh - 108px);'
        + 'border:none;border-radius:12px;'
        + 'box-shadow:0 8px 32px rgba(0,0,0,0.12);z-index:999999;'
        + 'overflow:hidden;display:none;';

    var iframe = document.createElement('iframe');
    iframe.src = iframeUrl;
    iframe.style.cssText = 'width:100%;height:100%;border:none;';
    iframe.setAttribute('allow', 'clipboard-write');
    iframe.setAttribute('title', title);
    container.appendChild(iframe);

    // Toggle visibility on button click
    var isOpen = false;
    btn.addEventListener('click', function() {
        isOpen = !isOpen;
        container.style.display = isOpen ? 'block' : 'none';
        btn.style.transform = isOpen ? 'rotate(90deg)' : 'rotate(0deg)';
    });

    // Append to page when DOM is ready
    function init() {
        document.body.appendChild(container);
        document.body.appendChild(btn);
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();
