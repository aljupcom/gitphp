<?php

declare(strict_types=1);

namespace App;

use Twig\Environment;
use Twig\Loader\FilesystemLoader;
use Twig\TwigFilter;
use Twig\TwigFunction;

final class View
{
    private Environment $twig;

    /**
     * @param array<string, mixed> $globals
     * @param string|null          $cachePath Twig compiled-template cache (null disables)
     */
    public function __construct(string $templatesPath, array $globals = [], ?string $cachePath = null)
    {
        $loader = new FilesystemLoader($templatesPath);
        $debug  = (bool) env('APP_DEBUG', false);

        // Ensure the compiled-template cache directory exists and is writable.
        // If it cannot be created/written, fall back to no caching so a
        // missing or read-only directory can never break rendering.
        if ($cachePath !== null) {
            @mkdir($cachePath, 0777, true);
            @chmod($cachePath, 0777);
            if (! is_dir($cachePath)) {
                $cachePath = null;
            } elseif (! is_writable($cachePath)) {
                $cachePath = null;
            }
        }

        $this->twig = new Environment($loader, [
            'cache'       => $cachePath !== null ? $cachePath : false,
            // auto_reload must NEVER be off in production: with it disabled a
            // warm cache serves stale templates forever after a deployment
            // (git pull changes template files, the cache ignores them). The
            // per-request mtime stat is negligible next to correctness.
            'auto_reload' => true,
            'debug'       => $debug,
        ]);

        foreach ($globals as $name => $value) $this->twig->addGlobal($name, $value);

        // Unified repository header data (breadcrumb + branch selector +
        // counts + actions) so every repo tab shares the same toolbar.
        // i18n & Language System Globals & Functions
        $currentLocale = \App\Service\Locale::current();
        $isRtl = \App\Service\Locale::isRtl($currentLocale);
        $this->twig->addGlobal('current_locale', $currentLocale);
        $this->twig->addGlobal('is_rtl', $isRtl);
        $this->twig->addGlobal('dir', $isRtl ? 'rtl' : 'ltr');
        $this->twig->addGlobal('installed_locales', (new \App\Service\LanguagePackageService())->listInstalled());

        $this->twig->addFunction(new TwigFunction('t', static function (string $key, array $params = []): string {
            return \App\Service\Locale::t($key, $params);
        }));

        $this->twig->addFunction(new TwigFunction('t_attr', static function (string $key, array $params = []): string {
            return htmlspecialchars(\App\Service\Locale::t($key, $params), ENT_QUOTES, 'UTF-8');
        }));

        $this->twig->addFunction(new TwigFunction('t_html', static function (string $key, array $params = []): string {
            return \App\Service\Locale::t($key, $params);
        }, ['is_safe' => ['html']]));

        $this->twig->addFunction(new TwigFunction('repo_header', static function (mixed $repo, string $currentRef = ''): array {
            if (! is_array($repo) || empty($repo['id'])) return [];

            return (new \App\Service\RepoHeaderBuilder(\App\App::instance()))->build($repo, $currentRef);
        }));

        // Stable DOM anchors (diff line review comments, etc.)
        $this->twig->addFunction(new TwigFunction('icon', static function (string $name, int $size = 16, string $class = ''): string {
            $cls = trim('ti ti-' . $name . ' ' . $class);
            return '<svg class="' . $cls . '" width="' . $size . '" height="' . $size . '" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><use href="#ti-' . $name . '"/></svg>';
        }, ['is_safe' => ['html']]));

        // Colored per-type file/folder icon (self-hosted Tabler, CSP-safe).
        $this->twig->addFunction(new TwigFunction('file_icon', static function (string $name, string $type = 'blob'): string {
            $render = static function (string $icon, string $color): string {
                return '<span class="gh-ficon gh-ficon--' . $color . '">'
                    . '<svg class="ti ti-' . $icon . '" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">'
                    . '<use href="#ti-' . $icon . '"/></svg></span>';
            };

            if ($type === 'tree') {
                return $render('folder', 'folder');
            }

            $lower = strtolower($name);

            $specials = [
                'dockerfile'          => ['brand-docker', 'docker'],
                'docker-compose.yml'  => ['brand-docker', 'docker'],
                'docker-compose.yaml' => ['brand-docker', 'docker'],
                'makefile'            => ['terminal', 'shell'],
                'readme'              => ['file-text', 'doc'],
                'readme.md'           => ['markdown', 'md'],
                'readme.txt'          => ['file-text', 'doc'],
                'license'             => ['file-text', 'doc'],
                'license.md'          => ['markdown', 'md'],
                'changelog'           => ['file-text', 'doc'],
                'changelog.md'        => ['markdown', 'md'],
                'composer.json'       => ['brand-php', 'php'],
                'package.json'        => ['brand-npm', 'js'],
                'composer.lock'       => ['file-text', 'config'],
                'package-lock.json'   => ['file-text', 'config'],
                'yarn.lock'           => ['file-text', 'config'],
                '.env'                => ['file-text', 'config'],
                '.env.example'        => ['file-text', 'config'],
                '.gitignore'          => ['file-text', 'config'],
                '.gitattributes'      => ['file-text', 'config'],
            ];
            if (isset($specials[$lower])) {
                [$icon, $color] = $specials[$lower];
                return $render($icon, $color);
            }

            if (str_starts_with($name, '.')) {
                return $render('file-text', 'config');
            }

            $ext = strtolower((string) pathinfo($name, PATHINFO_EXTENSION));

            $map = [
                'php'   => ['file-type-php', 'php'],
                'js'    => ['file-type-js', 'js'],
                'mjs'   => ['file-type-js', 'js'],
                'cjs'   => ['file-type-js', 'js'],
                'jsx'   => ['file-type-jsx', 'react'],
                'ts'    => ['file-type-ts', 'ts'],
                'tsx'   => ['file-type-tsx', 'react'],
                'css'   => ['file-type-css', 'css'],
                'scss'  => ['file-type-css', 'css'],
                'less'  => ['file-type-css', 'css'],
                'html'  => ['file-type-html', 'html'],
                'htm'   => ['file-type-html', 'html'],
                'xhtml' => ['file-type-html', 'html'],
                'vue'   => ['file-type-vue', 'vue'],
                'svelte'=> ['brand-svelte', 'svelte'],
                'py'    => ['brand-python', 'python'],
                'rb'    => ['file-code', 'ruby'],
                'rs'    => ['file-type-rs', 'rust'],
                'go'    => ['brand-golang', 'go'],
                'java'  => ['file-code', 'java'],
                'kt'    => ['brand-kotlin', 'kotlin'],
                'kts'   => ['brand-kotlin', 'kotlin'],
                'swift' => ['brand-swift', 'swift'],
                'c'     => ['file-code', 'c'],
                'h'     => ['file-code', 'c'],
                'cpp'   => ['brand-cpp', 'c'],
                'cc'    => ['brand-cpp', 'c'],
                'cxx'   => ['brand-cpp', 'c'],
                'cs'    => ['brand-c-sharp', 'c'],
                'sql'   => ['file-type-sql', 'sql'],
                'json'  => ['json', 'json'],
                'yml'   => ['file-text', 'config'],
                'yaml'  => ['file-text', 'config'],
                'toml'  => ['toml', 'config'],
                'ini'   => ['file-text', 'config'],
                'conf'  => ['file-text', 'config'],
                'cfg'   => ['file-text', 'config'],
                'lock'  => ['file-text', 'config'],
                'xml'   => ['file-type-xml', 'xml'],
                'svg'   => ['file-type-svg', 'svg'],
                'md'    => ['markdown', 'md'],
                'markdown' => ['markdown', 'md'],
                'rst'   => ['file-text', 'doc'],
                'txt'   => ['file-type-txt', 'doc'],
                'log'   => ['file-text', 'doc'],
                'csv'   => ['file-type-csv', 'csv'],
                'pdf'   => ['file-type-pdf', 'pdf'],
                'zip'   => ['file-type-zip', 'archive'],
                'gz'    => ['zip', 'archive'],
                'tgz'   => ['zip', 'archive'],
                'tar'   => ['zip', 'archive'],
                'rar'   => ['zip', 'archive'],
                '7z'    => ['zip', 'archive'],
                'png'   => ['file-type-png', 'image'],
                'jpg'   => ['file-type-jpg', 'image'],
                'jpeg'  => ['file-type-jpg', 'image'],
                'gif'   => ['photo', 'image'],
                'webp'  => ['photo', 'image'],
                'avif'  => ['photo', 'image'],
                'bmp'   => ['file-type-bmp', 'image'],
                'ico'   => ['photo', 'image'],
                'mp3'   => ['file-music', 'audio'],
                'wav'   => ['file-music', 'audio'],
                'ogg'   => ['file-music', 'audio'],
                'flac'  => ['file-music', 'audio'],
                'm4a'   => ['file-music', 'audio'],
                'mp4'   => ['video', 'video'],
                'mov'   => ['video', 'video'],
                'avi'   => ['video', 'video'],
                'mkv'   => ['video', 'video'],
                'webm'  => ['video', 'video'],
                'm4v'   => ['video', 'video'],
                'sh'    => ['terminal', 'shell'],
                'bash'  => ['terminal', 'shell'],
                'zsh'   => ['terminal', 'shell'],
                'fish'  => ['terminal', 'shell'],
                'twig'  => ['file-code', 'twig'],
                'doc'   => ['file-type-doc', 'doc'],
                'docx'  => ['file-type-docx', 'doc'],
                'xls'   => ['file-type-xls', 'doc'],
                'xlsx'  => ['file-type-xls', 'doc'],
                'ppt'   => ['file-type-ppt', 'doc'],
                'pptx'  => ['file-type-ppt', 'doc'],
                'exe'   => ['file', 'binary'],
                'dll'   => ['file', 'binary'],
                'so'    => ['file', 'binary'],
                'bin'   => ['file', 'binary'],
                'apk'   => ['file', 'binary'],
            ];

            if (isset($map[$ext])) {
                [$icon, $color] = $map[$ext];
                return $render($icon, $color);
            }

            return $render('file', 'file');
        }, ['is_safe' => ['html']]));

        $this->twig->addFilter(new TwigFilter('capitalize', static fn(mixed $v): string => ucfirst((string) $v)));

        $this->twig->addFilter(new TwigFilter('avatar_letter', static function (mixed $v): string {
            if (is_array($v)) {
                $v = $v['username'] ?? $v['name'] ?? (reset($v) ?: '');
            }
            return strtoupper(substr((string) $v, 0, 1));
        }));

        $this->twig->addFilter(new TwigFilter('md5', static fn(mixed $v): string => md5((string) $v)));

        // Cache-busted asset URLs: static files are served with a long
        // max-age, so the query string must change whenever the file does.
        $this->twig->addFunction(new TwigFunction('asset', static function (string $path): string {

            $path  = ltrim($path, '/');
            $mtime = @filemtime(dirname(__DIR__) . '/public_html/' . $path);

            return '/' . $path . ($mtime !== false ? '?v=' . $mtime : '');
        }));

                // GitHub-style relative timestamps: "3 hours ago", "on Jan 15".
        $this->twig->addFilter(new TwigFilter('time_ago', static function (mixed $time): string {
            $ts = null;

            if ($time instanceof \DateTimeInterface) {
                $ts = $time->getTimestamp();
            } elseif (is_string($time)) {
                $parsed = strtotime($time);
                if ($parsed !== false) $ts = $parsed;
            } elseif (is_int($time) || is_numeric($time)) {
                $ts = (int) $time;
            }

            if ($ts === null) return '';

            $diff = time() - $ts;

            // Handle timezone differences / clock skew
            if ($diff < 0) {
                if ($diff > -60) {
                    $diff = 0;
                } else {
                    $utcTs = strtotime((string)$time . ' UTC');
                    if ($utcTs !== false) {
                        $diff = time() - $utcTs;
                        if ($diff < 0) $diff = 0;
                    } else {
                        $diff = 0;
                    }
                }
            }

            $lang = \App\Service\Locale::current();

            if ($lang === 'ar') {
                if ($diff < 45)       return 'الآن';
                if ($diff < 90)       return 'منذ دقيقة';
                if ($diff < 3540) {
                    $m = (int) round($diff / 60);
                    if ($m === 2) return 'منذ دقيقتين';
                    if ($m >= 3 && $m <= 10) return 'منذ ' . $m . ' دقائق';
                    return 'منذ ' . $m . ' دقيقة';
                }
                if ($diff < 5400)     return 'منذ ساعة';
                if ($diff < 86400) {
                    $h = (int) round($diff / 3600);
                    if ($h === 2) return 'منذ ساعتين';
                    if ($h >= 3 && $h <= 10) return 'منذ ' . $h . ' ساعات';
                    return 'منذ ' . $h . ' ساعة';
                }
                if ($diff < 129600)   return 'منذ يوم';
                if ($diff < 2592000) {
                    $d = (int) round($diff / 86400);
                    if ($d === 2) return 'منذ يومين';
                    if ($d >= 3 && $d <= 10) return 'منذ ' . $d . ' أيام';
                    return 'منذ ' . $d . ' يوماً';
                }
                if ($diff < 3888000)  return 'منذ شهر';
                if ($diff < 31104000) {
                    $mo = (int) round($diff / 2592000);
                    if ($mo === 2) return 'منذ شهرين';
                    if ($mo >= 3 && $mo <= 10) return 'منذ ' . $mo . ' أشهر';
                    return 'منذ ' . $mo . ' شهراً';
                }
                if ($diff < 46656000) return 'منذ سنة';
                return 'في ' . date('Y/m/d', $ts);
            }

            if ($diff < 45)        return 'just now';
            if ($diff < 90)        return 'a minute ago';
            if ($diff < 3540)      return (int) round($diff / 60) . ' minutes ago';
            if ($diff < 5400)      return 'an hour ago';
            if ($diff < 86400)     return (int) round($diff / 3600) . ' hours ago';
            if ($diff < 129600)    return 'a day ago';
            if ($diff < 2592000)   return (int) round($diff / 86400) . ' days ago';
            if ($diff < 3888000)   return 'a month ago';
            if ($diff < 31104000)  return (int) round($diff / 2592000) . ' months ago';
            if ($diff < 46656000)  return 'a year ago';

            return 'on ' . date('M j, Y', $ts);
        }));
    }

    /**
     * Render a Twig template and return the HTML string.
     * @param array<string, mixed> $data
     */
    public function render(string $template, array $data = []): string
    {
        if (isset($_SESSION['flash_success']) && !isset($data['flash_success']) && !isset($data['success'])) {
            $data['flash_success'] = $_SESSION['flash_success'];
            $data['success']       = $_SESSION['flash_success'];
            unset($_SESSION['flash_success']);
        }

        if (isset($_SESSION['flash_error']) && !isset($data['flash_error']) && !isset($data['error'])) {
            $data['flash_error'] = $_SESSION['flash_error'];
            $data['error']       = $_SESSION['flash_error'];
            unset($_SESSION['flash_error']);
        }

        return $this->twig->render($template, $data);
    }

    /**
     * Render a template and send it to the browser.
     * @param array<string, mixed> $data
     */
    public function display(string $template, array $data = []): void
    {
        echo $this->render($template, $data);
    }

    /** Access the underlying Twig environment. */
    public function twig(): Environment
    {
        return $this->twig;
    }
}
