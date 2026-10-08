<?php

declare(strict_types=1);

namespace App\Service;

use App\App;
use App\Auth;

/**
 * Builds the unified repository header data (breadcrumb + branch selector
 * + branch/tag counts + star/watch/fork actions) so every repository tab
 * renders the same GitHub-style toolbar. Cheap: branches/tags come from
 * one git read each, social from two indexed queries.
 */
final class RepoHeaderBuilder
{
    private App $app;
    private Auth $auth;
    private GitReader $gitReader;
    private GitService $gitService;

    /** @var array<string, mixed>|null */
    private ?array $cached = null;

    public function __construct(App $app)
    {
        $this->app        = $app;
        $this->auth       = new Auth($app);
        $this->gitReader  = new GitReader();
        $this->gitService = new GitService();
    }

    /**
     * @param array<string, mixed> $repo
     * @return array<string, mixed>
     */
    public function build(array $repo, string $currentRef = ''): array
    {
        if ($this->cached !== null) return $this->cached;

        $slug  = (string) $repo['slug'];
        $owner = $this->resolveOwnerName($repo);
        $path  = null;

        try {
            if ($this->gitService->repoExists($slug)) {
                $path = $this->gitService->getRepoPath($slug);
            }
        } catch (\Throwable) {
            $path = null;
        }

        $branches = [];
        $tags     = [];
        if ($path !== null) {
            try {
                // Same cache keys + TTL the controllers use, so the header
                // reuses entries instead of spawning two extra git reads
                // on every repository page.
                $branches = $this->app->cache()->remember(
                    "repo:{$slug}:branches",
                    60,
                    fn() => $this->gitReader->getBranches($path),
                );
                $tags = $this->app->cache()->remember(
                    "repo:{$slug}:tags",
                    60,
                    fn() => $this->gitReader->getTags($path),
                );
            } catch (\Throwable) {
                // Repo may be empty or removed; header simply shows no refs.
            }
        }

        $defaultBranch = (string) ($repo['default_branch'] ?? 'main');
        if ($currentRef !== '') {
            $current = $currentRef;
        } elseif (in_array($defaultBranch, $branches, true)) {
            $current = $defaultBranch;
        } elseif ($branches !== []) {
            $current = $branches[0];
        } else {
            $current = $defaultBranch;
        }

        $repoId = (int) $repo['id'];

        $social = [
            'can_interact' => false,
            'is_starred'   => false,
            'is_watching'  => false,
            'stars'        => (int) ($repo['stars_count'] ?? 0),
            'watchers'     => 0,
        ];

        if ($this->auth->isLoggedIn()) {
            $uid = $this->auth->userId();
            $social['can_interact'] = ! $this->auth->isOwner();
            $social['is_starred'] = ! $this->auth->isOwner()
                && $this->app->db()->fetchOne(
                    'SELECT id FROM `repo_likes` WHERE `repo_id` = :r AND `user_id` = :u LIMIT 1',
                    ['r' => $repoId, 'u' => $uid],
                ) !== false;
            $social['is_watching'] = ! $this->auth->isOwner()
                && $this->app->db()->fetchOne(
                    'SELECT id FROM `repo_subscriptions` WHERE `repo_id` = :r AND `user_id` = :u LIMIT 1',
                    ['r' => $repoId, 'u' => $uid],
                ) !== false;
        }

                $canFork = true;

        // Check global forking policy
        $allowForkingPolicy = (string) ($this->app->db()->fetchOne("SELECT value FROM system_settings WHERE key_name = 'allow_repo_forking'")['value'] ?? '1');
        if ($allowForkingPolicy === '0' && ! $this->auth->isOwner() && ! $this->auth->isAdmin()) {
            $canFork = false;
        }

        $userRole = (string) ($_SESSION['user']['role'] ?? 'user');
        if ($userRole === 'restricted' || $userRole === 'bot') {
            $canFork = false;
        }

        if ($this->auth->isLoggedIn()) {
            if ($this->auth->isOwner()) {
                if ($owner === 'admin' || (int) ($repo['owner_user_id'] ?? 0) === 0) {
                    $canFork = false;
                }
            } else {
                $uid = (int) $this->auth->userId();
                if ((int) ($repo['owner_user_id'] ?? 0) === $uid) {
                    $canFork = false;
                }
            }
        }

        $social['watchers'] = (int) ($this->app->db()->fetchOne(
            'SELECT COUNT(*) AS c FROM `repo_subscriptions` WHERE `repo_id` = :r', ['r' => $repoId],
        )['c'] ?? 0);

                $commitCount = 0;
        if ($path !== null && $current !== '') {
            try {
                $commitCount = (int) $this->app->cache()->remember(
                    "repo:{$slug}:count:{$current}",
                    60,
                    fn() => $this->gitReader->getCommitCount($path, $current),
                );
            } catch (\Throwable) {}
        }

        $isEmpty = $path === null || $branches === [];

        $appUrl   = rtrim((string) $this->app->config('app.url', 'https://git.ysnapp.com'), '/');
        $host     = parse_url($appUrl, PHP_URL_HOST) ?: 'git.ysnapp.com';
        $sshUser  = (string) $this->app->config('git.ssh_user', 'git');
        $httpsUrl = "{$appUrl}/{$owner}/{$slug}.git";
        $sshUrl   = "{$sshUser}@{$host}:{$owner}/{$slug}.git";

        $canWrite = $this->auth->canWriteRepo($repoId);

        $this->cached = [
            'owner'        => $owner,
            'can_write'    => $canWrite,
            'repo'         => $repo,
            'branches'     => $branches,
            'tags'         => $tags,
            'current_ref'  => $current,
            'is_empty'     => $isEmpty,
            'social'       => $social,
            'commit_count' => $commitCount,
            'can_fork'     => $canFork,
            'forks_count'  => (int) ($repo['forks_count'] ?? 0),
            'csrf'         => $this->auth->generateCsrf(),
            'https_url'    => $httpsUrl,
            'ssh_url'      => $sshUrl,
        ];

        return $this->cached;
    }

    private function resolveOwnerName(array $repo): string
    {
        $uid = (int) ($repo['owner_user_id'] ?? 0);

        if ($uid > 0) {
            $u = $this->app->db()->fetchOne('SELECT `username` FROM `users` WHERE `id` = :id', ['id' => $uid]);
            if ($u !== false) return (string) $u['username'];
        }

        return (string) $this->app->config('app.owner', 'admin');
    }
}
