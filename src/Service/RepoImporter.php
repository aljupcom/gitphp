<?php

declare(strict_types=1);

namespace App\Service;

use InvalidArgumentException;
use RuntimeException;
use Symfony\Component\Process\Process;

/**
 * Validates and inspects remote repositories on supported hosting platforms
 * (GitHub, GitLab, Codeberg) before they are imported.
 */
final class RepoImporter
{
    /** Allowed hosts mapped to their display name (SSRF protection: allow-list only). */
    private const PLATFORMS = [
        'github.com'       => 'GitHub',
        'www.github.com'   => 'GitHub',
        'gitlab.com'       => 'GitLab',
        'www.gitlab.com'   => 'GitLab',
        'codeberg.org'     => 'Codeberg',
        'www.codeberg.org' => 'Codeberg',
    ];

    /**
     * Parse and validate a remote repository URL.
     *
     * Supports HTTPS, HTTP (upgraded to HTTPS), SSH (git@host:owner/repo.git),
     * bare platform URLs (github.com/owner/repo), and embedded credentials.
     *
     * @return array{platform: string, host: string, owner: string, name: string, url: string, slug: string, embedded_token: string}
     * @throws InvalidArgumentException When the URL is not a supported repository URL.
     */
    public function parseRemoteUrl(string $url): array
    {
        $url = trim($url);

        if ($url === '') {
            throw new InvalidArgumentException('Please provide a repository URL.');
        }

        $embeddedToken = '';

        // 1. Convert SSH URL format (git@github.com:owner/repo.git) to HTTPS
        if (preg_match('#^git@([^:]+):(.+?)(?:\.git)?/?$#i', $url, $sshMatch)) {
            $host = strtolower($sshMatch[1]);
            $path = '/' . ltrim($sshMatch[2], '/');
            $url  = "https://{$host}{$path}";
        }

        // 2. Normalize scheme if missing or HTTP / git
        if (preg_match('#^http://#i', $url)) {
            $url = preg_replace('#^http://#i', 'https://', $url) ?? $url;
        } elseif (preg_match('#^git://#i', $url)) {
            $url = preg_replace('#^git://#i', 'https://', $url) ?? $url;
        } elseif (!preg_match('#^https?://#i', $url) && preg_match('#^[\w\.\-]+/[\w\.\-/]+#i', $url)) {
            $url = 'https://' . $url;
        }

        $parts = parse_url($url);

        if ($parts === false || !isset($parts['host'])) {
            throw new InvalidArgumentException('That does not look like a valid URL.');
        }

        $host = strtolower($parts['host']);

        if (!isset(self::PLATFORMS[$host])) {
            $supported = implode(', ', array_unique(array_values(self::PLATFORMS)));
            throw new InvalidArgumentException("Unsupported platform. Supported hosts: {$supported}.");
        }

        // 3. Extract embedded credentials if any (e.g. https://token@github.com/user/repo)
        if (isset($parts['user'])) {
            $embeddedToken = (string) $parts['user'];
            if (isset($parts['pass']) && $parts['pass'] !== '') {
                $embeddedToken = (string) $parts['pass'];
            }
        }

        if (isset($parts['port'])) {
            throw new InvalidArgumentException('URLs with explicit non-standard ports are not allowed.');
        }

        $rawPath = $parts['path'] ?? '';
        $rawPath = preg_replace('/[?#].*$/', '', $rawPath) ?? $rawPath;
        $rawPath = rtrim($rawPath, '/');
        $rawPath = preg_replace('/\.git$/i', '', $rawPath) ?? $rawPath;

        // Path format: /owner/repo or /group/subgroup/repo (GitLab)
        $segments = array_values(array_filter(explode('/', trim($rawPath, '/'))));

        if (count($segments) < 2) {
            throw new InvalidArgumentException('Expected a repository URL like https://github.com/username/repo.git');
        }

        $name  = (string) array_pop($segments);
        $owner = implode('/', $segments);

        return [
            'platform'       => self::PLATFORMS[$host],
            'host'           => $host,
            'owner'          => $owner,
            'name'           => $name,
            'url'            => "https://{$host}/{$owner}/{$name}.git", // canonical form
            'slug'           => $this->slugify($name),
            'embedded_token' => $embeddedToken,
        ];
    }

    /**
     * Build an authenticated URL for Git operations if a token is given.
     */
    public function authenticatedUrl(string $url, string $token): string
    {
        $first = trim(explode(',', $token)[0] ?? '');
        if ($first === '') return $url;

        $info = $this->parseRemoteUrl($url);
        $encodedToken = rawurlencode($first);

        return match ($info['platform']) {
            'GitHub'   => "https://{$encodedToken}@{$info['host']}/{$info['owner']}/{$info['name']}.git",
            'GitLab'   => "https://oauth2:{$encodedToken}@{$info['host']}/{$info['owner']}/{$info['name']}.git",
            default    => "https://{$encodedToken}@{$info['host']}/{$info['owner']}/{$info['name']}.git", // Codeberg / Gitea
        };
    }

    /**
     * Verify that the remote repository exists and is reachable.
     * Token is optional: tries with token first if provided, and falls back to
     * public unauthenticated fetch (like Codeberg).
     *
     * Returns the remote default branch ('main' when it cannot be determined).
     */
    public function verifyRemote(string $url, string $token = ''): string
    {
        $info  = $this->parseRemoteUrl($url);
        $first = trim(explode(',', $token)[0] ?? '');

        if ($first === '' && ($info['embedded_token'] ?? '') !== '') {
            $first = $info['embedded_token'];
        }

        // 1. If a token is provided, attempt authenticated connection
        if ($first !== '') {
            $authUrl = $this->authenticatedUrl($url, $first);
            $procAuth = new Process(['git', 'ls-remote', '--symref', $authUrl, 'HEAD'], null, [
                'GIT_TERMINAL_PROMPT' => '0',
            ]);
            $procAuth->setTimeout(20);
            $procAuth->run();

            if ($procAuth->isSuccessful()) {
                if (preg_match('/^ref:\s+refs\/heads\/(\S+)\s+HEAD/m', $procAuth->getOutput(), $m)) {
                    return $m[1];
                }
                return 'main';
            }

            // Also try with http.extraheader
            $header = $this->authHeader($url, $first);
            if ($header !== '') {
                $procH = new Process(['git', '-c', "http.extraheader={$header}", 'ls-remote', '--symref', $info['url'], 'HEAD'], null, [
                    'GIT_TERMINAL_PROMPT' => '0',
                ]);
                $procH->setTimeout(20);
                $procH->run();

                if ($procH->isSuccessful()) {
                    if (preg_match('/^ref:\s+refs\/heads\/(\S+)\s+HEAD/m', $procH->getOutput(), $m)) {
                        return $m[1];
                    }
                    return 'main';
                }
            }
        }

        // 2. Attempt unauthenticated connection (for public repos or Codeberg-style open access)
        $procPublic = new Process(['git', 'ls-remote', '--symref', $info['url'], 'HEAD'], null, [
            'GIT_TERMINAL_PROMPT' => '0',
        ]);
        $procPublic->setTimeout(20);
        $procPublic->run();

        if ($procPublic->isSuccessful()) {
            if (preg_match('/^ref:\s+refs\/heads\/(\S+)\s+HEAD/m', $procPublic->getOutput(), $m)) {
                return $m[1];
            }
            return 'main';
        }

        // If both failed:
        if ($first !== '') {
            throw new RuntimeException(
                "Could not access '{$info['owner']}/{$info['name']}' on {$info['platform']}. Either the repository does not exist, or the provided Access Token lacks permissions (ensure the GitHub Personal Access Token has the 'repo' scope checked).",
            );
        }

        throw new RuntimeException(
            "The repository '{$info['owner']}/{$info['name']}' on {$info['platform']} is private or does not exist. Please provide a Personal Access Token with the 'repo' scope to import private repositories.",
        );
    }

    /**
     * Build the platform-specific HTTP auth header for Git CLI operations over HTTPS.
     */
    public function authHeader(string $url, string $token): string
    {
        $first = trim(explode(',', $token)[0] ?? '');

        if ($first === '') return '';

        $info = $this->parseRemoteUrl($url);

        return match ($info['platform']) {
            'GitHub'   => 'Authorization: Basic ' . base64_encode("token:{$first}"),
            'GitLab'   => 'Authorization: Basic ' . base64_encode("oauth2:{$first}"),
            default    => "Authorization: token {$first}", // Codeberg (Gitea)
        };
    }

    /**
     * Verify that a freshly mirrored repository is complete by comparing its
     * local refs against the remote's advertised refs.
     *
     * @return array{branches: int, tags: int, commits: int, missing: string[]}
     */
    public function auditImport(string $repoPath, string $remoteUrl, string $token = ''): array
    {
        $info  = $this->parseRemoteUrl($remoteUrl);
        $first = trim(explode(',', $token)[0] ?? '');
        $targetUrl = $first !== '' ? $this->authenticatedUrl($remoteUrl, $first) : $info['url'];

        $remote = new Process(['git', 'ls-remote', '--heads', '--tags', $targetUrl], null, [
            'GIT_TERMINAL_PROMPT' => '0',
        ]);
        $remote->setTimeout(60);
        $remote->run();

        if (!$remote->isSuccessful()) {
            // Fallback without token
            $remote = new Process(['git', 'ls-remote', '--heads', '--tags', $info['url']], null, [
                'GIT_TERMINAL_PROMPT' => '0',
            ]);
            $remote->setTimeout(60);
            $remote->run();
        }

        if (!$remote->isSuccessful()) {
            throw new RuntimeException('Could not list remote refs for the post-import check.');
        }

        // Collect what the remote advertises (branches + tags)
        $remoteRefs = [];
        foreach (array_filter(explode("\n", trim($remote->getOutput()))) as $line) {
            $parts = preg_split('/\s+/', $line, 2);
            if (!isset($parts[1])) continue;
            if (str_ends_with($parts[1], '^{}')) continue;
            $remoteRefs[$parts[1]] = true;
        }

        // Collect what landed locally
        $local = new Process(['git', 'for-each-ref', '--format=%(refname)'], $repoPath);
        $local->setTimeout(30);
        $local->run();

        $localRefs = [];
        foreach (array_filter(explode("\n", trim($local->getOutput()))) as $line) {
            $localRefs[trim($line)] = true;
        }

        // Count reachable commits across all heads
        $branches = new Process(['git', 'rev-list', '--count', '--all'], $repoPath);
        $branches->setTimeout(30);
        $branches->run();

        $branchCount = new Process(['git', 'for-each-ref', '--format=%(refname)', 'refs/heads'], $repoPath);
        $branchCount->setTimeout(30);
        $branchCount->run();

        $tagCount = new Process(['git', 'for-each-ref', '--format=%(refname)', 'refs/tags'], $repoPath);
        $tagCount->setTimeout(30);
        $tagCount->run();

        $missing = [];
        foreach (array_keys($remoteRefs) as $ref) {
            if (!isset($localRefs[$ref])) {
                $missing[] = $ref;
            }
        }

        return [
            'branches' => count(array_filter(explode("\n", trim($branchCount->getOutput())))),
            'tags'     => count(array_filter(explode("\n", trim($tagCount->getOutput())))),
            'commits'  => (int) trim($branches->getOutput()),
            'missing'  => $missing,
        ];
    }

    /** Derive a local slug from a repository name. */
    public function slugify(string $name): string
    {
        $slug = strtolower(trim($name));
        $slug = preg_replace('/[^a-z0-9]+/', '-', $slug) ?? '';
        $slug = trim($slug, '-');

        return substr($slug, 0, 100);
    }

    /**
     * Fetch repository metadata (name, description, default branch) from the
     * platform's public REST API. Token is optional.
     *
     * @return array{name: string, description: string, default_branch: string, stars_count: int, homepage: string, topics: string, is_fallback: bool}
     */
    public function fetchRemoteMetadata(string $url, string $token = ''): array
    {
        $info  = $this->parseRemoteUrl($url);
        $first = trim(explode(',', $token)[0] ?? '');

        if ($first === '' && ($info['embedded_token'] ?? '') !== '') {
            $first = $info['embedded_token'];
        }

        $fallback = [
            'name'           => (string) $info['name'],
            'description'    => '',
            'default_branch' => 'main',
            'stars_count'    => 0,
            'homepage'       => '',
            'topics'         => '',
            'is_fallback'    => true,
        ];

        try {
            [$apiUrl, $headers] = match ($info['platform']) {
                'GitHub' => [
                    "https://api.github.com/repos/{$info['owner']}/{$info['name']}",
                    array_filter([
                        'Accept: application/vnd.github+json',
                        'User-Agent: GitPHP',
                        $first !== '' ? "Authorization: Bearer {$first}" : null,
                    ]),
                ],
                'GitLab' => [
                    'https://gitlab.com/api/v4/projects/' . rawurlencode("{$info['owner']}/{$info['name']}"),
                    array_filter([
                        'User-Agent: GitPHP',
                        $first !== '' ? "PRIVATE-TOKEN: {$first}" : null,
                    ]),
                ],
                default => [ // Codeberg / Gitea
                    "https://codeberg.org/api/v1/repos/{$info['owner']}/{$info['name']}",
                    array_filter([
                        'Accept: application/json',
                        'User-Agent: GitPHP',
                        $first !== '' ? "Authorization: token {$first}" : null,
                    ]),
                ],
            };

            $context = stream_context_create([
                'http' => [
                    'method'        => 'GET',
                    'header'        => implode("\r\n", $headers),
                    'timeout'       => 8,
                    'ignore_errors' => true,
                ],
            ]);

            $body = @file_get_contents($apiUrl, false, $context);

            if ($body !== false) {
                $data = json_decode($body, true);
                if (is_array($data) && isset($data['name'])) {
                    $topics = '';
                    if (isset($data['topics']) && is_array($data['topics'])) {
                        $topics = implode(',', array_map('strval', $data['topics']));
                    } elseif (isset($data['tag_list']) && is_array($data['tag_list'])) {
                        $topics = implode(',', array_map('strval', $data['tag_list']));
                    }

                    return [
                        'name'           => (string) ($data['name'] ?? $info['name']),
                        'description'    => (string) ($data['description'] ?? ''),
                        'default_branch' => (string) ($data['default_branch'] ?? 'main'),
                        'stars_count'    => (int) ($data['stargazers_count'] ?? ($data['star_count'] ?? 0)),
                        'homepage'       => (string) ($data['homepage'] ?? ''),
                        'topics'         => $topics,
                        'is_fallback'    => false,
                    ];
                }
            }
        } catch (\Throwable) {
            // Non-blocking
        }

        return $fallback;
    }

    /**
     * The platform URL of the repository's wiki.
     */
    public function wikiRepoUrl(string $url): string
    {
        $info = $this->parseRemoteUrl($url);

        return "https://{$info['host']}/{$info['owner']}/{$info['name']}.wiki.git";
    }

    /**
     * Clone the remote wiki repo (best-effort) and return its Markdown pages.
     *
     * @return array<int, array{title: string, content: string}>
     */
    public function fetchWikiPages(string $url, string $token = '', int $timeout = 180): array
    {
        $wikiUrl = $this->wikiRepoUrl($url);
        $first   = trim(explode(',', $token)[0] ?? '');
        $targetUrl = $first !== '' ? $this->authenticatedUrl($wikiUrl, $first) : $wikiUrl;

        $tmpDir = rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR)
            . DIRECTORY_SEPARATOR . 'gitphp-wiki-' . bin2hex(random_bytes(6));

        $process = new Process(['git', 'clone', '--depth', '1', $targetUrl, $tmpDir], null, [
            'GIT_TERMINAL_PROMPT' => '0',
        ]);
        $process->setTimeout($timeout);
        $process->run();

        if (! $process->isSuccessful()) {
            $this->deleteDirectoryRecursive($tmpDir);
            return [];
        }

        $pages = [];

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($tmpDir, \FilesystemIterator::SKIP_DOTS),
        );

        foreach ($iterator as $file) {
            if (! $file->isFile()) continue;
            if (strtolower($file->getExtension()) !== 'md') continue;

            $content = @file_get_contents($file->getPathname());
            if ($content === false || trim($content) === '') continue;

            $title = pathinfo($file->getFilename(), PATHINFO_FILENAME);
            $title = str_replace(['-', '_'], ' ', $title);
            $title = trim(preg_replace('/\s+/', ' ', $title) ?? $title);

            if ($title === '') $title = 'Untitled';

            $pages[] = ['title' => $title, 'content' => $content];
        }

        $this->deleteDirectoryRecursive($tmpDir);

        return $pages;
    }

    /**
     * Fetch the remote issue/bug list (best-effort).
     *
     * @return array<int, array{title: string, description: string, status: string}>
     */
    public function fetchRemoteIssues(string $url, string $token = ''): array
    {
        $info  = $this->parseRemoteUrl($url);
        $first = trim(explode(',', $token)[0] ?? '');

        if ($first === '' && ($info['embedded_token'] ?? '') !== '') {
            $first = $info['embedded_token'];
        }

        try {
            [$apiUrl, $headers] = match ($info['platform']) {
                'GitHub' => [
                    "https://api.github.com/repos/{$info['owner']}/{$info['name']}/issues?state=all&per_page=100",
                    array_filter([
                        'Accept: application/vnd.github+json',
                        'User-Agent: GitPHP',
                        $first !== '' ? "Authorization: Bearer {$first}" : null,
                    ]),
                ],
                'GitLab' => [
                    'https://gitlab.com/api/v4/projects/' . rawurlencode("{$info['owner']}/{$info['name']}") . '/issues?state=all&per_page=100',
                    array_filter([
                        'User-Agent: GitPHP',
                        $first !== '' ? "PRIVATE-TOKEN: {$first}" : null,
                    ]),
                ],
                default => [ // Codeberg / Gitea
                    "https://codeberg.org/api/v1/repos/{$info['owner']}/{$info['name']}/issues?state=all&limit=50",
                    array_filter([
                        'Accept: application/json',
                        'User-Agent: GitPHP',
                        $first !== '' ? "Authorization: token {$first}" : null,
                    ]),
                ],
            };

            $context = stream_context_create([
                'http' => [
                    'method'        => 'GET',
                    'header'        => implode("\r\n", $headers),
                    'timeout'       => 15,
                    'ignore_errors' => true,
                ],
            ]);

            $body = @file_get_contents($apiUrl, false, $context);
            if ($body === false) return [];

            $data = json_decode($body, true);
            if (! is_array($data)) return [];

            $issues = [];
            foreach ($data as $issue) {
                if (! is_array($issue) || empty($issue['title'])) continue;
                if (! empty($issue['pull_request'])) continue;

                $description = (string) ($issue['body'] ?? '');
                if ($description === '') {
                    $description = (string) ($issue['description'] ?? '');
                }

                $state = (string) ($issue['state'] ?? 'open');

                $issues[] = [
                    'title'       => mb_substr((string) $issue['title'], 0, 255),
                    'description' => mb_substr($description, 0, 20000),
                    'status'      => in_array($state, ['open', 'closed', 'resolved'], true) ? $state : 'open',
                ];
            }

            return $issues;
        } catch (\Throwable) {
            return [];
        }
    }

    /** Recursively delete a temporary directory. */
    private function deleteDirectoryRecursive(string $path): void
    {
        if (! is_dir($path)) return;

        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );

        foreach ($items as $item) {
            if ($item->isDir()) {
                @rmdir($item->getPathname());
            } else {
                @unlink($item->getPathname());
            }
        }

        @rmdir($path);
    }
}
