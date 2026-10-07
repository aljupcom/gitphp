<?php

declare(strict_types=1);

namespace App\Middleware;

use App\App;
use App\Auth;

final class AuthMiddleware
{
    /** Redirect to /login if the request targets /admin and the user is not authenticated. */
    public function handle(string $uri): void
    {
        if (! str_starts_with($uri, '/admin')) return;

        $auth = new Auth(App::instance());

        if (! $auth->isLoggedIn()) {
            header('Location: /login');
            exit;
        }
    }
}
