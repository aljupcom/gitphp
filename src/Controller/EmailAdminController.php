<?php

declare(strict_types=1);

namespace App\Controller;

use App\App;
use App\Auth;
use App\Service\EmailTemplate;
use App\Service\MailService;

/**
 * Admin control panel: email server configuration, template overview,
 * delivery logs and a live test-send. Owner-only.
 */
final class EmailAdminController
{
    private App $app;
    private Auth $auth;

    public function __construct(App $app)
    {
        $this->app  = $app;
        $this->auth = new Auth($app);
    }

    /** GET /{ap}/email — configuration, logs and templates. */
    public function index(): void
    {
        $this->auth->requireOwner();

        $mail = new MailService($this->app);

        $config = [
            'provider'        => $mail->setting('provider', 'log'),
            'from_email'      => $mail->setting('from_email', (string) env('MAIL_FROM', '')),
            'from_name'       => $mail->setting('from_name', (string) $this->app->config('app.name', 'GitPHP')),
            'smtp_host'       => $mail->setting('smtp_host', ''),
            'smtp_port'       => $mail->setting('smtp_port', '587'),
            'smtp_encryption' => $mail->setting('smtp_encryption', 'tls'),
            'smtp_user'       => $mail->setting('smtp_user', ''),
            'smtp_pass_set'   => $mail->setting('smtp_pass', '') !== '',
            'transport'       => $mail->transport(),
        ];

        $logs = [];
        $stats = ['sent' => 0, 'failed' => 0, 'logged' => 0, 'total' => 0];
        try {
            $logs = $this->app->db()->fetchAll(
                'SELECT `to_email`, `subject`, `template_key`, `event_key`, `provider`, `status`, `error`, `created_at`
                 FROM `email_log` ORDER BY `id` DESC LIMIT 30',
            );
            $rows = $this->app->db()->fetchAll('SELECT `status`, COUNT(*) AS c FROM `email_log` GROUP BY `status`');
            foreach ($rows as $r) {
                $stats[(string) $r['status']] = (int) $r['c'];
                $stats['total'] += (int) $r['c'];
            }
        } catch (\Throwable) {
        }

        $templates = [];
        try {
            $templates = $this->app->db()->fetchAll(
                'SELECT `template_key`, `locale`, `name`, `subject`, `is_active`, `updated_at`
                 FROM `email_templates` ORDER BY `template_key` ASC',
            );
        } catch (\Throwable) {
        }

        $error   = $_SESSION['flash_error'] ?? null;
        $success = $_SESSION['flash_success'] ?? null;
        unset($_SESSION['flash_error'], $_SESSION['flash_success']);

        $this->app->view()->display('admin/email.twig', [
            'page_title'   => 'Email & Notifications',
            'admin_prefix' => $this->auth->adminPrefix(),
            'csrf_token'   => $this->auth->generateCsrf(),
            'config'       => $config,
            'logs'         => $logs,
            'stats'        => $stats,
            'templates'    => $templates,
            'error'        => $error,
            'success'      => $success,
            'hide_global_flash' => true,
        ]);
    }

    /** POST /{ap}/email — persist SMTP / provider configuration. */
    public function save(): void
    {
        $this->auth->requireOwner();
        $ap = $this->auth->adminPrefix();

        if (! $this->auth->validateCsrf((string) ($_POST['csrf_token'] ?? ''))) {
            $_SESSION['flash_error'] = 'Invalid security token.';
            header("Location: {$ap}/email");
            exit;
        }

        $mail     = new MailService($this->app);
        $provider = in_array($_POST['provider'] ?? 'log', ['log', 'mail', 'smtp'], true) ? (string) $_POST['provider'] : 'log';
        $enc      = in_array($_POST['smtp_encryption'] ?? 'tls', ['tls', 'ssl', 'none'], true) ? (string) $_POST['smtp_encryption'] : 'tls';

        $mail->saveSetting('provider', $provider);
        $mail->saveSetting('from_email', strtolower(trim((string) ($_POST['from_email'] ?? ''))));
        $mail->saveSetting('from_name', trim((string) ($_POST['from_name'] ?? '')));
        $mail->saveSetting('smtp_host', trim((string) ($_POST['smtp_host'] ?? '')));
        $mail->saveSetting('smtp_port', (string) max(1, (int) ($_POST['smtp_port'] ?? 587)));
        $mail->saveSetting('smtp_encryption', $enc);
        $mail->saveSetting('smtp_user', trim((string) ($_POST['smtp_user'] ?? '')));

        // Only overwrite the stored password when a new value is provided.
        $pass = (string) ($_POST['smtp_pass'] ?? '');
        if ($pass !== '') {
            $mail->saveSetting('smtp_pass', $pass, true);
        }

        $_SESSION['flash_success'] = 'Email configuration saved.';
        header("Location: {$ap}/email");
        exit;
    }

    /** POST /{ap}/email/test — send a test email to the given address. */
    public function test(): void
    {
        $this->auth->requireOwner();
        $ap = $this->auth->adminPrefix();

        if (! $this->auth->validateCsrf((string) ($_POST['csrf_token'] ?? ''))) {
            $_SESSION['flash_error'] = 'Invalid security token.';
            header("Location: {$ap}/email");
            exit;
        }

        $to = strtolower(trim((string) ($_POST['test_email'] ?? '')));
        if ($to === '' || filter_var($to, FILTER_VALIDATE_EMAIL) === false) {
            $_SESSION['flash_error'] = 'Enter a valid destination email address.';
            header("Location: {$ap}/email");
            exit;
        }

        $mail = new MailService($this->app);
        $tpl  = new EmailTemplate($this->app);
        $r = $tpl->render('generic', [
            'subject' => 'Test email from ' . $this->app->config('app.name', 'GitPHP'),
            'message' => 'This is a test message confirming your outgoing email configuration works. Transport: ' . $mail->transport() . '.',
        ]);

        $ok = $mail->send($to, $r['subject'], $r['html'], $r['text'], ['event_key' => 'admin.test', 'template_key' => 'generic']);

        $_SESSION[$ok ? 'flash_success' : 'flash_error'] = $ok
            ? ('Test email dispatched to ' . $to . ' via "' . $mail->transport() . '" transport. Check the logs below.')
            : 'Test email failed — see the delivery log for the error.';

        header("Location: {$ap}/email");
        exit;
    }
}
