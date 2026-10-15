<?php
namespace App\Core;
use PDO;
final class Db {
    private static ?PDO $pdo = null;
    private static int $depth = 0;
    static function pdo(): PDO {
        if (!self::$pdo) {
            $dsn = sprintf('mysql:host=%s;dbname=%s;charset=utf8mb4', Env::get('DB_HOST', 'localhost'), Env::get('DB_NAME'));
            self::$pdo = new PDO($dsn, Env::get('DB_USER'), Env::get('DB_PASS'), [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
            ]);
            self::$pdo->exec("SET time_zone = '+00:00'");
        }
        return self::$pdo;
    }
    static function run(string $sql, array $p = []): \PDOStatement { $s = self::pdo()->prepare($sql); $s->execute($p); return $s; }
    static function all(string $sql, array $p = []): array { return self::run($sql, $p)->fetchAll(); }
    static function one(string $sql, array $p = []): ?array { $r = self::run($sql, $p)->fetch(); return $r ?: null; }
    static function val(string $sql, array $p = []): mixed { $r = self::run($sql, $p)->fetchColumn(); return $r === false ? null : $r; }
    static function insert(string $sql, array $p = []): string { self::run($sql, $p); return self::pdo()->lastInsertId(); }
    /** Transação reentrante: chamadas aninhadas reutilizam a transação externa. */
    static function tx(callable $fn): mixed {
        $pdo = self::pdo();
        if (self::$depth > 0) return $fn();
        self::$depth++;
        $pdo->beginTransaction();
        try { $r = $fn(); $pdo->commit(); return $r; }
        catch (\Throwable $e) { if ($pdo->inTransaction()) $pdo->rollBack(); throw $e; }
        finally { self::$depth--; }
    }
}
