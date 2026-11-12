<?php
declare(strict_types=1);

namespace App\Core;

/**
 * Tiny router.
 *  - Patterns use {name}. {id} and any {..._id} only match digits,
 *    {ref} matches letters/digits/dashes, {token} matches hex, others match one path segment.
 *  - Route options: auth/perm (see Guard), 'csrf' => false to skip CSRF (never used for forms),
 *    'api' => true for JSON endpoints (JSON errors instead of HTML pages).
 */
final class Router
{
    private array $routes = [];

    public function get(string $pattern, array $handler, array $opts = []): void
    {
        $this->add('GET', $pattern, $handler, $opts);
    }

    public function post(string $pattern, array $handler, array $opts = []): void
    {
        $this->add('POST', $pattern, $handler, $opts);
    }

    public function add(string $method, string $pattern, array $handler, array $opts = []): void
    {
        $this->routes[] = [
            'method'  => $method,
            'regex'   => self::compile($pattern),
            'handler' => $handler,
            'opts'    => $opts,
        ];
    }

    public static function compile(string $pattern): string
    {
        $pattern = $pattern === '/' ? '/' : rtrim($pattern, '/');
        $regex = preg_replace_callback('#\{(\w+)\}#', static function (array $m): string {
            $name = $m[1];
            if ($name === 'id' || str_ends_with($name, '_id')) {
                $part = '\d+';
            } elseif ($name === 'ref') {
                $part = '[A-Za-z0-9\-]+';
            } elseif ($name === 'token') {
                $part = '[A-Za-z0-9\-]{16,128}';
            } else {
                $part = '[^/]+';
            }
            return '(?P<' . $name . '>' . $part . ')';
        }, $pattern);
        return '#^' . $regex . '$#';
    }

    /** Find the matching route and run it. */
    public function dispatch(string $method, string $path): void
    {
        $path = $path === '/' ? '/' : rtrim($path, '/');
        $methodMismatch = false;

        foreach ($this->routes as $route) {
            if (!preg_match($route['regex'], $path, $m)) {
                continue;
            }
            if ($route['method'] !== $method && !($method === 'HEAD' && $route['method'] === 'GET')) {
                $methodMismatch = true;
                continue;
            }

            $opts = $route['opts'];
            $GLOBALS['_dps_is_api'] = !empty($opts['api']);

            Guard::check($opts, !empty($opts['api']));
            if ($method === 'POST' && ($opts['csrf'] ?? true)) {
                Csrf::verifyRequest();
            }

            $params = array_filter($m, 'is_string', ARRAY_FILTER_USE_KEY);
            [$class, $action] = $route['handler'];
            $controller = new $class();
            // Fixed 'args' from the route definition come first, then URL parameters.
            $controller->$action(...array_merge($opts['args'] ?? [], array_values($params)));
            return;
        }

        throw new HttpException($methodMismatch ? 405 : 404);
    }
}
