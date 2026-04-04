<?php

namespace App\Http\Controllers;

use App\Models\EmbedApiKey;
use App\Models\KnowledgePackageItem;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Serves the embedded chat page loaded inside an iframe.
 *
 * This controller is NOT behind Sanctum/session auth.
 * Authentication is via the API key in the URL token.
 */
class EmbedController extends Controller
{
    /**
     * Render the standalone chat page for iframe embedding.
     *
     * URL: GET /embed/chat/{token}?title=...&theme=...&color=...&greeting=...
     */
    public function show(Request $request, string $token): View
    {
        // Validate the API key
        $keyHash = hash('sha256', $token);
        $embedKey = EmbedApiKey::where('key_hash', $keyHash)->first();

        if (!$embedKey || !$embedKey->isValid()) {
            abort(403, 'Invalid or expired embed key.');
        }

        $embedKey->loadMissing('package');
        if (!$embedKey->package || !$embedKey->package->isPublished()) {
            abort(404, 'Package not available.');
        }

        // Read saved appearance config from the package (DB defaults)
        $savedConfig = $embedKey->package->embed_config_json ?? [];

        // Customization via query parameters, falling back to saved config, then hard defaults
        $color = $request->query('color', $savedConfig['color'] ?? '#0071e3');
        if (!preg_match('/^#[0-9a-fA-F]{3,6}$/', $color)) {
            $color = '#0071e3';
        }
        $themeParam = $request->query('theme', $savedConfig['theme'] ?? 'light');
        $theme = in_array($themeParam, ['light', 'dark']) ? $themeParam : 'light';

        // Decode openers from query param (JSON string) or saved config
        $openers = [];
        if ($request->has('openers')) {
            $decoded = json_decode($request->query('openers'), true);
            if (is_array($decoded)) {
                $openers = array_slice($decoded, 0, 3);
            }
        } elseif (!empty($savedConfig['openers'])) {
            $openers = array_slice($savedConfig['openers'], 0, 3);
        }

        $config = [
            'title' => $request->query('title', $savedConfig['title'] ?? $embedKey->package->name),
            'theme' => $theme,
            'accent_color' => $color,
            'initial_message' => $request->query('greeting', $savedConfig['greeting'] ?? null),
            'placeholder' => $request->query('placeholder', $savedConfig['placeholder'] ?? 'Type your question...'),
            'icon_url' => $request->query('icon', $savedConfig['icon_url'] ?? null),
            'openers' => $openers,
            'api_key' => $token,
            'package_name' => $embedKey->package->name,
            'chat_endpoint' => url('/embed/api/chat'),
        ];

        return view('embed.chat', $config);
    }

    /**
     * Render a demo inquiry page with the chat widget embedded.
     *
     * Dynamically generates a fictional company page based on KU topics.
     * Industry/theme is inferred from keyword matching against topics.
     */
    public function demo(Request $request, string $token): View
    {
        $keyHash = hash('sha256', $token);
        $embedKey = EmbedApiKey::where('key_hash', $keyHash)->first();

        if (!$embedKey || !$embedKey->isValid()) {
            abort(403, 'Invalid or expired embed key.');
        }

        $embedKey->loadMissing('package');
        if (!$embedKey->package || !$embedKey->package->isPublished()) {
            abort(404, 'Package not available.');
        }

        // Collect top KU topics from the package to infer industry
        $topics = KnowledgePackageItem::where('knowledge_package_id', $embedKey->package->id)
            ->join('knowledge_units', 'knowledge_package_items.knowledge_unit_id', '=', 'knowledge_units.id')
            ->where('knowledge_units.review_status', 'approved')
            ->pluck('knowledge_units.topic')
            ->take(10)
            ->toArray();

        $theme = $this->inferDemoTheme($topics, $embedKey->package->name);

        return view('embed.demo', [
            'api_key' => $token,
            'widget_url' => url('/widget.js'),
            'package_name' => $embedKey->package->name,
            'topics' => $topics,
            'theme' => $theme,
        ]);
    }

    /**
     * Infer a demo page theme (company name, industry, color, etc.) from KU topics.
     */
    private function inferDemoTheme(array $topics, string $packageName): array
    {
        $text = mb_strtolower(implode(' ', $topics) . ' ' . $packageName);

        // Industry detection by keyword matching
        $themes = [
            'tech' => [
                'keywords' => ['software', 'app', 'api', 'login', 'password', 'account', 'system', 'error', 'bug', 'update', 'install', 'server', 'cloud', 'database', 'network', 'wifi', 'bluetooth', 'device', 'printer', 'pc', 'mac', 'mobile', 'ios', 'android',
                              'ログイン', 'パスワード', 'アカウント', 'アプリ', 'インストール', 'エラー', '設定', '接続', 'ソフト', 'デバイス'],
                'company' => 'NovaTech Solutions',
                'tagline' => 'Smart technology for modern businesses',
                'color' => '#2563eb',
                'icon' => '💻',
                'industry' => 'Technology',
            ],
            'finance' => [
                'keywords' => ['bank', 'payment', 'transfer', 'balance', 'card', 'credit', 'debit', 'loan', 'interest', 'fee', 'invoice', 'billing', 'refund', 'charge', 'transaction',
                              '振込', '残高', 'カード', '手数料', '請求', '口座', '支払', '返金', '決済'],
                'company' => 'FinBridge Corp.',
                'tagline' => 'Financial services you can trust',
                'color' => '#059669',
                'icon' => '🏦',
                'industry' => 'Financial Services',
            ],
            'retail' => [
                'keywords' => ['order', 'shipping', 'delivery', 'return', 'product', 'cart', 'price', 'stock', 'purchase', 'warranty', 'exchange', 'coupon', 'discount', 'size', 'color',
                              '注文', '配送', '返品', '商品', '在庫', '購入', '保証', '交換', 'クーポン', '割引'],
                'company' => 'MarketPlace One',
                'tagline' => 'Your one-stop shopping destination',
                'color' => '#dc2626',
                'icon' => '🛍️',
                'industry' => 'Retail & E-Commerce',
            ],
            'telecom' => [
                'keywords' => ['phone', 'call', 'data', 'plan', 'sim', 'roaming', 'signal', 'coverage', 'contract', 'voicemail', 'sms', 'message', 'carrier',
                              '携帯', '電話', 'プラン', '回線', '通信', '契約', 'データ', 'SIM', '機種'],
                'company' => 'ConnectWave Mobile',
                'tagline' => 'Keeping you connected everywhere',
                'color' => '#7c3aed',
                'icon' => '📱',
                'industry' => 'Telecommunications',
            ],
            'insurance' => [
                'keywords' => ['policy', 'claim', 'coverage', 'premium', 'deductible', 'beneficiary', 'insurance', 'accident', 'health', 'life', 'auto', 'home',
                              '保険', '保障', '契約', '請求', '補償', '事故', '給付'],
                'company' => 'SafeGuard Insurance',
                'tagline' => 'Protection for what matters most',
                'color' => '#0891b2',
                'icon' => '🛡️',
                'industry' => 'Insurance',
            ],
            'healthcare' => [
                'keywords' => ['patient', 'appointment', 'doctor', 'prescription', 'symptom', 'treatment', 'diagnosis', 'hospital', 'clinic', 'medical', 'health', 'test', 'lab',
                              '予約', '診察', '処方', '症状', '治療', '検査', '病院', '医師'],
                'company' => 'MediCare Plus',
                'tagline' => 'Health solutions for better living',
                'color' => '#0d9488',
                'icon' => '🏥',
                'industry' => 'Healthcare',
            ],
            'education' => [
                'keywords' => ['course', 'student', 'teacher', 'class', 'exam', 'grade', 'enrollment', 'tuition', 'lecture', 'certificate', 'degree', 'scholarship',
                              '講座', '学生', '授業', '試験', '成績', '入学', '受講'],
                'company' => 'EduPath Academy',
                'tagline' => 'Learning without boundaries',
                'color' => '#ea580c',
                'icon' => '🎓',
                'industry' => 'Education',
            ],
        ];

        // Score each theme by keyword matches
        $bestTheme = 'tech';
        $bestScore = 0;
        foreach ($themes as $key => $theme) {
            $score = 0;
            foreach ($theme['keywords'] as $kw) {
                if (str_contains($text, mb_strtolower($kw))) {
                    $score++;
                }
            }
            if ($score > $bestScore) {
                $bestScore = $score;
                $bestTheme = $key;
            }
        }

        return $themes[$bestTheme];
    }
}
