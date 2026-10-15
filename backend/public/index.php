<?php
declare(strict_types=1);
use App\Core\{Request, Router, HttpError, Env};
$src = is_file(__DIR__ . '/../src/bootstrap.php') ? __DIR__ . '/../src' : __DIR__ . '/../../app/src';
require $src . '/bootstrap.php';

$rid = bin2hex(random_bytes(6));
function out(int $s, mixed $d): never {
    http_response_code($s);
    header('Content-Type: application/json; charset=utf-8');
    if ($s !== 204) echo json_encode($d, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION);
    exit;
}
if ($o = Env::get('APP_ORIGIN')) {
    header("Access-Control-Allow-Origin: $o");
    header('Access-Control-Allow-Headers: Authorization, Content-Type, Idempotency-Key, X-Api-Key');
    header('Access-Control-Allow-Methods: GET, POST, PUT, PATCH, DELETE, OPTIONS');
}
header('X-Request-Id: ' . $rid);
header('X-Content-Type-Options: nosniff');
try {
    if (($_SERVER['REQUEST_METHOD'] ?? '') === 'OPTIONS') out(204, null);
    $q = Request::capture();
    $r = new Router();
    (require $src . '/routes.php')($r);
    [$s, $d] = $r->dispatch($q);
    out($s, $d);
} catch (HttpError $e) {
    out($e->status, ['error' => ['code' => $e->errCode, 'message' => $e->getMessage(), 'request_id' => $rid]]);
} catch (Throwable $e) {
    error_log("[$rid] " . $e::class . ': ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
    out(500, ['error' => ['code' => 'INTERNAL', 'message' => 'Erro interno', 'request_id' => $rid]]);
}
