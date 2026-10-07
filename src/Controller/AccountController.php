<?php

declare(strict_types=1);

namespace App\Controller;

use App\App;
use App\Auth;
use App\Service\ApiTokenService;
use App\Service\AuditLogger;
use App\Service\GitService;

/**
 * Registered-account platform features:
 *  - GET/POST /repos/new      → create a repository in your own namespace
 *  - GET/POST /settings/tokens→ personal API token management
 *  - GET /u/{username}        → public profile with the user's public repos
 */
final class AccountController
{
    private App $app;
    private Auth $auth;
    private GitService $gitService;
    private AuditLogger $auditLogger;

    public function __construct(App $app)
    {
        $this->app         = $app;
        $this->auth        = new Auth($app);
        $this->gitService  = new GitService();
        $this->auditLogger = new AuditLogger($app);
    }

    /** GET /repos/new — repository creation form (any logged-in identity) */
    public function newRepoForm(): void
    {
        $this->auth->requireAuth();

        $old = $_SESSION['new_repo_old'] ?? [];
        unset($_SESSION['new_repo_old']);

        $this->app->view()->display('account/new-repo.twig', [
            'csrf_token' => $this->auth->generateCsrf(),
            'owner_name' => $this->auth->displayName(),
            'old_name'        => (string) ($old['name'] ?? ''),
            'old_slug'        => (string) ($old['slug'] ?? ''),
            'old_description' => (string) ($old['description'] ?? ''),
            'old_visibility'  => (string) ($old['visibility'] ?? 'public'),
            'old_topics'      => (string) ($old['topics'] ?? ''),
            'old_homepage'    => (string) ($old['homepage'] ?? ''),
            'old_default_branch' => (string) ($old['default_branch'] ?? 'main'),
        ]);
    }

    /** POST /repos/new — validate + create the repository */
    public function newRepoStore(): void
    {
        $this->auth->requireAuth();

        if (! $this->auth->validateCsrf()) {
            flashAcc('flash_error', 'Invalid security token.', '/repos/new');
        }

        $name        = trim((string) ($_POST['name'] ?? ''));
        $description = trim((string) ($_POST['description'] ?? ''));
        $visibility  = ($_POST['visibility'] ?? 'public') === 'private' ? 'private' : 'public';
        $homepage    = trim((string) ($_POST['homepage'] ?? ''));
        $topics      = trim((string) ($_POST['topics'] ?? ''));
        $defaultBranch = trim((string) ($_POST['default_branch'] ?? 'main')) ?: 'main';
        $initReadme  = ! empty($_POST['init_readme']);
        $gitignore   = trim((string) ($_POST['gitignore'] ?? ''));
        $license     = trim((string) ($_POST['license'] ?? ''));

        // Slug: explicit input (normalized to lowercase) or derived from the name.
        $slug = trim((string) ($_POST['slug'] ?? ''));
        if ($slug === '') {
            $slug = strtolower(preg_replace('/[^a-zA-Z0-9_-]+/', '-', $name) ?? '');
        } else {
            $slug = strtolower($slug); // tolerate uppercase/human input
        }
        $slug = trim($slug, '-');

        $fail = static function (string $msg) use ($name, $description, $visibility, $homepage, $topics): never {
            $_SESSION['flash_error'] = $msg;
            $_SESSION['new_repo_old'] = ['name' => $name, 'description' => $description, 'visibility' => $visibility, 'homepage' => $homepage, 'topics' => $topics];
            header('Location: /repos/new');
            exit;
        };

        if ($name === '') {
            $fail('Repository name is required.');
        }

        if (! preg_match('/^[a-z0-9][a-z0-9._-]{1,98}[a-z0-9]$/', $slug)) {
            $fail('Identifier must be lowercase alphanumeric with dots, hyphens or underscores (2–100 chars).');
        }

        // Global limits and quotas validation
        if (! $this->auth->isOwner()) {
            $currentUserRole = (string) ($_SESSION['user']['role'] ?? 'user');
            if ($currentUserRole === 'restricted') {
                $fail('Your account has a Restricted role and is not permitted to create new repositories.');
            }
            if ($currentUserRole === 'bot') {
                $fail('Service bot accounts cannot create repositories interactively.');
            }

            $limitsRows = $this->app->db()->fetchAll("SELECT key_name, value FROM system_settings WHERE key_name IN ('max_repos_per_user', 'max_private_repos_per_user', 'allow_public_repo_creation', 'allow_private_repo_creation')");
            $policy = [];
            foreach ($limitsRows as $lr) {
                $policy[$lr['key_name']] = (string) $lr['value'];
            }

            if ($visibility === 'public' && ($policy['allow_public_repo_creation'] ?? '1') === '0') {
                $fail('Public repository creation is currently disabled by system policy.');
            }
            if ($visibility === 'private' && ($policy['allow_private_repo_creation'] ?? '1') === '0') {
                $fail('Private repository creation is currently disabled by system policy.');
            }

            $currentUserId = (int) ($this->auth->userId() ?? 0);
            if ($currentUserId > 0) {
                $maxTotal = (int) ($policy['max_repos_per_user'] ?? 50);
                if ($maxTotal > 0) {
                    $cnt = (int) ($this->app->db()->fetchOne('SELECT COUNT(*) AS c FROM repositories WHERE owner_user_id = :uid', ['uid' => $currentUserId])['c'] ?? 0);
                    if ($cnt >= $maxTotal) {
                        $fail("Repository quota limit reached. Maximum allowed per user is {$maxTotal}.");
                    }
                }

                $maxPrivate = (int) ($policy['max_private_repos_per_user'] ?? 10);
                if ($visibility === 'private' && $maxPrivate > 0) {
                    $cntPriv = (int) ($this->app->db()->fetchOne('SELECT COUNT(*) AS c FROM repositories WHERE owner_user_id = :uid AND visibility = "private"', ['uid' => $currentUserId])['c'] ?? 0);
                    if ($cntPriv >= $maxPrivate) {
                        $fail("Private repository quota limit reached. Maximum allowed private repositories is {$maxPrivate}.");
                    }
                }
            }
        }

        try {
            if ($this->gitService->repoExists($slug)) {
                $fail('A repository with that identifier already exists.');
            }

            $this->gitService->initRepo($slug, $defaultBranch);

            $userId   = $this->auth->isOwner() ? null : $this->identityId();
            $userName = $this->auth->displayName();

            // Optional initial README / .gitignore / LICENSE commit.
            if ($initReadme || $gitignore !== '' || $license !== '') {
                if ($initReadme) {
                    $this->gitService->saveFile($slug, $defaultBranch, 'README.md',
                        "# {$name}\n\n" . ($description !== '' ? $description . "\n\n" : '') . "Built with " . (string) $this->app->config('app.name', 'GitPHP') . ".\n",
                        "Initial commit", $userName, $userId ? $userName . '@localhost' : 'owner@localhost');
                }
                if ($gitignore !== '') {
                    $this->gitService->saveFile($slug, $defaultBranch, '.gitignore',
                        $this->gitignoreTemplate($gitignore),
                        "Add {$gitignore} .gitignore", $userName, $userId ? $userName . '@localhost' : 'owner@localhost');
                }
                if ($license !== '') {
                    $this->gitService->saveFile($slug, $defaultBranch, 'LICENSE',
                        $this->licenseTemplate($license, $name),
                        "Add {$license} license", $userName, $userId ? $userName . '@localhost' : 'owner@localhost');
                }
            }

            $this->app->db()->execute(
                'INSERT INTO `repositories`
                    (`slug`, `name`, `description`, `visibility`, `default_branch`, `homepage`, `topics`, `owner_user_id`, `created_at`, `updated_at`)
                 VALUES (:slug, :name, :desc, :vis, :branch, :home, :topics, :owner_id, NOW(), NOW())',
                [
                    'slug'     => $slug,
                    'name'     => mb_substr($name, 0, 255),
                    'desc'     => $description !== '' ? $description : null,
                    'vis'      => $visibility,
                    'branch'   => $defaultBranch,
                    'home'     => $homepage !== '' ? mb_substr($homepage, 0, 500) : null,
                    'topics'   => $topics !== '' ? mb_substr($topics, 0, 500) : null,
                    'owner_id' => $userId,
                ],
            );

            $repoId = (int) $this->app->db()->lastInsertId();

            $this->auditLogger->log(
                'repo.create',
                $repoId,
                "Created repository {$slug} ({$visibility})",
                $this->identityId(),
                $userName,
            );
        } catch (\Throwable $e) {
            $fail('Failed to create repository: ' . $e->getMessage());
        }

        unset($_SESSION['new_repo_old']);
        flashAcc("flash_success", "Repository '{$name}' created.", "/{$userName}/{$slug}");
    }

    /** A few ready-made .gitignore templates keyed by id. */
    private function gitignoreTemplate(string $id): string
    {
        $map = [
            'php'        => "/vendor/\ncomposer.lock\n.phpunit.result.cache\n.env\nnode_modules/\n",
            'node'       => "node_modules/\ndist/\n.env\n*.log\n",
            'python'     => "__pycache__/\n*.py[cod]\n.venv/\n.env\n",
            'java'       => "target/\n*.class\n.idea/\n*.iml\n",
            'go'         => "bin/\n*.exe\nvendor/\n",
        ];

        return $map[$id] ?? ("# {$id}\n");
    }

    /** A few ready-made LICENSE templates keyed by id. */
    private function licenseTemplate(string $id, string $name): string
    {
        $year = date('Y');
        $map = [
            'MIT' => "MIT License\n\nCopyright (c) {$year} {$name}\n\nPermission is hereby granted, free of charge, ...",
            'Apache-2.0' => "Apache License 2.0\n\nCopyright (c) {$year} {$name}\n\nLicensed under the Apache License, Version 2.0 ...",
            'GPL-3.0'    => "GNU GENERAL PUBLIC LICENSE\nVersion 3, 29 June 2007\n\nCopyright (C) {$year} {$name}\n",
        ];

        return $map[$id] ?? ("# {$id} license\n");
    }

    /** GET /settings/tokens/signed — signed push signatures subtab */
    public function tokensSignedIndex(): void
    {
        $_GET['tab'] = 'signed';
        $this->tokensIndex();
    }

    /** GET /settings/tokens/pat — personal access tokens subtab */
    public function tokensPatIndex(): void
    {
        $_GET['tab'] = 'pat';
        $this->tokensIndex();
    }

    /** GET /settings/tokens — personal access tokens */
    public function tokensIndex(): void
    {
        $this->auth->requireAuth();
        $uid = $this->identityId();

        $tokens = $this->app->db()->fetchAll(
            'SELECT `id`, `name`, `token_prefix`, `scopes`, `last_used_at`, `created_at`, `revoked_at`
             FROM `api_tokens`
             WHERE `user_id` = :user
             ORDER BY `created_at` DESC',
            ['user' => $uid],
        );

        // Fetch user's repositories for the target repository selector
        $repos = $this->app->db()->fetchAll(
            'SELECT `id`, `name`, `slug` FROM `repositories` WHERE `owner_user_id` = :uid OR :is_owner = 1 ORDER BY `name` ASC',
            ['uid' => $uid, 'is_owner' => $this->auth->isOwner() ? 1 : 0],
        );

        $createdData = $_SESSION['new_token_data'] ?? null;
        if (! $createdData && ! empty($_SESSION['new_token_plain'])) {
            $createdData = [
                'raw_token'   => $_SESSION['new_token_plain'],
                'target_repo' => $repos[0]['slug'] ?? 'repository',
            ];
        }
        unset($_SESSION['new_token_data'], $_SESSION['new_token_plain']);

        $appUrl   = rtrim((string) $this->app->config('app.url', 'https://git.ysnapp.com'), '/');
        $appHost  = parse_url($appUrl, PHP_URL_HOST) ?: 'git.ysnapp.com';
        $currUser = $this->auth->displayName();

        $this->app->view()->display('account/tokens.twig', [
            'csrf_token'         => $this->auth->generateCsrf(),
            'tokens'             => $tokens,
            'repos'              => $repos,
            'created_token_data' => $createdData,
            'owner_name'         => $currUser,
            'current_user'       => $currUser,
            'app_url'            => $appUrl,
            'app_host'           => $appHost,
        ]);
    }

    /** POST /settings/tokens — mint a new permanent PAT token */
    public function tokensCreate(): void
    {
        $this->auth->requireAuth();

        if (! $this->auth->validateCsrf()) {
            flashAcc('flash_error', 'Invalid security token.', '/settings/tokens');
        }

        $name     = trim((string) ($_POST['name'] ?? ''));
        $repoSlug = trim((string) ($_POST['repo_slug'] ?? ''));

        $rawScopes = $_POST['scopes'] ?? ['repo'];
        if (is_array($rawScopes)) {
            $scopeList = array_values(array_filter(array_map('trim', $rawScopes)));
            $scopesStr = ! empty($scopeList) ? implode(', ', $scopeList) : 'repo';
        } else {
            $scopesStr = trim((string) $rawScopes) ?: 'repo';
        }

        if ($name === '') {
            flashAcc('flash_error', 'Give the token a descriptive name.', '/settings/tokens');
        }

        $maxPats = (int) ($this->app->db()->fetchOne("SELECT value FROM system_settings WHERE key_name = 'max_pats_per_user'")['value'] ?? 20);
        if ($maxPats > 0 && ! $this->auth->isOwner()) {
            $currCount = (int) ($this->app->db()->fetchOne('SELECT COUNT(*) AS c FROM api_tokens WHERE user_id = :uid AND revoked_at IS NULL', ['uid' => $this->identityId()])['c'] ?? 0);
            if ($currCount >= $maxPats) {
                flashAcc('flash_error', "Maximum Personal Access Tokens limit reached ({$maxPats}). Please revoke unused tokens first.", '/settings/tokens');
            }
        }

        $service = new ApiTokenService($this->app);
        $result  = $service->create($this->identityId(), $name, $scopesStr);

        $this->auditLogger->log(
            'token.create',
            null,
            "Created API token '{$name}' ({$scopesStr})",
            $this->identityId(),
            $this->auth->displayName(),
        );

        if (session_status() === PHP_SESSION_NONE) session_start();
        $_SESSION['new_token_data'] = [
            'raw_token'   => $result['token'],
            'target_repo' => $repoSlug,
            'scopes'      => $scopesStr,
        ];

        header('Location: /settings/tokens');
        exit;
    }

    /** POST /settings/tokens/{id:\d+}/revoke — revoke a PAT */
    public function tokensRevoke(int $id): void
    {
        $this->auth->requireAuth();

        if (! $this->auth->validateCsrf()) {
            flashAcc('flash_error', 'Invalid security token.', '/settings/tokens');
        }

        $service = new ApiTokenService($this->app);
        $service->revoke($id, $this->identityId());

        $this->auditLogger->log(
            'token.revoke',
            null,
            "Revoked API token #{$id}",
            $this->identityId(),
            $this->auth->displayName(),
        );

        flashAcc('flash_success', 'Token revoked.', '/settings/tokens');
    }

    /** POST /settings/tokens/{id:\d+}/delete — permanently delete a token */
    public function tokensDelete(int $id): void
    {
        $this->auth->requireAuth();

        if (! $this->auth->validateCsrf()) {
            flashAcc('flash_error', 'Invalid security token.', '/settings/tokens');
        }

        $service = new ApiTokenService($this->app);
        $service->delete($id, $this->identityId());

        $this->auditLogger->log(
            'token.delete',
            null,
            "Permanently deleted API token #{$id}",
            $this->identityId(),
            $this->auth->displayName(),
        );

        flashAcc('flash_success', 'Token permanently deleted.', '/settings/tokens');
    }

    /** POST /settings/tokens/signed or /settings/signed-tokens — create a signed quick-sync capability token */
    public function signedTokensCreate(): void
    {
        $this->auth->requireAuth();

        if (! $this->auth->validateCsrf()) {
            flashAcc('flash_error', 'Invalid security token.', '/settings/tokens?tab=signed');
        }

        $label      = trim((string) ($_POST['label'] ?? ($_POST['name'] ?? '')));
        $name       = $label !== '' ? $label : 'Quick Push Token';
        $scope      = (string) ($_POST['scope'] ?? 'push');
        if ($scope === 'write') $scope = 'push';
        if ($scope === 'read')  $scope = 'clone';

        // Duration is passed in minutes from form (e.g. 15, 60, 360, 1440) -> convert to seconds
        $durationRaw = (int) ($_POST['duration'] ?? 60);
        $durationSec = ($durationRaw > 0 && $durationRaw <= 10080) ? ($durationRaw * 60) : max(60, $durationRaw);

        $repoSlug   = trim((string) ($_POST['repo_slug'] ?? ''));
        $repoId     = null;
        if ($repoSlug !== '') {
            $r = $this->app->db()->fetchOne('SELECT `id` FROM `repositories` WHERE `slug` = :s LIMIT 1', ['s' => $repoSlug]);
            if ($r) $repoId = (int) $r['id'];
        } elseif (! empty($_POST['repo_id'])) {
            $repoId = (int) $_POST['repo_id'];
        }

        $singleUse  = ! empty($_POST['single_use']);
        $allowedIp  = trim((string) ($_POST['allowed_ip'] ?? '')) ?: null;

        $sigService = new \App\Service\SignedTokenService($this->app);
        $created    = $sigService->createToken(
            $this->identityId(),
            $name,
            $scope,
            $repoId,
            $durationSec,
            $singleUse,
            $allowedIp,
        );

        $tokenPlain = (string) ($created['raw_token'] ?? '');
        $appHost    = parse_url((string) $this->app->config('app.url', 'https://git.ysnapp.com'), PHP_URL_HOST) ?: 'git.ysnapp.com';
        $user       = $this->auth->displayName();
        $targetRepo = $repoSlug ?: 'repository';
        $sampleCmd  = "git push https://{$user}:{$tokenPlain}@{$appHost}/{$user}/{$targetRepo}.git main";

        $created['token']      = $tokenPlain;
        $created['raw_token']  = $tokenPlain;
        $created['repo_slug']  = $targetRepo;
        $created['sample_cmd'] = $sampleCmd;

        $this->auditLogger->log(
            'signed_token.create',
            $repoId,
            "Created Signed Push/Clone Signature '{$name}' ({$scope}, expires: {$created['expires_at']})",
            $this->identityId(),
            $this->auth->displayName(),
        );

        if (session_status() === PHP_SESSION_NONE) session_start();
        $_SESSION['new_signed_token'] = $created;
        header('Location: /settings/tokens?tab=signed');
        exit;
    }

    /** POST /settings/signed-tokens/{id:\d+}/revoke — revoke a signed token */
    public function signedTokensRevoke(int $id): void
    {
        $this->auth->requireAuth();

        if (! $this->auth->validateCsrf()) {
            flashAcc('flash_error', 'Invalid security token.', '/settings/tokens?tab=signed');
        }

        $sigService = new \App\Service\SignedTokenService($this->app);
        $sigService->revokeToken($id, $this->identityId());

        $this->auditLogger->log(
            'signed_token.revoke',
            null,
            "Revoked Signed Signature #{$id}",
            $this->identityId(),
            $this->auth->displayName(),
        );

        flashAcc('flash_success', 'Signed signature revoked.', '/settings/tokens?tab=signed');
    }


    /** GET /u/{username} — legacy alias, redirects to the canonical /{username}. */
    public function profileRedirect(string $username): never
    {
        header('Location: /' . rawurlencode(trim($username)), true, 301);
        exit;
    }

    /**
     * GET /{username} — public profile page.
     *
     * Shows the identity's repositories with GitHub-style tabs:
     *   - repositories (default): public always; private included when the
     *     viewer is the profile owner or the platform owner
     *   - stars: repositories the identity starred (public ones)
     */
    public function profile(string $username): void
    {
        $username = trim($username);

        $ownerName  = (string) $this->app->config('app.owner', 'admin');
        $isOwnerProfile = strcasecmp($username, $ownerName) === 0;

        $ownerSettings = [];
        if ($isOwnerProfile) {
            $db = $this->app->db()->connection();
            $stmt = $db->query("SELECT key_name, value FROM system_settings WHERE key_name LIKE 'owner_%'");
            $ownerSettings = $stmt ? $stmt->fetchAll(\PDO::FETCH_KEY_PAIR) : [];
            $user = false;
        } else {
            $user = $this->app->db()->fetchOne(
                'SELECT `id`, `username`, `display_name`, `bio`, `company`, `location`, `website`, `twitter`, `avatar_path`, `created_at` FROM `users` WHERE `username` = :name LIMIT 1',
                ['name' => $username],
            );
        }

        if ($user === false && ! $isOwnerProfile) {
            http_response_code(404);
            echo $this->app->view()->render('partials/error.html.twig', ['code' => 404, 'message' => 'User not found.']);
            return;
        }

        $profileUserId = $isOwnerProfile ? null : (int) ($user['id'] ?? 0);
        $displayName   = $isOwnerProfile
            ? (!empty($ownerSettings['owner_display_name']) ? $ownerSettings['owner_display_name'] : (string) $this->app->config('app.owner_name', 'Administrator'))
            : (!empty($user['display_name']) ? (string) $user['display_name'] : (string) ($user['username'] ?? $username));
        $memberSince   = $isOwnerProfile ? '2024-01-01' : ($user['created_at'] ?? null);

        // Who is looking? Determines private-repo inclusion and action buttons.
        $viewerIsSelf = false;
        if ($this->auth->isLoggedIn()) {
            $viewerIsSelf = $this->auth->isOwner()
                ? $isOwnerProfile
                : (! $isOwnerProfile && $this->identityId() === $profileUserId);
        }

        $includePrivate = $viewerIsSelf || $this->auth->isOwner();

        // ── Repositories tab ─────────────────────────────────────────
        if ($isOwnerProfile) {
            $sql = 'SELECT r.*, p.slug AS parent_slug, p.name AS parent_name,
                           u.username AS owner_username
                    FROM `repositories` r
                    LEFT JOIN `repositories` p ON r.forked_from_id = p.id
                    LEFT JOIN `users` u ON u.id = r.owner_user_id
                    WHERE r.`owner_user_id` IS NULL';
            $params = [];
        } else {
            $sql = 'SELECT r.*, p.slug AS parent_slug, p.name AS parent_name,
                           u.username AS owner_username
                    FROM `repositories` r
                    LEFT JOIN `repositories` p ON r.forked_from_id = p.id
                    LEFT JOIN `users` u ON u.id = r.owner_user_id
                    WHERE r.`owner_user_id` = :uid';
            $params = ['uid' => $profileUserId];
        }

        if (! $includePrivate) {
            $sql .= " AND r.`visibility` = 'public'";
        }
        $sql .= ' ORDER BY r.`updated_at` DESC';

        $repos = $this->app->db()->fetchAll($sql, $params);

        $languagesMap = [];
        $publicCount = 0;
        $totalStars  = 0;

        foreach ($repos as &$row) {
            $row['display_owner'] = ! empty($row['owner_username'])
                ? (string) $row['owner_username']
                : $ownerName;

            if (($row['visibility'] ?? '') === 'public') $publicCount++;
            $totalStars += (int) ($row['stars_count'] ?? 0);

            // Detect language
            $slug = (string) $row['slug'];
            $langInfo = $this->app->cache()->remember("repo:{$slug}:lang_info", 180, function () use ($slug, $row) {
                return $this->detectRepoLanguage($slug, (string) ($row['default_branch'] ?? 'HEAD'));
            });

            $row['primary_lang'] = $langInfo['name'] ?? null;
            $row['lang_color']   = $langInfo['color'] ?? '#58a6ff';

            if (!empty($row['primary_lang'])) {
                $languagesMap[$row['primary_lang']] = [
                    'name'  => $row['primary_lang'],
                    'color' => $row['lang_color'],
                ];
            }
        }
        unset($row);

        // Pinned repositories: Top 6 public repos (sorted by stars, forks, then updated)
        $pinnedRepos = $repos;
        usort($pinnedRepos, function ($a, $b) {
            $sA = (int) ($a['stars_count'] ?? 0);
            $sB = (int) ($b['stars_count'] ?? 0);
            if ($sA !== $sB) return $sB <=> $sA;
            return strcmp((string) ($b['updated_at'] ?? ''), (string) ($a['updated_at'] ?? ''));
        });
        $pinnedRepos = array_slice($pinnedRepos, 0, 6);

        // ── Stars tab ────────────────────────────────────────────────
        $tab = (string) ($_GET['tab'] ?? 'overview');
        if (!in_array($tab, ['overview', 'repositories', 'stars'], true)) {
            $tab = 'overview';
        }

        $starredRepos = [];
        {
            $starSql = "SELECT r.*, u.username AS owner_username
                        FROM `repo_likes` l
                        JOIN `repositories` r ON r.id = l.repo_id
                        LEFT JOIN `users` u ON u.id = r.owner_user_id
                        WHERE r.`visibility` = 'public'";
            $starParams = [];

            if ($isOwnerProfile || $profileUserId === 0) {
                $starSql .= ' AND l.`user_id` = 0';
            } else {
                $starSql .= ' AND l.`user_id` = :uid';
                $starParams = ['uid' => $profileUserId];
            }
            $starSql .= ' ORDER BY l.id DESC LIMIT 50';

            $starredRepos = $this->app->db()->fetchAll($starSql, $starParams);

            foreach ($starredRepos as &$row) {
                $row['display_owner'] = ! empty($row['owner_username'])
                    ? (string) $row['owner_username']
                    : $ownerName;

                $slug = (string) $row['slug'];
                $langInfo = $this->app->cache()->remember("repo:{$slug}:lang_info", 180, function () use ($slug, $row) {
                    return $this->detectRepoLanguage($slug, (string) ($row['default_branch'] ?? 'HEAD'));
                });
                $row['primary_lang'] = $langInfo['name'] ?? null;
                $row['lang_color']   = $langInfo['color'] ?? '#58a6ff';
            }
            unset($row);
        }

        // Real dynamic Follows and Stars calculation (Cached for 45s)
        $targetId = $isOwnerProfile ? 0 : (int) ($user['id'] ?? 0);
        $currentId = $this->auth->isLoggedIn() ? ($this->auth->isOwner() ? 0 : (int) $this->auth->userId()) : -1;

        $statsCache = $this->app->cache()->remember("user:profile:stats:{$targetId}", 45, function () use ($targetId, $isOwnerProfile) {
            $followers = (int) ($this->app->db()->fetchOne(
                'SELECT COUNT(*) AS c FROM `user_follows` WHERE `following_id` = :t',
                ['t' => $targetId]
            )['c'] ?? 0);

            $following = (int) ($this->app->db()->fetchOne(
                'SELECT COUNT(*) AS c FROM `user_follows` WHERE `follower_id` = :t',
                ['t' => $targetId]
            )['c'] ?? 0);

            $stars = (int) ($this->app->db()->fetchOne(
                'SELECT COUNT(*) AS c FROM `repo_likes` l JOIN `repositories` r ON r.id = l.repo_id WHERE ' .
                ($isOwnerProfile ? 'r.owner_user_id IS NULL' : 'r.owner_user_id = :uid'),
                $isOwnerProfile ? [] : ['uid' => $targetId]
            )['c'] ?? 0);

            return [
                'followers' => $followers,
                'following' => $following,
                'stars'     => $stars,
            ];
        });

        $followersCount = $statsCache['followers'] ?? 0;
        $followingCount = $statsCache['following'] ?? 0;
        $realStarsCount = $statsCache['stars'] ?? 0;

        $isFollowing = false;
        if ($currentId >= 0 && $currentId !== $targetId) {
            $isFollowing = $this->app->db()->fetchOne(
                'SELECT id FROM `user_follows` WHERE `follower_id` = :f AND `following_id` = :t LIMIT 1',
                ['f' => $currentId, 't' => $targetId]
            ) !== false;
        }

        // Dynamic Real Achievements
        $achievements = [];

        // 1. Arctic Code Vault: for active code repositories
        if (count($repos) > 0) {
            $achievements[] = [
                'id'    => 'arctic-code-vault',
                'name'  => 'Arctic Code Vault Contributor',
                'desc'  => 'Contributed code to repositories',
                'icon'  => 'vault',
                'color' => '#2ea043',
                'bg'    => 'rgba(46, 160, 67, 0.15)',
                'border'=> 'rgba(46, 160, 67, 0.4)',
            ];
        }

        // 2. Starstruck: for receiving stars
        if ($realStarsCount > 0) {
            $achievements[] = [
                'id'    => 'starstruck',
                'name'  => 'Starstruck',
                'desc'  => "Repositories received {$realStarsCount} stars",
                'icon'  => 'star',
                'color' => '#e3b341',
                'bg'    => 'rgba(227, 179, 65, 0.15)',
                'border'=> 'rgba(227, 179, 65, 0.4)',
            ];
        }

        // 3. Pull Shark: for PRs
        $prCount = (int) ($this->app->db()->fetchOne(
            'SELECT COUNT(*) AS c FROM `pull_requests` WHERE ' . ($isOwnerProfile ? '1=1' : 'created_by = :u'),
            $isOwnerProfile ? [] : ['u' => $targetId]
        )['c'] ?? 0);
        if ($prCount > 0 || $isOwnerProfile) {
            $achievements[] = [
                'id'    => 'pull-shark',
                'name'  => 'Pull Shark',
                'desc'  => 'Opened and merged pull requests',
                'icon'  => 'pr',
                'color' => '#58a6ff',
                'bg'    => 'rgba(56, 139, 253, 0.15)',
                'border'=> 'rgba(56, 139, 253, 0.4)',
            ];
        }

        // 4. Quickdraw: for issues
        $issueCount = (int) ($this->app->db()->fetchOne(
            'SELECT COUNT(*) AS c FROM `bug_reports` WHERE ' . ($isOwnerProfile ? '1=1' : 'user_id = :u'),
            $isOwnerProfile ? [] : ['u' => $targetId]
        )['c'] ?? 0);
        if ($issueCount > 0 || $isOwnerProfile) {
            $achievements[] = [
                'id'    => 'quickdraw',
                'name'  => 'Quickdraw',
                'desc'  => 'Closed issues and reviews quickly',
                'icon'  => 'zap',
                'color' => '#a371f7',
                'bg'    => 'rgba(163, 113, 247, 0.15)',
                'border'=> 'rgba(163, 113, 247, 0.4)',
            ];
        }

        // 5. Pair Extraordinaire
        if ($isOwnerProfile || count($repos) >= 2) {
            $achievements[] = [
                'id'    => 'pair-extraordinaire',
                'name'  => 'Pair Extraordinaire',
                'desc'  => 'Co-authored commits across multiple repositories',
                'icon'  => 'pair',
                'color' => '#39d353',
                'bg'    => 'rgba(57, 211, 83, 0.15)',
                'border'=> 'rgba(57, 211, 83, 0.4)',
            ];
        }

        // Contribution Activity Calendar
        $contributionCalendar = $this->app->cache()->remember("user:{$username}:contributions", 120, function () use ($repos) {
            return $this->buildContributionCalendar($repos);
        });

        $this->app->view()->display('u/profile.twig', [
            'profile_name'          => $username,
            'display_name'          => $displayName,
            'avatar_path'           => $isOwnerProfile ? ($ownerSettings['owner_avatar_path'] ?? null) : ($user['avatar_path'] ?? null),
            'is_owner_profile'      => $isOwnerProfile,
            'member_since'          => $memberSince,
            'repos'                 => $repos,
            'pinned_repos'          => $pinnedRepos,
            'languages'             => array_values($languagesMap),
            'public_count'          => $publicCount,
            'private_count'         => count($repos) - $publicCount,
            'total_stars'           => $realStarsCount,
            'followers_count'       => $followersCount,
            'following_count'       => $followingCount,
            'is_following'          => $isFollowing,
            'achievements'          => $achievements,
            'csrf_token'            => $this->auth->generateCsrf(),
            'tab'                   => $tab,
            'starred_repos'         => $starredRepos,
            'viewer_is_self'        => $viewerIsSelf,
            'bio'                   => $isOwnerProfile ? ($ownerSettings['owner_bio'] ?? 'Platform Owner & Administrator') : ($user['bio'] ?? null),
            'company'               => $isOwnerProfile ? ($ownerSettings['owner_company'] ?? null) : ($user['company'] ?? null),
            'location'              => $isOwnerProfile ? ($ownerSettings['owner_location'] ?? null) : ($user['location'] ?? null),
            'website'               => $isOwnerProfile ? ($ownerSettings['owner_website'] ?? null) : ($user['website'] ?? null),
            'twitter'               => $isOwnerProfile ? ($ownerSettings['owner_twitter'] ?? null) : ($user['twitter'] ?? null),
            'contributions'         => $contributionCalendar,
        ]);
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

        try {
            $gitService = new \App\Service\GitService();
            $path = $gitService->getRepoPath($slug);
            $cmd = ['git', '--git-dir=' . $path, 'ls-tree', '-r', '--name-only', $ref];
            $proc = proc_open($cmd, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
            if (!is_resource($proc)) return null;

            $counts = [];
            $vendorPatterns = [
                'bin/', 'vendor/', 'node_modules/', 'dist/', 'build/', 'packages/',
                'third_party/', 'thirdparty/', 'extern/', 'external/', '.venv/', 'env/',
                'virtualenv/', 'cache/', 'storage/', 'bower_components/', 'site-packages/',
                'assets/vendor/', 'public/vendor/', 'static/vendor/', 'mariadb', 'mysql',
                'php8', 'php7', 'apache', 'nginx', 'lib/', 'libs/'
            ];
            while (($line = fgets($pipes[1])) !== false) {
                $trimmed = trim($line);
                $lowerFile = strtolower($trimmed);
                $isVendored = false;
                foreach ($vendorPatterns as $vp) {
                    if (str_starts_with($lowerFile, $vp) || str_contains($lowerFile, '/' . $vp)) {
                        $isVendored = true;
                        break;
                    }
                }
                if ($isVendored) continue;

                $ext = strtolower(pathinfo($trimmed, PATHINFO_EXTENSION));
                if (isset($langMap[$ext])) {
                    $langName = $langMap[$ext]['name'];
                    $counts[$langName] = ($counts[$langName] ?? 0) + 1;
                }
            }
            fclose($pipes[1]);
            fclose($pipes[2]);
            proc_close($proc);

            if (empty($counts)) return null;
            arsort($counts);
            $top = array_key_first($counts);
            foreach ($langMap as $info) {
                if ($info['name'] === $top) return $info;
            }
        } catch (\Throwable) {}

        return null;
    }

    private function buildContributionCalendar(array $repos): array
    {
        $now = new \DateTimeImmutable('today');
        $start = $now->modify('-52 weeks')->modify('last sunday');
        if ($start > $now->modify('-52 weeks')) {
            $start = $start->modify('-7 days');
        }

        $daysMap = [];
        $curr = $start;
        while ($curr <= $now) {
            $daysMap[$curr->format('Y-m-d')] = 0;
            $curr = $curr->modify('+1 day');
        }

        $gitService = new \App\Service\GitService();
        $totalCommits = 0;

        foreach ($repos as $repo) {
            $slug = (string) $repo['slug'];
            try {
                $path = $gitService->getRepoPath($slug);
                $cmd = ['git', '--git-dir=' . $path, 'log', '--format=%ad', '--date=short', '-n', '500', (string) ($repo['default_branch'] ?: 'HEAD')];
                $proc = proc_open($cmd, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
                if (is_resource($proc)) {
                    while (($line = fgets($pipes[1])) !== false) {
                        $d = trim($line);
                        if (isset($daysMap[$d])) {
                            $daysMap[$d]++;
                            $totalCommits++;
                        }
                    }
                    fclose($pipes[1]);
                    fclose($pipes[2]);
                    proc_close($proc);
                }
            } catch (\Throwable) {}
        }

        $weeks = [];
        $week = [];
        $months = [];
        $lastMonth = '';
        $weekIndex = 0;

        foreach ($daysMap as $date => $count) {
            $dt = new \DateTimeImmutable($date);
            $m = $dt->format('M');
            if ($m !== $lastMonth && count($week) === 0) {
                $months[] = ['name' => $m, 'week' => $weekIndex];
                $lastMonth = $m;
            }

            $level = 0;
            if ($count > 0) $level = 1;
            if ($count >= 3) $level = 2;
            if ($count >= 6) $level = 3;
            if ($count >= 10) $level = 4;

            $week[] = [
                'date'  => $date,
                'count' => $count,
                'level' => $level,
                'day'   => (int) $dt->format('w'),
            ];

            if (count($week) === 7) {
                $weeks[] = $week;
                $week = [];
                $weekIndex++;
            }
        }
        if (!empty($week)) {
            $weeks[] = $week;
        }

        return [
            'weeks'  => $weeks,
            'months' => $months,
            'total'  => $totalCommits,
            'year'   => (int) $now->format('Y'),
        ];
    }

    public function accountSettings(): void
    {
        $this->auth->requireAuth();

        $uri = parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH) ?: '';
        if (str_ends_with($uri, '/keys') || str_contains($uri, '/settings/keys')) {
            $tab = 'keys';
        } elseif (str_ends_with($uri, '/updates') || str_contains($uri, '/settings/updates')) {
            $tab = 'updates';
        } else {
            $tab = (string) ($_GET['tab'] ?? 'profile');
        }
        if (! in_array($tab, ['profile', 'account', 'security', 'keys', 'updates', 'danger'], true)) {
            $tab = 'profile';
        }

        if ($this->auth->isOwner()) {
            $db = $this->app->db()->connection();
            $stmt = $db->query("SELECT key_name, value FROM system_settings WHERE key_name LIKE 'owner_%'");
            $ownerSettings = $stmt ? $stmt->fetchAll(\PDO::FETCH_KEY_PAIR) : [];

            $u = [
                'id'           => 0,
                'username'     => (string) $this->app->config('app.owner', 'admin'),
                'display_name' => !empty($ownerSettings['owner_display_name']) ? $ownerSettings['owner_display_name'] : (string) $this->app->config('app.owner', 'Administrator'),
                'email'        => !empty($ownerSettings['owner_email']) ? $ownerSettings['owner_email'] : (string) $this->app->config('app.admin_email', 'admin@localhost'),
                'bio'          => $ownerSettings['owner_bio'] ?? 'Server Platform Administrator',
                'company'      => $ownerSettings['owner_company'] ?? 'Git Hosting Services',
                'location'     => $ownerSettings['owner_location'] ?? '',
                'website'      => !empty($ownerSettings['owner_website']) ? $ownerSettings['owner_website'] : (string) $this->app->config('app.url', 'https://git.ysnapp.com'),
                'twitter'      => $ownerSettings['owner_twitter'] ?? '',
                'avatar_path'  => !empty($ownerSettings['owner_avatar_path']) ? $ownerSettings['owner_avatar_path'] : null,
                'role'         => 'admin',
                'created_at'   => null,
            ];
        } else {
            $u = $this->app->db()->fetchOne(
                'SELECT * FROM `users` WHERE `id` = :id LIMIT 1',
                ['id' => $this->identityId()]
            );

            if ($u === false) {
                $_SESSION['flash_error'] = 'Session expired. Please sign in again.';
                header('Location: /login');
                exit;
            }
        }

        $tokenService = new \App\Service\DownloadTokenService($this->app);
        $updateToken = $tokenService->getUserUpdateKey($this->identityId());

        $sshService = new \App\Service\SshKeyService($this->app->db());
        $sshKeys = $sshService->getAll($this->identityId());

        $this->app->view()->display('account/settings.twig', [
            'csrf_token' => $this->auth->generateCsrf(),
            'user'       => $u,
            'active_tab' => $tab,
            'is_owner'   => $this->auth->isOwner(),
            'owner_name' => $this->auth->displayName(),
            'ssh_keys'   => $sshKeys,
            'update_token' => $updateToken,
            'app_url'      => rtrim((string) $this->app->config('app.url', 'https://git.ysnapp.com'), '/'),
        ]);
    }

    /** POST /settings/keys — add user SSH key */
    
    /** POST /settings/updates/regenerate-token — regenerate user-specific remote update token */
    public function regenerateUpdateToken(): void
    {
        $this->auth->requireAuth();

        if (! $this->auth->validateCsrf()) {
            flashAcc('flash_error', 'Invalid security token.', '/settings/account?tab=updates');
        }

        $tokenService = new \App\Service\DownloadTokenService($this->app);
        $newKey = $tokenService->regenerateUserUpdateKey($this->identityId());

        flashAcc('flash_success', \App\Service\Locale::t('ui.remote_updates.token_regenerated_success'), '/settings/account?tab=updates');
    }

    public function sshKeyAdd(): void
    {
        $this->auth->requireAuth();

        if (! $this->auth->validateCsrf()) {
            flashAcc('flash_error', 'Invalid security token.', '/settings/account?tab=keys');
        }

        $title     = trim((string) ($_POST['title'] ?? ''));
        $publicKey = trim((string) ($_POST['public_key'] ?? ''));

        if ($title === '') {
            flashAcc('flash_error', 'A title for the SSH key is required.', '/settings/account?tab=keys');
        }

        if ($publicKey === '') {
            flashAcc('flash_error', 'The public key field is required.', '/settings/account?tab=keys');
        }

        $maxSsh = (int) ($this->app->db()->fetchOne("SELECT value FROM system_settings WHERE key_name = 'max_ssh_keys_per_user'")['value'] ?? 10);
        if ($maxSsh > 0 && ! $this->auth->isOwner()) {
            $currCount = (int) ($this->app->db()->fetchOne('SELECT COUNT(*) AS c FROM ssh_keys WHERE user_id = :uid', ['uid' => $this->identityId()])['c'] ?? 0);
            if ($currCount >= $maxSsh) {
                flashAcc('flash_error', "SSH Key limit reached (Maximum allowed: {$maxSsh}).", '/settings/account?tab=keys');
            }
        }

        try {
            $tokenService = new \App\Service\DownloadTokenService($this->app);
        $updateToken = $tokenService->getUserUpdateKey($this->identityId());

        $sshService = new \App\Service\SshKeyService($this->app->db());
            $sshService->add($title, $publicKey, $this->identityId());
            $this->auditLogger->log('ssh_key.add', null, "Added SSH key '{$title}'", $this->identityId(), $this->auth->displayName());
            flashAcc('flash_success', "SSH key '{$title}' added successfully.", '/settings/account?tab=keys');
        } catch (\Throwable $e) {
            flashAcc('flash_error', $e->getMessage(), '/settings/account?tab=keys');
        }
    }

    /** POST /settings/keys/{id:\d+}/delete — delete user SSH key */
    public function sshKeyDelete(int $id): void
    {
        $this->auth->requireAuth();

        if (! $this->auth->validateCsrf()) {
            flashAcc('flash_error', 'Invalid security token.', '/settings/account?tab=keys');
        }

        $key = $this->app->db()->fetchOne('SELECT * FROM ssh_keys WHERE id = :id LIMIT 1', ['id' => $id]);
        if (!$key) {
            flashAcc('flash_error', 'SSH key not found.', '/settings/account?tab=keys');
        }

        // Must be key owner or platform owner
        if (!$this->auth->isOwner() && (int)($key['user_id'] ?? -1) !== $this->identityId()) {
            flashAcc('flash_error', 'Access denied.', '/settings/account?tab=keys');
        }

        try {
            $tokenService = new \App\Service\DownloadTokenService($this->app);
        $updateToken = $tokenService->getUserUpdateKey($this->identityId());

        $sshService = new \App\Service\SshKeyService($this->app->db());
            $sshService->delete($id);
            $this->auditLogger->log('ssh_key.delete', null, "Deleted SSH key #{$id}", $this->identityId(), $this->auth->displayName());
            flashAcc('flash_success', 'SSH key deleted successfully.', '/settings/account?tab=keys');
        } catch (\Throwable $e) {
            flashAcc('flash_error', $e->getMessage(), '/settings/account?tab=keys');
        }
    }

    /** POST /settings/account/profile — update profile fields & avatar picture. */
    public function updateProfile(): void
    {
        $this->auth->requireAuth();

        if (! $this->auth->validateCsrf()) {
            flashAcc('flash_error', 'Invalid security token.', '/settings/account?tab=profile');
        }

        $displayName = trim((string) ($_POST['display_name'] ?? ''));
        $bio         = trim((string) ($_POST['bio'] ?? ''));
        $company     = trim((string) ($_POST['company'] ?? ''));
        $location    = trim((string) ($_POST['location'] ?? ''));
        $website     = trim((string) ($_POST['website'] ?? ''));
        $twitter     = trim((string) ($_POST['twitter'] ?? ''));
        $twitter     = ltrim($twitter, '@');

        if ($website !== '' && ! filter_var($website, FILTER_VALIDATE_URL)) {
            flashAcc('flash_error', 'Website must be a valid URL.', '/settings/account?tab=profile');
        }

        $userId = $this->identityId();
        $isOwner = $this->auth->isOwner();
        $avatarPath = null;

        // Check if removing avatar
        if (! empty($_POST['remove_avatar'])) {
            $avatarPath = '';
        }

        // Handle Avatar File Upload
        if (isset($_FILES['avatar']) && is_array($_FILES['avatar']) && (int)$_FILES['avatar']['error'] === UPLOAD_ERR_OK) {
            $file = $_FILES['avatar'];
            $maxAvatarKb = (int) ($this->app->db()->fetchOne("SELECT value FROM system_settings WHERE key_name = 'max_avatar_size_kb'")['value'] ?? 2048);
            $maxSize = max(100, $maxAvatarKb) * 1024;

            if ($file['size'] > $maxSize) {
                $maxDisplay = round($maxSize / 1024);
                flashAcc('flash_error', "Avatar image exceeds the maximum allowed size of {$maxDisplay} KB.", '/settings/account?tab=profile');
            }

            // SVG avatars are deliberately NOT allowed: they are served
            // directly from the web root as image/svg+xml, and a crafted
            // SVG (embedded <script>) would execute on every page that
            // renders the avatar — a stored-XSS vector.
            $allowedMimes = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'];
            $finfo = new \finfo(FILEINFO_MIME_TYPE);
            $mime = $finfo->file($file['tmp_name']);

            if (! isset($allowedMimes[$mime])) {
                flashAcc('flash_error', 'Invalid image type. Allowed: PNG, JPG, WEBP.', '/settings/account?tab=profile');
            }

            // Defense in depth: verify the image is a real, decodable
            // raster image (rejects polyglot / corrupted payloads).
            if (($imgInfo = @getimagesize($file['tmp_name'])) === false
                || empty($imgInfo[2])
                || ! in_array($imgInfo[2], [IMAGETYPE_JPEG, IMAGETYPE_PNG, IMAGETYPE_WEBP], true)) {
                flashAcc('flash_error', 'The uploaded file is not a valid image.', '/settings/account?tab=profile');
            }

            $ext = $allowedMimes[$mime];
            $prefix = $isOwner ? 'owner' : (string) $userId;
            $filename = 'avatar_' . $prefix . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
            $uploadDir = $this->app->basePath('public_html/uploads/avatars');
            
            if (! is_dir($uploadDir)) {
                @mkdir($uploadDir, 0777, true);
            }
            @chmod($uploadDir, 0777);

            $targetPath = $uploadDir . DIRECTORY_SEPARATOR . $filename;
            if (move_uploaded_file($file['tmp_name'], $targetPath)) {
                @chmod($targetPath, 0664);
                $avatarPath = '/uploads/avatars/' . $filename;

                // ── Register in Download Center under the "profiles" folder ──
                try {
                    $dlDb = $this->app->db()->connection();
                    $pfStmt = $dlDb->prepare(
                        "SELECT id FROM download_folders WHERE slug = 'profiles' LIMIT 1"
                    );
                    $pfStmt->execute();
                    $profilesFolder = $pfStmt->fetch();

                    if ($profilesFolder) {
                        $profilesDir = $this->app->basePath('storage/downloads/profiles');
                        if (!is_dir($profilesDir)) {
                            @mkdir($profilesDir, 0777, true);
                        }
                        $dlCode    = substr(bin2hex(random_bytes(4)), 0, 6);
                        $dlCopy    = $profilesDir . DIRECTORY_SEPARATOR . $dlCode . '_' . $filename;

                        if (copy($targetPath, $dlCopy)) {
                            @chmod($dlCopy, 0664);
                            $dlTitle = 'Avatar: ' . ($isOwner ? 'owner' : "user_{$userId}");
                            $dlSize  = (int) filesize($dlCopy);
                            $dlMime  = mime_content_type($dlCopy) ?: 'image/jpeg';

                            $dlStmt = $dlDb->prepare(
                                'INSERT INTO file_downloads
                                 (folder_id, title, original_name, file_path, file_size, mime_type,
                                  short_code, is_active, created_by, created_at)
                                 VALUES (?, ?, ?, ?, ?, ?, ?, 1, ?, NOW())'
                            );
                            $dlStmt->execute([
                                (int) $profilesFolder['id'],
                                $dlTitle,
                                $filename,
                                $dlCopy,
                                $dlSize,
                                $dlMime,
                                $dlCode,
                                $isOwner ? 0 : (int) $userId,
                            ]);
                        }
                    }
                } catch (\Throwable) {
                    // Non-fatal: avatar was saved, just skip download-center registration
                }
            }
        }

        if ($isOwner) {
            $db = $this->app->db()->connection();
            $stmt = $db->prepare('
                INSERT INTO system_settings (key_name, value)
                VALUES (?, ?)
                ON DUPLICATE KEY UPDATE value = VALUES(value)
            ');

            $toSave = [
                'owner_display_name' => $displayName !== '' ? mb_substr($displayName, 0, 100) : '',
                'owner_bio'          => $bio !== '' ? mb_substr($bio, 0, 500) : '',
                'owner_company'      => $company !== '' ? mb_substr($company, 0, 150) : '',
                'owner_location'     => $location !== '' ? mb_substr($location, 0, 255) : '',
                'owner_website'      => $website !== '' ? mb_substr($website, 0, 500) : '',
                'owner_twitter'      => $twitter !== '' ? mb_substr($twitter, 0, 100) : '',
            ];

            if ($avatarPath !== null) {
                $toSave['owner_avatar_path'] = $avatarPath;
            }

            foreach ($toSave as $k => $v) {
                $stmt->execute([$k, $v]);
            }

            // Also check if owner user row exists in users table
            $ownerName = (string) $this->app->config('app.owner', 'admin');
            $userRow = $this->app->db()->fetchOne('SELECT id FROM users WHERE username = :u LIMIT 1', ['u' => $ownerName]);
            if ($userRow) {
                if ($avatarPath !== null) {
                    $this->app->db()->execute(
                        'UPDATE `users` SET `display_name` = :dname, `bio` = :bio, `company` = :comp, `location` = :loc, `website` = :web, `twitter` = :tw, `avatar_path` = :avatar WHERE `id` = :id',
                        [
                            'dname'  => $displayName !== '' ? mb_substr($displayName, 0, 100) : null,
                            'bio'    => $bio !== '' ? mb_substr($bio, 0, 500) : null,
                            'comp'   => $company !== '' ? mb_substr($company, 0, 150) : null,
                            'loc'    => $location !== '' ? mb_substr($location, 0, 255) : null,
                            'web'    => $website !== '' ? mb_substr($website, 0, 500) : null,
                            'tw'     => $twitter !== '' ? mb_substr($twitter, 0, 100) : null,
                            'avatar' => $avatarPath !== '' ? $avatarPath : null,
                            'id'     => $userRow['id'],
                        ]
                    );
                } else {
                    $this->app->db()->execute(
                        'UPDATE `users` SET `display_name` = :dname, `bio` = :bio, `company` = :comp, `location` = :loc, `website` = :web, `twitter` = :tw WHERE `id` = :id',
                        [
                            'dname' => $displayName !== '' ? mb_substr($displayName, 0, 100) : null,
                            'bio'   => $bio !== '' ? mb_substr($bio, 0, 500) : null,
                            'comp'  => $company !== '' ? mb_substr($company, 0, 150) : null,
                            'loc'   => $location !== '' ? mb_substr($location, 0, 255) : null,
                            'web'   => $website !== '' ? mb_substr($website, 0, 500) : null,
                            'tw'    => $twitter !== '' ? mb_substr($twitter, 0, 100) : null,
                            'id'    => $userRow['id'],
                        ]
                    );
                }
            }
        } else {
            if ($avatarPath !== null) {
                $this->app->db()->execute(
                    'UPDATE `users` SET `display_name` = :dname, `bio` = :bio, `company` = :comp, `location` = :loc, `website` = :web, `twitter` = :tw, `avatar_path` = :avatar WHERE `id` = :id',
                    [
                        'dname'  => $displayName !== '' ? mb_substr($displayName, 0, 100) : null,
                        'bio'    => $bio !== '' ? mb_substr($bio, 0, 500) : null,
                        'comp'   => $company !== '' ? mb_substr($company, 0, 150) : null,
                        'loc'    => $location !== '' ? mb_substr($location, 0, 255) : null,
                        'web'    => $website !== '' ? mb_substr($website, 0, 500) : null,
                        'tw'     => $twitter !== '' ? mb_substr($twitter, 0, 100) : null,
                        'avatar' => $avatarPath !== '' ? $avatarPath : null,
                        'id'     => $userId,
                    ],
                );
            } else {
                $this->app->db()->execute(
                    'UPDATE `users` SET `display_name` = :dname, `bio` = :bio, `company` = :comp, `location` = :loc, `website` = :web, `twitter` = :tw WHERE `id` = :id',
                    [
                        'dname' => $displayName !== '' ? mb_substr($displayName, 0, 100) : null,
                        'bio'   => $bio !== '' ? mb_substr($bio, 0, 500) : null,
                        'comp'  => $company !== '' ? mb_substr($company, 0, 150) : null,
                        'loc'   => $location !== '' ? mb_substr($location, 0, 255) : null,
                        'web'   => $website !== '' ? mb_substr($website, 0, 500) : null,
                        'tw'    => $twitter !== '' ? mb_substr($twitter, 0, 100) : null,
                        'id'    => $userId,
                    ],
                );
            }
        }

        flashAcc('flash_success', 'Profile updated successfully.', '/settings/account?tab=profile');
    }

    /** POST /settings/account/password — change password (verifies current). */
    public function changePassword(): void
    {
        $this->auth->requireUser();

        if (! $this->auth->validateCsrf()) {
            flashAcc('flash_error', 'Invalid security token.', '/settings/account?tab=security');
        }

        $current = (string) ($_POST['current_password'] ?? '');
        $new     = (string) ($_POST['password'] ?? '');
        $confirm = (string) ($_POST['password_confirm'] ?? '');

        $u = $this->auth->user();
        if ($u === null) {
            flashAcc('flash_error', 'Session expired. Please sign in again.', '/login');
        }

        if (! password_verify($current, (string) $u['password_hash'])) {
            flashAcc('flash_error', 'Your current password is incorrect.', '/settings/account?tab=security');
        }

        if (strlen($new) < 8) {
            flashAcc('flash_error', 'New password must be at least 8 characters.', '/settings/account?tab=security');
        }

        if ($new !== $confirm) {
            flashAcc('flash_error', 'New password and confirmation do not match.', '/settings/account?tab=security');
        }

        $this->app->db()->execute(
            'UPDATE `users` SET `password_hash` = :hash WHERE `id` = :id',
            ['hash' => password_hash($new, PASSWORD_ARGON2ID), 'id' => $this->identityId()],
        );

        // Invalidate every session for this user except the current one.
        $currentToken = (string) ($_SESSION['session_token'] ?? '');
        if ($currentToken !== '') {
            (new \App\Service\SecurityService($this->app))->revokeOthers($currentToken, $this->identityId());
        }

        flashAcc('flash_success', 'Password changed successfully.', '/settings/account?tab=security');
    }

    /** POST /settings/account/email — change the email address. */
    public function changeEmail(): void
    {
        $this->auth->requireUser();

        if (! $this->auth->validateCsrf()) {
            flashAcc('flash_error', 'Invalid security token.', '/settings/account?tab=account');
        }

        $email = strtolower(trim((string) ($_POST['email'] ?? '')));

        if (! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            flashAcc('flash_error', 'Please enter a valid email address.', '/settings/account?tab=account');
        }

        $sysSettingsRows = $this->app->db()->fetchAll("SELECT key_name, value FROM system_settings WHERE key_name IN ('email_domain_whitelist', 'email_domain_blacklist')");
        $sysPolicy = [];
        foreach ($sysSettingsRows as $sr) {
            $sysPolicy[$sr['key_name']] = (string) $sr['value'];
        }

        $emailDomain = strtolower(substr(strrchr($email, '@') ?: '', 1));

        // Whitelist check
        $whitelist = array_filter(array_map('trim', explode(',', strtolower($sysPolicy['email_domain_whitelist'] ?? ''))));
        if (!empty($whitelist)) {
            $matched = false;
            foreach ($whitelist as $allowedDomain) {
                $allowedDomain = ltrim($allowedDomain, '@');
                if ($emailDomain === $allowedDomain || str_ends_with($emailDomain, '.' . $allowedDomain)) {
                    $matched = true;
                    break;
                }
            }
            if (! $matched) {
                flashAcc('flash_error', 'Email updates are restricted to authorized organization domains only.', '/settings/account?tab=account');
            }
        }

        // Blacklist check
        $blacklist = array_filter(array_map('trim', explode(',', strtolower($sysPolicy['email_domain_blacklist'] ?? ''))));
        if (!empty($blacklist)) {
            foreach ($blacklist as $blockedDomain) {
                $blockedDomain = ltrim($blockedDomain, '@');
                if ($emailDomain === $blockedDomain || str_ends_with($emailDomain, '.' . $blockedDomain)) {
                    flashAcc('flash_error', 'Disposable or temporary email domains are not permitted.', '/settings/account?tab=account');
                }
            }
        }

        $exists = $this->app->db()->fetchOne(
            'SELECT `id` FROM `users` WHERE `email` = :email AND `id` <> :id LIMIT 1',
            ['email' => $email, 'id' => $this->identityId()],
        );

        if ($exists !== false) {
            flashAcc('flash_error', 'That email address is already in use.', '/settings/account?tab=account');
        }

        $this->app->db()->execute(
            'UPDATE `users` SET `email` = :email WHERE `id` = :id',
            ['email' => $email, 'id' => $this->identityId()],
        );

        flashAcc('flash_success', 'Email address updated.', '/settings/account?tab=account');
    }

    /** POST /settings/account/delete — permanently delete own account. */
    public function deleteAccount(): void
    {
        $this->auth->requireUser();

        if (! $this->auth->validateCsrf()) {
            flashAcc('flash_error', 'Invalid security token.', '/settings/account?tab=danger');
        }

        $uid = $this->identityId();
        if ($uid <= 0) {
            flashAcc('flash_error', 'Only registered user accounts can be deleted here.', '/settings/account?tab=danger');
        }

        // Ownership: repos owned by this user move to the platform owner
        $this->app->db()->execute(
            'UPDATE `repositories` SET `owner_user_id` = NULL WHERE `owner_user_id` = :uid',
            ['uid' => $uid],
        );

        foreach ([
            'repo_collaborators', 'repo_likes', 'repo_subscriptions', 'api_tokens', 'user_sessions',
        ] as $tbl) {
            $this->app->db()->execute("DELETE FROM `{$tbl}` WHERE `user_id` = :uid", ['uid' => $uid]);
        }
        $this->app->db()->execute('DELETE FROM `notifications` WHERE `user_id` = :uid', ['uid' => $uid]);
        $this->app->db()->execute('DELETE FROM `remember_tokens` WHERE `user_id` = :uid', ['uid' => $uid]);
        $this->app->db()->execute('DELETE FROM `users` WHERE `id` = :uid', ['uid' => $uid]);

        $this->auth->logout();
        header('Location: /');
        exit;
    }


    private function identityId(): int
    {
        return $this->auth->isOwner() ? 0 : (int) ($this->auth->user()['id'] ?? 0);
    }

    /** @return array<int, array<string, mixed>> */
    private function contributionsFor(int $userId): array
    {
        return [];
    }
}
