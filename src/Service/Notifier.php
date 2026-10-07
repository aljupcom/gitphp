<?php

declare(strict_types=1);

namespace App\Service;

use App\App;

/**
 * Event-driven notification dispatcher.
 *
 * A single entry point — Notifier::event() — turns a system event into a
 * rendered email (via {@see EmailTemplate} + {@see MailService}) and,
 * optionally, an in-app notification. It is fully best-effort: any failure
 * is swallowed so the primary action is never affected.
 */
final class Notifier
{
    private App $app;
    private MailService $mail;
    private EmailTemplate $templates;

    public function __construct(App $app)
    {
        $this->app       = $app;
        $this->mail      = new MailService($app);
        $this->templates = new EmailTemplate($app);
    }

    /**
     * Fire an event: send the mapped email to $to and record an optional
     * in-app notification for $userId (0 = none).
     *
     * @param string      $eventKey    e.g. 'user.registered', 'ticket.created'
     * @param string      $to          recipient email ('' = skip email)
     * @param array       $context     template variables
     * @param int         $userId      in-app notification target (0 = skip)
     * @param string|null $inAppMsg    in-app message (null = skip in-app)
     * @param string|null $inAppLink   in-app deep link
     */
    public function event(
        string $eventKey,
        string $to,
        array $context = [],
        int $userId = 0,
        ?string $inAppMsg = null,
        ?string $inAppLink = null
    ): void {
        try {
            $map = $this->eventConfig($eventKey);

            // Respect per-user category preferences for the in-app channel.
            if ($userId > 0 && $inAppMsg !== null && $this->wantsChannel($userId, 'in_app', $map['category'])) {
                (new NotificationService($this->app))->notifyUser(
                    $userId,
                    $context['repo_id'] ?? null,
                    $eventKey,
                    $inAppMsg,
                    $inAppLink ?? '#',
                );
            }

            if ($to !== '' && filter_var($to, FILTER_VALIDATE_EMAIL) !== false) {
                if ($userId === 0 || $this->wantsChannel($userId, 'email', $map['category'])) {
                    $rendered = $this->templates->render($map['template_key'], $context);
                    $this->mail->send($to, $rendered['subject'], $rendered['html'], $rendered['text'], [
                        'template_key' => $map['template_key'],
                        'event_key'    => $eventKey,
                    ]);
                }
            }
        } catch (\Throwable) {
            // Notifications must never break the primary action.
        }
    }

    /** Resolve an event's template/category (DB override, else built-in). */
    private function eventConfig(string $eventKey): array
    {
        $defaults = self::eventDefaults();
        $base = $defaults[$eventKey] ?? ['template_key' => 'generic', 'category' => 'system'];

        try {
            $row = $this->app->db()->fetchOne(
                'SELECT `template_key`, `category`, `enabled` FROM `email_events` WHERE `event_key` = :k LIMIT 1',
                ['k' => $eventKey],
            );
            if ($row !== false) {
                if ((int) ($row['enabled'] ?? 1) === 0) {
                    return ['template_key' => $base['template_key'], 'category' => $base['category'], 'enabled' => false];
                }
                return [
                    'template_key' => (string) ($row['template_key'] ?: $base['template_key']),
                    'category'     => (string) ($row['category'] ?: $base['category']),
                    'enabled'      => true,
                ];
            }
        } catch (\Throwable) {}

        return $base + ['enabled' => true];
    }

    /** Whether a user opted in to a channel for a category (default: yes). */
    private function wantsChannel(int $userId, string $channel, string $category): bool
    {
        try {
            $row = $this->app->db()->fetchOne(
                'SELECT `channel_in_app`, `channel_email`, `categories` FROM `user_notification_prefs` WHERE `user_id` = :u LIMIT 1',
                ['u' => $userId],
            );
        } catch (\Throwable) {
            return true;
        }
        if ($row === false) return true; // opt-out model: no row = all enabled

        if ($channel === 'in_app' && isset($row['channel_in_app']) && (int) $row['channel_in_app'] === 0) return false;
        if ($channel === 'email' && isset($row['channel_email']) && (int) $row['channel_email'] === 0) return false;

        // categories is a CSV of *disabled* categories, when present.
        $disabled = array_filter(array_map('trim', explode(',', (string) ($row['categories'] ?? ''))));
        return ! in_array($category, $disabled, true);
    }

    /** Built-in event → template/category map. */
    public static function eventDefaults(): array
    {
        return [
            'user.registered'   => ['template_key' => 'welcome',         'category' => 'auth'],
            'user.verify_email' => ['template_key' => 'verify_email',    'category' => 'auth'],
            'auth.password_reset' => ['template_key' => 'password_reset','category' => 'security'],
            'auth.security_alert' => ['template_key' => 'security_alert','category' => 'security'],
            'ticket.created'    => ['template_key' => 'ticket_created',   'category' => 'system'],
            'ticket.answered'   => ['template_key' => 'ticket_answered',  'category' => 'system'],
        ];
    }
}
