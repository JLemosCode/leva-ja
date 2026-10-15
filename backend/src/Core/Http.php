<?php
namespace App\Core;
final class HttpError extends \RuntimeException {
    function __construct(public int $status, public string $errCode, string $msg = '') { parent::__construct($msg ?: $errCode); }
}
final class Request {
    public array $params = [];
    function __construct(public string $method, public string $path, public array $query, public array $body, private array $headers, public string $raw = '') {}
    static function capture(): self {
        $uri = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
        $i = strpos($uri, '/v1/');
        $path = $i === false ? $uri : substr($uri, $i);
        $raw = file_get_contents('php://input') ?: '';
        $body = $raw !== '' ? json_decode($raw, true) : [];
        if (!is_array($body)) throw new HttpError(400, 'INVALID_JSON', 'Corpo JSON inválido');
        $h = [];
        foreach ($_SERVER as $k => $v) if (str_starts_with($k, 'HTTP_')) $h[strtolower(str_replace('_', '-', substr($k, 5)))] = $v;
        if (!isset($h['authorization']) && isset($_SERVER['REDIRECT_HTTP_AUTHORIZATION'])) $h['authorization'] = $_SERVER['REDIRECT_HTTP_AUTHORIZATION'];
        return new self($_SERVER['REQUEST_METHOD'] ?? 'GET', rtrim($path, '/') ?: '/', $_GET, $body, $h, $raw);
    }
    function header(string $n): ?string { return $this->headers[strtolower($n)] ?? null; }
    function bearer(): ?string { $a = $this->header('authorization'); return $a && preg_match('/^Bearer\s+(.+)$/i', $a, $m) ? $m[1] : null; }
    function ip(): string { return $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0'; }
}
final class Router {
    private array $routes = [];
    function add(string $m, string $p, callable $h): void {
        $re = '#^' . preg_replace('#\{(\w+)\}#', '(?P<$1>[^/]+)', $p) . '$#';
        $this->routes[] = [$m, $re, $h];
    }
    function dispatch(Request $q): array {
        $other = false;
        foreach ($this->routes as [$m, $re, $h]) {
            if (!preg_match($re, $q->path, $mt)) continue;
            if ($m !== $q->method) { $other = true; continue; }
            $q->params = array_filter($mt, 'is_string', ARRAY_FILTER_USE_KEY);
            $r = $h($q);
            return is_array($r) && isset($r[0]) && is_int($r[0]) ? $r : [200, $r];
        }
        throw $other ? new HttpError(405, 'METHOD_NOT_ALLOWED') : new HttpError(404, 'NOT_FOUND', 'Rota não encontrada');
    }
}
