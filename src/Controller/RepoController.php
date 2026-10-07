<?php

declare(strict_types=1);

namespace App\Controller;

use App\App;
use App\Auth;
use App\Service\Cache;
use App\Service\GitReader;
use App\Service\GitService;
use App\Service\MarkdownRenderer;

final class RepoController
{
    /** Seconds cached git results stay fresh (post-receive pushes refresh after TTL). */
    private const CACHE_TTL = 60;

    /** Blob content cache TTL: 5 minutes. */
    private const BLOB_CACHE_TTL = 300;

    /** Commit/diff cache TTL: permanent (1 year). A commit hash is immutable. */
    private const COMMIT_CACHE_TTL = 31536000;

    /** Blame cache TTL: 5 minutes. */
    private const BLAME_CACHE_TTL = 300;

    /** README rendered HTML TTL: 60s. */
    private const README_CACHE_TTL = 60;

    private App $app;
    private Auth $auth;
    private GitReader $gitReader;
    private GitService $gitService;
    private MarkdownRenderer $markdown;
    private Cache $cache;

    public function __construct(App $app)
    {
        $this->app        = $app;
        $this->auth       = new Auth($app);
        $this->gitReader  = new GitReader();
        $this->gitService = new GitService();
        $this->markdown   = new MarkdownRenderer();
        $this->cache      = $app->cache();

        // Browse pages only read session state; release the session lock early
        // so concurrent requests are never serialized behind it. Seed the CSRF
        // token first: star/watch forms on these pages POST it, and the token
        // must be persisted while the session is still writable.
        if (session_status() === PHP_SESSION_ACTIVE) {
            $this->auth->generateCsrf();
            session_write_close();
        }
    }

    /**
     * Write a one-time flash message, re-opening the session if the
     * constructor released it early (writes after session_write_close()
     * would otherwise be silently lost).
     */
    private function flash(string $key, string $message): void
    {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }

        $_SESSION[$key] = $message;
    }

    /** GET /{user}/{repo} — repository overview (file tree + README). */
        private function canManageRepo(array $dbRepo): bool
    {
        if ($this->auth->isOwner()) {
            return true;
        }
        if (! $this->auth->isLoggedIn()) {
            return false;
        }
        $userId = (int) $this->auth->userId();
        if ($userId > 0 && (int) ($dbRepo['owner_user_id'] ?? 0) === $userId) {
            return true;
        }
        return false;
    }

    public function show(string $user, string $repo): void
    {
        $dbRepo = $this->resolveRepo($repo);

        if ($dbRepo === null) {
            $this->notFound();
            return;
        }

        $canonicalOwner = $this->resolveOwnerName($dbRepo);
        if (strcasecmp($user, $canonicalOwner) !== 0) {
            header("Location: /{$canonicalOwner}/{$dbRepo['slug']}", true, 302);
            exit;
        }
        $user = $canonicalOwner;

        $repoPath = $this->gitService->getRepoPath($dbRepo['slug']);
        $social   = $this->socialData((int) $dbRepo['id'], (int) ($dbRepo['stars_count'] ?? 0));
        $canManage = $this->canManageRepo($dbRepo);

        // Empty repo
        if ($this->gitReader->isEmpty($repoPath)) {
            $this->app->view()->display('repo/show.twig', [
                'repo'              => $dbRepo,
                'owner'             => $user,
                'is_empty'          => true,
                'can_manage'        => $canManage,
                'can_write'         => $canManage,
                'show_settings_tab' => $canManage,
                'branches'          => [],
                'tags'              => [],
                'current_ref'       => $dbRepo['default_branch'],
                'files'             => [],
                'readme_html'       => '',
                'https_url'         => $this->httpsUrl($user, $dbRepo['slug']),
                'ssh_url'           => $this->sshUrl($user, $dbRepo['slug']),
                'social'            => $social,
                'csrf_token'        => $this->auth->generateCsrf(),
            ]);
            return;
        }

        $ref      = $dbRepo['default_branch'];
        $branches = $this->branches($repoPath, $dbRepo['slug']);
        $tags     = $this->tags($repoPath, $dbRepo['slug']);
        $files    = $this->treeWithCommits($repoPath, $dbRepo['slug'], $ref);

        // Find README (markdown variants only — rendered as sanitized HTML, cached)
        $readmeHtml = '';
        foreach ($files as $file) {
            if ($file['type'] === 'blob' && preg_match('/^readme\.(md|markdown)$/i', $file['name'])) {
                $readmeHtml = $this->readmeHtml($repoPath, $dbRepo['slug'], $ref, $file['name'], $user);
                break;
            }
        }

        $commitCount  = $this->commitCount($repoPath, $dbRepo['slug'], $ref);
        $latestCommit = $this->latestCommit($repoPath, $dbRepo['slug'], $ref);

        // Sidebar: Topics
        $topics = [];
        if (! empty($dbRepo['topics'])) {
            if (is_string($dbRepo['topics'])) {
                $decoded = json_decode($dbRepo['topics'], true);
                $topics = is_array($decoded) ? $decoded : array_filter(array_map('trim', explode(',', $dbRepo['topics'])));
            } elseif (is_array($dbRepo['topics'])) {
                $topics = $dbRepo['topics'];
            }
        }

        // Sidebar: Releases (drafts never surface in the sidebar — even for
        // writers the sidebar is a public "what's shipped" widget).
        $releasesCount = (int) ($this->app->db()->fetchOne(
            'SELECT COUNT(*) AS c FROM `repo_releases` WHERE `repo_id` = :r AND `is_draft` = 0',
            ['r' => (int) $dbRepo['id']]
        )['c'] ?? 0);

        $latestRelease = $this->app->db()->fetchOne(
            'SELECT * FROM `repo_releases` WHERE `repo_id` = :r AND `is_draft` = 0 ORDER BY `created_at` DESC LIMIT 1',
            ['r' => (int) $dbRepo['id']]
        );

        // Fallback to Git tags if no DB release exists or to sync tag counts
        if (empty($latestRelease) && !empty($tags)) {
            $firstTag = $tags[0];
            $tName = is_array($firstTag) ? (string) ($firstTag['name'] ?? '') : (string) $firstTag;
            $shortHash = is_array($firstTag) ? ($firstTag['short_hash'] ?? substr($firstTag['hash'] ?? '', 0, 7)) : '';
            $date = is_array($firstTag) ? (string) ($firstTag['date'] ?? '') : '';

            // If tag date is empty, retrieve exact commit/tag date directly from git
            if (empty($date) && !empty($tName)) {
                $tagDate = trim((string) @shell_exec("git -C " . escapeshellarg($repoPath) . " log -1 --format=%aI " . escapeshellarg($tName)));
                if (!empty($tagDate)) {
                    $date = $tagDate;
                }
            }

            if (empty($date)) {
                $date = date('Y-m-d H:i:s');
            }

            $latestRelease = [
                'id'               => null,
                'tag_name'         => $tName,
                'target_commitish' => $shortHash ?: $tName,
                'name'             => $tName,
                'created_at'       => $date,
                'published_at'     => $date,
                'is_tag_only'      => 1,
            ];
            if ($releasesCount === 0) {
                $releasesCount = count($tags);
            }
        }

        // Sidebar: Contributors (unique git commit authors)
        $contributors = $this->cache->remember("repo:{$dbRepo['slug']}:contribs", 300, function () use ($repoPath, $ref) {
            $log = $this->gitReader->getLog($repoPath, $ref, 100, 0);
            $authors = [];
            foreach ($log as $c) {
                $email = strtolower(trim((string) ($c['email'] ?? '')));
                $name  = trim((string) ($c['author'] ?? 'Contributor'));
                if ($email !== '' && ! isset($authors[$email])) {
                    $authors[$email] = [
                        'name'   => $name,
                        'email'  => $email,
                        'avatar' => 'https://www.gravatar.com/avatar/' . md5($email) . '?d=identicon&s=64',
                    ];
                }
            }
            return array_values($authors);
        });

        // Sidebar: Languages Breakdown
        $languages = $this->cache->remember("repo:{$dbRepo['slug']}:lang_breakdown_v2", 600, function () use ($dbRepo, $ref) {
            return $this->calculateLanguageBreakdown($dbRepo['slug'], $ref);
        });

        // Sidebar: License info
        $licenseInfo = ! empty($dbRepo['license']) ? $dbRepo['license'] : null;
        if (! $licenseInfo) {
            foreach ($files as $f) {
                if (preg_match('/^license(\.(md|txt))?$/i', (string)($f['name'] ?? ''))) {
                    $licenseInfo = 'View license';
                    break;
                }
            }
        }

        $this->app->view()->display('repo/show.twig', [
            'repo'           => $dbRepo,
            'owner'          => $user,
            'is_empty'       => false,
            'can_write'      => $canManage,
            'branches'       => $branches,
            'tags'           => $tags,
            'current_ref'    => $ref,
            'commit_count'   => $commitCount,
            'latest_commit'  => $latestCommit,
            'files'          => $files,
            'readme_html'    => $readmeHtml,
            'https_url'      => $this->httpsUrl($user, $dbRepo['slug']),
            'ssh_url'        => $this->sshUrl($user, $dbRepo['slug']),
            'social'         => $social,
            'topics'         => $topics,
            'releases_count' => $releasesCount,
            'latest_release' => $latestRelease ?: null,
            'contributors'   => $contributors,
            'languages'      => $languages,
            'license_info'   => $licenseInfo,
            'forks_count'    => (int) ($this->app->db()->fetchOne('SELECT COUNT(*) AS c FROM repositories WHERE forked_from_id = :id', ['id' => (int) $dbRepo['id']])['c'] ?? 0),
            'csrf_token'     => $this->auth->generateCsrf(),
        ]);
    }

    /** Calculate language percentages for repository sidebar */
    private function calculateLanguageBreakdown(string $slug, string $ref): array
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

            // Data, Config, Infrastructure & Query
            'sql'        => ['name' => 'SQL', 'color' => '#e38c00'],
            'pgsql'      => ['name' => 'PLpgSQL', 'color' => '#336790'],
            'plsql'      => ['name' => 'PLSQL', 'color' => '#dad8d8'],
            'prisma'     => ['name' => 'Prisma', 'color' => '#2D3748'],
            'graphql'    => ['name' => 'GraphQL', 'color' => '#e10098'],
            'gql'        => ['name' => 'GraphQL', 'color' => '#e10098'],
            'proto'      => ['name' => 'Protocol Buffer', 'color' => '#4f87c4'],
            'thrift'     => ['name' => 'Thrift', 'color' => '#D12127'],
            'json'       => ['name' => 'JSON', 'color' => '#292929'],
            'json5'      => ['name' => 'JSON5', 'color' => '#267CB9'],
            'jsonc'      => ['name' => 'JSON with Comments', 'color' => '#267CB9'],
            'yaml'       => ['name' => 'YAML', 'color' => '#cb171e'],
            'yml'        => ['name' => 'YAML', 'color' => '#cb171e'],
            'toml'       => ['name' => 'TOML', 'color' => '#9c4221'],
            'xml'        => ['name' => 'XML', 'color' => '#0060ac'],
            'xsd'        => ['name' => 'XML', 'color' => '#0060ac'],
            'svg'        => ['name' => 'SVG', 'color' => '#ff9900'],
            'ini'        => ['name' => 'INI', 'color' => '#d1dbe0'],
            'conf'       => ['name' => 'Configuration', 'color' => '#6d8086'],
            'env'        => ['name' => 'Dotenv', 'color' => '#e5cd52'],
            'tf'         => ['name' => 'HCL (Terraform)', 'color' => '#844FBA'],
            'tfvars'     => ['name' => 'HCL', 'color' => '#844FBA'],
            'hcl'        => ['name' => 'HCL', 'color' => '#844FBA'],
            'dockerfile' => ['name' => 'Dockerfile', 'color' => '#384d54'],
            'containerfile'=> ['name' => 'Dockerfile', 'color' => '#384d54'],
            'sol'        => ['name' => 'Solidity', 'color' => '#AA6746'],
            'nix'        => ['name' => 'Nix', 'color' => '#7e7eff'],
            'makefile'   => ['name' => 'Makefile', 'color' => '#427819'],
            'mk'         => ['name' => 'Makefile', 'color' => '#427819'],
            'cmake'      => ['name' => 'CMake', 'color' => '#DA3434'],
            'gradle'     => ['name' => 'Gradle', 'color' => '#02303a'],
            'pas'        => ['name' => 'Pascal', 'color' => '#E3F171'],
            'pp'         => ['name' => 'Puppet', 'color' => '#302B6D'],
            'tex'        => ['name' => 'TeX / LaTeX', 'color' => '#3D6117'],
            'sty'        => ['name' => 'TeX', 'color' => '#3D6117'],
            'md'         => ['name' => 'Markdown', 'color' => '#083fa1'],
            'markdown'   => ['name' => 'Markdown', 'color' => '#083fa1'],
            'rst'        => ['name' => 'reStructuredText', 'color' => '#141414'],
            'asciidoc'   => ['name' => 'AsciiDoc', 'color' => '#73a0c5'],
            'adoc'       => ['name' => 'AsciiDoc', 'color' => '#73a0c5'],
        ];

        // Exact-filename map for files with no meaningful extension
        $filenameMap = [
            'dockerfile'       => ['name' => 'Dockerfile',  'color' => '#384d54'],
            'containerfile'    => ['name' => 'Dockerfile',  'color' => '#384d54'],
            'makefile'         => ['name' => 'Makefile',    'color' => '#427819'],
            'gnumakefile'      => ['name' => 'Makefile',    'color' => '#427819'],
            'rakefile'         => ['name' => 'Ruby',        'color' => '#701516'],
            'gemfile'          => ['name' => 'Ruby',        'color' => '#701516'],
            'guardfile'        => ['name' => 'Ruby',        'color' => '#701516'],
            'vagrantfile'      => ['name' => 'Ruby',        'color' => '#701516'],
            'gruntfile'        => ['name' => 'JavaScript',  'color' => '#f1e05a'],
            'gulpfile'         => ['name' => 'JavaScript',  'color' => '#f1e05a'],
            'jenkinsfile'      => ['name' => 'Groovy',      'color' => '#4298b8'],
            'cmakelists.txt'   => ['name' => 'CMake',       'color' => '#DA3434'],
            'gradlew'          => ['name' => 'Shell',       'color' => '#89e051'],
        ];

        try {
            $repoPath = $this->gitService->getRepoPath($slug);
            $proc = new \Symfony\Component\Process\Process(
                ['git', 'ls-tree', '-r', '-l', '--full-tree', $ref],
                $repoPath,
            );
            $proc->setTimeout(20);
            $proc->run();
            $lines = $proc->isSuccessful()
                ? array_filter(array_map('trim', explode("\n", $proc->getOutput())))
                : [];

            $bytesByLang = [];
            $totalBytes  = 0;

            foreach ($lines as $line) {
                if (! preg_match('/^\d+\s+blob\s+[a-f0-9]+\s+(\d+|-)\s+(.+)$/i', trim((string)$line), $m)) {
                    continue;
                }
                $size = (int) ($m[1] === '-' ? 0 : $m[1]);
                $path = (string) $m[2];

                if (preg_match('#(^|/)(\.git|vendor|node_modules|dist|build|\.gradle|Pods|target|bin|obj)/#i', $path)) {
                    continue;
                }
                $filename = basename($path);
                if (in_array($filename, ['package-lock.json', 'composer.lock', 'yarn.lock', 'pnpm-lock.yaml', 'Cargo.lock'], true)) {
                    continue;
                }

                $filenameLower = strtolower($filename);
                $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));

                // Exclude prose / documentation / markdown files from language stats (GitHub Linguist standard)
                if (in_array($ext, ['md', 'markdown', 'rst', 'adoc', 'asciidoc', 'txt', 'textile', 'tex', 'sty', 'pdf'], true)
                    || in_array($filenameLower, ['readme', 'readme.md', 'readme.txt', 'license', 'license.md', 'license.txt', 'authors', 'changelog', 'copying', 'notice'], true)) {
                    continue;
                }

                // Prefer exact filename match first (Dockerfile, Makefile, etc.)
                if (isset($filenameMap[$filenameLower])) {
                    $langName = $filenameMap[$filenameLower]['name'];
                    $bytesByLang[$langName] = ($bytesByLang[$langName] ?? 0) + $size;
                    $totalBytes += $size;
                } elseif ($ext !== '' && isset($langMap[$ext])) {
                    $langName = $langMap[$ext]['name'];
                    $bytesByLang[$langName] = ($bytesByLang[$langName] ?? 0) + $size;
                    $totalBytes += $size;
                }
            }

            if ($totalBytes === 0) return [];

            arsort($bytesByLang);
            $result = [];
            $otherBytes = 0;

            foreach ($bytesByLang as $name => $b) {
                $pct = round(($b / $totalBytes) * 100, 1);
                if ($pct < 0.5) {
                    $otherBytes += $b;
                    continue;
                }
                $color = '#58a6ff';
                foreach ($langMap as $lm) {
                    if ($lm['name'] === $name) {
                        $color = $lm['color'];
                        break;
                    }
                }
                $result[] = [
                    'name'       => $name,
                    'color'      => $color,
                    'percentage' => $pct,
                ];
            }

            if ($otherBytes > 0) {
                $otherPct = round(($otherBytes / $totalBytes) * 100, 1);
                if ($otherPct >= 0.1) {
                    $result[] = [
                        'name'       => 'Other',
                        'color'      => '#8b949e',
                        'percentage' => $otherPct,
                    ];
                }
            }

            return $result;
        } catch (\Throwable) {
            return [];
        }
    }

    /** GET /{user}/{repo}/languages[?lang=X] — GitHub-style language breakdown. */
    public function languages(string $user, string $repo): void
    {
        $dbRepo = $this->resolveRepo($repo);
        if ($dbRepo === null) { $this->notFound(); return; }

        $repoPath = $this->gitService->getRepoPath($dbRepo['slug']);
        $branches = $this->branches($repoPath, $dbRepo['slug']);
        $ref = ($_GET['ref'] ?? '') !== '' ? (string) $_GET['ref'] : ($dbRepo['default_branch'] ?? 'main');
        $lang = trim((string) ($_GET['lang'] ?? ''));

        $overview = $this->languagesOverview($dbRepo['slug'], $ref);

        $activeLang = null;
        $files      = [];
        $totalLines = 0;
        if ($lang !== '') {
            foreach ($overview as $o) {
                if (strcasecmp((string) $o['name'], $lang) === 0) { $activeLang = $o; break; }
            }
            if ($activeLang !== null) {
                $detail      = $this->languageFiles($dbRepo['slug'], $ref, (string) $activeLang['name']);
                $files       = $detail['files'];
                $totalLines  = $detail['total_lines'];
            }
        }

        $this->app->view()->display('repo/languages.twig', [
            'repo'        => $dbRepo,
            'owner'       => $user,
            'current_ref' => $ref,
            'branches'    => $branches,
            'languages'   => $overview,
            'active_lang' => $activeLang,
            'files'       => $files,
            'total_lines' => $totalLines,
            'page_title'  => 'Languages',
        ]);
    }

    /** Cheap per-language file counts + byte proportions (no blob reads). */
    private function languagesOverview(string $slug, string $ref): array
    {
        return $this->cache->remember("repo:{$slug}:langs_overview_v2:{$ref}", 600, function () use ($slug, $ref): array {
            $repoPath = $this->gitService->getRepoPath($slug);

            $proc = new \Symfony\Component\Process\Process(
                ['git', 'ls-tree', '-r', '-l', '--full-tree', $ref],
                $repoPath,
            );
            $proc->setTimeout(20);
            $proc->run();
            $rows = $proc->isSuccessful()
                ? array_filter(array_map('trim', explode("\n", $proc->getOutput())))
                : [];

            $byLang   = [];
            $totalBytes = 0;
            foreach ($rows as $line) {
                if (! preg_match('/^\d+\s+blob\s+[a-f0-9]+\s+(\d+|-)\s+(.+)$/i', trim((string) $line), $m)) continue;
                $size = (int) ($m[1] === '-' ? 0 : $m[1]);
                $path = (string) $m[2];
                if (preg_match('#(^|/)(\.git|vendor|node_modules|dist|build|\.gradle|Pods|target|bin|obj)/#i', $path)) continue;

                $name = $this->mapFilePathToLanguage($path);
                if ($name === '') continue;
                $byLang[$name]['bytes']  = ($byLang[$name]['bytes'] ?? 0) + $size;
                $byLang[$name]['files']  = ($byLang[$name]['files'] ?? 0) + 1;
                $totalBytes += $size;
            }

            if ($totalBytes === 0) return [];
            uasort($byLang, static fn (array $a, array $b): int => $b['bytes'] <=> $a['bytes']);

            $out = [];
            foreach ($byLang as $name => $d) {
                $out[] = [
                    'name'       => $name,
                    'color'      => $this->languageColor($name),
                    'percentage' => round($d['bytes'] / $totalBytes * 100, 1),
                    'file_count' => $d['files'],
                    'bytes'      => $d['bytes'],
                ];
            }
            return $out;
        });
    }

    /** For one language: the list of files with their line counts. */
    private function languageFiles(string $slug, string $ref, string $lang): array
    {
        return $this->cache->remember("repo:{$slug}:langs_files:{$ref}:" . md5($lang), 600, function () use ($slug, $ref, $lang): array {
            $repoPath = $this->gitService->getRepoPath($slug);

            // Argument-array Process call (never shell strings) — matches
            // the platform-wide "proc_open only" security claim.
            $proc = new \Symfony\Component\Process\Process(
                ['git', 'ls-tree', '-r', '-l', '--full-tree', $ref],
                $repoPath,
            );
            $proc->setTimeout(20);
            $proc->run();
            $rows = $proc->isSuccessful()
                ? array_filter(array_map('trim', explode("\n", $proc->getOutput())))
                : [];

            $files = [];
            $totalLines = 0;
            foreach ($rows as $line) {
                if (! preg_match('/^\d+\s+blob\s+[a-f0-9]+\s+(\d+|-)\s+(.+)$/i', trim((string) $line), $m)) continue;
                $size = (int) ($m[1] === '-' ? 0 : $m[1]);
                $path = (string) $m[2];
                if (preg_match('#(^|/)(\.git|vendor|node_modules|dist|build|\.gradle|Pods|target|bin|obj)/#i', $path)) continue;
                if ($this->mapFilePathToLanguage($path) !== $lang) continue;

                // Count lines from blob content (skip very large / binary-ish files).
                $lines = 0;
                if ($size <= 1024 * 1024) {
                    $content = $this->gitReader->getBlob($repoPath, $ref, $path);
                    if ($content !== null) {
                        $lines = trim($content) === '' ? 0 : substr_count($content, "\n") + 1;
                    }
                }
                $totalLines += $lines;
                $files[] = ['path' => $path, 'lines' => $lines, 'size' => $size];
            }

            usort($files, static fn (array $a, array $b): int => $b['lines'] <=> $a['lines']);
            return ['files' => $files, 'total_lines' => $totalLines];
        });
    }

    /** Resolve a repo file path to a GitHub-style language name. */
    private function mapFilePathToLanguage(string $path): string
    {
        $name = basename($path);
        $lower = strtolower($name);

        $special = [
            'dockerfile' => 'Dockerfile', 'makefile' => 'Makefile', 'cmakelists.txt' => 'CMake',
            'gradlew' => 'Shell', 'jenkinsfile' => 'Groovy',
        ];
        if (isset($special[$lower])) return $special[$lower];

        $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
        if ($ext === '' || in_array($ext, ['md', 'markdown', 'rst', 'adoc', 'asciidoc', 'txt', 'textile', 'tex', 'sty', 'pdf'], true)) return '';
        if (isset(self::LANG_EXT[$ext]) && !in_array(self::LANG_EXT[$ext], ['Markdown', 'Text', 'AsciiDoc', 'reStructuredText', 'Textile'], true)) {
            return self::LANG_EXT[$ext];
        }
        return '';
    }

    private function languageColor(string $name): string
    {
        return self::LANG_COLORS[$name] ?? '#8b949e';
    }

    /** Common extension → language name (must mirror GitHub's colour palette). */
    private const LANG_EXT = [
        // Shell
        'sh' => 'Shell', 'bash' => 'Shell', 'zsh' => 'Shell', 'fish' => 'Shell', 'bat' => 'Batchfile', 'ps1' => 'PowerShell',
        // Web / markup
        'html' => 'HTML', 'htm' => 'HTML', 'xhtml' => 'HTML', 'css' => 'CSS', 'scss' => 'SCSS', 'less' => 'Less', 'sass' => 'Sass',
        'js' => 'JavaScript', 'mjs' => 'JavaScript', 'cjs' => 'JavaScript', 'jsx' => 'JavaScript', 'vue' => 'Vue', 'svelte' => 'Svelte',
        'ts' => 'TypeScript', 'tsx' => 'TypeScript', 'php' => 'PHP', 'phtml' => 'PHP', 'ctp' => 'PHP',
        'md' => 'Markdown', 'markdown' => 'Markdown', 'rst' => 'reStructuredText', 'adoc' => 'AsciiDoc', 'textile' => 'Textile',
        'twig' => 'Twig', 'tpl' => 'Smarty', 'xml' => 'XML', 'svg' => 'SVG', 'yml' => 'YAML', 'yaml' => 'YAML', 'toml' => 'TOML', 'json' => 'JSON', 'ini' => 'INI', 'conf' => 'Config', 'env' => 'Dotenv',
        // Languages
        'py' => 'Python', 'rb' => 'Ruby', 'go' => 'Go', 'rs' => 'Rust', 'java' => 'Java', 'kt' => 'Kotlin', 'kts' => 'Kotlin',
        'swift' => 'Swift', 'c' => 'C', 'h' => 'C', 'cpp' => 'C++', 'cc' => 'C++', 'cxx' => 'C++', 'hpp' => 'C++', 'cs' => 'C#',
        'm' => 'Objective-C', 'mm' => 'Objective-C++', 'sql' => 'T-SQL', 'pl' => 'Perl', 'lua' => 'Lua', 'r' => 'R',
        'dart' => 'Dart', 'ex' => 'Elixir', 'exs' => 'Elixir', 'erl' => 'Erlang', 'clj' => 'Clojure', 'cls' => 'VBA',
        'scala' => 'Scala', 'groovy' => 'Groovy', 'gradle' => 'Groovy', 'zig' => 'Zig', 'nim' => 'Nim', 'fs' => 'F#', 'fsx' => 'F#',
        // Config / misc
        'txt' => 'Text', 'log' => 'Text', 'csv' => 'CSV', 'tsv' => 'TSV', 'ipynb' => 'Jupyter Notebook',
    ];

    /** GitHub-style language colours. */
    private const LANG_COLORS = [
        'Shell' => '#89e051', 'HTML' => '#e34c26', 'CSS' => '#563d7c', 'SCSS' => '#c6538c', 'Less' => '#1d365d',
        'Sass' => '#a53b70', 'JavaScript' => '#f1e05a', 'TypeScript' => '#3178c6', 'Vue' => '#41b883', 'Svelte' => '#ff3e00',
        'PHP' => '#4F5D95', 'Markdown' => '#083fa1', 'Python' => '#3572A5', 'Ruby' => '#701516', 'Go' => '#00ADD8',
        'Rust' => '#dea584', 'Java' => '#b07219', 'Kotlin' => '#F18E33', 'Swift' => '#F05138', 'C' => '#555555',
        'C++' => '#f34b7d', 'C#' => '#178600', 'Objective-C' => '#438eff', 'Objective-C++' => '#6866fb', 'T-SQL' => '#e38c00',
        'Perl' => '#0298c3', 'Lua' => '#000080', 'R' => '#198CE7', 'Dart' => '#00B4AB', 'Elixir' => '#6e4a7e',
        'Erlang' => '#B83998', 'Clojure' => '#db5855', 'VBA' => '#867db1', 'Scala' => '#c22d40', 'Groovy' => '#4298b8',
        'Zig' => '#ec915c', 'Nim' => '#ffc200', 'F#' => '#b845fc', 'Dockerfile' => '#384d54', 'Makefile' => '#427819',
        'CMake' => '#DA3434', 'XML' => '#0060ac', 'SVG' => '#ff9900', 'YAML' => '#cb171e', 'TOML' => '#9c4221',
        'JSON' => '#292929', 'INI' => '#d1dbe0', 'Config' => '#6a737d', 'Text' => '#8b949e', 'CSV' => '#237346',
        'TSV' => '#237346', 'Twig' => '#c1d026', 'Smarty' => '#f0c040', 'Batchfile' => '#C1F12E', 'PowerShell' => '#012456',
        'AsciiDoc' => '#73a0c5', 'reStructuredText' => '#141414', 'Textile' => '#ffe7ac', 'Groovy' => '#4298b8',
        'Dotenv' => '#e5d559', 'Jupyter Notebook' => '#DA5B0B',
    ];

    /** GET /{user}/{repo}/tree/{ref}[/{path}] — file/directory listing. */
    public function tree(string $user, string $repo, string $ref, string $path = ''): void
    {
        $dbRepo = $this->resolveRepo($repo);

        if ($dbRepo === null) {
            $this->notFound();
            return;
        }

        $repoPath = $this->gitService->getRepoPath($dbRepo['slug']);
        $branches = $this->branches($repoPath, $dbRepo['slug']);
        $tags     = $this->tags($repoPath, $dbRepo['slug']);
        $files    = $this->treeWithCommits($repoPath, $dbRepo['slug'], $ref, $path);

        $commitCount  = $this->commitCount($repoPath, $dbRepo['slug'], $ref);
        $latestCommit = $this->latestCommit($repoPath, $dbRepo['slug'], $ref);

        $segments = $path !== '' ? explode('/', $path) : [];

        $this->app->view()->display('repo/tree.twig', [
            'repo'          => $dbRepo,
            'owner'         => $user,
            'current_ref'   => $ref,
            'current_path'  => $path,
            'commit_count'  => $commitCount,
            'latest_commit' => $latestCommit,
            'segments'      => $segments,
            'branches'      => $branches,
            'tags'          => $tags,
            'files'         => $files,
            'base_url'      => "/{$user}/{$dbRepo['slug']}/tree/{$ref}",
            'can_write'     => $this->auth->isOwner() || ($this->auth->isLoggedIn() && $this->auth->canWriteRepo((int) $dbRepo['id'])),
            'csrf_token'    => $this->auth->generateCsrf(),
        ]);
    }

    /** GET /{user}/{repo}/blob/{ref}/{path} — display a single file. */
    public function blob(string $user, string $repo, string $ref, string $path): void
    {
        $dbRepo = $this->resolveRepo($repo);

        if ($dbRepo === null) {
            $this->notFound();
            return;
        }

        $repoPath = $this->gitService->getRepoPath($dbRepo['slug']);
        $branches = $this->branches($repoPath, $dbRepo['slug']);
        $tags     = $this->tags($repoPath, $dbRepo['slug']);
        $size     = $this->blobSize($repoPath, $dbRepo['slug'], $ref, $path);

        $segments = explode('/', $path);
        $tooLarge = $size > 1024 * 1024; // 1 MB

        // Use cached blob content (keyed by repo:ref:path — safe to cache for BLOB_CACHE_TTL)
        $content  = null;
        if (!$tooLarge) $content = $this->blobContent($repoPath, $dbRepo['slug'], $ref, $path);

        $canWrite = $this->auth->isOwner() || ($this->auth->isLoggedIn() && $this->auth->canWriteRepo((int) $dbRepo['id']));

        // Render Markdown files to sanitized HTML for the rich preview.
        $isMarkdown   = (bool) preg_match('/\.(md|markdown)$/i', $path);
        $markdownHtml = '';
        if ($isMarkdown && $content !== null && ! $tooLarge) {
            $rawBase      = "/{$user}/{$dbRepo['slug']}/raw/{$ref}";
            $blobBase     = "/{$user}/{$dbRepo['slug']}/blob/{$ref}";
            $markdownHtml = $this->markdown->renderHtml($content, $rawBase, $blobBase);
        }

        $this->app->view()->display('repo/blob.twig', [
            'repo'         => $dbRepo,
            'owner'        => $user,
            'current_ref'  => $ref,
            'current_path' => $path,
            'segments'     => $segments,
            'branches'     => $branches,
            'tags'         => $tags,
            'file_size'    => $size,
            'too_large'    => $tooLarge,
            'content'      => $content,
            'is_markdown'  => $isMarkdown,
            'markdown_html' => $markdownHtml,
            'can_write'    => $canWrite,
            'base_url'     => "/{$user}/{$dbRepo['slug']}/blob/{$ref}",
            'raw_url'      => "/{$user}/{$dbRepo['slug']}/raw/{$ref}/{$path}",
            'csrf_token'   => $this->auth->generateCsrf(),
        ]);
    }

    /** GET /{user}/{repo}/new[/{ref}] — web file create form */
    public function createFile(string $user, string $repo, string $ref = ''): void
    {
        $this->auth->requireAuth();
        $dbRepo = $this->resolveRepo($repo);
        if ($dbRepo === null) {
            $this->notFound();
            return;
        }

        if ($this->auth->isReadOnly() || $this->auth->isBot()) {
            $this->flash('flash_error', 'Your account is restricted from creating files.');
            header("Location: /{$user}/{$dbRepo['slug']}");
            exit;
        }

        $canWrite = $this->auth->isOwner() || $this->auth->canWriteRepo((int) $dbRepo['id']);
        if (! $canWrite) {
            $this->flash('flash_error', 'You do not have write access to this repository.');
            header("Location: /{$user}/{$dbRepo['slug']}");
            exit;
        }

        $repoPath    = $this->gitService->getRepoPath($dbRepo['slug']);
        $branches    = $this->branches($repoPath, $dbRepo['slug']);
        $currentRef  = $ref !== '' ? $ref : ($dbRepo['default_branch'] ?? 'main');

        $this->app->view()->display('repo/file-edit.twig', [
            'repo'        => $dbRepo,
            'owner'       => $user,
            'current_ref' => $currentRef,
            'branches'    => $branches,
            'file_path'   => (string) ($_GET['filename'] ?? ''),
            'content'     => '',
            'mode'        => 'create',
            'csrf_token'  => $this->auth->generateCsrf(),
        ]);
    }

    /** GET /{user}/{repo}/edit/{ref}/{path} — web file editor form */
    public function editFile(string $user, string $repo, string $ref, string $path): void
    {
        $this->auth->requireAuth();
        $dbRepo = $this->resolveRepo($repo);
        if ($dbRepo === null) {
            $this->notFound();
            return;
        }

        $canWrite = $this->auth->isOwner() || $this->auth->canWriteRepo((int) $dbRepo['id']);
        if (! $canWrite) {
            $this->flash('flash_error', 'You do not have write access to this repository.');
            header("Location: /{$user}/{$dbRepo['slug']}/blob/{$ref}/{$path}");
            exit;
        }

        $repoPath = $this->gitService->getRepoPath($dbRepo['slug']);
        $branches = $this->branches($repoPath, $dbRepo['slug']);
        $content  = $this->gitReader->getBlob($repoPath, $ref, $path) ?? '';

        $this->app->view()->display('repo/file-edit.twig', [
            'repo'        => $dbRepo,
            'owner'       => $user,
            'current_ref' => $ref,
            'branches'    => $branches,
            'file_path'   => $path,
            'content'     => $content,
            'mode'        => 'edit',
            'csrf_token'  => $this->auth->generateCsrf(),
        ]);
    }

    /** POST /{user}/{repo}/file/save — handle web file save / commit */
    public function saveFile(string $user, string $repo): void
    {
        $this->auth->requireAuth();
        $dbRepo = $this->resolveRepo($repo);
        if ($dbRepo === null) {
            $this->notFound();
            return;
        }

        if (! $this->auth->validateCsrf((string) ($_POST['csrf_token'] ?? ''))) {
            $this->flash('flash_error', 'Invalid security token.');
            header("Location: /{$user}/{$dbRepo['slug']}");
            exit;
        }

        $canWrite = $this->auth->isOwner() || $this->auth->canWriteRepo((int) $dbRepo['id']);
        if (! $canWrite) {
            $this->flash('flash_error', 'You do not have write access to this repository.');
            header("Location: /{$user}/{$dbRepo['slug']}");
            exit;
        }

        if ($this->auth->isReadOnly() || $this->auth->isBot()) {
            $this->flash('flash_error', 'Your account is in Read-Only mode. File deletion is blocked.');
            header("Location: /{$user}/{$dbRepo['slug']}");
            exit;
        }

        $branch   = trim((string) ($_POST['branch'] ?? $dbRepo['default_branch'] ?? 'main'));
        $filePath = trim((string) ($_POST['file_path'] ?? ''));
        $content  = (string) ($_POST['content'] ?? '');
        $commitMsg= trim((string) ($_POST['commit_message'] ?? ''));

        if ($filePath === '') {
            $this->flash('flash_error', 'File name / path cannot be empty.');
            header("Location: /{$user}/{$dbRepo['slug']}");
            exit;
        }

        if ($this->auth->isReadOnly() || $this->auth->isBot()) {
            $this->flash('flash_error', 'Your account is in Read-Only mode. File commits are blocked.');
            header("Location: /{$user}/{$dbRepo['slug']}");
            exit;
        }

        // Check single repo quota
        $maxRepoMb = (int) ($this->app->db()->fetchOne("SELECT value FROM system_settings WHERE key_name = 'repo_max_size_mb'")['value'] ?? 2048);
        if ($maxRepoMb > 0) {
            $repoPath = $this->gitService->getRepoPath($dbRepo['slug']);
            $currRepoSize = 0;
            if (is_dir($repoPath)) {
                try {
                    $flags = \FilesystemIterator::SKIP_DOTS | \FilesystemIterator::CURRENT_AS_FILEINFO;
                    $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($repoPath, $flags));
                    foreach ($it as $f) { if ($f->isFile()) $currRepoSize += $f->getSize(); }
                } catch (\Throwable) {}
            }
            if ($currRepoSize >= ($maxRepoMb * 1024 * 1024)) {
                $this->flash('flash_error', "Repository size limit exceeded ({$maxRepoMb} MB). File save rejected.");
                header("Location: /{$user}/{$dbRepo['slug']}");
                exit;
            }
        }

        $authorName  = $this->auth->displayName();
        $authorEmail = $this->auth->isOwner() ? 'owner@localhost' : ($this->auth->user()['email'] ?? 'user@localhost');

        $result = $this->gitService->saveFile(
            $dbRepo['slug'],
            $branch,
            $filePath,
            $content,
            $commitMsg,
            $authorName,
            $authorEmail,
        );

        if (! $result['ok']) {
            $this->flash('flash_error', 'Failed to save file: ' . ($result['error'] ?? 'Unknown error'));
            header("Location: /{$user}/{$dbRepo['slug']}");
            exit;
        }

        // Invalidate tree caches
        $this->cache->forget("repo:{$dbRepo['slug']}:tree:{$branch}:ROOT");
        $this->cache->forget("repo:{$dbRepo['slug']}:commits:{$branch}:1");
        $this->cache->forget("repo:{$dbRepo['slug']}:count:{$branch}");

        $this->flash('flash_success', "File '{$filePath}' committed successfully.");
        header("Location: /{$user}/{$dbRepo['slug']}/blob/{$branch}/{$filePath}");
        exit;
    }

    /** POST /{user}/{repo}/file/delete — remove a file via a revertible commit. */
    public function deleteFile(string $user, string $repo): void
    {
        $this->auth->requireAuth();
        $dbRepo = $this->resolveRepo($repo);
        if ($dbRepo === null) {
            $this->notFound();
            return;
        }

        if (! $this->auth->validateCsrf((string) ($_POST['csrf_token'] ?? ''))) {
            $this->flash('flash_error', 'Invalid security token.');
            header("Location: /{$user}/{$dbRepo['slug']}");
            exit;
        }

        $canWrite = $this->auth->isOwner() || $this->auth->canWriteRepo((int) $dbRepo['id']);
        if (! $canWrite) {
            $this->flash('flash_error', 'You do not have write access to this repository.');
            header("Location: /{$user}/{$dbRepo['slug']}");
            exit;
        }

        $branch   = trim((string) ($_POST['branch'] ?? $dbRepo['default_branch'] ?? 'main'));
        $filePath = ltrim(str_replace('\\', '/', trim((string) ($_POST['file_path'] ?? ''))), '/');

        if ($filePath === '' || str_contains($filePath, '..')) {
            $this->flash('flash_error', 'Invalid file path.');
            header("Location: /{$user}/{$dbRepo['slug']}/tree/{$branch}");
            exit;
        }

        // Respect branch protection rules — same policy as branch deletion.
        $db = $this->app->db()->connection();
        $protStmt = $db->prepare('SELECT prevent_delete FROM branch_protections WHERE repo_id = ? AND branch_name = ? LIMIT 1');
        $protStmt->execute([(int) $dbRepo['id'], $branch]);
        $prot = $protStmt->fetch();
        if ($prot && ! empty($prot['prevent_delete'])) {
            $this->flash('flash_error', "Branch '{$branch}' is protected — file deletion is not allowed on it.");
            header("Location: /{$user}/{$dbRepo['slug']}/blob/{$branch}/{$filePath}");
            exit;
        }

        $authorName  = $this->auth->displayName();
        $authorEmail = $this->auth->isOwner() ? 'owner@localhost' : ($this->auth->user()['email'] ?? 'user@localhost');
        $commitMsg   = trim((string) ($_POST['commit_message'] ?? ''));

        $result = $this->gitService->deleteFile(
            $dbRepo['slug'],
            $branch,
            $filePath,
            $commitMsg,
            $authorName,
            $authorEmail,
        );

        if (! $result['ok']) {
            $this->flash('flash_error', 'Failed to delete file: ' . ($result['error'] ?? 'Unknown error'));
            header("Location: /{$user}/{$dbRepo['slug']}/blob/{$branch}/{$filePath}");
            exit;
        }

        // Invalidate caches for the affected tree level and history page
        $parentDir = str_contains($filePath, '/') ? dirname($filePath) : 'ROOT';
        $this->cache->forget("repo:{$dbRepo['slug']}:tree:{$branch}:ROOT");
        $this->cache->forget("repo:{$dbRepo['slug']}:tree:{$branch}:{$parentDir}");
        $this->cache->forget("repo:{$dbRepo['slug']}:commits:{$branch}:1");
        $this->cache->forget("repo:{$dbRepo['slug']}:count:{$branch}");

        $audit = new \App\Service\AuditLogger($this->app);
        $currUser = $this->auth->user();
        $userId   = $this->auth->isOwner() ? 0 : (int) ($currUser['id'] ?? 0);
        $userName = $this->auth->isOwner() ? 'owner' : (string) ($currUser['username'] ?? 'user');
        $audit->log('file.delete', (int) $dbRepo['id'], "Deleted {$filePath} on {$branch} ({$result['commit_sha']})", $userId, $userName);

        $this->flash('flash_success', "File '{$filePath}' removed from '{$branch}'. It stays recoverable from history.");
        $backTo = str_contains($filePath, '/') ? "/{$user}/{$dbRepo['slug']}/tree/{$branch}/" . dirname($filePath) : "/{$user}/{$dbRepo['slug']}/tree/{$branch}";
        header("Location: {$backTo}");
        exit;
    }

    /** GET /{user}/{repo}.rss or /{user}/{repo}/rss — standard RSS 2.0 XML feed of recent commits. */
    public function rss(string $user, string $repo): void
    {
        $dbRepo = $this->resolveRepo($repo);
        if ($dbRepo === null) {
            $this->notFound();
            return;
        }

        // If private, require authentication & access
        if (($dbRepo['visibility'] ?? 'public') === 'private') {
            $this->auth->requireAuth();
            $currUserId = $this->auth->isOwner() ? 0 : (int) $this->auth->userId();
            if ($currUserId > 0 && (int) ($dbRepo['owner_user_id'] ?? 0) !== $currUserId) {
                $isCollab = $this->app->db()->fetchOne(
                    'SELECT id FROM repo_collaborators WHERE repo_id = :r AND user_id = :u LIMIT 1',
                    ['r' => (int) $dbRepo['id'], 'u' => $currUserId]
                );
                if (! $isCollab) {
                    $this->notFound();
                    return;
                }
            }
        }

        $repoPath = $this->gitService->getRepoPath($dbRepo['slug']);
        $branch   = (string) ($dbRepo['default_branch'] ?? 'main');
        $commits  = $this->cache->remember(
            "repo:{$dbRepo['slug']}:rss_log",
            60,
            fn() => $this->gitReader->getLog($repoPath, $branch, 25, 0),
        );

        $appUrl   = rtrim((string) $this->app->config('app.url', 'https://git.ysnapp.com'), '/');
        $repoUrl  = "{$appUrl}/{$user}/{$dbRepo['slug']}";
        $feedDate = ! empty($commits[0]['date']) ? date(DATE_RSS, strtotime((string)$commits[0]['date'])) : date(DATE_RSS);

        header('Content-Type: application/rss+xml; charset=utf-8');
        header('X-Content-Type-Options: nosniff');

        echo '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
        echo '<rss version="2.0" xmlns:atom="http://www.w3.org/2005/Atom">' . "\n";
        echo '  <channel>' . "\n";
        echo '    <title>' . htmlspecialchars("{$user}/{$dbRepo['name']} Commits") . '</title>' . "\n";
        echo '    <link>' . htmlspecialchars($repoUrl) . '</link>' . "\n";
        echo '    <description>' . htmlspecialchars("Recent commits to {$user}/{$dbRepo['name']} ({$branch})") . '</description>' . "\n";
        echo '    <language>en-us</language>' . "\n";
        echo '    <pubDate>' . $feedDate . '</pubDate>' . "\n";
        echo '    <atom:link href="' . htmlspecialchars("{$repoUrl}.rss") . '" rel="self" type="application/rss+xml"/>' . "\n";

        foreach ($commits as $c) {
            $hash = (string) ($c['hash'] ?? $c['sha'] ?? '');
            $cUrl = "{$repoUrl}/commit/{$hash}";
            $msg  = (string) ($c['message'] ?? $c['subject'] ?? 'Commit ' . substr($hash, 0, 7));
            $lines = explode("\n", trim($msg));
            $subject = $lines[0];
            $author  = (string) ($c['author'] ?? $c['author_name'] ?? 'Author');
            $cDate   = ! empty($c['date']) ? date(DATE_RSS, strtotime((string)$c['date'])) : date(DATE_RSS);

            echo '    <item>' . "\n";
            echo '      <title>' . htmlspecialchars($subject) . '</title>' . "\n";
            echo '      <link>' . htmlspecialchars($cUrl) . '</link>' . "\n";
            echo '      <guid isPermaLink="true">' . htmlspecialchars($cUrl) . '</guid>' . "\n";
            echo '      <pubDate>' . $cDate . '</pubDate>' . "\n";
            echo '      <author>' . htmlspecialchars($author) . '</author>' . "\n";
            echo '      <description><![CDATA[' . nl2br(htmlspecialchars($msg)) . ']]></description>' . "\n";
            echo '    </item>' . "\n";
        }

        echo '  </channel>' . "\n";
        echo '</rss>' . "\n";
        exit;
    }

    /** GET /{user}/{repo}/commits[/{ref}] — paginated commit log. */
    public function commits(string $user, string $repo, ?string $ref = null): void
    {
        $dbRepo = $this->resolveRepo($repo);

        if ($dbRepo === null) {
            $this->notFound();
            return;
        }

        $repoPath = $this->gitService->getRepoPath($dbRepo['slug']);
        $branches = $this->branches($repoPath, $dbRepo['slug']);
        $tags     = $this->tags($repoPath, $dbRepo['slug']);

        $ref = ($ref !== null && $ref !== '') ? $ref : $dbRepo['default_branch'];
        $ref = (string) preg_replace('#^(branch|tag)/#', '', $ref);

        $perPage = 50;
        $page    = max(1, (int) ($_GET['page'] ?? 1));
        $offset  = ($page - 1) * $perPage;

        // Fetch one extra to know if there's a next page (cached per page/ref)
        $cacheKey = "repo:{$dbRepo['slug']}:log:{$ref}:p{$page}";
        $commits  = $this->cache->remember(
            $cacheKey,
            self::CACHE_TTL,
            fn() => $this->gitReader->getLog($repoPath, $ref, $perPage + 1, $offset),
        );
        $hasNext  = count($commits) > $perPage;

        if ($hasNext) array_pop($commits);

        $totalCommits = $this->cache->remember(
            "repo:{$dbRepo['slug']}:count:{$ref}",
            self::CACHE_TTL,
            fn() => $this->gitReader->getCommitCount($repoPath, $ref),
        );

        $this->app->view()->display('repo/commits.twig', [
            'repo'         => $dbRepo,
            'owner'        => $user,
            'current_ref'  => $ref,
            'branches'     => $branches,
            'tags'         => $tags,
            'commits'      => $commits,
            'total'        => $totalCommits,
            'commit_count' => $totalCommits,
            'page'         => $page,
            'has_prev'     => $page > 1,
            'has_next'     => $hasNext,
            'base_url'     => "/{$user}/{$dbRepo['slug']}/commits/{$ref}",
        ]);
    }

    /** GET /{user}/{repo}/commit/{hash} — single commit detail with diff. */
    public function commit(string $user, string $repo, string $hash): void
    {
        $dbRepo = $this->resolveRepo($repo);

        if ($dbRepo === null) {
            $this->notFound();
            return;
        }

        $repoPath = $this->gitService->getRepoPath($dbRepo['slug']);

        // Commits are immutable: cache forever keyed by the full commit hash.
        // parseDiff output is also cached so no extra computation on cache hit.
        $cached = $this->cache->remember(
            "repo:{$dbRepo['slug']}:commit:{$hash}",
            self::COMMIT_CACHE_TTL,
            function () use ($repoPath, $hash): ?array {
                $detail = $this->gitReader->getCommit($repoPath, $hash);
                if ($detail === null) return null;

                $diffFiles = $this->parseDiff($detail['diff']);
                $additions = 0;
                $deletions = 0;
                foreach ($diffFiles as $file) {
                    $additions += $file['additions'];
                    $deletions += $file['deletions'];
                }

                return [
                    'detail'     => $detail,
                    'diff_files' => $diffFiles,
                    'diff_stats' => [
                        'files'     => count($diffFiles),
                        'additions' => $additions,
                        'deletions' => $deletions,
                    ],
                ];
            },
        );

        if ($cached === null) {
            $this->notFound();
            return;
        }

        $this->app->view()->display('repo/commit.twig', [
            'repo'       => $dbRepo,
            'owner'      => $user,
            'commit'     => $cached['detail'],
            'diff_files' => $cached['diff_files'],
            'diff_stats' => $cached['diff_stats'],
        ]);
    }

    /** GET /{user}/{repo}/branches — branch list with tip-commit details. */
    public function branchesIndex(string $user, string $repo): void
    {
        $dbRepo = $this->resolveRepo($repo);

        if ($dbRepo === null) {
            $this->notFound();
            return;
        }

        $repoPath = $this->gitService->getRepoPath($dbRepo['slug']);

        $branches = $this->cache->remember(
            "repo:{$dbRepo['slug']}:branches_detailed",
            self::CACHE_TTL,
            fn() => $this->gitReader->getBranchesDetailed($repoPath),
        );
        // Keep the default branch pinned to the top, GitHub-style.
        $isDefault = static fn(array $b): bool => $b['name'] === $dbRepo['default_branch'];
        $branches = [...array_filter($branches, $isDefault), ...array_filter($branches, static fn(array $b): bool => !$isDefault($b))];

        $this->app->view()->display('repo/branches.twig', [
            'repo'           => $dbRepo,
            'owner'          => $user,
            'branches'       => $branches,
            'default_branch' => $dbRepo['default_branch'],
        ]);
    }

    /** GET /{user}/{repo}/tags — tag list with commit details. */
    public function tagsIndex(string $user, string $repo): void
    {
        $dbRepo = $this->resolveRepo($repo);

        if ($dbRepo === null) {
            $this->notFound();
            return;
        }

        $repoPath = $this->gitService->getRepoPath($dbRepo['slug']);

        $tags = $this->cache->remember(
            "repo:{$dbRepo['slug']}:tags_detailed",
            self::CACHE_TTL,
            fn() => $this->gitReader->getTagsDetailed($repoPath),
        );

        $canWrite = $this->auth->isOwner()
            || ($this->auth->isLoggedIn() && $this->auth->canWriteRepo((int) $dbRepo['id']));

        // Check which tags have an official release in DB
        $tagHasRelease = [];
        $releasesCount = 0;
        foreach ($this->app->db()->fetchAll(
            'SELECT `tag_name`, `name`, `id` FROM `repo_releases` WHERE `repo_id` = :repo AND `is_draft` = 0',
            ['repo' => (int) $dbRepo['id']],
        ) as $row) {
            $tagHasRelease[(string) $row['tag_name']] = $row;
            $releasesCount++;
        }

        foreach ($tags as $i => $tagInfo) {
            $tName = is_array($tagInfo) ? (string) ($tagInfo['name'] ?? '') : (string) $tagInfo;
            $tags[$i]['has_release'] = isset($tagHasRelease[$tName]);
            if (isset($tagHasRelease[$tName])) {
                $tags[$i]['release_name'] = $tagHasRelease[$tName]['name'] ?: $tName;
                $tags[$i]['release_id']   = (int) $tagHasRelease[$tName]['id'];
            }
        }

        $this->app->view()->display('repo/tags.twig', [
            'repo'            => $dbRepo,
            'owner'           => $user,
            'tags'            => $tags,
            'releases_count'  => $releasesCount,
            'can_write'       => $canWrite,
            'csrf_token'      => $this->auth->generateCsrf(),
        ]);
    }

    /** GET /{user}/{repo}/activity — repository activity and pulse stream. */
    public function activity(string $user, string $repo): void
    {
        if (! isset($_GET['tab'])) {
            $_GET['tab'] = 'pulse';
        }
        $this->insights($user, $repo);
    }

    /** GET /{user}/{repo}/insights — repository activity, commit charts, punchcard & contributors. */
    public function insights(string $user, string $repo): void
    {
        $dbRepo = $this->resolveRepo($repo);
        if ($dbRepo === null) {
            $this->notFound();
            return;
        }

        $repoPath = $this->gitService->getRepoPath($dbRepo['slug']);
        $branch   = (string) ($dbRepo['default_branch'] ?? 'main');

        $branches = $this->branches($repoPath, $dbRepo['slug']);
        $tags     = $this->tags($repoPath, $dbRepo['slug']);
        $totalCommits = $this->commitCount($repoPath, $dbRepo['slug'], $branch);

        $tab = (string) ($_GET['tab'] ?? 'commit-activity');
        if (! in_array($tab, ['pulse', 'contributors', 'commit-activity', 'punchcard'], true)) {
            $tab = 'commit-activity';
        }

        // Fast raw git log parse for up to 3000 commits (argument-array
        // Process call — no shell string, matching the platform policy).
        $logProc = new \Symfony\Component\Process\Process(
            ['git', 'log', $branch, '--format=%H|%an|%ae|%at', '-n', '3000'],
            $repoPath,
        );
        $logProc->setTimeout(60);
        $logProc->run();
        $rawLog = $logProc->isSuccessful()
            ? array_filter(array_map('trim', explode("\n", $logProc->getOutput())))
            : [];

        $now = time();
        $oneYearAgo = $now - (52 * 7 * 86400);

        // ── 1. 52-Week Activity Grid ─────────────────────────────────
        $weeks = [];
        $startOfWeek = strtotime('last Sunday, 00:00:00', $now);
        for ($w = 51; $w >= 0; $w--) {
            $wStart = $startOfWeek - ($w * 7 * 86400);
            $wEnd   = $wStart + (7 * 86400) - 1;
            $weeks[] = [
                'index'      => 51 - $w,
                'start_ts'   => $wStart,
                'end_ts'     => $wEnd,
                'label'      => date('M j', $wStart),
                'short_label'=> date('M', $wStart),
                'total'      => 0,
                'days'       => [0, 0, 0, 0, 0, 0, 0], // Sun..Sat
            ];
        }

        // ── 2. Punchcard Grid (7 days x 24 hours) ────────────────────
        // 0=Sun, 1=Mon, ..., 6=Sat
        $punchcard = [];
        for ($d = 0; $d < 7; $d++) {
            $punchcard[$d] = array_fill(0, 24, 0);
        }
        $maxPunch = 0;

        // ── 3. Contributors Dictionary ───────────────────────────────
        $contribMap = [];
        $commitsLast7d  = 0;
        $commitsLast30d = 0;
        $authorsLast30d = [];

        foreach ($rawLog as $line) {
            $parts = explode('|', $line, 4);
            if (count($parts) < 4) continue;
            [$hash, $authorName, $authorEmail, $tsStr] = $parts;
            $ts = (int) $tsStr;
            if ($ts <= 0) continue;

            $authorName = trim($authorName) ?: 'Anonymous';
            $authorEmail = strtolower(trim($authorEmail));

            // Pulse counters
            if ($ts >= $now - (7 * 86400)) {
                $commitsLast7d++;
            }
            if ($ts >= $now - (30 * 86400)) {
                $commitsLast30d++;
                $authorsLast30d[$authorName] = true;
            }

            // Punchcard
            $dayOfWeek = (int) date('w', $ts); // 0 (Sun) - 6 (Sat)
            $hourOfDay = (int) date('G', $ts); // 0 - 23
            $punchcard[$dayOfWeek][$hourOfDay]++;
            if ($punchcard[$dayOfWeek][$hourOfDay] > $maxPunch) {
                $maxPunch = $punchcard[$dayOfWeek][$hourOfDay];
            }

            // 52-Week allocation
            if ($ts >= $oneYearAgo) {
                $wIdx = (int) floor(($ts - ($startOfWeek - (51 * 7 * 86400))) / (7 * 86400));
                if ($wIdx >= 0 && $wIdx < 52) {
                    $weeks[$wIdx]['total']++;
                    $weeks[$wIdx]['days'][$dayOfWeek]++;
                }
            }

            // Contributor aggregation
            if (! isset($contribMap[$authorName])) {
                $contribMap[$authorName] = [
                    'name'         => $authorName,
                    'email'        => $authorEmail,
                    'email_md5'    => md5($authorEmail),
                    'initial'      => mb_strtoupper(mb_substr($authorName, 0, 1)),
                    'count'        => 0,
                    'first_ts'     => $ts,
                    'last_ts'      => $ts,
                    'weekly'       => array_fill(0, 52, 0),
                ];
            }
            $contribMap[$authorName]['count']++;
            if ($ts < $contribMap[$authorName]['first_ts']) $contribMap[$authorName]['first_ts'] = $ts;
            if ($ts > $contribMap[$authorName]['last_ts'])  $contribMap[$authorName]['last_ts']  = $ts;

            if ($ts >= $oneYearAgo) {
                $cwIdx = (int) floor(($ts - ($startOfWeek - (51 * 7 * 86400))) / (7 * 86400));
                if ($cwIdx >= 0 && $cwIdx < 52) {
                    $contribMap[$authorName]['weekly'][$cwIdx]++;
                }
            }
        }

        // Sort contributors by commit count descending
        uasort($contribMap, static fn($a, $b) => $b['count'] <=> $a['count']);

        $contributors = [];
        $totalParsed = max(1, count($rawLog));
        foreach ($contribMap as $c) {
            $c['percent'] = round(($c['count'] / $totalParsed) * 100, 1);
            $c['weekly_max'] = max(1, max($c['weekly']));
            $contributors[] = $c;
        }

        $maxWeekCommits = 0;
        foreach ($weeks as $w) {
            if ($w['total'] > $maxWeekCommits) $maxWeekCommits = $w['total'];
        }

        // Pulse DB metrics (Issues & PRs)
        $repoId = (int) ($dbRepo['id'] ?? 0);
        $pulseStats = [
            'commits_7d'    => $commitsLast7d,
            'commits_30d'   => $commitsLast30d,
            'active_devs'   => count($authorsLast30d),
            'open_issues'   => 0,
            'closed_issues' => 0,
            'open_prs'      => 0,
            'merged_prs'    => 0,
        ];

        try {
            if ($repoId > 0) {
                $pulseStats['open_issues'] = (int) ($this->app->db()->fetchOne(
                    "SELECT COUNT(*) as c FROM `bug_reports` WHERE `repo_id` = :r AND `status` = 'open'",
                    ['r' => $repoId]
                )['c'] ?? 0);
                $pulseStats['closed_issues'] = (int) ($this->app->db()->fetchOne(
                    "SELECT COUNT(*) as c FROM `bug_reports` WHERE `repo_id` = :r AND `status` = 'closed'",
                    ['r' => $repoId]
                )['c'] ?? 0);
                $pulseStats['open_prs'] = (int) ($this->app->db()->fetchOne(
                    "SELECT COUNT(*) as c FROM `pull_requests` WHERE `repo_id` = :r AND `status` = 'open'",
                    ['r' => $repoId]
                )['c'] ?? 0);
                $pulseStats['merged_prs'] = (int) ($this->app->db()->fetchOne(
                    "SELECT COUNT(*) as c FROM `pull_requests` WHERE `repo_id` = :r AND `status` = 'merged'",
                    ['r' => $repoId]
                )['c'] ?? 0);
            }
        } catch (\Throwable) {}

        $owner = (string) $this->app->config('app.owner', 'admin');

        $this->app->view()->display('repo/insights.twig', [
            'repo'             => $dbRepo,
            'owner'            => $user,
            'repo_owner'       => $owner,
            'branches'         => $branches,
            'tags'             => $tags,
            'current_ref'      => $branch,
            'active_tab'       => $tab,
            'total_commits'    => $totalCommits,
            'weeks'            => $weeks,
            'max_week_commits' => max(1, $maxWeekCommits),
            'punchcard'        => $punchcard,
            'max_punch'        => max(1, $maxPunch),
            'contributors'     => $contributors,
            'pulse'            => $pulseStats,
            'can_write'        => $this->auth->isOwner() || ($this->auth->isLoggedIn() && $this->auth->canWriteRepo((int) $dbRepo['id'])),
        ]);
    }


    /** GET /{user}/{repo}/issues — placeholder for the Issues section. */
    public function issuesIndex(string $user, string $repo): void
    {
        $this->featurePage($user, $repo, 'issues');
    }

    /** GET /{user}/{repo}/pulls — placeholder for the Pull requests section. */
    public function pullsIndex(string $user, string $repo): void
    {
        $this->featurePage($user, $repo, 'pulls');
    }

    /** GET /{user}/{repo}/wiki — placeholder for the Wiki section. */
    public function wikiIndex(string $user, string $repo): void
    {
        $this->featurePage($user, $repo, 'wiki');
    }

    /** GET /{user}/{repo}/raw/{ref}/{path} — serve the file content verbatim. */
    public function raw(string $user, string $repo, string $ref, string $path): void
    {
        $dbRepo = $this->resolveRepo($repo);

        if ($dbRepo === null) {
            $this->notFound();
            return;
        }

        $repoPath = $this->gitService->getRepoPath($dbRepo['slug']);
        $content  = $this->gitReader->getBlob($repoPath, $ref, $path);

        if ($content === null) {
            $this->notFound();
            return;
        }

        $mime = $this->mimeForPath($path, $content);

        // Stored-XSS guard: never serve attacker-controlled blobs as
        // executable document types from the app origin (GitHub forces
        // text/plain for the same reason). Browsers must download instead.
        if (preg_match('#^(text/html|image/svg\+xml|application/xml|text/xml)#i', $mime)) {
            $mime = 'text/plain; charset=utf-8';
        }

        // Conditional HTTP caching: ETag (content hash) + Last-Modified
        // (last commit touching the path). Repeat requests get 304 with an
        // empty body — a byte-for-byte identical payload never re-ships.
        $etag = '"' . hash('sha256', $mime . '|' . $content) . '"';

        $lastCommitDate = null;
        try {
            $proc = new \Symfony\Component\Process\Process(
                ['git', 'log', '-1', '--format=%ct', '--', $path, $ref],
                $repoPath,
            );
            $proc->setTimeout(5);
            $proc->run();
            if ($proc->isSuccessful() && preg_match('/^\d+$/', trim($proc->getOutput()))) {
                $lastCommitDate = (int) trim($proc->getOutput());
            }
        } catch (\Throwable) {
            // Conditional date is best-effort only.
        }

        $ifNoneMatch = trim((string) ($_SERVER['HTTP_IF_NONE_MATCH'] ?? ''), " \t");
        if ($ifNoneMatch !== '') {
            $candidates = array_map('trim', explode(',', $ifNoneMatch));
            if (in_array($etag, $candidates, true) || in_array('*', $candidates, true)) {
                http_response_code(304);
                header('ETag: ' . $etag);
                header('Cache-Control: public, max-age=' . self::CACHE_TTL);
                return;
            }
        }

        if ($lastCommitDate !== null && ! empty($_SERVER['HTTP_IF_MODIFIED_SINCE'])) {
            $ims = strtotime((string) $_SERVER['HTTP_IF_MODIFIED_SINCE']);
            // HTTP dates have second precision; compare truncated.
            if ($ims !== false && $ims >= $lastCommitDate) {
                http_response_code(304);
                header('ETag: ' . $etag);
                header('Cache-Control: public, max-age=' . self::CACHE_TTL);
                return;
            }
        }

        header('Content-Type: ' . $mime);
        header('X-Content-Type-Options: nosniff');
        header('Content-Length: ' . strlen($content));
        header('Cache-Control: public, max-age=' . self::CACHE_TTL);
        header('ETag: ' . $etag);
        if ($lastCommitDate !== null) {
            header('Last-Modified: ' . gmdate('D, d M Y H:i:s', $lastCommitDate) . ' GMT');
        }
        echo $content;
    }

    /** GET /{user}/{repo}/archive/{ref}.zip — download a zip of the ref's tree. */
    public function archive(string $user, string $repo, string $ref): void
    {
        $this->archiveFormat($user, $repo, $ref, 'zip');
    }

    /** GET /{user}/{repo}/archive/{ref}.tar.gz — download a tar.gz of the ref's tree. */
    public function archiveTarGz(string $user, string $repo, string $ref): void
    {
        $this->archiveFormat($user, $repo, $ref, 'tar.gz');
    }

    private function archiveFormat(string $user, string $repo, string $ref, string $format): void
    {
        $dbRepo = $this->resolveRepo($repo);
        if ($dbRepo === null) {
            $this->notFound();
            return;
        }

        $repoPath = $this->gitService->getRepoPath($dbRepo['slug']);
        $format = in_array($format, ['zip', 'tar.gz'], true) ? $format : 'zip';
        $ext = $format === 'tar.gz' ? 'tar.gz' : 'zip';

        // Resolve ref to 40-character commit SHA
        $sha = '';
        if (method_exists($this->gitReader, 'resolveRef')) {
            $sha = $this->gitReader->resolveRef($repoPath, $ref);
        }
        if ($sha === '') {
            $sha = trim((string) @shell_exec("git -C " . escapeshellarg($repoPath) . " rev-parse --verify " . escapeshellarg("{$ref}^{commit}") . " 2>/dev/null"));
            if ($sha === '') {
                $sha = trim((string) @shell_exec("git -C " . escapeshellarg($repoPath) . " rev-parse --verify " . escapeshellarg($ref) . " 2>/dev/null"));
            }
        }

        if ($sha === '') {
            $this->notFound();
            return;
        }

        $cleanRef = preg_replace('/[^a-zA-Z0-9_.-]/', '_', $ref);
        $cacheDir = $this->app->basePath('storage/cache/archives/' . $dbRepo['slug']);
        if (! is_dir($cacheDir)) @mkdir($cacheDir, 0775, true);

        // Persistent cache filename incorporating slug, clean ref and commit SHA
        $cacheFile = $cacheDir . DIRECTORY_SEPARATOR . $dbRepo['slug'] . '-' . $cleanRef . '-' . substr($sha, 0, 12) . '.' . $ext;

        // Check if valid cache file already exists
        if (! is_file($cacheFile) || filesize($cacheFile) === 0) {
            @set_time_limit(600);
            @ignore_user_abort(true);

            $partFile = $cacheFile . '.part.' . getmypid() . '.' . bin2hex(random_bytes(4));
            try {
                $this->gitService->createArchive($dbRepo['slug'], $ref, $partFile, $format);
                if (is_file($partFile) && filesize($partFile) > 0) {
                    @rename($partFile, $cacheFile);
                } else {
                    @unlink($partFile);
                    $this->notFound();
                    return;
                }
            } catch (\Throwable $e) {
                @unlink($partFile);
                error_log('[ArchiveError] ' . $e->getMessage());
                $this->notFound();
                return;
            }
        }

        if (! is_file($cacheFile) || filesize($cacheFile) === 0) {
            $this->notFound();
            return;
        }

        $downloadFilename = $dbRepo['slug'] . '-' . $ref . '.' . $ext;
        $mime = $format === 'tar.gz' ? 'application/gzip' : 'application/zip';
        $size = (int) filesize($cacheFile);

        // Enforce short URL /d/{code} architecture:
        // Check if caller provides permanent update key (CLI/CI/remote updaters)
        $tokenService = new \App\Service\DownloadTokenService($this->app);
        $updateToken = (string) ($_GET['update_token'] ?? $_GET['token'] ?? ($_SERVER['HTTP_X_UPDATE_TOKEN'] ?? ''));
        $isRemoteUpdater = $updateToken !== '' && $tokenService->isPermanentUpdateKey($updateToken, (int) ($dbRepo['owner_user_id'] ?? 0));

        // Relative path inside project storage
        $relPath = 'storage/cache/archives/' . $dbRepo['slug'] . '/' . basename($cacheFile);

        // Find or create short code in file_downloads
        $db = $this->app->db()->connection();
        $q = $db->prepare('SELECT short_code FROM file_downloads WHERE file_path = ? OR (original_name = ? AND folder_id = (SELECT id FROM download_folders WHERE slug = "archives" LIMIT 1)) ORDER BY id DESC LIMIT 1');
        $q->execute([$relPath, $downloadFilename]);
        $shortCode = $q->fetchColumn();

        if (empty($shortCode)) {
            // Find or create Archives folder
            $fStmt = $db->prepare('SELECT id FROM download_folders WHERE slug = "archives" LIMIT 1');
            $fStmt->execute();
            $folder = $fStmt->fetch();
            if (!$folder) {
                $insF = $db->prepare('INSERT INTO download_folders (name, slug, description, created_at) VALUES ("Archives", "archives", "Source code archives & snapshots", NOW())');
                $insF->execute([]);
                $folderId = (int) $db->lastInsertId();
            } else {
                $folderId = (int) $folder['id'];
            }

            $shortCode = substr(bin2hex(random_bytes(4)), 0, 6);
            $title = $dbRepo['name'] . ' (' . $ref . ') - ' . strtoupper($ext);

            $ins = $db->prepare('
                INSERT INTO file_downloads (title, folder_id, original_name, file_path, file_size, mime_type, short_code, is_active, created_by, created_at)
                VALUES (?, ?, ?, ?, ?, ?, ?, 1, ?, NOW())
            ');
            $ins->execute([
                $title,
                $folderId,
                $downloadFilename,
                $relPath,
                $size,
                $mime,
                $shortCode,
                !empty($dbRepo['owner_user_id']) ? (int) $dbRepo['owner_user_id'] : null,
            ]);
        }

        // If not an automated remote updater, redirect user directly to the protected short code portal
        if (!$isRemoteUpdater && !empty($shortCode)) {
            header("Location: /d/{$shortCode}", true, 302);
            exit;
        }
        $mime = $format === 'tar.gz' ? 'application/gzip' : 'application/zip';
        $size = (int) filesize($cacheFile);

        // Clean any active output buffers to allow immediate streaming without proxy timeouts
        while (ob_get_level() > 0) {
            ob_end_clean();
        }

        header('Content-Type: ' . $mime);
        header('Content-Disposition: attachment; filename="' . rawurlencode($downloadFilename) . '"');
        header('Cache-Control: private, max-age=86400');
        header('X-Content-Type-Options: nosniff');
        header('Accept-Ranges: bytes');

        // Handle partial range requests if client sent HTTP_RANGE
        $range = (string) ($_SERVER['HTTP_RANGE'] ?? '');
        $start = 0;
        $end = $size - 1;

        if ($range !== '' && preg_match('/^bytes=(\d*)-(\d*)$/i', $range, $m)) {
            if ($m[1] === '' && $m[2] !== '') {
                $start = max(0, $size - (int) $m[2]);
            } elseif ($m[1] !== '' && $m[2] === '') {
                $start = (int) $m[1];
            } elseif ($m[1] !== '' && $m[2] !== '') {
                $start = (int) $m[1];
                $end = min($size - 1, (int) $m[2]);
            }

            if ($start > $end || $start >= $size) {
                http_response_code(416);
                header("Content-Range: bytes */{$size}");
                exit;
            }

            http_response_code(206);
            $length = $end - $start + 1;
            header("Content-Range: bytes {$start}-{$end}/{$size}");
            header("Content-Length: {$length}");
        } else {
            header('Content-Length: ' . $size);
        }

        $fp = @fopen($cacheFile, 'rb');
        if ($fp === false) {
            $this->notFound();
            return;
        }

        if ($start > 0) {
            fseek($fp, $start);
        }

        $bytesToRead = $end - $start + 1;
        while (! feof($fp) && $bytesToRead > 0) {
            $chunkSize = min(262144, $bytesToRead);
            $buffer = fread($fp, $chunkSize);
            if ($buffer === false || $buffer === '') break;
            echo $buffer;
            flush();
            $bytesToRead -= strlen($buffer);
            if (connection_status() !== CONNECTION_NORMAL) break;
        }
        fclose($fp);
        exit;
    }

    /** GET /{user}/{repo}/blame/{ref}/{path} — line-by-line blame view. */
    public function blame(string $user, string $repo, string $ref, string $path): void
    {
        $dbRepo = $this->resolveRepo($repo);

        if ($dbRepo === null) {
            $this->notFound();
            return;
        }

        $repoPath = $this->gitService->getRepoPath($dbRepo['slug']);
        $size     = $this->blobSize($repoPath, $dbRepo['slug'], $ref, $path);

        // Blame is expensive; cap it for large files and cache the result
        $tooLarge = $size > 256 * 1024;
        $lines = [];
        if (!$tooLarge && $size > 0) {
            $lines = $this->cache->remember(
                "repo:{$dbRepo['slug']}:blame:{$ref}:{$path}",
                self::BLAME_CACHE_TTL,
                fn() => $this->gitReader->getBlame($repoPath, $ref, $path),
            );
        }

        $segments = explode('/', $path);

        $this->app->view()->display('repo/blame.twig', [
            'repo'         => $dbRepo,
            'owner'        => $user,
            'current_ref'  => $ref,
            'current_path' => $path,
            'segments'     => $segments,
            'branches'     => $this->branches($repoPath, $dbRepo['slug']),
            'tags'         => $this->tags($repoPath, $dbRepo['slug']),
            'lines'        => $lines,
            'too_large'    => $tooLarge,
            'file_size'    => $size,
        ]);
    }

    /** Shared renderer for sections this self-hosted instance doesn't implement. */
    private function featurePage(string $user, string $repo, string $feature): void
    {
        $dbRepo = $this->resolveRepo($repo);

        if ($dbRepo === null) {
            $this->notFound();
            return;
        }

        $features = [
            'issues' => ['title' => 'Issues', 'hint' => 'Issue tracking is not enabled for this repository.'],
            'pulls'  => ['title' => 'Pull requests', 'hint' => 'Pull requests are not supported by this self-hosted instance.'],
            'wiki'   => ['title' => 'Wiki', 'hint' => 'The wiki is not enabled for this repository.'],
        ];

        $this->app->view()->display('repo/feature.twig', [
            'repo'          => $dbRepo,
            'owner'         => $user,
            'active_tab'    => $feature,
            'feature_title' => $features[$feature]['title'],
            'feature_hint'  => $features[$feature]['hint'],
        ]);
    }

    /**
     * Look up a repo by slug in the DB and check visibility.
     * @return array<string, mixed>|null
     */
    private function resolveRepo(string $slug): ?array
    {
        // Tolerate clone-URL style paths (/{user}/{repo}.git visited in a browser)
        $slug = str_ends_with($slug, '.git') ? substr($slug, 0, -4) : $slug;

        if ($slug === '') return null;

        // The base row + derived counters are stable across viewers, so a
        // short shared cache absorbs repeated identical queries (previously
        // every page re-ran the 4-subquery SELECT). Visibility is still
        // enforced per-request below — never from the cache. The key lives
        // inside the "repo:{slug}:" family so existing invalidation calls
        // (forgetPrefix("repo:{slug}:")) flush it automatically.
        $row = $this->cache->remember(
            "repo:{$slug}:row",
            60,
            function () use ($slug) {
                $r = $this->app->db()->fetchOne('
                    SELECT r.*, p.slug AS parent_slug, p.name AS parent_name,
                           (SELECT COUNT(*) FROM repositories WHERE forked_from_id = r.id) AS forks_count,
                           (SELECT COUNT(*) FROM bug_reports WHERE repo_id = r.id AND status = \'open\') AS open_issues_count,
                           (SELECT COUNT(*) FROM repo_releases WHERE repo_id = r.id AND is_draft = 0) AS releases_count
                    FROM repositories r
                    LEFT JOIN repositories p ON r.forked_from_id = p.id
                    WHERE r.slug = :slug
                    LIMIT 1
                ', ['slug' => $slug]);

                return $r === false ? null : $r;
            },
        );

        if ($row === null) return null;

        // Private repos are visible to the owner and collaborators only.
        if (! $this->auth->canViewRepo((int) $row['id'], (string) $row['visibility'])) {
            if (! $this->auth->isLoggedIn()) {
                header('Location: /login');
                exit;
            }
            return null;
        }

        $repoPath = $this->gitService->getRepoPath($row['slug']);
        $branchesCount = 0;
        $tagsCount = 0;
        if (is_dir($repoPath)) {
            $branchesCount = count($this->gitReader->getBranches($repoPath));
            $tagsCount     = count($this->gitReader->getTags($repoPath));
        }

        return [
            'id'                => (int) $row['id'],
            'slug'              => $row['slug'],
            'name'              => $row['name'],
            'description'       => $row['description'],
            'visibility'        => $row['visibility'],
            'default_branch'    => $row['default_branch'],
            'owner_user_id'     => isset($row['owner_user_id']) ? (int) $row['owner_user_id'] : 0,
            'stars_count'       => (int) ($row['stars_count'] ?? 0),
            'forks_count'       => (int) ($row['forks_count'] ?? 0),
            'open_issues_count' => (int) ($row['open_issues_count'] ?? 0),
            'releases_count'    => (int) ($row['releases_count'] ?? 0),
            'branches_count'    => max(1, $branchesCount),
            'tags_count'        => $tagsCount,
            'forked_from_id'    => $row['forked_from_id'] ? (int) $row['forked_from_id'] : null,
            'parent_slug'       => $row['parent_slug'] ?? null,
            'parent_name'       => $row['parent_name'] ?? null,
            'homepage'          => $row['homepage'] ?? null,
            'topics'            => $row['topics'] ?? null,
            'source_url'        => $row['source_url'] ?? null,
            'created_at'        => $row['created_at'],
            'updated_at'        => $row['updated_at'],
        ];
    }

    /** Cached branch name list (rendered by the ref selector on every page). */
    private function resolveOwnerName(array $repo): string
    {
        $uid = (int) ($repo['owner_user_id'] ?? 0);
        if ($uid > 0) {
            $u = $this->app->db()->fetchOne('SELECT `username` FROM `users` WHERE `id` = :id', ['id' => $uid]);
            if ($u !== false && !empty($u['username'])) {
                return (string) $u['username'];
            }
        }
        return (string) $this->app->config('app.owner', 'admin');
    }

    private function branches(string $repoPath, string $slug): array
    {
        return $this->cache->remember(
            "repo:{$slug}:branches",
            self::CACHE_TTL,
            fn() => $this->gitReader->getBranches($repoPath),
        );
    }

    /** Cached tag name list. */
    private function tags(string $repoPath, string $slug): array
    {
        return $this->cache->remember(
            "repo:{$slug}:tags_detailed_v2",
            self::CACHE_TTL,
            fn() => method_exists($this->gitReader, 'getTagsDetailed')
                ? $this->gitReader->getTagsDetailed($repoPath)
                : $this->gitReader->getTags($repoPath),
        );
    }

    /** Cached total commit count (rev-list walks the whole history). */
    private function commitCount(string $repoPath, string $slug, string $ref): int
    {
        return (int) $this->cache->remember(
            "repo:{$slug}:count:{$ref}",
            self::CACHE_TTL,
            fn() => $this->gitReader->getCommitCount($repoPath, $ref),
        );
    }

    /** Cached tree listing enriched with each entry's last commit. */
    private function treeWithCommits(string $repoPath, string $slug, string $ref, string $path = ''): array
    {
        return $this->cache->remember(
            "repo:{$slug}:tree:{$ref}:" . ($path === '' ? 'ROOT' : $path),
            self::CACHE_TTL,
            function () use ($repoPath, $ref, $path) {
                return $this->enrichTreeWithCommits(
                    $repoPath,
                    $ref,
                    $this->gitReader->getTree($repoPath, $ref, $path),
                    $path,
                );
            },
        );
    }

    /** Cached latest commit on a ref (shown in tree and show views). */
    private function latestCommit(string $repoPath, string $slug, string $ref): ?array
    {
        return $this->cache->remember(
            "repo:{$slug}:latest_commit:{$ref}",
            self::CACHE_TTL,
            function () use ($repoPath, $ref): ?array {
                $log = $this->gitReader->getLog($repoPath, $ref, 1);
                return $log[0] ?? null;
            },
        );
    }

    /**
     * Cached README HTML (rendered Markdown).
     * Keyed by slug+user+ref+filename with automatic relative image and link rewriting.
     */
    private function readmeHtml(string $repoPath, string $slug, string $ref, string $filename, string $user = 'admin'): string
    {
        $owner = $user ?: 'admin';
        return $this->cache->remember(
            "repo:{$slug}:readme:{$owner}:{$ref}:{$filename}",
            self::README_CACHE_TTL,
            function () use ($repoPath, $owner, $slug, $ref, $filename): string {
                $content = $this->gitReader->getBlob($repoPath, $ref, $filename);
                if ($content === null) return '';
                $rawBase  = "/{$owner}/{$slug}/raw/{$ref}";
                $blobBase = "/{$owner}/{$slug}/src/branch/{$ref}";
                return $this->markdown->renderHtml($content, $rawBase, $blobBase);
            },
        );
    }

    /** Cached blob size in bytes (avoids double git cat-file calls). */
    private function blobSize(string $repoPath, string $slug, string $ref, string $path): int
    {
        return $this->cache->remember(
            "repo:{$slug}:blobsize:{$ref}:{$path}",
            self::BLOB_CACHE_TTL,
            fn() => $this->gitReader->getBlobSize($repoPath, $ref, $path),
        );
    }

    /** Cached blob content (keyed by repo+ref+path, TTL=BLOB_CACHE_TTL). */
    private function blobContent(string $repoPath, string $slug, string $ref, string $path): ?string
    {
        return $this->cache->remember(
            "repo:{$slug}:blob:{$ref}:{$path}",
            self::BLOB_CACHE_TTL,
            fn() => $this->gitReader->getBlob($repoPath, $ref, $path),
        );
    }

    /** Guess a Content-Type for raw file delivery. */
    private function mimeForPath(string $path, string $content): string
    {
        static $map = [
            'png'  => 'image/png',
            'jpg'  => 'image/jpeg',
            'jpeg' => 'image/jpeg',
            'gif'  => 'image/gif',
            'webp' => 'image/webp',
            'avif' => 'image/avif',
            'svg'  => 'image/svg+xml',
            'ico'  => 'image/x-icon',
            'bmp'  => 'image/bmp',
            'tiff' => 'image/tiff',
            'mp4'  => 'video/mp4',
            'webm' => 'video/webm',
            'pdf'  => 'application/pdf',
            'zip'  => 'application/zip',
            'tar'  => 'application/x-tar',
            'gz'   => 'application/gzip',
            'html' => 'text/html; charset=utf-8',
            'htm'  => 'text/html; charset=utf-8',
            'css'  => 'text/css; charset=utf-8',
            'js'   => 'application/javascript; charset=utf-8',
            'json' => 'application/json',
            'xml'  => 'application/xml',
            'md'   => 'text/markdown; charset=utf-8',
            'txt'  => 'text/plain; charset=utf-8',
            'sh'   => 'text/plain; charset=utf-8',
            'php'  => 'text/plain; charset=utf-8',
            'py'   => 'text/plain; charset=utf-8',
            'go'   => 'text/plain; charset=utf-8',
            'rs'   => 'text/plain; charset=utf-8',
        ];

        $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
        if (isset($map[$ext])) return $map[$ext];

        // Binary sniff: NUL byte in the first kilobyte
        if (str_contains(substr($content, 0, 1024), "\0")) return 'application/octet-stream';

        return 'text/plain; charset=utf-8';
    }

    /**
     * Star/watch state for the current visitor, shown on the repo overview.
     * @return array<string, mixed>
     */
    private function socialData(int $repoId, int $stars): array
    {
        $isLoggedIn = $this->auth->isLoggedIn();
        $userId     = $this->auth->isOwner() ? 0 : $this->auth->userId();

        $watchers = (int) ($this->app->db()->fetchOne(
            'SELECT COUNT(*) AS `c` FROM `repo_subscriptions` WHERE `repo_id` = :repo',
            ['repo' => $repoId],
        )['c'] ?? 0);

        $isStarred  = false;
        $isWatching = false;

        if ($isLoggedIn) {
            $isStarred = $this->app->db()->fetchOne(
                'SELECT `id` FROM `repo_likes` WHERE `repo_id` = :repo AND `user_id` = :user LIMIT 1',
                ['repo' => $repoId, 'user' => $userId],
            ) !== false;

            $isWatching = $this->app->db()->fetchOne(
                'SELECT `id` FROM `repo_subscriptions` WHERE `repo_id` = :repo AND `user_id` = :user LIMIT 1',
                ['repo' => $repoId, 'user' => $userId],
            ) !== false;
        }

        return [
            'stars'        => $stars,
            'watchers'     => $watchers,
            'is_starred'   => $isStarred,
            'is_watching'  => $isWatching,
            'can_interact' => $isLoggedIn,
        ];
    }

    /**
     * Add last-commit info to each tree entry using a single batched
     * git process (see GitReader::getLastCommitsForPaths).
     * @param array<int, array<string, mixed>> $entries
     * @return array<int, array<string, mixed>>
     */
    private function enrichTreeWithCommits(string $repoPath, string $ref, array $entries, string $path = ''): array
    {
        $paths = [];
        foreach ($entries as $entry) {
            $paths[] = $path === '' ? $entry['name'] : $path . '/' . $entry['name'];
        }

        $lastCommits = $this->gitReader->getLastCommitsForPaths($repoPath, $ref, $paths);

        foreach ($entries as &$entry) {
            $fullPath = $path === '' ? $entry['name'] : $path . '/' . $entry['name'];
            $entry['last_commit'] = $lastCommits[$fullPath] ?? null;
        }

        return $entries;
    }

    /**
     * Parse unified diff output into structured, GitHub-style file entries:
     * path, status (added/deleted/renamed/modified), +/- counts, and lines
     * carrying old/new line numbers. Rendering is capped per file.
     */
    private function parseDiff(string $diff): array
    {
        if (trim($diff) === '') return [];

        $files    = [];
        $current  = null;
        $oldNo    = 0;
        $newNo    = 0;
        $lineCap  = 2000; // rendered lines per file (counts keep going)

        foreach (explode("\n", $diff) as $line) {
            // New file section
            if (str_starts_with($line, 'diff --git ')) {
                if ($current !== null) $files[] = $current;

                $path = '';
                if (preg_match('#^diff --git a/(.+) b/(.+)$#', $line, $m)) {
                    $path = $m[2];
                }

                $current = [
                    'path'      => $path,
                    'old_path'  => null,
                    'status'    => 'modified',
                    'binary'    => false,
                    'additions' => 0,
                    'deletions' => 0,
                    'truncated' => false,
                    'lines'     => [],
                ];
                continue;
            }

            if ($current === null) continue;

            // Extended headers -> status flags, not rendered
            if (str_starts_with($line, 'new file mode'))   { $current['status'] = 'added';   continue; }
            if (str_starts_with($line, 'deleted file mode')) { $current['status'] = 'deleted'; continue; }
            if (str_starts_with($line, 'rename from '))    { $current['old_path'] = substr($line, 12); $current['status'] = 'renamed'; continue; }
            if (str_starts_with($line, 'rename to '))      { $current['path'] = substr($line, 10);     $current['status'] = 'renamed'; continue; }
            if (str_starts_with($line, 'Binary files') || str_starts_with($line, 'GIT binary patch')) {
                $current['binary'] = true;
                continue;
            }
            if (
                str_starts_with($line, 'index ') ||
                str_starts_with($line, 'old mode') ||
                str_starts_with($line, 'new mode') ||
                str_starts_with($line, 'similarity index') ||
                str_starts_with($line, 'dissimilarity index') ||
                str_starts_with($line, 'copy from') ||
                str_starts_with($line, 'copy to') ||
                str_starts_with($line, '--- ') ||
                str_starts_with($line, '+++ ')
            ) {
                continue;
            }

            // Hunk header: @@ -oldStart[,count] +newStart[,count] @@ [section]
            if (preg_match('/^@@ -(\d+)(?:,\d+)? \+(\d+)(?:,\d+)? @@(.*)$/', $line, $m)) {
                $oldNo = (int) $m[1];
                $newNo = (int) $m[2];

                if (count($current['lines']) < $lineCap) {
                    $current['lines'][] = ['type' => 'hunk', 'content' => $line];
                }
                continue;
            }

            if (count($current['lines']) >= $lineCap) {
                $current['truncated'] = true;
            }

            if (str_starts_with($line, '+')) {
                $current['additions']++;
                if (!$current['truncated']) {
                    $current['lines'][] = ['type' => 'add', 'old' => null, 'new' => $newNo++, 'content' => substr($line, 1)];
                } else {
                    $newNo++;
                }
            } elseif (str_starts_with($line, '-')) {
                $current['deletions']++;
                if (!$current['truncated']) {
                    $current['lines'][] = ['type' => 'del', 'old' => $oldNo++, 'new' => null, 'content' => substr($line, 1)];
                } else {
                    $oldNo++;
                }
            } elseif (str_starts_with($line, '\\')) {
                // "\ No newline at end of file"
                if (!$current['truncated']) {
                    $current['lines'][] = ['type' => 'meta', 'content' => substr($line, 2)];
                }
            } else {
                if (!$current['truncated']) {
                    $current['lines'][] = ['type' => 'ctx', 'old' => $oldNo++, 'new' => $newNo++, 'content' => substr($line, 1)];
                } else {
                    $oldNo++;
                    $newNo++;
                }
            }
        }

        if ($current !== null) $files[] = $current;

        return $files;
    }

    private function httpsUrl(string $owner, string $slug): string
    {
        $appUrl = (string) $this->app->config('app.url', '');
        return rtrim($appUrl, '/') . "/{$owner}/{$slug}.git";
    }

    private function sshUrl(string $owner, string $slug): string
    {
        $appUrl  = (string) $this->app->config('app.url', '');
        $host    = parse_url($appUrl, PHP_URL_HOST) ?: 'localhost';
        $sshUser = (string) $this->app->config('git.ssh_user', 'git');
        return "{$sshUser}@{$host}:{$owner}/{$slug}.git";
    }

    private function notFound(): void
    {
        http_response_code(404);
        $this->app->view()->display('partials/error.html.twig', [
            'code'    => 404,
            'message' => 'Repository not found.',
        ]);
    }
}
