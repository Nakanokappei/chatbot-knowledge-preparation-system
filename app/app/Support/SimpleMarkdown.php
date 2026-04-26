<?php

namespace App\Support;

/**
 * Minimal Markdown → HTML converter for the parameter-search advisory.
 *
 * The advisory prompt constrains Claude to a small subset of Markdown
 * (`## h2`, `### h3`, `- bullets`, `1. ordered lists`, `**bold**` and
 * `*italic*` plus paragraphs). A full library would be overkill for a
 * single block of constrained AI output, so we hand-roll a converter
 * that handles exactly that subset and HTML-escapes everything else.
 *
 * Anything outside the expected grammar falls through as an escaped
 * paragraph so we never emit user-supplied raw HTML.
 */
class SimpleMarkdown
{
    /**
     * Convert the constrained Markdown subset to HTML.
     *
     * Returns an empty string when the input is null or whitespace.
     */
    public static function toHtml(?string $markdown): string
    {
        if ($markdown === null) {
            return '';
        }
        $text = trim($markdown);
        if ($text === '') {
            return '';
        }

        $lines = preg_split("/\r?\n/", $text) ?: [];
        $html = [];
        $listType = null;     // 'ul' | 'ol' | null
        $paragraph = [];      // accumulated paragraph lines

        // Helper closures share state to keep the line-loop readable.
        $flushParagraph = function () use (&$paragraph, &$html) {
            if (!empty($paragraph)) {
                $joined = implode(' ', $paragraph);
                $html[] = '<p>' . self::renderInline($joined) . '</p>';
                $paragraph = [];
            }
        };
        $closeList = function () use (&$listType, &$html) {
            if ($listType !== null) {
                $html[] = "</{$listType}>";
                $listType = null;
            }
        };

        foreach ($lines as $rawLine) {
            $line = rtrim($rawLine);

            // Blank line — flush any open paragraph or list and continue.
            if ($line === '') {
                $flushParagraph();
                $closeList();
                continue;
            }

            // Headings: ## or ###.
            if (preg_match('/^(#{2,3})\s+(.*)$/', $line, $matches)) {
                $flushParagraph();
                $closeList();
                $level = strlen($matches[1]);
                $content = self::renderInline(trim($matches[2]));
                $html[] = "<h{$level}>{$content}</h{$level}>";
                continue;
            }

            // Bullet list: "- item" or "* item".
            if (preg_match('/^[-*]\s+(.*)$/', $line, $matches)) {
                $flushParagraph();
                if ($listType !== 'ul') {
                    $closeList();
                    $html[] = '<ul>';
                    $listType = 'ul';
                }
                $html[] = '<li>' . self::renderInline(trim($matches[1])) . '</li>';
                continue;
            }

            // Ordered list: "1. item".
            if (preg_match('/^\d+\.\s+(.*)$/', $line, $matches)) {
                $flushParagraph();
                if ($listType !== 'ol') {
                    $closeList();
                    $html[] = '<ol>';
                    $listType = 'ol';
                }
                $html[] = '<li>' . self::renderInline(trim($matches[1])) . '</li>';
                continue;
            }

            // Anything else is paragraph text — close any open list first
            // so the new paragraph starts on its own line.
            $closeList();
            $paragraph[] = trim($line);
        }

        $flushParagraph();
        $closeList();

        return implode("\n", $html);
    }

    /**
     * Inline conversion: HTML-escape, then re-introduce **bold** / *italic*.
     *
     * Order matters — bold first, then italic, so `**foo**` does not get
     * eaten by the italic pass.
     */
    private static function renderInline(string $text): string
    {
        $escaped = htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $escaped = preg_replace('/\*\*(.+?)\*\*/u', '<strong>$1</strong>', $escaped);
        $escaped = preg_replace('/(?<!\*)\*(?!\s)(.+?)(?<!\s)\*(?!\*)/u', '<em>$1</em>', $escaped);
        return $escaped;
    }
}
