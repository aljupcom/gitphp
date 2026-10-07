<?php

declare(strict_types=1);

namespace App\Service;

use ZipArchive;

final class LanguagePackageService
{
    private string $langDir;

    public function __construct()
    {
        $this->langDir = dirname(__DIR__, 2) . '/config/lang';
    }

    /**
     * List all installed language packages with their metadata
     */
    public function listInstalled(): array
    {
        $languages = [];
        if (!is_dir($this->langDir)) {
            return $languages;
        }

        $items = scandir($this->langDir);
        if ($items === false) {
            return $languages;
        }

        foreach ($items as $item) {
            if ($item === '.' || $item === '..') continue;
            
            $dirPath = $this->langDir . '/' . $item;
            $manifestPath = $dirPath . '/manifest.json';

            if (is_dir($dirPath) && file_exists($manifestPath)) {
                $raw = @file_get_contents($manifestPath);
                $manifest = $raw ? @json_decode($raw, true) : null;
                
                if (is_array($manifest)) {
                    $code = $manifest['code'] ?? $item;
                    $languages[$code] = [
                        'code'        => $code,
                        'name'        => $manifest['name'] ?? ucfirst($code),
                        'native_name' => $manifest['native_name'] ?? ($manifest['name'] ?? $code),
                        'direction'   => strtolower($manifest['direction'] ?? 'ltr'),
                        'version'     => $manifest['version'] ?? '1.0.0',
                        'author'      => $manifest['author'] ?? 'Unknown',
                        'active'      => !isset($manifest['active']) || (bool)$manifest['active'],
                        'default'     => !empty($manifest['default']),
                        'has_ui'      => file_exists($dirPath . '/ui.php'),
                        'has_adm'     => file_exists($dirPath . '/adm.php'),
                        'has_common'  => file_exists($dirPath . '/common.php'),
                    ];
                }
            }
        }

        return $languages;
    }

    /**
     * Install language package from uploaded ZIP file
     */
    public function installZip(string $tmpZipPath): array
    {
        if (!class_exists('ZipArchive')) {
            return ['success' => false, 'error' => 'ZipArchive PHP extension is not installed.'];
        }

        $zip = new ZipArchive();
        if ($zip->open($tmpZipPath) !== true) {
            return ['success' => false, 'error' => 'Failed to open uploaded ZIP package.'];
        }

        // Find manifest.json in root or subfolder
        $manifestContent = null;
        $manifestSubDir  = '';

        for ($i = 0; $i < $zip->numFiles; $i++) {
            $filename = $zip->getNameIndex($i);
            if (basename($filename) === 'manifest.json') {
                $manifestContent = $zip->getFromIndex($i);
                $manifestSubDir = dirname($filename);
                if ($manifestSubDir === '.') $manifestSubDir = '';
                break;
            }
        }

        if (!$manifestContent) {
            $zip->close();
            return ['success' => false, 'error' => 'Invalid package. Missing manifest.json file.'];
        }

        $manifest = json_decode($manifestContent, true);
        if (!is_array($manifest) || empty($manifest['code'])) {
            $zip->close();
            return ['success' => false, 'error' => 'Invalid manifest.json content. "code" property is required.'];
        }

        $code = strtolower(trim($manifest['code']));
        $targetDir = $this->langDir . '/' . $code;

        if (!is_dir($targetDir)) {
            @mkdir($targetDir, 0777, true);
        }

        // Extract files
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $filename = $zip->getNameIndex($i);
            if ($zip->statIndex($i)['size'] == 0) continue; // skip directories

            if ($manifestSubDir !== '') {
                if (strpos($filename, $manifestSubDir . '/') !== 0) continue;
                $relativePath = substr($filename, strlen($manifestSubDir . '/'));
            } else {
                $relativePath = $filename;
            }

            $destPath = $targetDir . '/' . ltrim($relativePath, '/');
            $destSubDir = dirname($destPath);
            if (!is_dir($destSubDir)) {
                @mkdir($destSubDir, 0777, true);
            }

            $content = $zip->getFromIndex($i);
            if ($content !== false) {
                @file_put_contents($destPath, $content);
            }
        }

        $zip->close();

        return [
            'success' => true,
            'code'    => $code,
            'message' => "Language package '{$code}' installed successfully."
        ];
    }

    /**
     * Toggle active status of a language package
     */
    public function toggleActive(string $code): bool
    {
        $manifestPath = $this->langDir . '/' . $code . '/manifest.json';
        if (!file_exists($manifestPath)) return false;

        $raw = file_get_contents($manifestPath);
        $manifest = $raw ? json_decode($raw, true) : null;
        if (!is_array($manifest)) return false;

        $manifest['active'] = !(!isset($manifest['active']) || (bool)$manifest['active']);
        file_put_contents($manifestPath, json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        return true;
    }

    /**
     * Set default system language package
     */
    public function setDefault(string $code): bool
    {
        $targetManifest = $this->langDir . '/' . $code . '/manifest.json';
        if (!file_exists($targetManifest)) return false;

        $installed = $this->listInstalled();
        foreach ($installed as $langCode => $meta) {
            $manifestPath = $this->langDir . '/' . $langCode . '/manifest.json';
            if (file_exists($manifestPath)) {
                $raw = file_get_contents($manifestPath);
                $manifest = $raw ? json_decode($raw, true) : [];
                if (is_array($manifest)) {
                    $manifest['default'] = ($langCode === $code);
                    if ($langCode === $code) {
                        $manifest['active'] = true;
                    }
                    file_put_contents($manifestPath, json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
                }
            }
        }

        Locale::set($code);
        return true;
    }

    /**
     * Export language package as ZIP file for download
     */
    public function exportZip(string $code): ?string
    {
        $packageDir = $this->langDir . '/' . $code;
        if (!is_dir($packageDir) || !file_exists($packageDir . '/manifest.json')) {
            return null;
        }

        if (!class_exists('ZipArchive')) {
            return null;
        }

        $tmpZip = sys_get_temp_dir() . '/' . $code . '_lang_pack.zip';
        @unlink($tmpZip);

        $zip = new ZipArchive();
        if ($zip->open($tmpZip, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            return null;
        }

        $files = scandir($packageDir);
        if ($files !== false) {
            foreach ($files as $file) {
                if ($file === '.' || $file === '..') continue;
                $filePath = $packageDir . '/' . $file;
                if (is_file($filePath)) {
                    $zip->addFile($filePath, $file);
                }
            }
        }

        $zip->close();
        return file_exists($tmpZip) ? $tmpZip : null;
    }

    /**
     * Delete language package directory
     */
    public function deletePackage(string $code): bool
    {
        if ($code === 'en') return false; // Prevent deleting base English

        $packageDir = $this->langDir . '/' . $code;
        if (!is_dir($packageDir)) return false;

        // Check if default
        $manifestPath = $packageDir . '/manifest.json';
        if (file_exists($manifestPath)) {
            $raw = file_get_contents($manifestPath);
            $manifest = $raw ? json_decode($raw, true) : null;
            if (is_array($manifest) && !empty($manifest['default'])) {
                return false; // Prevent deleting default language
            }
        }

        $this->rmdirRecursive($packageDir);
        return !is_dir($packageDir);
    }

    private function rmdirRecursive(string $dir): void
    {
        if (!is_dir($dir)) return;
        $files = array_diff(scandir($dir) ?: [], ['.', '..']);
        foreach ($files as $file) {
            $path = $dir . '/' . $file;
            is_dir($path) ? $this->rmdirRecursive($path) : @unlink($path);
        }
        @rmdir($dir);
    }
}
