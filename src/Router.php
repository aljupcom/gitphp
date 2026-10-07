<?php

declare(strict_types=1);

namespace App;

use FastRoute\Dispatcher;
use FastRoute\RouteCollector;
use RuntimeException;

use function FastRoute\simpleDispatcher;

final class Router
{
    /** @var array<int, array{string, string, string}> */
    private array $routes = [];

    private ?Dispatcher $dispatcher = null;

    /** Register a route. */
    public function addRoute(string $method, string $pattern, string $handler): void
    {
        $this->routes[] = [$method, $pattern, $handler];
        $this->dispatcher = null; // reset cached dispatcher
    }

    /** Build (or return cached) FastRoute dispatcher. */
    private function getDispatcher(): Dispatcher
    {
        if ($this->dispatcher !== null) return $this->dispatcher;

        $routes = $this->routes;

        $this->dispatcher = simpleDispatcher(static function (RouteCollector $r) use ($routes): void {
            foreach ($routes as [$method, $pattern, $handler]) $r->addRoute($method, $pattern, $handler);
        });

        return $this->dispatcher;
    }

    /**
     * Dispatch the current HTTP request.
     * @return array{int, mixed, array<string, string>}
     */
    public function dispatch(): array
    {
        $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
        $uri    = $_SERVER['REQUEST_URI']    ?? '/';

        // Strip query string
        $pos = strpos($uri, '?');
        if ($pos !== false) $uri = substr($uri, 0, $pos);

        $uri = rawurldecode($uri);

        $routeInfo = $this->getDispatcher()->dispatch($method, $uri);

        return match ($routeInfo[0]) {
            Dispatcher::NOT_FOUND => [
                Dispatcher::NOT_FOUND,
                null,
                [],
            ],
            Dispatcher::METHOD_NOT_ALLOWED => [
                Dispatcher::METHOD_NOT_ALLOWED,
                $routeInfo[1], // allowed methods
                [],
            ],
            Dispatcher::FOUND => [
                Dispatcher::FOUND,
                $routeInfo[1], // handler string
                $routeInfo[2], // route variables
            ],
            default => throw new RuntimeException('Unexpected dispatcher status.'),
        };
    }
}
