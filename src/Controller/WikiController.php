<?php

declare(strict_types=1);

namespace App\Controller;

use App\App;
use App\Auth;
use App\Service\MarkdownRenderer;

/**
 * Per-repository wiki: Markdown pages stored in `wiki_pages`.
 *
 * Everyone can read the wiki of a visible repository; only the admin creates
 * and edits pages (through a Markdown editor with a live preview).
 */
final class WikiController
{
    private App $app;
    private Auth $auth;
    private MarkdownRenderer $markdown;

    public function __construct(App $app)
    {
        $this->app      = $app;
        $this->auth     = new Auth($app);
        $this->markdown = new MarkdownRenderer();
    }

    /** GET /{user}/{repo}/wiki — wiki index (redirects to the first page). */
    public function index(string $user, string $repo): void
    {
        $dbRepo = $this->resolveRepo($repo);

        if ($dbRepo === null) {
            $this->notFound();
            return;
        }

        $pages = $this->app->db()->fetchAll(
            'SELECT `slug`, `title`, `updated_at` FROM `wiki_pages`
             WHERE `repo_id` = :repo
             ORDER BY `title` ASC',
            ['repo' => (int) $dbRepo['id']],
        );

        // A wiki with pages opens on its first page (GitHub-style Home).
        if ($pages !== []) {
            $home = $this->findHomeSlug($pages);
            header("Location: /{$user}/{$dbRepo['slug']}/wiki/{$home}");
            exit;
        }

        $this->app->view()->display('repo/wiki.twig', [
            'repo'       => $dbRepo,
            'owner'      => $user,
            'active_tab' => 'wiki',
            'pages'      => [],
            'is_empty'   => true,
        ]);
    }

    /** GET /{user}/{repo}/wiki/{page} — render one wiki page. */
    public function show(string $user, string $repo, string $page): void
    {
        $dbRepo = $this->resolveRepo($repo);

        if ($dbRepo === null) {
            $this->notFound();
            return;
        }

        $wikiPage = $this->app->db()->fetchOne(
            'SELECT w.*, u.username AS updated_by_name
             FROM `wiki_pages` w
             LEFT JOIN `users` u ON u.id = w.updated_by
             WHERE w.repo_id = :repo AND w.slug = :slug
             LIMIT 1',
            ['repo' => (int) $dbRepo['id'], 'slug' => $this->normalizeSlug($page)],
        );

        if ($wikiPage === false) {
            $this->notFound();
            return;
        }

        $pages = $this->app->db()->fetchAll(
            'SELECT `slug`, `title`, `updated_at` FROM `wiki_pages`
             WHERE `repo_id` = :repo
             ORDER BY `title` ASC',
            ['repo' => (int) $dbRepo['id']],
        );

        $this->app->view()->display('repo/wiki-page.twig', [
            'repo'         => $dbRepo,
            'owner'        => $user,
            'active_tab'   => 'wiki',
            'page'         => $wikiPage,
            'pages'        => $pages,
            'content_html' => $this->markdown->renderHtml((string) ($wikiPage['content'] ?? '')),
        ]);
    }

    /** GET /{user}/{repo}/wiki/new — blank page form (admin only). */
    public function newPage(string $user, string $repo): void
    {
        $this->auth->requireOwner();

        $dbRepo = $this->resolveRepo($repo);

        if ($dbRepo === null) {
            $this->notFound();
            return;
        }

        $this->app->view()->display('repo/wiki-edit.twig', [
            'repo'       => $dbRepo,
            'owner'      => $user,
            'active_tab' => 'wiki',
            'page'       => null,
            'csrf_token' => $this->auth->generateCsrf(),
            // error flashes are injected globally during App::boot()
            'old'        => $_SESSION['wiki_old'] ?? [],
        ]);

        unset($_SESSION['wiki_old']);
    }

    /** POST /{user}/{repo}/wiki — create a new wiki page (admin only). */
    public function store(string $user, string $repo): void
    {
        $this->auth->requireOwner();

        $dbRepo = $this->resolveRepo($repo);

        if ($dbRepo === null) {
            $this->notFound();
            return;
        }

        $fail = function (string $message, array $old) use ($user, $dbRepo): never {
            $_SESSION['flash_error'] = $message;
            $_SESSION['wiki_old']    = $old;
            header("Location: /{$user}/{$dbRepo['slug']}/wiki/new");
            exit;
        };

        if (! $this->auth->validateCsrf((string) ($_POST['csrf_token'] ?? ''))) {
            $fail('Invalid security token.', []);
        }

        $title   = trim((string) ($_POST['title'] ?? ''));
        $slug    = $this->normalizeSlug((string) ($_POST['slug'] ?? ''));
        $content = (string) ($_POST['content'] ?? '');

        $old = ['title' => $title, 'slug' => $slug, 'content' => $content];

        if ($title === '' || mb_strlen($title) > 255) {
            $fail('Please provide a page title (max 255 characters).', $old);
        }

        if ($slug === '') $slug = $this->slugify($title);

        if ($slug === '' || ! preg_match('/^[a-zA-Z0-9][a-zA-Z0-9._-]{0,199}$/', $slug)) {
            $fail('The page slug may only contain letters, numbers, dots, dashes and underscores.', $old);
        }

        $exists = $this->app->db()->fetchOne(
            'SELECT `id` FROM `wiki_pages` WHERE `repo_id` = :repo AND `slug` = :slug LIMIT 1',
            ['repo' => (int) $dbRepo['id'], 'slug' => $slug],
        );

        if ($exists !== false) {
            $fail("A wiki page named '{$slug}' already exists.", $old);
        }

        if (mb_strlen($content) > 200000) {
            $fail('The page content is too long (max 200000 characters).', $old);
        }

        $this->app->db()->execute(
            'INSERT INTO `wiki_pages` (`repo_id`, `slug`, `title`, `content`, `updated_by`)
             VALUES (:repo, :slug, :title, :content, :user)',
            [
                'repo'    => (int) $dbRepo['id'],
                'slug'    => $slug,
                'title'   => $title,
                'content' => $content !== '' ? $content : null,
                'user'    => $this->wikiAuthorId() ?: null, // 0 (owner) stays NULL
            ],
        );

        // Revision history: revision 1.
        $pageId = (int) $this->app->db()->connection()->lastInsertId();
        $this->app->db()->execute(
            'INSERT INTO `wiki_revisions` (`page_id`, `content`, `edited_by`)
             VALUES (:page, :content, :editor)',
            ['page' => $pageId, 'content' => $content, 'editor' => $this->wikiAuthorId() ?: null],
        );

        $_SESSION['flash_success'] = "Wiki page '{$title}' created.";
        header("Location: /{$user}/{$dbRepo['slug']}/wiki/{$slug}");
        exit;
    }

    /** GET /{user}/{repo}/wiki/{page}/edit — edit form (admin only). */
    public function edit(string $user, string $repo, string $page): void
    {
        $this->auth->requireOwner();

        $dbRepo = $this->resolveRepo($repo);

        if ($dbRepo === null) {
            $this->notFound();
            return;
        }

        $wikiPage = $this->app->db()->fetchOne(
            'SELECT * FROM `wiki_pages` WHERE `repo_id` = :repo AND `slug` = :slug LIMIT 1',
            ['repo' => (int) $dbRepo['id'], 'slug' => $this->normalizeSlug($page)],
        );

        if ($wikiPage === false) {
            $this->notFound();
            return;
        }

        $this->app->view()->display('repo/wiki-edit.twig', [
            'repo'       => $dbRepo,
            'owner'      => $user,
            'active_tab' => 'wiki',
            'page'       => $wikiPage,
            'csrf_token' => $this->auth->generateCsrf(),
            // error flashes are injected globally during App::boot()
            'old'        => $_SESSION['wiki_old'] ?? [],
        ]);

        unset($_SESSION['wiki_old']);
    }

    /** POST /{user}/{repo}/wiki/{page}/update — save changes (admin only). */
    public function update(string $user, string $repo, string $page): void
    {
        $this->auth->requireOwner();

        $dbRepo = $this->resolveRepo($repo);

        if ($dbRepo === null) {
            $this->notFound();
            return;
        }

        $slug = $this->normalizeSlug($page);

        $wikiPage = $this->app->db()->fetchOne(
            'SELECT * FROM `wiki_pages` WHERE `repo_id` = :repo AND `slug` = :slug LIMIT 1',
            ['repo' => (int) $dbRepo['id'], 'slug' => $slug],
        );

        if ($wikiPage === false) {
            $this->notFound();
            return;
        }

        $fail = function (string $message, array $old) use ($user, $dbRepo, $slug): never {
            $_SESSION['flash_error'] = $message;
            $_SESSION['wiki_old']    = $old;
            header("Location: /{$user}/{$dbRepo['slug']}/wiki/{$slug}/edit");
            exit;
        };

        if (! $this->auth->validateCsrf((string) ($_POST['csrf_token'] ?? ''))) {
            $fail('Invalid security token.', []);
        }

        $title   = trim((string) ($_POST['title'] ?? ''));
        $content = (string) ($_POST['content'] ?? '');

        $old = ['title' => $title, 'content' => $content];

        if ($title === '' || mb_strlen($title) > 255) {
            $fail('Please provide a page title (max 255 characters).', $old);
        }

        if (mb_strlen($content) > 200000) {
            $fail('The page content is too long (max 200000 characters).', $old);
        }

        $this->app->db()->execute(
            'UPDATE `wiki_pages`
             SET `title` = :title, `content` = :content, `updated_at` = NOW(), `updated_by` = :editor
             WHERE `id` = :id',
            [
                'title'   => $title,
                'content' => $content !== '' ? $content : null,
                'editor'  => $this->wikiAuthorId() ?: null,
                'id'      => (int) $wikiPage['id'],
            ],
        );

        // Revision history: only when the content actually changed.
        if ((string) ($wikiPage['content'] ?? '') !== $content) {
            $this->app->db()->execute(
                'INSERT INTO `wiki_revisions` (`page_id`, `content`, `edited_by`)
                 VALUES (:page, :content, :editor)',
                ['page' => (int) $wikiPage['id'], 'content' => $content, 'editor' => $this->wikiAuthorId() ?: null],
            );
        }

        $_SESSION['flash_success'] = "Wiki page '{$title}' updated.";
        header("Location: /{$user}/{$dbRepo['slug']}/wiki/{$slug}");
        exit;
    }

    /** POST /{user}/{repo}/wiki/{page}/delete — remove a page (admin only). */
    public function delete(string $user, string $repo, string $page): void
    {
        $this->auth->requireOwner();

        $dbRepo = $this->resolveRepo($repo);

        if ($dbRepo === null) {
            $this->notFound();
            return;
        }

        if (! $this->auth->validateCsrf((string) ($_POST['csrf_token'] ?? ''))) {
            $_SESSION['flash_error'] = 'Invalid security token.';
            header("Location: /{$user}/{$dbRepo['slug']}/wiki");
            exit;
        }

        $this->app->db()->execute(
            'DELETE FROM `wiki_pages` WHERE `repo_id` = :repo AND `slug` = :slug',
            ['repo' => (int) $dbRepo['id'], 'slug' => $this->normalizeSlug($page)],
        );

        $_SESSION['flash_success'] = 'Wiki page deleted.';
        header("Location: /{$user}/{$dbRepo['slug']}/wiki");
        exit;
    }

    /** POST /{user}/{repo}/wiki/preview — JSON Markdown preview (admin only). */
    public function preview(string $user, string $repo): void
    {
        $this->auth->requireOwner();

        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store');

        if (! $this->auth->validateCsrf((string) ($_POST['csrf_token'] ?? ''))) {
            http_response_code(403);
            echo (string) json_encode(['ok' => false, 'error' => 'Invalid security token.']);
            return;
        }

        $content = (string) ($_POST['content'] ?? '');

        if (mb_strlen($content) > 200000) {
            echo (string) json_encode(['ok' => false, 'error' => 'Content too long.']);
            return;
        }

        echo (string) json_encode([
            'ok'   => true,
            'html' => $this->markdown->renderHtml($content),
        ]);
    }

    /** Pick the "Home" page if present, otherwise the first page alphabetically. */
    private function findHomeSlug(array $pages): string
    {
        foreach ($pages as $page) {
            if (strcasecmp((string) $page['slug'], 'home') === 0) return (string) $page['slug'];
        }

        return (string) $pages[0]['slug'];
    }

    /** Normalize a page slug from the URL (lowercase, trimmed). */
    private function normalizeSlug(string $slug): string
    {
        return strtolower(trim($slug));
    }

    /** Derive a slug from a page title ("Getting Started" -> "getting-started"). */
    private function slugify(string $title): string
    {
        $slug = strtolower(trim($title));
        $slug = preg_replace('/[^a-z0-9]+/', '-', $slug) ?? '';
        $slug = trim($slug, '-');

        return substr($slug, 0, 200);
    }

    /**
     * Look up a repo by slug and enforce visibility (public for everyone,
     * private only for the owner and collaborators).
     * @return array<string, mixed>|null
     */
    /**
     * Wiki write gate: the owner OR any write-collaborator (Gitea parity —
     * write access to the repository includes editing its wiki).
     * Emits 401/403/404 pages itself and nulls $dbRepo when denied.
     */
    /** GET /{user}/{repo}/wiki/{page}/history — revision list. */
    public function history(string $user, string $repo, string $page): void
    {
        $dbRepo = $this->resolveRepo($repo);

        if ($dbRepo === null) {
            $this->notFound();
            return;
        }

        $wikiPage = $this->app->db()->fetchOne(
            'SELECT * FROM `wiki_pages` WHERE `repo_id` = :repo AND `slug` = :slug LIMIT 1',
            ['repo' => (int) $dbRepo['id'], 'slug' => $this->normalizeSlug($page)],
        );

        if ($wikiPage === false) {
            $this->notFound();
            return;
        }

        $revisions = $this->app->db()->fetchAll(
            'SELECT rv.*, u.`username` AS `editor_name`
             FROM `wiki_revisions` rv
             LEFT JOIN `users` u ON u.id = rv.edited_by
             WHERE rv.`page_id` = :pid
             ORDER BY rv.`id` DESC',
            ['pid' => (int) $wikiPage['id']],
        );

        $this->app->view()->display('repo/wiki-history.twig', [
            'repo'       => $dbRepo,
            'owner'      => $user,
            'active_tab' => 'wiki',
            'page'       => $wikiPage,
            'revisions'  => $revisions,
            'can_write'  => $this->auth->isOwner()
                || ($this->auth->isLoggedIn() && $this->auth->canWriteRepo((int) $dbRepo['id'])),
            'csrf_token' => $this->auth->generateCsrf(),
        ]);
    }

    /** GET /{user}/{repo}/wiki/{page}/revision/{id} — view one revision (read-only). */
    public function revision(string $user, string $repo, string $page, int $id): void
    {
        $dbRepo = $this->resolveRepo($repo);

        if ($dbRepo === null) {
            $this->notFound();
            return;
        }

        $wikiPage = $this->app->db()->fetchOne(
            'SELECT * FROM `wiki_pages` WHERE `repo_id` = :repo AND `slug` = :slug LIMIT 1',
            ['repo' => (int) $dbRepo['id'], 'slug' => $this->normalizeSlug($page)],
        );
        if ($wikiPage === false) { $this->notFound(); return; }

        $rev = $this->app->db()->fetchOne(
            'SELECT rv.*, u.`username` AS `editor_name`
             FROM `wiki_revisions` rv LEFT JOIN `users` u ON u.id = rv.edited_by
             WHERE rv.`id` = :rid AND rv.`page_id` = :pid LIMIT 1',
            ['rid' => $id, 'pid' => (int) $wikiPage['id']],
        );
        if ($rev === false) { $this->notFound(); return; }

        $this->app->view()->display('repo/wiki-revision.twig', [
            'repo'       => $dbRepo,
            'owner'      => $user,
            'active_tab' => 'wiki',
            'page'       => $wikiPage,
            'revision'   => $rev,
            'content_html' => $this->markdown->renderHtml((string) $rev['content']),
            'can_write'  => $this->auth->isOwner()
                || ($this->auth->isLoggedIn() && $this->auth->canWriteRepo((int) $dbRepo['id'])),
            'csrf_token' => $this->auth->generateCsrf(),
        ]);
    }

    /** POST /{user}/{repo}/wiki/{page}/restore/{id} — roll back to a revision (writers). */
    public function restore(string $user, string $repo, string $page, int $id): void
    {
        $this->requireWikiWriter($repo, $dbRepo);
        if ($dbRepo === null) return;

        $slug = $this->normalizeSlug($page);

        $wikiPage = $this->app->db()->fetchOne(
            'SELECT * FROM `wiki_pages` WHERE `repo_id` = :repo AND `slug` = :slug LIMIT 1',
            ['repo' => (int) $dbRepo['id'], 'slug' => $slug],
        );
        if ($wikiPage === false) { $this->notFound(); return; }

        if (! $this->auth->validateCsrf((string) ($_POST['csrf_token'] ?? ''))) {
            $_SESSION['flash_error'] = 'Invalid security token.';
            header("Location: /{$user}/{$dbRepo['slug']}/wiki/{$slug}/history");
            exit;
        }

        $rev = $this->app->db()->fetchOne(
            'SELECT `content` FROM `wiki_revisions` WHERE `id` = :rid AND `page_id` = :pid LIMIT 1',
            ['rid' => $id, 'pid' => (int) $wikiPage['id']],
        );
        if ($rev === false) { $this->notFound(); return; }

        // Restore = update page + record the restore as a NEW revision
        // (history is append-only; nothing is ever lost).
        $this->app->db()->execute(
            'UPDATE `wiki_pages`
             SET `content` = :content, `updated_at` = NOW(), `updated_by` = :editor
             WHERE `id` = :id',
            ['content' => (string) $rev['content'], 'editor' => $this->wikiAuthorId() ?: null, 'id' => (int) $wikiPage['id']],
        );

        $this->app->db()->execute(
            'INSERT INTO `wiki_revisions` (`page_id`, `content`, `edited_by`)
             VALUES (:page, :content, :editor)',
            ['page' => (int) $wikiPage['id'], 'content' => (string) $rev['content'], 'editor' => $this->wikiAuthorId() ?: null],
        );

        $_SESSION['flash_success'] = 'Wiki page restored from revision #' . $id . '.';
        header("Location: /{$user}/{$dbRepo['slug']}/wiki/{$slug}");
        exit;
    }

    private function requireWikiWriter(string $repo, ?array &$dbRepo): void
    {
        $this->auth->requireAuth();
        $dbRepo = $this->resolveRepo($repo);

        if ($dbRepo === null) {
            $this->notFound();
            return;
        }

        $canWrite = $this->auth->isOwner() || $this->auth->canWriteRepo((int) $dbRepo['id']);
        if (! $canWrite) {
            http_response_code(403);
            echo $this->app->view()->render('partials/error.html.twig', [
                'code'    => 403,
                'message' => 'You do not have write permissions for this repository\'s wiki.',
            ]);
            $dbRepo = null;
        }
    }

    /** Identity for revision attribution (owner = 0, matching repo_releases). */
    private function wikiAuthorId(): int
    {
        return $this->auth->isOwner() ? 0 : (int) ($this->auth->user()['id'] ?? 0);
    }

    private function resolveRepo(string $slug): ?array
    {
        $slug = str_ends_with($slug, '.git') ? substr($slug, 0, -4) : $slug;

        if ($slug === '') return null;

        $row = $this->app->db()->fetchOne(
            'SELECT * FROM `repositories` WHERE `slug` = :slug LIMIT 1',
            ['slug' => $slug],
        );

        if ($row === false) return null;

        if (! $this->auth->canViewRepo((int) $row['id'], (string) $row['visibility'])) {
            if (! $this->auth->isLoggedIn()) {
                header('Location: /login');
                exit;
            }
            return null;
        }

        return $row;
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
