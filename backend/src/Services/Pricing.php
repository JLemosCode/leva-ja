<?php
namespace App\Services;
use App\Core\Util;
final class Pricing {
    /** Preço sempre calculado no servidor. Total = entrega + taxa da plataforma (configurável). */
    static function quote(float $a, float $b, float $c, float $d): array {
        $km = Util::km($a, $b, $c, $d) * Settings::num('road_factor');
        $price = max(Settings::int('min_cents'), Settings::int('base_cents') + (int)round(Settings::num('per_km_cents') * $km));
        $fee = Settings::int('platform_fee_cents');
        return ['distance_m' => (int)round($km * 1000), 'price_cents' => $price, 'fee_cents' => $fee, 'total_cents' => $price + $fee,
                'eta_min' => max(1, (int)ceil($km / max(1, Settings::num('avg_speed_kmh')) * 60))];
    }
}
