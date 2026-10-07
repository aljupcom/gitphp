<?php

declare(strict_types=1);

// ── Global env() helper ────────────────────────────────────────────
// This file has no namespace declaration, so env() is registered in
// the global scope and callable from anywhere (namespaced or not).
// It is autoloaded via the "files" entry in composer.json.

if (! function_exists('env')) {
    /**
     * Retrieve an environment variable with an optional default.
     */
    function env(string $key, mixed $default = null): mixed
    {
        $value = $_ENV[$key] ?? getenv($key);

        if ($value === false || $value === null) {
            return $default;
        }

        // Cast common string representations
        return match (strtolower((string) $value)) {
            'true', '(true)'   => true,
            'false', '(false)' => false,
            'null', '(null)'   => null,
            'empty', '(empty)' => '',
            default            => $value,
        };
    }
}


if (! function_exists('flashAcc')) {
    /**
     * Set a session flash message and redirect, then terminate execution.
     *
     * Used throughout AccountController for both error and success responses.
     * Always calls exit() so the caller does not need to add a return/exit.
     *
     * @param string $key      Session key, e.g. 'flash_error' or 'flash_success'
     * @param string $message  Human-readable message to show on the next page
     * @param string $location URL to redirect to (header Location)
     */
    function flashAcc(string $key, string $message, string $location): never
    {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }

        $_SESSION[$key] = $message;

        header('Location: ' . $location);
        exit;
    }
}
