<?php

declare(strict_types=1);

namespace App\Service;

use App\App;

/**
 * Email template store & renderer.
 *
 * Loads a template from the `email_templates` table (by key + locale) and
 * falls back to a built-in default when none exists. Placeholders use the
 * {{variable}} syntax; HTML output is escaped, text output is raw.
 */
final class EmailTemplate
{
    private App $app;

    public function __construct(App $app)
    {
        $this->app = $app;
    }

    /** @return array{subject:string,html:string,text:string} */
    public function render(string $key, array $context = [], string $locale = 'en'): array
    {
        $tpl = $this->load($key, $locale) ?? $this->load($key, 'en') ?? (self::defaults()[$key] ?? self::defaults()['generic']);

        $ctx = array_merge([
            'app_name' => (string) $this->app->config('app.name', 'GitPHP'),
            'app_url'  => rtrim((string) $this->app->config('app.url', ''), '/'),
            'year'     => date('Y'),
        ], $context);

        $subject = $this->interpolate((string) $tpl['subject'], $ctx, false);
        $html    = $this->wrap($this->interpolate((string) $tpl['body_html'], $ctx, true), $subject, $ctx);
        $text    = $this->interpolate((string) ($tpl['body_text'] ?? ''), $ctx, false);
        if ($text === '') $text = trim(strip_tags(str_replace(['<br>', '</p>'], "\n", $html)));

        return ['subject' => $subject, 'html' => $html, 'text' => $text];
    }

    /** @return array{subject:string,body_html:string,body_text:?string}|null */
    private function load(string $key, string $locale): ?array
    {
        try {
            $row = $this->app->db()->fetchOne(
                'SELECT `subject`, `body_html`, `body_text` FROM `email_templates`
                 WHERE `template_key` = :k AND `locale` = :l AND `is_active` = 1 LIMIT 1',
                ['k' => $key, 'l' => $locale],
            );
        } catch (\Throwable) {
            return null;
        }
        return $row === false ? null : $row;
    }

    private function interpolate(string $tpl, array $ctx, bool $escape): string
    {
        return preg_replace_callback('/\{\{\s*([a-zA-Z0-9_]+)\s*\}\}/', function (array $m) use ($ctx, $escape): string {
            $v = $ctx[$m[1]] ?? '';
            if (! is_scalar($v)) $v = '';
            $v = (string) $v;
            return $escape ? htmlspecialchars($v, ENT_QUOTES, 'UTF-8') : $v;
        }, $tpl) ?? $tpl;
    }

    /** Wrap inner HTML in a minimal, email-safe branded shell. */
    private function wrap(string $inner, string $subject, array $ctx): string
    {
        // If the template already provides a full document, use it as-is.
        if (stripos($inner, '<html') !== false || stripos($inner, '<!doctype') !== false) {
            return $inner;
        }
        $app = htmlspecialchars((string) $ctx['app_name'], ENT_QUOTES, 'UTF-8');
        $url = htmlspecialchars((string) $ctx['app_url'], ENT_QUOTES, 'UTF-8');
        return
            '<!doctype html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"></head>'
            . '<body style="margin:0;background:#f4f5f7;font-family:Verdana,Arial,sans-serif;color:#1a1a1a;">'
            . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#f4f5f7;padding:24px 12px;"><tr><td align="center">'
            . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="max-width:520px;background:#ffffff;border:1px solid #d0d7de;border-radius:6px;overflow:hidden;">'
            . '<tr><td style="background:#2a5db0;padding:14px 20px;"><a href="' . $url . '" style="color:#fff;text-decoration:none;font-size:16px;font-weight:700;">' . $app . '</a></td></tr>'
            . '<tr><td style="padding:22px 20px;font-size:14px;line-height:1.6;">' . $inner . '</td></tr>'
            . '<tr><td style="padding:14px 20px;border-top:1px solid #eee;font-size:11px;color:#777;">'
            . 'You are receiving this email from ' . $app . '. If this was not you, please contact support.'
            . '</td></tr></table>'
            . '<div style="font-size:11px;color:#999;margin-top:12px;">&copy; ' . htmlspecialchars((string) $ctx['year']) . ' ' . $app . '</div>'
            . '</td></tr></table></body></html>';
    }

    /** Built-in default templates (seeded to DB, editable in the admin panel). */
    public static function defaults(): array
    {
        return [
            'welcome' => [
                'subject'   => 'Welcome to {{app_name}}, {{username}}!',
                'body_html' => '<h2 style="margin:0 0 12px;">Welcome aboard, {{username}} 👋</h2>'
                    . '<p>Your {{app_name}} account is ready. You can now create repositories, open pull requests and more.</p>'
                    . '<p><a href="{{app_url}}" style="display:inline-block;background:#2a5db0;color:#fff;text-decoration:none;padding:9px 18px;border-radius:4px;font-weight:600;">Open {{app_name}}</a></p>',
                'body_text' => "Welcome, {{username}}!\n\nYour {{app_name}} account is ready: {{app_url}}",
            ],
            'verify_email' => [
                'subject'   => 'Verify your {{app_name}} email',
                'body_html' => '<h2 style="margin:0 0 12px;">Confirm your email</h2>'
                    . '<p>Hello {{username}}, please confirm your email address to finish setting up your account.</p>'
                    . '<p><a href="{{verify_url}}" style="display:inline-block;background:#2a5db0;color:#fff;text-decoration:none;padding:9px 18px;border-radius:4px;font-weight:600;">Verify email</a></p>'
                    . '<p style="color:#777;font-size:12px;">If the button does not work, open: {{verify_url}}</p>',
                'body_text' => "Hello {{username}},\n\nConfirm your email: {{verify_url}}",
            ],
            'password_reset' => [
                'subject'   => 'Reset your {{app_name}} password',
                'body_html' => '<h2 style="margin:0 0 12px;">Password reset requested</h2>'
                    . '<p>We received a request to reset your password. This link expires soon and can be used once.</p>'
                    . '<p><a href="{{reset_url}}" style="display:inline-block;background:#2a5db0;color:#fff;text-decoration:none;padding:9px 18px;border-radius:4px;font-weight:600;">Reset password</a></p>'
                    . '<p style="color:#777;font-size:12px;">If you did not request this, you can safely ignore this email.</p>',
                'body_text' => "Reset your password: {{reset_url}}\n\nIf you did not request this, ignore this email.",
            ],
            'security_alert' => [
                'subject'   => '[{{app_name}}] Security alert: {{alert}}',
                'body_html' => '<h2 style="margin:0 0 12px;">Security alert</h2>'
                    . '<p><strong>{{alert}}</strong></p>'
                    . '<p style="color:#555;">IP: {{ip}}<br>When: {{when}}</p>'
                    . '<p>If this was you, no action is needed. Otherwise, change your password and review your active sessions.</p>',
                'body_text' => "Security alert: {{alert}}\nIP: {{ip}}\nWhen: {{when}}",
            ],
            'ticket_created' => [
                'subject'   => '[{{app_name}}] Ticket {{reference}} created',
                'body_html' => '<h2 style="margin:0 0 12px;">We received your ticket</h2>'
                    . '<p>Thanks {{name}}, your support ticket has been created. Keep this reference to track progress:</p>'
                    . '<p style="font-size:18px;font-weight:800;font-family:monospace;">{{reference}}</p>'
                    . '<p><strong>Subject:</strong> {{subject}}</p>'
                    . '<p><a href="{{ticket_url}}" style="display:inline-block;background:#2a5db0;color:#fff;text-decoration:none;padding:9px 18px;border-radius:4px;font-weight:600;">View ticket</a></p>',
                'body_text' => "Ticket {{reference}} created.\nSubject: {{subject}}\nTrack it: {{ticket_url}}",
            ],
            'ticket_answered' => [
                'subject'   => '[{{app_name}}] New reply on ticket {{reference}}',
                'body_html' => '<h2 style="margin:0 0 12px;">There is a new reply on your ticket</h2>'
                    . '<p><strong>{{reference}}</strong> — {{subject}}</p>'
                    . '<p><a href="{{ticket_url}}" style="display:inline-block;background:#2a5db0;color:#fff;text-decoration:none;padding:9px 18px;border-radius:4px;font-weight:600;">Read the reply</a></p>',
                'body_text' => "New reply on ticket {{reference}}: {{ticket_url}}",
            ],
            'generic' => [
                'subject'   => '[{{app_name}}] {{subject}}',
                'body_html' => '<p>{{message}}</p>',
                'body_text' => "{{message}}",
            ],
        ];
    }
}
