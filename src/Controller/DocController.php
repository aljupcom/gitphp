<?php

declare(strict_types=1);

namespace App\Controller;

use App\App;

/**
 * Public documentation & guide controller.
 * Supports bilingual display (ar/en) and categorized browsing via ?cat=xxxx.
 */
final class DocController
{
    private App $app;

    public function __construct(App $app)
    {
        $this->app = $app;
    }

    /** GET /docs or GET /guide */
    public function index(): void
    {
        $lang = $this->resolveLanguage();
        $categories = $this->categories($lang);
        $currentCat = $this->resolveCategory($categories);

        $cacheKey = "doc:page_v2:{$lang}:{$currentCat}";
        $html = $this->app->cache()->remember($cacheKey, 1800, function () use ($lang, $currentCat, $categories) {
            return $this->app->view()->render('guide.twig', [
                'lang'        => $lang,
                'current_cat' => $currentCat,
                'categories'  => $categories,
                't'           => $this->translations($lang),
                'owner'       => (string) $this->app->config('app.owner', 'admin'),
                'app_url'     => rtrim((string) $this->app->config('app.url', 'https://git.ysnapp.com'), '/'),
                'app_name'    => (string) $this->app->config('app.name', 'GitPHP'),
                'api_version' => 'v1',
            ]);
        });

        echo $html;
    }

    private function resolveLanguage(): string
    {
        // 1. Explicit query parameter (?lang=en or ?lang=ar)
        if (isset($_GET['lang'])) {
            $lang = strtolower(trim((string) $_GET['lang']));
            if (in_array($lang, ['ar', 'en'], true)) {
                return $lang;
            }
        }

        // 2. Explicit session preference
        $sessionLang = $_SESSION['lang'] ?? null;
        if (is_string($sessionLang) && in_array($sessionLang, ['ar', 'en'], true)) {
            return $sessionLang;
        }

        // 3. Default language is English (en)
        return 'en';
    }

    /**
     * Resolve active category tab or fallback to 'all'
     *
     * @param array<string, mixed> $categories
     */
    private function resolveCategory(array $categories): string
    {
        $cat = strtolower(trim((string) ($_GET['cat'] ?? 'all')));
        if (array_key_exists($cat, $categories)) {
            return $cat;
        }
        return 'all';
    }

    /**
     * Categories list with metadata and associated section IDs
     *
     * @return array<string, array<string, mixed>>
     */
    private function categories(string $lang): array
    {
        if ($lang === 'ar') {
            return [
                'all' => [
                    'title'    => 'الكل',
                    'icon'     => '📑',
                    'desc'     => 'الدليل الشامل لكافة الأقسام والميزات',
                    'sections' => ['remote-updates', 'downloads-gateway', 'installation', 'deploy-server', 'overview', 'quick-start', 'token-push', 'git-http', 'git-ssh', 'branches', 'pull-requests', 'code-review', 'protections', 'webhooks', 'api', 'issues-wiki', 'releases', 'files', 'accounts', 'admin', 'security', 'troubleshoot'],
                ],
                'installation' => [
                    'title'    => '📦 التثبيت والخوادم',
                    'icon'     => '📦',
                    'desc'     => 'خطوات التثبيت والاستضافة وإعداد Nginx / OLS / Apache',
                    'sections' => ['remote-updates', 'downloads-gateway', 'installation', 'deploy-server'],
                ],
                            'remote-updates' => [
                'title'    => '🚀 التحديثات عن بعد ومركز التنزيل',
                'icon'     => '🚀',
                'desc'     => 'نظام التحديث التلقائي upd_* وروابط التنزيل الموحدة /d/*',
                'sections' => ['remote-updates', 'downloads-gateway'],
                'is_new'   => true,
            ],
            'token-push' => [
                    'title'    => '⭐️ التوكن (PAT)',
                    'icon'     => '🔑',
                    'desc'     => 'طريقة الدفع برمز الوصول الشخصي والأمان الفائق',
                    'sections' => ['token-push'],
                ],
                'getting-started' => [
                    'title'    => '🚀 البدء السريع',
                    'icon'     => '🚀',
                    'desc'     => 'نظرة عامة وإنشاء أول مستودع وتهيئته',
                    'sections' => ['overview', 'quick-start'],
                ],
                'git-push' => [
                    'title'    => '💻 Git & SSH',
                    'icon'     => '💻',
                    'desc'     => 'أوامر Git ومفاتيح SSH',
                    'sections' => ['git-http', 'git-ssh'],
                ],
                'collaboration' => [
                    'title'    => '🔀 Pull Requests',
                    'icon'     => '🔀',
                    'desc'     => 'الفروع، طلبات السحب، مراجعة الكود، وحماية الفروع',
                    'sections' => ['branches', 'pull-requests', 'code-review', 'protections'],
                ],
                'features' => [
                    'title'    => '📁 المميزات',
                    'icon'     => '📁',
                    'desc'     => 'إدارة الملفات، Issues، الويكي، والإصدارات',
                    'sections' => ['files', 'issues-wiki', 'releases', 'accounts'],
                ],
                'api-webhooks' => [
                    'title'    => '⚡️ REST API',
                    'icon'     => '⚡️',
                    'desc'     => 'نقاط النهاية البرمجية والإشعارات الفورية',
                    'sections' => ['api', 'webhooks'],
                ],
                'admin-security' => [
                    'title'    => '🛡️ الإدارة والأمان',
                    'icon'     => '🛡️',
                    'desc'     => 'لوحة التحكم، السجلات، وإعدادات الخادم',
                    'sections' => ['admin', 'security'],
                ],
                'troubleshoot' => [
                    'title'    => '🔧 حل المشاكل',
                    'icon'     => '🔧',
                    'desc'     => 'حلول مشاكل الرفع، الصلاحيات، والاتصال',
                    'sections' => ['troubleshoot'],
                ],
            ];
        }

        return [
            'all' => [
                'title'    => 'All',
                'icon'     => '📑',
                'desc'     => 'Full comprehensive documentation',
                'sections' => ['remote-updates', 'downloads-gateway', 'installation', 'deploy-server', 'overview', 'quick-start', 'token-push', 'git-http', 'git-ssh', 'branches', 'pull-requests', 'code-review', 'protections', 'webhooks', 'api', 'issues-wiki', 'releases', 'files', 'accounts', 'admin', 'security', 'troubleshoot'],
            ],
            'installation' => [
                'title'    => '📦 Installation',
                'icon'     => '📦',
                'desc'     => 'Self-hosting, requirements, and web server setup',
                'sections' => ['remote-updates', 'downloads-gateway', 'installation', 'deploy-server'],
            ],
                        'remote-updates' => [
                'title'    => '🚀 Remote Updates & Downloads',
                'icon'     => '🚀',
                'desc'     => 'CLI upd_* update tokens and unified /d/* download gateway',
                'sections' => ['remote-updates', 'downloads-gateway'],
                'is_new'   => true,
            ],
            'token-push' => [
                'title'    => '⭐️ Token Push',
                'icon'     => '🔑',
                'desc'     => 'Personal Access Tokens & CI/CD workflow',
                'sections' => ['token-push'],
            ],
            'getting-started' => [
                'title'    => '🚀 Quick Start',
                'icon'     => '🚀',
                'desc'     => 'Platform overview & first repository',
                'sections' => ['overview', 'quick-start'],
            ],
            'git-push' => [
                'title'    => '💻 Git & SSH',
                'icon'     => '💻',
                'desc'     => 'Standard clone, push, and SSH setup',
                'sections' => ['git-http', 'git-ssh'],
            ],
            'collaboration' => [
                'title'    => '🔀 Pull Requests',
                'icon'     => '🔀',
                'desc'     => 'Branches, pull requests, code review, protection',
                'sections' => ['branches', 'pull-requests', 'code-review', 'protections'],
            ],
            'features' => [
                'title'    => '📁 Features',
                'icon'     => '📁',
                'desc'     => 'File tree, Web Editor, issues, wiki, releases',
                'sections' => ['files', 'issues-wiki', 'releases', 'accounts'],
            ],
            'api-webhooks' => [
                'title'    => '⚡️ REST API',
                'icon'     => '⚡️',
                'desc'     => 'Endpoints, tokens, and HMAC webhooks',
                'sections' => ['api', 'webhooks'],
            ],
            'admin-security' => [
                'title'    => '🛡️ Admin & Security',
                'icon'     => '🛡️',
                'desc'     => 'Dashboard, audit logs, 2FA, and server config',
                'sections' => ['admin', 'security'],
            ],
            'troubleshoot' => [
                'title'    => '🔧 Troubleshooting',
                'icon'     => '🔧',
                'desc'     => 'Resolving push failures, 401s, and permissions',
                'sections' => ['troubleshoot'],
            ],
        ];
    }

    /** @return array<string, mixed> */
    private function translations(string $lang): array
    {
        if ($lang === 'ar') {
            return [
                'title' => 'دليل الاستخدام والتثبيت الشامل',
                'hero'  => [
                    'title'    => 'دليل منصة :app 🚀',
                    'subtitle' => 'دليل شامل ومفتوح المصدر: خطوات التثبيت والاستضافة، الدفع بالرموز الشخصية (PAT)، أوامر Git و SSH، الـ Pull Requests، مراجعة الكود، وحماية الفروع.',
                    'cta_updates' => 'التحديثات عن بعد 🚀 [جديد]',
                    'cta_install' => 'التثبيت 📦',
                    'cta_token'   => 'التوكن ⭐️',
                    'cta_start'   => 'البدء السريع',
                    'cta_git'     => 'أوامر Git',
                    'cta_pr'      => 'Pull Requests',
                    'cta_api'     => 'REST API',
                ],
                'categories_title' => 'أقسام الدليل',
                'toc'   => [
                    'title' => 'المحتويات',
                    'items' => [
                        'installation' => '📦 التثبيت',
                        'deploy-server'=> '⚙️ إعداد الخوادم',
                        'remote-updates' => '🚀 التحديثات عن بعد (upd_*) [جديد]',
                        'downloads-gateway' => '☁️ مركز التنزيل الموحد /d/* [جديد]',
                        'token-push'   => '⭐️ التوكن (PAT)',
                        'overview'     => 'نظرة عامة',
                        'quick-start'  => 'البدء السريع',
                        'git-http'     => 'Git HTTP',
                        'git-ssh'      => 'Git SSH',
                        'branches'     => 'الفروع والدمج',
                        'pull-requests'=> 'Pull Requests',
                        'code-review'  => 'مراجعة الكود',
                        'protections'  => 'حماية الفروع',
                        'webhooks'     => 'Webhooks',
                        'api'          => 'REST API',
                        'issues-wiki'  => 'Issues & Wiki',
                        'releases'     => 'الإصدارات',
                        'files'        => 'الملفات',
                        'accounts'     => 'الحسابات',
                        'admin'        => 'لوحة الإدارة',
                        'security'     => 'الأمان والحماية',
                        'troubleshoot' => 'حل المشاكل',
                    ],
                ],
            ];
        }

        return [
            'title' => 'Comprehensive Guide & Documentation',
            'hero'  => [
                'title'    => 'The :app guide 🚀',
                'subtitle' => 'Comprehensive open-source documentation: Self-hosting, Personal Access Tokens, Git & SSH, pull requests, and REST API.',
                'cta_updates' => 'Remote Updates 🚀 [NEW]',
                'cta_install' => 'Install 📦',
                'cta_token'   => 'Token Push ⭐️',
                'cta_start'   => 'Quick start',
                'cta_git'     => 'Git HTTP',
                'cta_pr'      => 'Pull requests',
                'cta_api'     => 'REST API',
            ],
            'categories_title' => 'Documentation Categories',
            'toc'   => [
                'title' => 'Contents',
                'items' => [
                    'installation' => '📦 Installation',
                    'deploy-server'=> '⚙️ Web Servers',
                    'remote-updates' => '🚀 Remote Updates (upd_*) [NEW]',
                    'downloads-gateway' => '☁️ Download Gateway /d/* [NEW]',
                    'token-push'   => '⭐️ Token Push (PAT)',
                    'overview'     => 'Overview',
                    'quick-start'  => 'Quick Start',
                    'git-http'     => 'Git HTTP',
                    'git-ssh'      => 'Git SSH',
                    'branches'     => 'Branches & Merge',
                    'pull-requests'=> 'Pull Requests',
                    'code-review'  => 'Code Review',
                    'protections'  => 'Branch Rules',
                    'webhooks'     => 'Webhooks',
                    'api'          => 'REST API',
                    'issues-wiki'  => 'Issues & Wiki',
                    'releases'     => 'Releases',
                    'files'        => 'Repositories',
                    'accounts'     => 'Accounts',
                    'admin'        => 'Admin Panel',
                    'security'     => 'Security',
                    'troubleshoot' => 'Troubleshooting',
                ],
            ],
        ];
    }
}
