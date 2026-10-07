<?php

declare(strict_types=1);

namespace App\Controller;

use App\App;
use App\Auth;

final class WebhookAdminController
{
    private App $app;
    private Auth $auth;

    public function __construct(App $app)
    {
        $this->app  = $app;
        $this->auth = new Auth($app);
    }

    /** GET /{$ap}/webhooks */
        /** Delegates to the shared NavCounts service (fixes the phantom `issues` table query). */
    private function getNavCounts(): array
    {
        return \App\Service\NavCounts::get($this->app);
    }

    public function index(): void
    {
        $this->auth->requireOwner();

        $webhooks = $this->app->db()->fetchAll(
            'SELECT w.*, r.name AS repo_name, r.slug AS repo_slug,
             (SELECT COUNT(*) FROM webhook_deliveries d WHERE d.webhook_id = w.id) AS delivery_count,
             (SELECT MAX(d.created_at) FROM webhook_deliveries d WHERE d.webhook_id = w.id) AS last_delivery
             FROM webhooks w
             LEFT JOIN repositories r ON w.repo_id = r.id
             ORDER BY w.created_at DESC'
        );

        $recentDeliveries = $this->app->db()->fetchAll(
            'SELECT d.*, w.url AS webhook_url, r.name AS repo_name
             FROM webhook_deliveries d
             LEFT JOIN webhooks w ON d.webhook_id = w.id
             LEFT JOIN repositories r ON w.repo_id = r.id
             ORDER BY d.created_at DESC
             LIMIT 30'
        );

        $this->app->view()->display('admin/webhooks.twig', [
            'csrf_token'        => $this->auth->generateCsrf(),
            'webhooks'          => $webhooks,
            'recent_deliveries' => $recentDeliveries,
            'nav_counts'        => $this->getNavCounts(),
            'flash_success'     => $_SESSION['flash_success'] ?? null,
            'flash_error'       => $_SESSION['flash_error'] ?? null,
        ]);

        unset($_SESSION['flash_success'], $_SESSION['flash_error']);
    }

    /** POST /{$ap}/webhooks/{id}/toggle */
    public function toggle(int $id): void
    {
        $this->auth->requireOwner();

        if (! $this->auth->validateCsrf()) {
            $_SESSION['flash_error'] = 'Invalid security token.';
            header('Location: ' . $this->adminPrefix() . '/webhooks');
            exit;
        }

        $hook = $this->app->db()->fetchOne('SELECT is_active FROM webhooks WHERE id = :id', ['id' => $id]);
        if ($hook) {
            $newStatus = empty($hook['is_active']) ? 1 : 0;
            $this->app->db()->execute('UPDATE webhooks SET is_active = :s WHERE id = :id', ['s' => $newStatus, 'id' => $id]);
            $_SESSION['flash_success'] = 'Webhook ' . ($newStatus ? 'activated' : 'deactivated') . ' successfully.';
        }

        header('Location: ' . $this->adminPrefix() . '/webhooks');
        exit;
    }

    /** POST /{$ap}/webhooks/{id}/delete */
    public function delete(int $id): void
    {
        $this->auth->requireOwner();

        if (! $this->auth->validateCsrf()) {
            $_SESSION['flash_error'] = 'Invalid security token.';
            header('Location: ' . $this->adminPrefix() . '/webhooks');
            exit;
        }

        $this->app->db()->execute('DELETE FROM webhook_deliveries WHERE webhook_id = :id', ['id' => $id]);
        $this->app->db()->execute('DELETE FROM webhooks WHERE id = :id', ['id' => $id]);

        $_SESSION['flash_success'] = 'Webhook deleted successfully.';
        header('Location: ' . $this->adminPrefix() . '/webhooks');
        exit;
    }

    private function adminPrefix(): string
    {
        $hash = substr(hash('sha256', session_id() . 'gitphp_admin_sec_2026'), 0, 12);
        return "/cp_{$hash}";
    }
}
