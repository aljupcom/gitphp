<?php

declare(strict_types=1);

namespace App\Controller;

use App\App;
use App\Auth;
use App\Service\GitReader;
use App\Service\GitService;

final class DashboardController
{
    private App $app;
    private Auth $auth;

    public function __construct(App $app)
    {
        $this->app  = $app;
        $this->auth = new Auth($app);
    }

    /** GET / — show public repositories on the home page. */
    public function index(): void
    {
        $rows = $this->app->db()->fetchAll(
            'SELECT r.`id`, r.`slug`, r.`name`, r.`description`, r.`visibility`, r.`default_branch`,
                    r.`stars_count`, r.`owner_user_id`, r.`updated_at`, r.`created_at`,
                    (SELECT COUNT(*) FROM `repositories` f WHERE f.`forked_from_id` = r.`id`) AS `forks_count`,
                    u.`username` AS `owner_username`
             FROM `repositories` r
             LEFT JOIN `users` u ON u.`id` = r.`owner_user_id`
             WHERE r.`visibility` = :vis
             ORDER BY r.`updated_at` DESC',
            ['vis' => 'public'],
        );

        $fallbackOwner = (string) $this->app->config('app.owner', 'admin');
        $appUrl        = rtrim((string) $this->app->config('app.url', ''), '/');

        $gitService = new GitService();
        $gitReader  = new GitReader();
        $cache      = $this->app->cache();

        $currentUserId = $this->auth->isLoggedIn() ? ($this->auth->isOwner() ? 0 : (int) $this->auth->userId()) : -1;
        $starredRepoIds = [];
        if ($currentUserId >= 0) {
            $userLikes = $this->app->db()->fetchAll(
                'SELECT `repo_id` FROM `repo_likes` WHERE `user_id` = :uid',
                ['uid' => $currentUserId]
            );
            $starredRepoIds = array_column($userLikes, 'repo_id');
        }

        $languagesMap = [];
        $totalCommits = 0;

        $repos = array_map(
            function (array $row) use ($fallbackOwner, $appUrl, $gitService, $gitReader, $cache, $starredRepoIds, &$languagesMap, &$totalCommits): array {
                $slug     = (string) $row['slug'];
                $repoPath = $gitService->getRepoPath($slug);

                $ownerName = (string) ($row['owner_username'] ?? '') !== ''
                    ? (string) $row['owner_username']
                    : $fallbackOwner;

                $defaultBranch = (string) ($row['default_branch'] ?? 'HEAD');

                // Retrieve real commit timestamp
                $latestDate = $cache->remember(
                    "home:{$slug}:lastcommit",
                    180,
                    static function () use ($gitReader, $repoPath, $defaultBranch, $row) {
                        $log = $gitReader->getLog($repoPath, $defaultBranch, 1);
                        if (!empty($log[0]['date'])) {
                            return $log[0]['date'];
                        }
                        return $row['updated_at'] ?? $row['created_at'];
                    }
                );

                // Commit count
                $repoCommitCount = (int) $cache->remember(
                    "home:{$slug}:commitcount",
                    300,
                    static function () use ($gitReader, $repoPath, $defaultBranch) {
                        return $gitReader->getCommitCount($repoPath, $defaultBranch);
                    }
                );
                $totalCommits += $repoCommitCount;

                // File count
                $fileCount = (int) $cache->remember(
                    "home:{$slug}:filecount",
                    300,
                    static fn() => count(
                        $gitReader->getTree($repoPath, $defaultBranch),
                    ),
                );

                // Language detection
                $langInfo = $cache->remember("repo:{$slug}:lang_info_v6", 3600, function () use ($slug, $defaultBranch) {
                    return $this->detectRepoLanguage($slug, $defaultBranch);
                });

                $primaryLang = $langInfo['name'] ?? null;
                $langColor   = $langInfo['color'] ?? '#58a6ff';

                if (!empty($primaryLang)) {
                    $languagesMap[$primaryLang] = [
                        'name'  => $primaryLang,
                        'color' => $langColor,
                    ];
                }

                return [
                    'id'             => (int) $row['id'],
                    'owner'          => $ownerName,
                    'slug'           => $slug,
                    'name'           => $row['name'],
                    'description'    => $row['description'],
                    'is_private'     => $row['visibility'] === 'private',
                    'default_branch' => $defaultBranch,
                    'stars_count'    => (int) ($row['stars_count'] ?? 0),
                    'forks_count'    => (int) ($row['forks_count'] ?? 0),
                    'is_starred'     => in_array((int) $row['id'], $starredRepoIds, true),
                    'updated_at'     => $latestDate,
                    'clone_url'      => "{$appUrl}/{$ownerName}/{$slug}.git",
                    'file_count'     => $fileCount,
                    'commit_count'   => $repoCommitCount,
                    'primary_lang'   => $primaryLang,
                    'lang_color'     => $langColor,
                ];
            },
            $rows,
        );

        $totalStars = array_sum(array_map(static fn(array $r): int => (int) ($r['stars_count'] ?? 0), $rows));
        $totalUsers = (int) ($this->app->db()->fetchOne('SELECT COUNT(*) AS `c` FROM `users`')['c'] ?? 1);

        $currLocale = \App\Service\Locale::current();

        // Fetch recent activities across public repositories
        $recentActivities = (array) $cache->remember('home:recent_activities_v8_' . $currLocale, 60, function () use ($fallbackOwner, $currLocale): array {
            $acts = $this->app->db()->fetchAll('
                SELECT a.*, r.slug AS repo_slug, r.name AS repo_name, r.visibility, u.username AS owner_username
                FROM activity_log a
                LEFT JOIN repositories r ON r.id = a.repo_id
                LEFT JOIN users u ON u.id = r.owner_user_id
                WHERE r.visibility = "public" OR a.repo_id IS NULL
                ORDER BY a.id DESC
                LIMIT 15
            ');
            return array_map(function (array $act) use ($fallbackOwner, $currLocale): array {
                $act['owner_username'] = !empty($act['owner_username']) ? $act['owner_username'] : $fallbackOwner;
                return $this->formatActivity($act, $currLocale);
            }, $acts);
        });

        // Fetch latest commits stream across repositories
        $latestCommits = (array) $cache->remember('home:latest_commits_stream_v3', 60, function () use ($rows, $gitService, $gitReader, $fallbackOwner): array {
            $allCommits = [];
            foreach (array_slice($rows, 0, 8) as $r) {
                $repoPath = $gitService->getRepoPath($r['slug']);
                $branch   = $r['default_branch'] ?: 'HEAD';
                $owner    = !empty($r['owner_username']) ? $r['owner_username'] : $fallbackOwner;
                $logs     = $gitReader->getLog($repoPath, $branch, 4);
                foreach ($logs as $c) {
                    $allCommits[] = [
                        'repo_name'    => $r['name'],
                        'repo_slug'    => $r['slug'],
                        'owner'        => $owner,
                        'hash'         => $c['hash'] ?? '',
                        'short_hash'   => substr($c['hash'] ?? '', 0, 7),
                        'subject'      => $c['subject'] ?? ($c['message'] ?? ''),
                        'author_name'  => $c['author_name'] ?? ($c['author'] ?? 'Admin'),
                        'author_email' => $c['author_email'] ?? '',
                        'date'         => $c['date'] ?? ($c['author_date'] ?? ''),
                    ];
                }
            }
            usort($allCommits, static function (array $a, array $b): int {
                return strtotime($b['date'] ?: 'now') <=> strtotime($a['date'] ?: 'now');
            });
            return array_slice($allCommits, 0, 10);
        });

        // Fetch recent releases
        $recentReleases = (array) $cache->remember('home:recent_releases_v3', 60, function () use ($fallbackOwner): array {
            $rels = $this->app->db()->fetchAll('
                SELECT rel.*, r.slug AS repo_slug, r.name AS repo_name, u.username AS owner_username
                FROM repo_releases rel
                JOIN repositories r ON r.id = rel.repo_id
                LEFT JOIN users u ON u.id = r.owner_user_id
                WHERE rel.is_draft = 0 AND r.visibility = "public"
                ORDER BY rel.created_at DESC
                LIMIT 5
            ');
            return array_map(function (array $rel) use ($fallbackOwner): array {
                $rel['owner_username'] = !empty($rel['owner_username']) ? $rel['owner_username'] : $fallbackOwner;
                return $rel;
            }, $rels);
        });

        $this->app->view()->display('home.twig', [
            'repos'             => $repos,
            'languages'         => array_values($languagesMap),
            'recent_activities' => $recentActivities,
            'latest_commits'    => $latestCommits,
            'recent_releases'   => $recentReleases,
            'csrf_token'        => $this->auth->generateCsrf(),
            'stats'             => [
                'repos'     => count($repos),
                'users'     => $totalUsers,
                'stars'     => $totalStars,
                'commits'   => $totalCommits,
            ],
        ]);
    }


    
    
                            private function formatActivity(array $act, string $locale): array
    {
        $action = strtolower(trim((string) ($act['action'] ?? '')));
        $details = (string) ($act['details'] ?? '');
        $owner = (string) ($act['owner_username'] ?? 'admin');
        $slug = (string) ($act['repo_slug'] ?? '');
        $isAr = ($locale === 'ar');

        $actionLabels = [
            'push'     => $isAr ? 'تم الدفع' : 'Push',
            'imported' => $isAr ? 'تم الاستيراد' : 'Imported',
            'created'  => $isAr ? 'تم الإنشاء' : 'Created',
            'deleted'  => $isAr ? 'تم الحذف' : 'Deleted',
            'synced'   => $isAr ? 'تمت المزامنة' : 'Synced',
            'release'  => $isAr ? 'تم الإصدار' : 'Release',
            'fork'     => $isAr ? 'تم التفريع' : 'Fork',
            'star'     => $isAr ? 'تم الإعجاب' : 'Star',
        ];
        $actionText = $actionLabels[$action] ?? ($isAr ? 'نشاط' : ucfirst($action));
        $formattedDetails = htmlspecialchars($details, ENT_QUOTES, 'UTF-8');

        if ($action === 'push') {
            $parts = explode(';', $details);
            $formattedParts = [];
            foreach ($parts as $part) {
                $part = trim($part);
                if (preg_match('/pushed to heads\/([^\s]+)\s*\(([a-f0-9]+)\)/i', $part, $m)) {
                    $branch = htmlspecialchars($m[1], ENT_QUOTES, 'UTF-8');
                    $sha = htmlspecialchars($m[2], ENT_QUOTES, 'UTF-8');
                    $commitLink = (!empty($owner) && !empty($slug))
                        ? '<a href="/' . $owner . '/' . $slug . '/commit/' . $sha . '" class="gh-mono" style="color: var(--gh-accent-fg); font-weight:600; text-decoration:none;">' . $sha . '</a>'
                        : '<span class="gh-mono">' . $sha . '</span>';
                    $branchLink = (!empty($owner) && !empty($slug))
                        ? '<a href="/' . $owner . '/' . $slug . '/tree/' . $branch . '" class="gh-tag-pill" style="font-size:11px; padding:1px 6px; background:rgba(56,139,253,0.15); color:#58a6ff; border-radius:4px; text-decoration:none; font-family:var(--gh-font-mono);">' . $branch . '</a>'
                        : '<span class="gh-tag-pill">' . $branch . '</span>';

                    $formattedParts[] = $isAr
                        ? ('دفع كود إلى الفرع ' . $branchLink . ' (' . $commitLink . ')')
                        : ('Pushed to branch ' . $branchLink . ' (' . $commitLink . ')');
                } elseif (preg_match('/pushed to tags\/([^\s]+)\s*\(([a-f0-9]+)\)/i', $part, $m)) {
                    $tag = htmlspecialchars($m[1], ENT_QUOTES, 'UTF-8');
                    $sha = htmlspecialchars($m[2], ENT_QUOTES, 'UTF-8');
                    $tagLink = (!empty($owner) && !empty($slug))
                        ? '<a href="/' . $owner . '/' . $slug . '/releases/tag/' . $tag . '" class="gh-tag-pill" style="font-size:11px; padding:1px 6px; background:rgba(63,185,80,0.15); color:#3fb950; border-radius:4px; text-decoration:none; font-family:var(--gh-font-mono);">' . $tag . '</a>'
                        : '<span class="gh-tag-pill">' . $tag . '</span>';
                    $commitLink = (!empty($owner) && !empty($slug))
                        ? '<a href="/' . $owner . '/' . $slug . '/commit/' . $sha . '" class="gh-mono" style="color: var(--gh-accent-fg); font-weight:600; text-decoration:none;">' . $sha . '</a>'
                        : '<span class="gh-mono">' . $sha . '</span>';

                    $formattedParts[] = $isAr
                        ? ('دفع إلى الوسم ' . $tagLink . ' (' . $commitLink . ')')
                        : ('Pushed to tag ' . $tagLink . ' (' . $commitLink . ')');
                } elseif (preg_match('/pushed to heads\/([^\s]+)/i', $part, $m)) {
                    $branch = htmlspecialchars($m[1], ENT_QUOTES, 'UTF-8');
                    $branchLink = (!empty($owner) && !empty($slug))
                        ? '<a href="/' . $owner . '/' . $slug . '/tree/' . $branch . '" class="gh-tag-pill" style="font-size:11px; padding:1px 6px; background:rgba(56,139,253,0.15); color:#58a6ff; border-radius:4px; text-decoration:none; font-family:var(--gh-font-mono);">' . $branch . '</a>'
                        : '<span class="gh-tag-pill">' . $branch . '</span>';
                    $formattedParts[] = $isAr
                        ? ('دفع كود إلى الفرع ' . $branchLink)
                        : ('Pushed to branch ' . $branchLink);
                } elseif (preg_match('/pushed to tags\/([^\s]+)/i', $part, $m)) {
                    $tag = htmlspecialchars($m[1], ENT_QUOTES, 'UTF-8');
                    $tagLink = (!empty($owner) && !empty($slug))
                        ? '<a href="/' . $owner . '/' . $slug . '/releases/tag/' . $tag . '" class="gh-tag-pill" style="font-size:11px; padding:1px 6px; background:rgba(63,185,80,0.15); color:#3fb950; border-radius:4px; text-decoration:none; font-family:var(--gh-font-mono);">' . $tag . '</a>'
                        : '<span class="gh-tag-pill">' . $tag . '</span>';
                    $formattedParts[] = $isAr
                        ? ('دفع إلى الوسم ' . $tagLink)
                        : ('Pushed to tag ' . $tagLink);
                }
            }
            if (!empty($formattedParts)) {
                $formattedDetails = implode($isAr ? ' و ' : ' & ', $formattedParts);
            }
        } elseif ($action === 'imported') {
            if (preg_match('/Repository\s*[\'"]?([^\'"]+)[\'"]?\s*imported from\s*([^—\(]+)(?:\(([^\)]+)\))?\s*—\s*(\d+)\s*branches?,\s*(\d+)\s*tags?,\s*(\d+)\s*commits?/i', $details, $m)) {
                $sourceName = trim($m[2]);
                $sourceUrl  = trim($m[3] ?? '');
                $bCount     = (int)$m[4];
                $tCount     = (int)$m[5];
                $cCount     = (int)$m[6];

                $srcLabel = !empty($sourceUrl)
                    ? '<a href="' . $sourceUrl . '" target="_blank" rel="noopener" style="color: var(--gh-accent-fg); text-decoration:none; font-weight:600;">' . $sourceName . '</a>'
                    : '<strong>' . $sourceName . '</strong>';

                if ($isAr) {
                    $bText = ($bCount === 1) ? 'فرع واحد' : (($bCount === 2) ? 'فرعان' : ($bCount . ' فروع'));
                    $tText = ($tCount === 1) ? 'وسم واحد' : (($tCount === 2) ? 'وسمان' : ($tCount . ' وسوم'));
                    $cText = ($cCount === 1) ? 'إيداع واحد' : (($cCount === 2) ? 'إيداعان' : ($cCount . ' إيداعات'));
                    $formattedDetails = 'تم استيراد المستودع من ' . $srcLabel . ' — ' . $bText . '، ' . $tText . '، ' . $cText;
                } else {
                    $bText = $bCount . ' ' . ($bCount === 1 ? 'branch' : 'branches');
                    $tText = $tCount . ' ' . ($tCount === 1 ? 'tag' : 'tags');
                    $cText = $cCount . ' ' . ($cCount === 1 ? 'commit' : 'commits');
                    $formattedDetails = 'Repository imported from ' . $srcLabel . ' — ' . $bText . ', ' . $tText . ', ' . $cText;
                }
            }
        } elseif ($action === 'created') {
            if (preg_match('/Repository\s*[\'"]?([^\'"]+)[\'"]?\\s*created/i', $details, $m)) {
                $repoName = htmlspecialchars($m[1], ENT_QUOTES, 'UTF-8');
                $formattedDetails = $isAr ? ('تم إنشاء المستودع «' . $repoName . '» بنجاح') : ('Repository ' . $repoName . ' created');
            }
        } elseif ($action === 'deleted') {
            if (preg_match('/Repository\s*[\'"]?([^\'"]+)[\'"]?\\s*deleted/i', $details, $m)) {
                $repoName = htmlspecialchars($m[1], ENT_QUOTES, 'UTF-8');
                $formattedDetails = $isAr ? ('تم حذف المستودع «' . $repoName . '»') : ('Repository ' . $repoName . ' deleted');
            }
        } elseif ($action === 'release') {
            if (preg_match('/Release\s*[\'"]?([^\'"]+)[\'"]?\\s*published/i', $details, $m)) {
                $relName = htmlspecialchars($m[1], ENT_QUOTES, 'UTF-8');
                $formattedDetails = $isAr ? ('تم نشر الإصدار «' . $relName . '»') : ('Release ' . $relName . ' published');
            }
        }

        $act['action_label'] = $actionText;
        $act['details_html'] = $formattedDetails;
        return $act;
    }


    private function detectRepoLanguage(string $slug, string $ref = 'HEAD'): ?array
    {
        $langMap = [
            // C / C++ / Systems
            'c'          => ['name' => 'C', 'color' => '#555555'],
            'h'          => ['name' => 'C', 'color' => '#555555'],
            'cpp'        => ['name' => 'C++', 'color' => '#f34b7d'],
            'cxx'        => ['name' => 'C++', 'color' => '#f34b7d'],
            'cc'         => ['name' => 'C++', 'color' => '#f34b7d'],
            'hpp'        => ['name' => 'C++', 'color' => '#f34b7d'],
            'hxx'        => ['name' => 'C++', 'color' => '#f34b7d'],
            'hh'         => ['name' => 'C++', 'color' => '#f34b7d'],
            'cs'         => ['name' => 'C#', 'color' => '#178600'],
            'csx'        => ['name' => 'C#', 'color' => '#178600'],
            'rs'         => ['name' => 'Rust', 'color' => '#dea584'],
            'go'         => ['name' => 'Go', 'color' => '#00ADD8'],
            'd'          => ['name' => 'D', 'color' => '#ba595e'],
            'zig'        => ['name' => 'Zig', 'color' => '#ec915c'],
            'nim'        => ['name' => 'Nim', 'color' => '#ffc200'],
            'v'          => ['name' => 'V', 'color' => '#4f87c4'],
            'odin'       => ['name' => 'Odin', 'color' => '#60A5FA'],
            'f90'        => ['name' => 'Fortran', 'color' => '#4d41b1'],
            'f95'        => ['name' => 'Fortran', 'color' => '#4d41b1'],
            'f03'        => ['name' => 'Fortran', 'color' => '#4d41b1'],
            'f'          => ['name' => 'Fortran', 'color' => '#4d41b1'],
            'for'        => ['name' => 'Fortran', 'color' => '#4d41b1'],
            'ada'        => ['name' => 'Ada', 'color' => '#02f88c'],
            'adb'        => ['name' => 'Ada', 'color' => '#02f88c'],
            'ads'        => ['name' => 'Ada', 'color' => '#02f88c'],
            'asm'        => ['name' => 'Assembly', 'color' => '#6E4C13'],
            's'          => ['name' => 'Assembly', 'color' => '#6E4C13'],

            // Web Core & Frontend
            'php'        => ['name' => 'PHP', 'color' => '#4F5D95'],
            'phtml'      => ['name' => 'PHP', 'color' => '#4F5D95'],
            'php4'       => ['name' => 'PHP', 'color' => '#4F5D95'],
            'php5'       => ['name' => 'PHP', 'color' => '#4F5D95'],
            'phps'       => ['name' => 'PHP', 'color' => '#4F5D95'],
            'ctp'        => ['name' => 'PHP', 'color' => '#4F5D95'],
            'js'         => ['name' => 'JavaScript', 'color' => '#f1e05a'],
            'mjs'        => ['name' => 'JavaScript', 'color' => '#f1e05a'],
            'cjs'        => ['name' => 'JavaScript', 'color' => '#f1e05a'],
            'jsx'        => ['name' => 'JavaScript', 'color' => '#f1e05a'],
            'ts'         => ['name' => 'TypeScript', 'color' => '#3178c6'],
            'mts'        => ['name' => 'TypeScript', 'color' => '#3178c6'],
            'cts'        => ['name' => 'TypeScript', 'color' => '#3178c6'],
            'tsx'        => ['name' => 'TypeScript', 'color' => '#3178c6'],
            'html'       => ['name' => 'HTML', 'color' => '#e34c26'],
            'htm'        => ['name' => 'HTML', 'color' => '#e34c26'],
            'xhtml'      => ['name' => 'HTML', 'color' => '#e34c26'],
            'css'        => ['name' => 'CSS', 'color' => '#563d7c'],
            'scss'       => ['name' => 'SCSS', 'color' => '#c6538c'],
            'sass'       => ['name' => 'Sass', 'color' => '#a53b70'],
            'less'       => ['name' => 'Less', 'color' => '#1d365d'],
            'styl'       => ['name' => 'Stylus', 'color' => '#ff6347'],
            'vue'        => ['name' => 'Vue', 'color' => '#41b883'],
            'svelte'     => ['name' => 'Svelte', 'color' => '#ff3e00'],
            'astro'      => ['name' => 'Astro', 'color' => '#ff5a03'],
            'twig'       => ['name' => 'Twig', 'color' => '#c1d026'],
            'blade.php'  => ['name' => 'Blade', 'color' => '#f7523f'],
            'ejs'        => ['name' => 'EJS', 'color' => '#a91e50'],
            'handlebars' => ['name' => 'Handlebars', 'color' => '#f7931e'],
            'hbs'        => ['name' => 'Handlebars', 'color' => '#f7931e'],
            'mustache'   => ['name' => 'Mustache', 'color' => '#724b3b'],
            'pug'        => ['name' => 'Pug', 'color' => '#a86454'],
            'haml'       => ['name' => 'Haml', 'color' => '#ece2a9'],
            'liquid'     => ['name' => 'Liquid', 'color' => '#67b8de'],

            // JVM Languages
            'java'       => ['name' => 'Java', 'color' => '#b07219'],
            'class'      => ['name' => 'Java', 'color' => '#b07219'],
            'jar'        => ['name' => 'Java', 'color' => '#b07219'],
            'kt'         => ['name' => 'Kotlin', 'color' => '#A97BFF'],
            'kts'        => ['name' => 'Kotlin', 'color' => '#A97BFF'],
            'scala'      => ['name' => 'Scala', 'color' => '#c22d40'],
            'sc'         => ['name' => 'Scala', 'color' => '#c22d40'],
            'groovy'     => ['name' => 'Groovy', 'color' => '#4298b8'],
            'gvy'        => ['name' => 'Groovy', 'color' => '#4298b8'],
            'clj'        => ['name' => 'Clojure', 'color' => '#db5855'],
            'cljs'       => ['name' => 'Clojure', 'color' => '#db5855'],
            'edn'        => ['name' => 'Clojure', 'color' => '#db5855'],

            // Python & Data Science
            'py'         => ['name' => 'Python', 'color' => '#3572A5'],
            'pyw'        => ['name' => 'Python', 'color' => '#3572A5'],
            'pyi'        => ['name' => 'Python', 'color' => '#3572A5'],
            'ipynb'      => ['name' => 'Jupyter Notebook', 'color' => '#DA5B0B'],
            'r'          => ['name' => 'R', 'color' => '#198CE7'],
            'rmd'        => ['name' => 'R', 'color' => '#198CE7'],
            'jl'         => ['name' => 'Julia', 'color' => '#a270ba'],
            'm'          => ['name' => 'MATLAB', 'color' => '#e16737'],
            'matlab'     => ['name' => 'MATLAB', 'color' => '#e16737'],
            'sas'        => ['name' => 'SAS', 'color' => '#B34936'],

            // Mobile & Apple / Google ecosystems
            'dart'       => ['name' => 'Dart', 'color' => '#00B4AB'],
            'swift'      => ['name' => 'Swift', 'color' => '#F05138'],
            'mm'         => ['name' => 'Objective-C++', 'color' => '#6866fb'],

            // Scripting & Automation
            'rb'         => ['name' => 'Ruby', 'color' => '#701516'],
            'erb'        => ['name' => 'ERB', 'color' => '#701516'],
            'gemspec'    => ['name' => 'Ruby', 'color' => '#701516'],
            'rake'       => ['name' => 'Ruby', 'color' => '#701516'],
            'pl'         => ['name' => 'Perl', 'color' => '#0298c3'],
            'pm'         => ['name' => 'Perl', 'color' => '#0298c3'],
            'raku'       => ['name' => 'Raku', 'color' => '#0000fb'],
            'lua'        => ['name' => 'Lua', 'color' => '#000080'],
            'tcl'        => ['name' => 'Tcl', 'color' => '#e4cc98'],
            'awk'        => ['name' => 'Awk', 'color' => '#c30e9b'],
            'sed'        => ['name' => 'Sed', 'color' => '#64b970'],
            'sh'         => ['name' => 'Shell', 'color' => '#89e051'],
            'bash'       => ['name' => 'Shell', 'color' => '#89e051'],
            'zsh'        => ['name' => 'Shell', 'color' => '#89e051'],
            'fish'       => ['name' => 'Fish', 'color' => '#4aae47'],
            'ps1'        => ['name' => 'PowerShell', 'color' => '#012456'],
            'psm1'       => ['name' => 'PowerShell', 'color' => '#012456'],
            'bat'        => ['name' => 'Batchfile', 'color' => '#C1F12E'],
            'cmd'        => ['name' => 'Batchfile', 'color' => '#C1F12E'],

            // Functional Languages
            'hs'         => ['name' => 'Haskell', 'color' => '#5e5086'],
            'lhs'        => ['name' => 'Haskell', 'color' => '#5e5086'],
            'ex'         => ['name' => 'Elixir', 'color' => '#6e4a7e'],
            'exs'        => ['name' => 'Elixir', 'color' => '#6e4a7e'],
            'erl'        => ['name' => 'Erlang', 'color' => '#B83998'],
            'hrl'        => ['name' => 'Erlang', 'color' => '#B83998'],
            'ml'         => ['name' => 'OCaml', 'color' => '#3be133'],
            'mli'        => ['name' => 'OCaml', 'color' => '#3be133'],
            'fs'         => ['name' => 'F#', 'color' => '#b845fc'],
            'fsi'        => ['name' => 'F#', 'color' => '#b845fc'],
            'lisp'       => ['name' => 'Common Lisp', 'color' => '#3fb68b'],
            'lsp'        => ['name' => 'Common Lisp', 'color' => '#3fb68b'],
            'scm'        => ['name' => 'Scheme', 'color' => '#1e4aec'],
            'rkt'        => ['name' => 'Racket', 'color' => '#3c5caa'],
            'elm'        => ['name' => 'Elm', 'color' => '#60B5CC'],
            'purs'       => ['name' => 'PureScript', 'color' => '#1D222D'],
            'vhd'        => ['name' => 'VHDL', 'color' => '#49809F'],
            'vhdl'       => ['name' => 'VHDL', 'color' => '#49809F'],
            'v'          => ['name' => 'Verilog', 'color' => '#b2b7f8'],
            'sv'         => ['name' => 'SystemVerilog', 'color' => '#DAE1C2'],


        ];

        $nonCodeExts = [
            'md', 'markdown', 'rst', 'adoc', 'asciidoc', 'txt',
            'json', 'json5', 'jsonc', 'yaml', 'yml', 'toml', 'xml', 'xsd',
            'ini', 'conf', 'env', 'svg', 'png', 'jpg', 'jpeg', 'gif', 'ico', 'webp',
            'ttf', 'woff', 'woff2', 'eot', 'mp3', 'mp4',
            'dockerfile', 'containerfile', 'makefile', 'mk', 'cmake', 'gitignore',
            'gitattributes', 'gitmodules', 'license', 'pro', 'properties', 'service',
            'pdf', 'zip', 'gz', 'tar', '7z', 'rar', 'jar', 'jks', 'keystore', 'so', 'dll', 'exe', 'bin', 'pyc'
        ];

        try {
            $gitReader = new GitReader();
            $gitService= new GitService();
            $repoPath  = $gitService->getRepoPath($slug);

            $refClass = new \ReflectionClass($gitReader);
            $runGitMethod = $refClass->getMethod('runGit');
            $runGitMethod->setAccessible(true);
            $res = $runGitMethod->invoke($gitReader, $repoPath, ['ls-tree', '-r', '-l', $ref]);

            $extBytes = [];
            $nonCodeCounts = [];

            if (($res['exitCode'] ?? 1) === 0 && !empty($res['output'])) {
                $lines = explode("
", trim((string)$res['output']));
                foreach ($lines as $line) {
                    $parts = preg_split('/\s+/', $line, 5);
                    if (count($parts) < 5) continue;
                    $size = (int)$parts[3];
                    $file = $parts[4];
                    $lowerFile = strtolower($file);

                    // Skip third-party, vendored, compiled or bundled binaries and headers
                    $isVendored = false;
                    $vendorPatterns = [
                        'bin/', 'vendor/', 'node_modules/', 'dist/', 'build/', 'packages/',
                        'third_party/', 'thirdparty/', 'extern/', 'external/', '.venv/', 'env/',
                        'virtualenv/', 'cache/', 'storage/', 'bower_components/', 'site-packages/',
                        'assets/vendor/', 'public/vendor/', 'static/vendor/', 'mariadb', 'mysql',
                        'php8', 'php7', 'apache', 'nginx', 'lib/', 'libs/'
                    ];
                    foreach ($vendorPatterns as $vp) {
                        if (str_starts_with($lowerFile, $vp) || str_contains($lowerFile, '/' . $vp)) {
                            $isVendored = true;
                            break;
                        }
                    }
                    if ($isVendored) continue;

                    $ext = strtolower(pathinfo($file, PATHINFO_EXTENSION));
                    if ($ext === '' || !isset($langMap[$ext])) continue;

                    if (in_array($ext, $nonCodeExts, true)) {
                        $nonCodeCounts[$ext] = ($nonCodeCounts[$ext] ?? 0) + 1;
                    } else {
                        $extBytes[$ext] = ($extBytes[$ext] ?? 0) + $size;
                    }
                }
            }

            if (!empty($extBytes)) {
                arsort($extBytes);
                $topExt = array_key_first($extBytes);
                return $langMap[$topExt] ?? null;
            }

            if (!empty($nonCodeCounts)) {
                arsort($nonCodeCounts);
                $topExt = array_key_first($nonCodeCounts);
                return $langMap[$topExt] ?? null;
            }

            return null;
        } catch (\Throwable) {
            return null;
        }
    }
}
