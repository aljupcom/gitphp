<?php

declare(strict_types=1);

namespace App\Controller;

use App\App;

/**
 * Essential site pages (legal & info): Privacy, Terms, About/Rights,
 * Contact and Security. Each renders a simple themed template that
 * extends the site layout.
 */
final class PagesController
{
    private App $app;

    public function __construct(App $app)
    {
        $this->app = $app;
    }

    /** GET /privacy — Privacy Policy */
    public function privacy(): void
    {
        $this->render('privacy', 'Privacy Policy');
    }

    /** GET /terms — Terms & Conditions */
    public function terms(): void
    {
        $this->render('terms', 'Terms & Conditions');
    }

    /** GET /about — About / Rights */
    public function about(): void
    {
        $this->render('about', 'About & Rights');
    }

    /** GET /contact — Contact */
    public function contact(): void
    {
        $this->render('contact', 'Contact');
    }

    /** GET /security — Security */
    public function security(): void
    {
        $this->render('security', 'Security');
    }

    private function render(string $template, string $title): void
    {
        $this->app->view()->display('pages/' . $template . '.twig', [
            'page_title' => $title,
            'owner'      => (string) $this->app->config('app.owner', 'admin'),
            'app_url'    => rtrim((string) $this->app->config('app.url', 'https://git.ysnapp.com'), '/'),
        ]);
    }
}
