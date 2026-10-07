<?php

declare(strict_types=1);

namespace App\Service;

use App\App;

/**
 * Outbound webhook dispatcher with HMAC-SHA256 signing and delivery log.
 */
final class WebhookService
{
    public const SUPPORTED_EVENTS = ['push', 'issues', 'pull_request', 'release'];

    private App $app;

    public function __construct(App $app)
    {
        $this->app = $app;
    }

    /**
     * Fire an event to every active webhook of the repository subscribed
     * to it. Delivery is synchronous with a short timeout; failures are
     * logged and never propagate.
     *
     * @param array<string, mixed> $payload
     */
    public function dispatch(string $repoSlug, string $event, array $payload): void
    {
        try {
            if (! in_array($event, self::SUPPORTED_EVENTS, true)) return;
            if (! function_exists('curl_init')) return;

            $hooks = $this->app->db()->fetchAll(
                'SELECT w.`id`, w.`url`, w.`secret`, w.`events`
                 FROM `webhooks` w
                 JOIN `repositories` r ON r.`id` = w.`repo_id`
                 WHERE r.`slug` = :slug AND w.`is_active` = 1',
                ['slug' => $repoSlug],
            );

            if ($hooks === []) return;

            $payloadJson = (string) json_encode([
                'event'     => $event,
                'repo'      => $repoSlug,
                'timestamp' => gmdate('c'),
                'data'      => $payload,
            ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

            foreach ($hooks as $hook) {
                $events = array_map('trim', explode(',', (string) $hook['events']));
                if (! in_array($event, $events, true) && ! in_array('*', $events, true)) continue;

                $this->deliver((int) $hook['id'], (string) $hook['url'], (string) ($hook['secret'] ?? ''), $event, $payloadJson);
            }
        } catch (\Throwable $e) {
            error_log('[WebhookService] ' . $e->getMessage());
        }
    }

    /** POST one payload and record the delivery outcome. */
    private function deliver(int $webhookId, string $url, string $secret, string $event, string $payloadJson): void
    {
        // Enforce webhook_max_deliveries_per_hour policy
        try {
            $maxDeliveriesPerHour = (int) ($this->app->db()->fetchOne("SELECT value FROM system_settings WHERE key_name = 'webhook_max_deliveries_per_hour'")['value'] ?? 100);
            if ($maxDeliveriesPerHour > 0) {
                $countPastHour = (int) ($this->app->db()->fetchOne(
                    'SELECT COUNT(*) AS c FROM `webhook_deliveries` WHERE `webhook_id` = :wid AND `created_at` >= DATE_SUB(NOW(), INTERVAL 1 HOUR)',
                    ['wid' => $webhookId]
                )['c'] ?? 0);
                if ($countPastHour >= $maxDeliveriesPerHour) {
                    $this->app->db()->execute(
                        'INSERT INTO `webhook_deliveries` (`webhook_id`, `event`, `payload`, `response_code`, `success`, `duration_ms`, `created_at`)
                         VALUES (:wid, :event, :payload, 429, 0, 0, CURRENT_TIMESTAMP)',
                        [
                            'wid'     => $webhookId,
                            'event'   => $event,
                            'payload' => mb_substr($payloadJson, 0, 60000),
                        ],
                    );
                    return;
                }
            }
        } catch (\Throwable) {}

        $ch = curl_init($url);
        if ($ch === false) return;

        $headers = [
            'Content-Type: application/json',
            'X-GitPHP-Event: ' . $event,
            'X-GitHub-Event: ' . $event,
            'User-Agent: GitPHP-Webhook/1.0',
        ];

        if ($secret !== '') {
            $sigHex = hash_hmac('sha256', $payloadJson, $secret);
            $headers[] = 'X-GitPHP-Signature: sha256=' . $sigHex;
            $headers[] = 'X-Hub-Signature-256: sha256=' . $sigHex;
        }

        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $payloadJson,
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_TIMEOUT        => 5,
            CURLOPT_CONNECTTIMEOUT => 3,
            CURLOPT_SSL_VERIFYPEER => true,
        ]);

        $start   = (int) (microtime(true) * 1000);
        $body    = curl_exec($ch);
        $code    = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $ok      = $body !== false && $code >= 200 && $code < 300;
        $tookMs  = (int) (microtime(true) * 1000) - $start;

        curl_close($ch);

        try {
            $this->app->db()->execute(
                'INSERT INTO `webhook_deliveries` (`webhook_id`, `event`, `payload`, `response_code`, `success`, `duration_ms`, `created_at`)
                 VALUES (:wid, :event, :payload, :code, :success, :ms, CURRENT_TIMESTAMP)',
                [
                    'wid'     => $webhookId,
                    'event'   => $event,
                    'payload' => mb_substr($payloadJson, 0, 60000),
                    'code'    => $code ?: null,
                    'success' => $ok ? 1 : 0,
                    'ms'      => $tookMs,
                ],
            );
        } catch (\Throwable) {
            // Delivery bookkeeping must not break the caller.
        }
    }
}
