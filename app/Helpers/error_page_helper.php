<?php

/*
|--------------------------------------------------------------------------
| Error pages
|--------------------------------------------------------------------------
| What the error pages (app/Views/errors/html) need to decide, kept out of the
| templates so it can be tested. The templates run while an error is being
| handled, so everything here is defensive: it never throws.
*/

if (! function_exists('error_page_detail')) {

    /**
     * The technical message an error page may show: only outside production, and only for a developer
     * to read. In production nothing about the failure (a path, a route, a class) is ever printed.
     */
    function error_page_detail(string $message, string $environment): ?string
    {
        $message = trim($message);

        return $environment === 'production' || $message === '' ? null : $message;
    }
}

if (! function_exists('error_page_next_step')) {

    /**
     * Where the main button of an error page leads and what it says: a signed-in person goes to their own
     * dashboard (the site root sends each role to its own), anyone else to the sign-in page. Falls back to
     * sign-in if who is signed in cannot be told while an error is being handled.
     *
     * @return array{label: string, url: string}
     */
    function error_page_next_step(): array
    {
        helper('url');

        try {
            if (function_exists('auth') && auth()->loggedIn()) {
                return ['label' => 'Go to my dashboard', 'url' => site_url('/')];
            }
        } catch (\Throwable) {
            // No session to ask: treat the visitor as signed out.
        }

        return ['label' => 'Sign in', 'url' => site_url('login')];
    }
}
