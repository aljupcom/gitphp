<?php

declare(strict_types=1);

namespace App\Service;

final class Locale
{
    private static array $cache = [];
    private static ?string $currentLocale = null;
    private static ?string $defaultLocale = null;

    /**
     * Get currently active locale (e.g. 'ar', 'en')
     */
    public static function current(): string
    {
        if (self::$currentLocale !== null) {
            return self::$currentLocale;
        }

        self::ensureSession();

        // 1. URL Query Parameter ?lang=
        if (!empty($_GET['lang']) && is_string($_GET['lang'])) {
            $code = strtolower(trim($_GET['lang']));
            if (self::isValidLocale($code)) {
                self::set($code);
                return self::$currentLocale = $code;
            }
        }

        // 2. Session
        if (!empty($_SESSION['locale']) && is_string($_SESSION['locale'])) {
            $code = strtolower(trim($_SESSION['locale']));
            if (self::isValidLocale($code)) {
                return self::$currentLocale = $code;
            }
        }

        // 3. Cookie
        if (!empty($_COOKIE['locale']) && is_string($_COOKIE['locale'])) {
            $code = strtolower(trim($_COOKIE['locale']));
            if (self::isValidLocale($code)) {
                return self::$currentLocale = $code;
            }
        }

        // 4. System Default
        return self::$currentLocale = self::getDefault();
    }

    /**
     * Set active locale
     */
    public static function set(string $locale): void
    {
        $locale = strtolower(trim($locale));
        if (!self::isValidLocale($locale)) {
            return;
        }

        self::ensureSession();
        self::$currentLocale = $locale;

        if (session_status() === PHP_SESSION_ACTIVE) {
            $_SESSION['locale'] = $locale;
        }

        if (!headers_sent()) {
            $isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
                || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
            
            @setcookie('locale', $locale, [
                'expires'  => time() + (86400 * 365),
                'path'     => '/',
                'secure'   => $isHttps,
                'httponly' => false,
                'samesite' => 'Lax',
            ]);
            $_COOKIE['locale'] = $locale;
        }

        // Dynamically update Twig environment globals if View exists
        if (class_exists('\\App\\App', false)) {
            try {
                $app = \App\App::instance();
                $isRtl = self::isRtl($locale);
                $view = $app->view();
                $ref = new \ReflectionClass($view);
                if ($ref->hasProperty('twig')) {
                    $prop = $ref->getProperty('twig');
                    $prop->setAccessible(true);
                    $twig = $prop->getValue($view);
                    if ($twig instanceof \Twig\Environment) {
                        $twig->addGlobal('current_locale', $locale);
                        $twig->addGlobal('is_rtl', $isRtl);
                        $twig->addGlobal('dir', $isRtl ? 'rtl' : 'ltr');
                    }
                }
            } catch (\Throwable) {
                // Ignore if App/View not yet booted
            }
        }
    }

    /**
     * Get system default locale
     */
    public static function getDefault(): string
    {
        if (self::$defaultLocale !== null) {
            return self::$defaultLocale;
        }

        // Try reading default from manifest files in config/lang/
        $langDir = dirname(__DIR__, 2) . '/config/lang';
        if (is_dir($langDir)) {
            $dirs = scandir($langDir);
            if ($dirs !== false) {
                foreach ($dirs as $dir) {
                    if ($dir === '.' || $dir === '..') continue;
                    $manifestPath = $langDir . '/' . $dir . '/manifest.json';
                    if (file_exists($manifestPath)) {
                        $content = @file_get_contents($manifestPath);
                        $manifest = $content ? @json_decode($content, true) : null;
                        if (is_array($manifest) && !empty($manifest['default'])) {
                            return self::$defaultLocale = $dir;
                        }
                    }
                }
            }
        }

        return self::$defaultLocale = 'en';
    }

    /**
     * Load translations for a locale & scope into static memory cache
     */
    public static function load(string $locale, string $scope): array
    {
        $locale = strtolower(trim($locale));
        $scope  = strtolower(trim($scope));

        if (isset(self::$cache[$locale][$scope])) {
            return self::$cache[$locale][$scope];
        }

        $filePath = dirname(__DIR__, 2) . "/config/lang/{$locale}/{$scope}.php";
        if (file_exists($filePath)) {
            $data = include $filePath;
            if (is_array($data)) {
                return self::$cache[$locale][$scope] = $data;
            }
        }

        return self::$cache[$locale][$scope] = [];
    }

    /**
     * Translate key with scoped fallback
     * e.g. Locale::t('ui.nav.home') -> scope='ui', key='nav.home'
     */
    public static function t(string $key, array $params = [], ?string $locale = null): string
    {
        $locale = $locale ?? self::current();
        $parts  = explode('.', $key, 2);
        
        if (count($parts) === 2) {
            $scope  = $parts[0];
            $subKey = $parts[1];
        } else {
            $scope  = 'common';
            $subKey = $key;
        }

        // 1) lang/{locale}/{scope}.php
        $val = self::extractKey(self::load($locale, $scope), $subKey);
        
        // 2) lang/{locale}/common.php
        if ($val === null && $scope !== 'common') {
            $val = self::extractKey(self::load($locale, 'common'), $subKey) 
                ?? self::extractKey(self::load($locale, 'common'), $key);
        }

        // 3) Fallback to 'en': lang/en/{scope}.php
        if ($val === null && $locale !== 'en') {
            $val = self::extractKey(self::load('en', $scope), $subKey);
        }

        // 4) Fallback to 'en' common: lang/en/common.php
        if ($val === null && $locale !== 'en') {
            $val = self::extractKey(self::load('en', 'common'), $subKey) 
                ?? self::extractKey(self::load('en', 'common'), $key);
        }

        // 5) If still null, return key itself
        if ($val === null) {
            $val = $key;
        }

        // Replace parameters in string: {param}, :param, %param%
        if (!empty($params) && is_string($val)) {
            foreach ($params as $pKey => $pVal) {
                $pStr = (string)$pVal;
                $val = str_replace(
                    ['{' . $pKey . '}', ':' . $pKey, '%' . $pKey . '%'],
                    $pStr,
                    $val
                );
            }
        }

        return (string)$val;
    }

    /**
     * Check if active locale is RTL
     */
    public static function isRtl(?string $locale = null): bool
    {
        $locale = $locale ?? self::current();
        
        // Check manifest
        $manifestPath = dirname(__DIR__, 2) . "/config/lang/{$locale}/manifest.json";
        if (file_exists($manifestPath)) {
            $content = @file_get_contents($manifestPath);
            $manifest = $content ? @json_decode($content, true) : null;
            if (is_array($manifest) && isset($manifest['direction'])) {
                return strtolower($manifest['direction']) === 'rtl';
            }
        }

        return in_array($locale, ['ar', 'fa', 'ur', 'he', 'ps'], true);
    }

    /**
     * Helper to extract nested key like 'nav.home' from array ['nav' => ['home' => 'Home']]
     */
    private static function extractKey(array $array, string $key): ?string
    {
        if (array_key_exists($key, $array) && is_string($array[$key])) {
            return $array[$key];
        }

        $segments = explode('.', $key);
        $current = $array;

        foreach ($segments as $segment) {
            if (is_array($current) && array_key_exists($segment, $current)) {
                $current = $current[$segment];
            } else {
                return null;
            }
        }

        return is_string($current) ? $current : null;
    }

    /**
     * Ensure session is started with proper project-local save path
     */
    private static function ensureSession(): void
    {
        if (session_status() === PHP_SESSION_NONE && !headers_sent()) {
            $basePath = dirname(__DIR__, 2);
            $sessionDir = $basePath . '/storage/sessions';
            if (!is_dir($sessionDir)) @mkdir($sessionDir, 0775, true);
            if (is_dir($sessionDir)) session_save_path($sessionDir);
            
            $isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
                || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
            
            @session_set_cookie_params([
                'lifetime' => 0,
                'path'     => '/',
                'secure'   => $isHttps,
                'httponly' => true,
                'samesite' => 'Lax',
            ]);
            @ini_set('session.use_strict_mode', '1');
            @session_start();
        }
    }

    /**
     * Validate if locale package folder exists and is active
     */
    public static function isValidLocale(string $locale): bool
    {
        $localeDir = dirname(__DIR__, 2) . "/config/lang/{$locale}";
        return is_dir($localeDir) && file_exists($localeDir . '/manifest.json');
    }
}
