<?php
namespace App\Core;
final class Env {
    private static array $v = [];
    static function load(string $f): void {
        if (!is_file($f)) return;
        foreach (file($f, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $l) {
            $l = trim($l);
            if ($l === '' || $l[0] === '#' || !str_contains($l, '=')) continue;
            [$k, $x] = explode('=', $l, 2);
            self::$v[trim($k)] = trim($x, " \t\"'");
        }
    }
    static function get(string $k, ?string $d = null): ?string {
        return self::$v[$k] ?? (getenv($k) ?: $d);
    }
}
