<?php

declare(strict_types=1);

namespace EduQR;

/**
 * Thin custom router. Matches HTTP method + URL pattern and dispatches to callables.
 *
 * Patterns support named segments: /admin/courses/{id}
 * Segments become entries in the $params array passed to the handler.
 */
final class Router
{
    private array $routes = [];
    private ?callable $notFoundHandler = null;
    private ?callable $errorHandler = null;

    // ── Registration ───────────────────────────────────────────────────────────

    public function get(string $pattern, callable $handler): self
    {
        return $this->add('GET', $pattern, $handler);
    }

    public function post(string $pattern, callable $handler): self
    {
        return $this->add('POST', $pattern, $handler);
    }

    public function patch(string $pattern, callable $handler): self
    {
        return $this->add('PATCH', $pattern, $handler);
    }

    public function delete(string $pattern, callable $handler): self
    {
        return $this->add('DELETE', $pattern, $handler);
    }

    public function any(string $pattern, callable $handler): self
    {
        foreach (['GET', 'POST', 'PATCH', 'DELETE', 'PUT'] as $method) {
            $this->add($method, $pattern, $handler);
        }
        return $this;
    }

    private function add(string $method, string $pattern, callable $handler): self
    {
        $this->routes[] = [
            'method'  => strtoupper($method),
            'pattern' => $pattern,
            'handler' => $handler,
            'regex'   => $this->patternToRegex($pattern),
            'params'  => $this->extractParamNames($pattern),
        ];
        return $this;
    }

    public function setNotFound(callable $handler): self
    {
        $this->notFoundHandler = $handler;
        return $this;
    }

    public function setErrorHandler(callable $handler): self
    {
        $this->errorHandler = $handler;
        return $this;
    }

    // ── Dispatch ───────────────────────────────────────────────────────────────

    public function dispatch(string $method, string $uri): void
    {
        $method = strtoupper($method);
        // Strip query string
        $path = parse_url($uri, PHP_URL_PATH) ?? '/';
        // Normalize: remove trailing slash (except root)
        if ($path !== '/') {
            $path = rtrim($path, '/');
        }

        // Optional locale prefix: /en/... or /tr/...
        $localePrefix = null;
        if (preg_match('#^/([a-z]{2})(/|$)#', $path, $m)) {
            $localePrefix = $m[1];
            $path = substr($path, 3) ?: '/';
        }

        foreach ($this->routes as $route) {
            if ($route['method'] !== $method) {
                continue;
            }
            if (!preg_match($route['regex'], $path, $matches)) {
                continue;
            }

            $params = [];
            foreach ($route['params'] as $i => $name) {
                $params[$name] = $matches[$i + 1] ?? '';
            }
            if ($localePrefix !== null) {
                $params['_locale'] = $localePrefix;
            }

            try {
                ($route['handler'])($params);
            } catch (\Throwable $e) {
                if ($this->errorHandler !== null) {
                    ($this->errorHandler)($e);
                } else {
                    throw $e;
                }
            }
            return;
        }

        // No match
        http_response_code(404);
        if ($this->notFoundHandler !== null) {
            ($this->notFoundHandler)(['path' => $path]);
        } else {
            echo '404 Not Found';
        }
    }

    // ── Helpers ────────────────────────────────────────────────────────────────

    private function patternToRegex(string $pattern): string
    {
        // Escape everything except {param} segments
        $regex = preg_replace_callback(
            '/\{([a-zA-Z_][a-zA-Z0-9_]*)\}/',
            fn($m) => '([^/]+)',
            preg_quote($pattern, '#')
        );
        // Undo the preg_quote on our replacement
        $regex = str_replace('\\(\\[\\^/\\]\\+\\)', '([^/]+)', $regex);
        return '#^' . $regex . '$#';
    }

    private function extractParamNames(string $pattern): array
    {
        preg_match_all('/\{([a-zA-Z_][a-zA-Z0-9_]*)\}/', $pattern, $m);
        return $m[1];
    }
}
