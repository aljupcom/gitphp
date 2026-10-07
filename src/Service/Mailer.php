<?php

declare(strict_types=1);

namespace App\Service;

use App\App;

/**
 * Backwards-compatible mail facade.
 *
 * Historically this used PHP mail() directly. It now delegates to the
 * full {@see MailService} (DB-configured SMTP/API/mail with logging), while
 * keeping the original `new Mailer()` call sites working unchanged.
 */
final class Mailer
{
    private MailService $service;

    public function __construct(?App $app = null)
    {
        $this->service = new MailService($app ?? App::instance());
    }

    public function enabled(): bool
    {
        return $this->service->enabled();
    }

    public function send(string $to, string $subject, string $bodyHtml, string $bodyText = ''): bool
    {
        return $this->service->send($to, $subject, $bodyHtml, $bodyText);
    }
}
