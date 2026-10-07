<?php

declare(strict_types=1);

namespace App\Controller;

use App\App;
use App\Auth;
use App\Service\SshKeyService;
use RuntimeException;

final class SshKeyController
{
    private App $app;
    private Auth $auth;
    private SshKeyService $sshKeyService;

    public function __construct(App $app)
    {
        $this->app           = $app;
        $this->auth          = new Auth($app);
        $this->sshKeyService = new SshKeyService($app->db());
    }

    /** GET /admin/ssh-keys — list all SSH keys and show the add-key form. */
    public function index(): void
    {
        $this->auth->requireOwner();

        $csrf = $this->auth->generateCsrf();
        $keys = $this->sshKeyService->getAll();

        $error   = $_SESSION['ssh_key_error']   ?? null;
        $success = $_SESSION['ssh_key_success'] ?? null;
        unset($_SESSION['ssh_key_error'], $_SESSION['ssh_key_success']);

        $this->app->view()->display('admin/ssh-keys/list.twig', [
            'csrf_token' => $csrf,
            'keys'       => $keys,
            'error'      => $error,
            'success'    => $success,
        ]);
    }

    /** POST /admin/ssh-keys — store a new SSH key. */
    public function store(): void
    {
        $this->auth->requireOwner();

        $csrfToken = $_POST['csrf_token'] ?? '';

        if (!$this->auth->validateCsrf($csrfToken)) {
            $_SESSION['ssh_key_error'] = 'Invalid security token. Please try again.';
            header('Location: /admin/ssh-keys');
            exit;
        }

        $title     = trim((string) ($_POST['title'] ?? ''));
        $publicKey = trim((string) ($_POST['public_key'] ?? ''));

        if ($title === '') {
            $_SESSION['ssh_key_error'] = 'A title for the SSH key is required.';
            header('Location: /admin/ssh-keys');
            exit;
        }

        if ($publicKey === '') {
            $_SESSION['ssh_key_error'] = 'The public key field is required.';
            header('Location: /admin/ssh-keys');
            exit;
        }

        $maxSsh = (int) ($this->app->db()->fetchOne("SELECT value FROM system_settings WHERE key_name = 'max_ssh_keys_per_user'")['value'] ?? 10);
        if ($maxSsh > 0 && ! $this->auth->isOwner()) {
            $currCount = (int) ($this->app->db()->fetchOne('SELECT COUNT(*) AS c FROM ssh_keys')['c'] ?? 0);
            if ($currCount >= $maxSsh) {
                $_SESSION['ssh_key_error'] = "SSH Key limit reached (Maximum allowed: {$maxSsh}).";
                header('Location: /admin/ssh-keys');
                exit;
            }
        }

        try {
            $this->sshKeyService->add($title, $publicKey);
            $_SESSION['ssh_key_success'] = 'SSH key "' . $title . '" added successfully.';
        } catch (RuntimeException $e) {
            $_SESSION['ssh_key_error'] = $e->getMessage();
        }

        header('Location: /admin/ssh-keys');
        exit;
    }

    /**
     * POST /admin/ssh-keys/{id}/delete — delete an SSH key.
     * @param string $id Route parameter from FastRoute
     */
    public function delete(string $id): void
    {
        $this->auth->requireOwner();

        $csrfToken = $_POST['csrf_token'] ?? '';

        if (!$this->auth->validateCsrf($csrfToken)) {
            $_SESSION['ssh_key_error'] = 'Invalid security token. Please try again.';
            header('Location: /admin/ssh-keys');
            exit;
        }

        $keyId = (int) $id;

        if ($keyId <= 0) {
            $_SESSION['ssh_key_error'] = 'Invalid key ID.';
            header('Location: /admin/ssh-keys');
            exit;
        }

        try {
            $deleted = $this->sshKeyService->delete($keyId);

            if ($deleted) {
                $_SESSION['ssh_key_success'] = 'SSH key deleted successfully.';
            } else {
                $_SESSION['ssh_key_error'] = 'SSH key not found.';
            }
        } catch (RuntimeException $e) {
            $_SESSION['ssh_key_error'] = $e->getMessage();
        }

        header('Location: /admin/ssh-keys');
        exit;
    }
}
