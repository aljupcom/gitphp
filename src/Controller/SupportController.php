<?php

declare(strict_types=1);

namespace App\Controller;

use App\App;
use App\Auth;
use App\Middleware\RateLimit;

/**
 * Support desk: ticket creation and tracking for BOTH visitors (guests)
 * and registered users.
 *
 * Guests identify a ticket with its reference + the email used to open it;
 * a successful lookup grants read access for the current session only.
 */
final class SupportController
{
    private const CATEGORIES = ['general', 'technical', 'account', 'abuse', 'feature'];
    private const PRIORITIES = ['low', 'normal', 'high', 'urgent'];

    private App $app;
    private Auth $auth;

    public function __construct(App $app)
    {
        $this->app  = $app;
        $this->auth = new Auth($app);
    }

    /** GET /support — tabbed dashboard (Create Ticket | Ticket Tracking). */
    public function dashboard(): void
    {
        $tab = ($_GET['tab'] ?? '') === 'track' ? 'track' : 'create';

        $error   = $_SESSION['support_error'] ?? null;
        $success = $_SESSION['support_success'] ?? null;
        $ref     = $_SESSION['support_ref'] ?? null;
        $old     = $_SESSION['support_old'] ?? [];
        unset($_SESSION['support_error'], $_SESSION['support_success'], $_SESSION['support_ref'], $_SESSION['support_old']);

        // Registered users get their ticket list automatically.
        $tickets = [];
        if ($this->auth->isLoggedIn() && ! $this->auth->isOwner() && $this->auth->userId() > 0) {
            $tickets = $this->app->db()->fetchAll(
                'SELECT `reference`, `subject`, `category`, `priority`, `status`, `created_at`, `updated_at`
                 FROM `support_tickets` WHERE `user_id` = :u ORDER BY `created_at` DESC LIMIT 50',
                ['u' => $this->auth->userId()],
            );
        }

        $isLoggedIn = $this->auth->isLoggedIn();
        $isOwner    = $isLoggedIn && $this->auth->isOwner();
        $user       = $isLoggedIn && ! $isOwner ? $this->auth->user() : null;

        // The session identity is the source of truth for the visible name.
        // Do not make the support form blank just because the user row could
        // not be loaded during this request (for example after a migration).
        $displayName  = $isLoggedIn ? $this->auth->displayName() : '';
        $displayEmail = $user !== null ? (string) ($user['email'] ?? '') : '';

        $this->app->view()->display('pages/support.twig', [
            'page_title'  => 'Support Center',
            'active_tab'  => $tab,
            'csrf_token'  => $this->auth->generateCsrf(),
            'error'       => $error,
            'success'     => $success,
            'new_ref'     => $ref,
            'old'         => $old,
            'tickets'     => $tickets,
            'categories'  => self::CATEGORIES,
            'priorities'  => self::PRIORITIES,
            'is_guest'      => ! $isLoggedIn,
            'is_owner'      => $isOwner,
            'prefill_name'  => $displayName,
            'prefill_email' => $displayEmail,
            'hide_global_flash' => true,
        ]);
    }

    /** POST /support/tickets — open a new ticket (guest or user). */
    public function store(): void
    {
        if (! $this->auth->validateCsrf((string) ($_POST['csrf_token'] ?? ''))) {
            $this->fail('Invalid security token. Please try again.');
        }

        $clientIp = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
        $limiter  = new RateLimit($this->app);
        if (! $limiter->check('ticket_' . $clientIp, maxAttempts: 5, decayMinutes: 15)) {
            $this->fail('Too many tickets submitted. Please try again in 15 minutes.');
        }

        $subject  = trim((string) ($_POST['subject'] ?? ''));
        $message  = trim((string) ($_POST['message'] ?? ''));
        $category = (string) ($_POST['category'] ?? 'general');
        $priority = (string) ($_POST['priority'] ?? 'normal');
        $name     = trim((string) ($_POST['name'] ?? ''));
        $email    = strtolower(trim((string) ($_POST['email'] ?? '')));

        if (! in_array($category, self::CATEGORIES, true)) $category = 'general';
        if (! in_array($priority, self::PRIORITIES, true)) $priority = 'normal';

        $isAuthenticated = $this->auth->isLoggedIn();
        $isUser          = $isAuthenticated && ! $this->auth->isOwner() && $this->auth->userId() > 0;
        $userId          = $isUser ? $this->auth->userId() : null;

        $old = ['subject' => $subject, 'message' => $message, 'category' => $category, 'priority' => $priority, 'name' => $name, 'email' => $email];

        if (mb_strlen($subject) < 5 || mb_strlen($subject) > 200) {
            $this->fail('Subject must be between 5 and 200 characters.', $old);
        }
        if (mb_strlen($message) < 15 || mb_strlen($message) > 5000) {
            $this->fail('Please describe your request in at least 15 characters.', $old);
        }

        if (! $isAuthenticated) {
            if (mb_strlen($name) < 2 || mb_strlen($name) > 100) {
                $this->fail('Please provide your name (2-100 characters).', $old);
            }
            if ($email === '' || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
                $this->fail('Please provide a valid email address so we can reply.', $old);
            }
        } elseif ($isUser) {
            $u     = $this->auth->user();
            $name  = (string) ($u['username'] ?? $this->auth->displayName());
            $email = (string) ($u['email'] ?? '');
        } else {
            // The owner has a valid authenticated identity but no row in
            // users; never send it through guest name/email validation.
            $name  = $this->auth->displayName();
            $email = '';
        }

        // Unique, human-friendly reference.
        $reference = '';
        for ($i = 0; $i < 6; $i++) {
            $candidate = 'TCK-' . strtoupper(bin2hex(random_bytes(4)));
            $exists = $this->app->db()->fetchOne('SELECT `id` FROM `support_tickets` WHERE `reference` = :r LIMIT 1', ['r' => $candidate]);
            if ($exists === false) { $reference = $candidate; break; }
        }
        if ($reference === '') $this->fail('Could not allocate a ticket reference. Please retry.', $old);

        $this->app->db()->execute(
            'INSERT INTO `support_tickets`
                (`reference`, `user_id`, `guest_name`, `guest_email`, `subject`, `category`, `priority`, `message`, `status`)
             VALUES (:ref, :uid, :gname, :gmail, :subject, :cat, :prio, :msg, \'open\')',
            [
                'ref'     => $reference,
                'uid'     => $userId,
                'gname'   => $isUser ? null : $name,
                'gmail'   => $isUser ? null : $email,
                'subject' => $subject,
                'cat'     => $category,
                'prio'    => $priority,
                'msg'     => $message,
            ],
        );

        $limiter->increment('ticket_' . $clientIp);

        // Grant this session read access (guests track without an account).
        $grants = $_SESSION['ticket_access'] ?? [];
        $grants[] = $reference;
        $_SESSION['ticket_access'] = array_values(array_unique($grants));

        $_SESSION['support_success'] = 'Your ticket has been created. Keep the reference below to track it.';
        $_SESSION['support_ref']     = $reference;

        // Transactional confirmation email + in-app notification (best-effort).
        try {
            $ticketUrl = rtrim((string) $this->app->config('app.url', ''), '/') . '/support/ticket/' . $reference;
            (new \App\Service\Notifier($this->app))->event(
                'ticket.created',
                $email,
                ['name' => $name, 'reference' => $reference, 'subject' => $subject, 'ticket_url' => $ticketUrl],
                (int) ($userId ?? 0),
                'Support ticket ' . $reference . ' created',
                '/support/ticket/' . $reference,
            );
        } catch (\Throwable) {
        }

        header('Location: /support?tab=track');
        exit;
    }

    /** POST /support/track — guest lookup by reference + email. */
    public function track(): void
    {
        if (! $this->auth->validateCsrf((string) ($_POST['csrf_token'] ?? ''))) {
            $this->fail('Invalid security token. Please try again.');
        }

        $reference = strtoupper(trim((string) ($_POST['reference'] ?? '')));
        $email     = strtolower(trim((string) ($_POST['email'] ?? '')));

        if ($reference === '' || $email === '') {
            $this->fail('Enter both the ticket reference and the email used to open it.');
        }

        $ticket = $this->app->db()->fetchOne(
            'SELECT `reference` FROM `support_tickets`
             WHERE `reference` = :r AND (`guest_email` = :e OR `user_id` IN (SELECT `id` FROM `users` WHERE `email` = :e2))
             LIMIT 1',
            ['r' => $reference, 'e' => $email, 'e2' => $email],
        );

        if ($ticket === false) {
            $this->fail('No ticket matches that reference and email.');
        }

        $grants = $_SESSION['ticket_access'] ?? [];
        $grants[] = (string) $ticket['reference'];
        $_SESSION['ticket_access'] = array_values(array_unique($grants));

        header('Location: /support/ticket/' . rawurlencode((string) $ticket['reference']));
        exit;
    }

    /** GET /support/ticket/{reference} — ticket detail + conversation. */
    public function show(string $reference): void
    {
        $reference = strtoupper(trim($reference));
        $ticket    = $this->loadTicket($reference);

        if ($ticket === null) {
            $_SESSION['support_error'] = 'Ticket not found, or you do not have access to it.';
            header('Location: /support?tab=track');
            exit;
        }

        $replies = $this->app->db()->fetchAll(
            'SELECT `author_name`, `is_staff`, `body`, `created_at`
             FROM `support_ticket_replies` WHERE `ticket_id` = :t ORDER BY `created_at` ASC',
            ['t' => (int) $ticket['id']],
        );

        $error = $_SESSION['support_error'] ?? null;
        $success = $_SESSION['support_success'] ?? null;
        unset($_SESSION['support_error'], $_SESSION['support_success']);

        $this->app->view()->display('pages/ticket.twig', [
            'page_title' => 'Ticket ' . $ticket['reference'],
            'ticket'     => $ticket,
            'replies'    => $replies,
            'csrf_token' => $this->auth->generateCsrf(),
            'error'      => $error,
            'success'    => $success,
            'can_reply'  => $ticket['status'] !== 'closed',
        ]);
    }

    /** POST /support/ticket/{reference}/reply — append a message. */
    public function reply(string $reference): void
    {
        $reference = strtoupper(trim($reference));

        if (! $this->auth->validateCsrf((string) ($_POST['csrf_token'] ?? ''))) {
            $_SESSION['support_error'] = 'Invalid security token. Please try again.';
            header('Location: /support/ticket/' . rawurlencode($reference));
            exit;
        }

        $ticket = $this->loadTicket($reference);
        if ($ticket === null) {
            $_SESSION['support_error'] = 'Ticket not found, or you do not have access to it.';
            header('Location: /support?tab=track');
            exit;
        }

        $body = trim((string) ($_POST['body'] ?? ''));
        if (mb_strlen($body) < 2 || mb_strlen($body) > 5000) {
            $_SESSION['support_error'] = 'Your reply must be between 2 and 5000 characters.';
            header('Location: /support/ticket/' . rawurlencode($reference));
            exit;
        }

        if ((string) $ticket['status'] === 'closed') {
            $_SESSION['support_error'] = 'This ticket is closed and can no longer receive replies.';
            header('Location: /support/ticket/' . rawurlencode($reference));
            exit;
        }

        $isStaff = $this->auth->isLoggedIn() && ($this->auth->isOwner() || $this->auth->isStaff());
        $author  = $this->auth->isLoggedIn() ? $this->auth->displayName() : (string) ($ticket['guest_name'] ?? 'Guest');

        $this->app->db()->execute(
            'INSERT INTO `support_ticket_replies` (`ticket_id`, `user_id`, `author_name`, `is_staff`, `body`)
             VALUES (:t, :u, :a, :s, :b)',
            [
                't' => (int) $ticket['id'],
                'u' => $this->auth->isLoggedIn() && ! $this->auth->isOwner() ? $this->auth->userId() : null,
                'a' => mb_substr($author, 0, 100),
                's' => $isStaff ? 1 : 0,
                'b' => $body,
            ],
        );

        $this->app->db()->execute(
            'UPDATE `support_tickets` SET `status` = :s, `updated_at` = NOW() WHERE `id` = :id',
            ['s' => $isStaff ? 'answered' : 'pending', 'id' => (int) $ticket['id']],
        );

        $_SESSION['support_success'] = 'Your reply has been added.';
        header('Location: /support/ticket/' . rawurlencode($reference));
        exit;
    }

    /** Load a ticket the current visitor is allowed to read, or null. */
    private function loadTicket(string $reference): ?array
    {
        if ($reference === '' || ! preg_match('/^TCK-[A-F0-9]{8}$/', $reference)) return null;

        $ticket = $this->app->db()->fetchOne(
            'SELECT * FROM `support_tickets` WHERE `reference` = :r LIMIT 1',
            ['r' => $reference],
        );

        if ($ticket === false) return null;

        // Staff / owner can always read.
        if ($this->auth->isLoggedIn() && ($this->auth->isOwner() || $this->auth->isStaff())) return $ticket;

        // The registered author can read their own ticket.
        if ($this->auth->isLoggedIn() && (int) ($ticket['user_id'] ?? 0) === $this->auth->userId() && $this->auth->userId() > 0) {
            return $ticket;
        }

        // A guest who proved reference + email in this session.
        $grants = $_SESSION['ticket_access'] ?? [];
        if (in_array($reference, $grants, true)) return $ticket;

        return null;
    }

    /** Redirect back to the dashboard with an error (and preserved input). */
    private function fail(string $message, array $old = []): never
    {
        $_SESSION['support_error'] = $message;
        if ($old !== []) $_SESSION['support_old'] = $old;
        header('Location: /support' . ($old === [] ? '?tab=track' : ''));
        exit;
    }
}
