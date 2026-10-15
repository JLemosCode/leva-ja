<?php
namespace App\Services;
use App\Core\{Db, HttpError};
final class Settings {
    const KEYS = ['platform_fee_cents','base_cents','per_km_cents','min_cents','road_factor','courier_share_pct','favorites_window_s','offer_ttl_s','network_radius_km','stale_after_s','max_accuracy_m','avg_speed_kmh','gps_retention_days'];
    private static ?array $c = null;
    static function all(): array { return self::$c ??= array_column(Db::all("SELECT k,v FROM settings"), 'v', 'k'); }
    static function num(string $k): float { return (float)(self::all()[$k] ?? 0); }
    static function int(string $k): int { return (int)self::num($k); }
    static function save(array $in): void {
        foreach ($in as $k => $v) {
            if (!in_array($k, self::KEYS, true)) continue;
            if (!is_numeric($v) || (float)$v < 0) throw new HttpError(422, 'VALIDATION', "Valor inválido para $k");
            Db::run("INSERT INTO settings (k,v) VALUES (?,?) ON DUPLICATE KEY UPDATE v=VALUES(v)", [$k, (string)$v]);
        }
        self::$c = null;
    }
}
