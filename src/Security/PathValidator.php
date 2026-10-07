<?php

declare(strict_types=1);

namespace App\Security;

use RuntimeException;

final class PathValidator
{
    /**
     * Validate that $path resolves to a location within $basePath.
     * @throws RuntimeException If the path is invalid or escapes the base.
     */
    public static function validate(string $path, string $basePath): string
    {
        // Reject null bytes
        if (str_contains($path, "\0")) throw new RuntimeException('Path contains null byte.');

        // Reject explicit ".." segments
        $normalized = str_replace('\\', '/', $path);
        $segments   = explode('/', $normalized);

        foreach ($segments as $segment) {
            if ($segment === '..') throw new RuntimeException('Path traversal detected.');
        }

        // Resolve the real, canonical path
        $realBase = realpath($basePath);

        if ($realBase === false) throw new RuntimeException('Base path does not exist: ' . $basePath);

        $realPath = realpath($path);

        // If the file doesn't exist yet, resolve the parent
        if ($realPath === false) {
            $parent   = dirname($path);
            $realPath = realpath($parent);

            if ($realPath === false) throw new RuntimeException('Parent directory does not exist: ' . $parent);

            $realPath .= DIRECTORY_SEPARATOR . basename($path);
        }

        // Ensure the resolved path starts within the base
        if (! str_starts_with($realPath, $realBase)) throw new RuntimeException('Path escapes base directory.');

        return $realPath;
    }

    /** Check whether a path is safely within a base directory (boolean version). */
    public static function isWithin(string $path, string $basePath): bool
    {
        try {
            self::validate($path, $basePath);
            return true;
        } catch (RuntimeException) {
            return false;
        }
    }
}
