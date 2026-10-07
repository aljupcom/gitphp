<?php

declare(strict_types=1);

// CLI runner wrapper for repo-optimize.php
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    header('Content-Type: text/plain; charset=utf-8');
    echo "Access denied. Run via CLI: php bin/repo-optimize.php\n";
    exit(1);
}

require_once dirname(__DIR__) . '/bin/repo-optimize.php';
