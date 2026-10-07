#!/usr/bin/env php
<?php

declare(strict_types=1);

/** Git Shell Wrapper — invoked by SSH authorized_keys forced-command. */

$basePath = dirname(__DIR__);

$reposPath = getenv('REPOS_PATH');
if ($reposPath === false || $reposPath === '') {
    // Try loading from .env
    $envFile = $basePath . '/.env';
    if (file_exists($envFile)) {
        $lines = file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if ($lines !== false) {
            foreach ($lines as $line) {
                $line = trim($line);
                if ($line === '' || str_starts_with($line, '#') || !str_contains($line, '=')) continue;
                [$key, $value] = explode('=', $line, 2);
                $key   = trim($key);
                $value = trim($value);
                if ((str_starts_with($value, '"') && str_ends_with($value, '"')) ||
                    (str_starts_with($value, "'") && str_ends_with($value, "'"))) {
                    $value = substr($value, 1, -1);
                }
                if ($key === 'REPOS_PATH') {
                    $reposPath = $value;
                    break;
                }
            }
        }
    }
}

// Fallback
if ($reposPath === false || $reposPath === '') $reposPath = $basePath . '/repos';

$reposPath = rtrim($reposPath, '/\\');

function sshLog(string $message): void
{
    $logFile = getenv('GIT_SSH_LOG');
    if ($logFile === false || $logFile === '') return;
    $timestamp = date('Y-m-d H:i:s');
    @file_put_contents($logFile, "[{$timestamp}] {$message}\n", FILE_APPEND | LOCK_EX);
}

$originalCommand = getenv('SSH_ORIGINAL_COMMAND');

if ($originalCommand === false || $originalCommand === '') {
    sshLog('REJECTED: No SSH_ORIGINAL_COMMAND provided');
    fwrite(STDERR, "Interactive shell is not available.\n");
    exit(1);
}

sshLog("Command: {$originalCommand}");

// Parse key-id and user-id from CLI arguments passed by authorized_keys command="..."
$cliKeyId  = 0;
$cliUserId = 0;
foreach ($argv as $arg) {
    if (str_starts_with($arg, "--key-id=")) {
        $cliKeyId = (int) substr($arg, 9);
    } elseif (str_starts_with($arg, "--user-id=")) {
        $cliUserId = (int) substr($arg, 10);
    }
}


// Expected format: git-upload-pack '/path/to/repo.git'
//                  git-receive-pack '/path/to/repo.git'

$allowedCommands = ['git-upload-pack', 'git-receive-pack'];

// Match: command 'path' or command "path" or command path
$pattern = '/^(git-upload-pack|git-receive-pack)\s+[\'"]?([^\'"]+)[\'"]?$/';

if (!preg_match($pattern, $originalCommand, $matches)) {
    sshLog('REJECTED: Malformed command — ' . $originalCommand);
    fwrite(STDERR, "Invalid command.\n");
    exit(1);
}

$gitCommand = $matches[1];
$repoArg    = $matches[2];

if (!in_array($gitCommand, $allowedCommands, true)) {
    sshLog("REJECTED: Disallowed command — {$gitCommand}");
    fwrite(STDERR, "Command not allowed.\n");
    exit(1);
}

// The repo argument may be a path like '/owner/repo.git' or just 'repo.git'

$repoArg = trim($repoArg, '/\\');

// Strip the .git suffix to get the slug
if (!str_ends_with($repoArg, '.git')) {
    sshLog("REJECTED: Repository path does not end with .git — {$repoArg}");
    fwrite(STDERR, "Invalid repository path.\n");
    exit(1);
}

$slug = substr($repoArg, 0, -4); // remove '.git'

// Slug validation: only safe characters (may contain owner/repo or just repo)
$slugBase = basename($slug);

if ($slugBase === '' || !preg_match('/^[a-zA-Z0-9._\-]+$/', $slugBase)) {
    sshLog("REJECTED: Invalid slug — {$slugBase}");
    fwrite(STDERR, "Invalid repository name.\n");
    exit(1);
}

$fullRepoPath = $reposPath . DIRECTORY_SEPARATOR . $slugBase . '.git';

// Resolve real paths for traversal protection
$realReposPath = realpath($reposPath);
if ($realReposPath === false) {
    sshLog("REJECTED: Repos directory does not exist — {$reposPath}");
    fwrite(STDERR, "Server misconfiguration.\n");
    exit(1);
}

$realRepoPath = realpath($fullRepoPath);

// Check: repo must exist and be inside the repos directory
if ($realRepoPath === false || !is_dir($realRepoPath)) {
    sshLog("REJECTED: Repository not found — {$fullRepoPath}");
    fwrite(STDERR, "Repository not found.\n");
    exit(1);
}

// Traversal check: ensure resolved path is within the repos directory
$normalizedRepos = $realReposPath . DIRECTORY_SEPARATOR;
if (!str_starts_with($realRepoPath . DIRECTORY_SEPARATOR, $normalizedRepos)) {
    sshLog("REJECTED: Path traversal attempt — {$realRepoPath}");
    fwrite(STDERR, "Access denied.\n");
    exit(1);
}

// Verify it looks like a bare git repo
if (!is_dir($realRepoPath . DIRECTORY_SEPARATOR . 'objects')) {
    sshLog("REJECTED: Not a git repository — {$realRepoPath}");
    fwrite(STDERR, "Not a valid git repository.\n");
    exit(1);
}

// Check user authorization against repository permissions
try {
    $autoload = $basePath . '/vendor/autoload.php';
    if (file_exists($autoload)) {
        require_once $autoload;
        $app = \App\App::boot($basePath);
        $db  = $app->db()->connection();

        $stmt = $db->prepare('SELECT id, owner_id, visibility FROM repositories WHERE slug = ? LIMIT 1');
        $stmt->execute([$slugBase]);
        $repoRow = $stmt->fetch(\PDO::FETCH_ASSOC);

        if ($repoRow) {
            $repoId     = (int) $repoRow['id'];
            $repoOwner  = (int) ($repoRow['owner_id'] ?? 0);
            $visibility = (string) ($repoRow['visibility'] ?? 'public');

            // Is platform owner or repo owner?
            $isOwner = ($cliUserId === 0) || ($cliUserId === $repoOwner);

            if (!$isOwner) {
                // Check collaborator permissions
                $collabStmt = $db->prepare('SELECT permission FROM repo_collaborators WHERE repo_id = ? AND user_id = ? LIMIT 1');
                $collabStmt->execute([$repoId, $cliUserId]);
                $perm = $collabStmt->fetchColumn();

                if ($gitCommand === 'git-receive-pack') {
                    // Push requires write or admin permission
                    if ($perm !== 'write' && $perm !== 'admin') {
                        sshLog("REJECTED: User {$cliUserId} denied write access to {$slugBase}");
                        fwrite(STDERR, "fatal: You do not have write access to repository '{$slugBase}'.\n");
                        exit(1);
                    }
                } else {
                    // Pull/Clone requires visibility=public OR collaborator
                    if ($visibility !== 'public' && !$perm) {
                        sshLog("REJECTED: User {$cliUserId} denied read access to private repo {$slugBase}");
                        fwrite(STDERR, "fatal: Repository '{$slugBase}' not found or access denied.\n");
                        exit(1);
                    }
                }
            }
        }
    }
} catch (\Throwable $e) {
    sshLog("AUTH_ERROR: " . $e->getMessage());
}


sshLog("ALLOWED: {$gitCommand} {$realRepoPath}");

// Use passthru to stream git data directly (binary-safe)
$escapedPath = escapeshellarg($realRepoPath);
$fullCommand = $gitCommand . ' ' . $escapedPath;

passthru($fullCommand, $exitCode);

exit($exitCode);
