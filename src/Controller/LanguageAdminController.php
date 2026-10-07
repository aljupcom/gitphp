<?php

declare(strict_types=1);

namespace App\Controller;

use App\App;
use App\Auth;
use App\Service\LanguagePackageService;

final class LanguageAdminController
{
    private App $app;
    private Auth $auth;
    private LanguagePackageService $service;

    public function __construct(App $app)
    {
        $this->app     = $app;
        $this->auth    = new Auth($app);
        $this->service = new LanguagePackageService();
    }

        /** Delegates to the shared NavCounts service (fixes the phantom `issues` table query). */
    private function getNavCounts(): array
    {
        return \App\Service\NavCounts::get($this->app);
    }

    /** GET /{$ap}/languages */
    public function index(): void
    {
        $this->auth->requireAuditor();
        $secPrefix = ltrim(\App\Service\AdminSecurityService::getAdminPrefix($this->app), '/');
        $languages = $this->service->listInstalled();

        $flashSuccess = $_SESSION['flash_success'] ?? null;
        $flashError   = $_SESSION['flash_error'] ?? null;
        unset($_SESSION['flash_success'], $_SESSION['flash_error']);

        $view = $this->app->view();
        echo $view->render('admin/languages.twig', [
            'admin_sec_prefix' => $secPrefix,
            'nav_counts'       => $this->getNavCounts(),
            'languages'        => $languages,
            'flash_success'    => $flashSuccess,
            'flash_error'      => $flashError,
            'title'            => 'Language Management',
        ]);
    }

    /** POST /{$ap}/languages/install */
    public function install(): void
    {
        $this->auth->requireAuditor();
        $secPrefix = ltrim(\App\Service\AdminSecurityService::getAdminPrefix($this->app), '/');

        if (empty($_FILES['package']['tmp_name'])) {
            $_SESSION['flash_error'] = 'Please select a language package (.zip) file to upload.';
            header("Location: /{$secPrefix}/languages");
            exit;
        }

        $tmpFile = $_FILES['package']['tmp_name'];
        $result = $this->service->installZip($tmpFile);

        if ($result['success']) {
            $_SESSION['flash_success'] = $result['message'];
        } else {
            $_SESSION['flash_error'] = $result['error'] ?? 'Failed to install language package.';
        }

        header("Location: /{$secPrefix}/languages");
        exit;
    }

    /** POST /{$ap}/languages/toggle */
    public function toggle(): void
    {
        $this->auth->requireAuditor();
        $secPrefix = ltrim(\App\Service\AdminSecurityService::getAdminPrefix($this->app), '/');

        $code = strtolower(trim($_POST['code'] ?? ''));
        if ($code !== '') {
            $this->service->toggleActive($code);
            $_SESSION['flash_success'] = "Language status updated for '{$code}'.";
        }

        header("Location: /{$secPrefix}/languages");
        exit;
    }

    /** POST /{$ap}/languages/default */
    public function setDefault(): void
    {
        $this->auth->requireAuditor();
        $secPrefix = ltrim(\App\Service\AdminSecurityService::getAdminPrefix($this->app), '/');

        $code = strtolower(trim($_POST['code'] ?? ''));
        if ($code !== '') {
            if ($this->service->setDefault($code)) {
                $_SESSION['flash_success'] = "Default system language set to '{$code}'.";
            } else {
                $_SESSION['flash_error'] = "Failed to set '{$code}' as default language.";
            }
        }

        header("Location: /{$secPrefix}/languages");
        exit;
    }

    /** POST /{$ap}/languages/delete */
    public function delete(): void
    {
        $this->auth->requireAuditor();
        $secPrefix = ltrim(\App\Service\AdminSecurityService::getAdminPrefix($this->app), '/');

        $code = strtolower(trim($_POST['code'] ?? ''));
        if ($code !== '') {
            if ($this->service->deletePackage($code)) {
                $_SESSION['flash_success'] = "Language package '{$code}' deleted.";
            } else {
                $_SESSION['flash_error'] = "Cannot delete language package '{$code}'. It might be default or base language.";
            }
        }

        header("Location: /{$secPrefix}/languages");
        exit;
    }

    /** GET /{$ap}/languages/export/{code} */
    public function export(array $vars = []): void
    {
        $this->auth->requireAuditor();
        $code = strtolower(trim($vars['code'] ?? ($_GET['code'] ?? '')));

        if ($code === '') {
            http_response_code(400);
            echo "Language code required.";
            return;
        }

        $zipPath = $this->service->exportZip($code);
        if (!$zipPath || !file_exists($zipPath)) {
            http_response_code(404);
            echo "Failed to generate export zip for '{$code}'.";
            return;
        }

        header('Content-Type: application/zip');
        header('Content-Disposition: attachment; filename="' . $code . '_lang_package.zip"');
        header('Content-Length: ' . filesize($zipPath));
        readfile($zipPath);
        @unlink($zipPath);
        exit;
    }
}
