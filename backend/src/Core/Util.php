<?php
namespace App\Core;
final class Util {
    static function uuid(): string {
        $b = random_bytes(16); $b[6] = chr(ord($b[6]) & 0x0f | 0x40); $b[8] = chr(ord($b[8]) & 0x3f | 0x80);
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($b), 4));
    }
    static function iso(array $row): array {
        foreach ($row as $k => $v) if (is_string($v) && str_ends_with($k, '_at') && preg_match('/^\d{4}-\d\d-\d\d \d\d:\d\d:\d\d/', $v)) $row[$k] = str_replace(' ', 'T', substr($v, 0, 19)) . 'Z';
        return $row;
    }
    static function km(float $a, float $b, float $c, float $d): float {
        $r = M_PI / 180; $dl = ($c - $a) * $r; $dg = ($d - $b) * $r;
        $x = sin($dl / 2) ** 2 + cos($a * $r) * cos($c * $r) * sin($dg / 2) ** 2;
        return 6371 * 2 * atan2(sqrt($x), sqrt(1 - $x));
    }
    static function need(array $a, string $k, string $msg = ''): mixed {
        if (!isset($a[$k]) || $a[$k] === '') throw new HttpError(422, 'VALIDATION', $msg ?: "Campo obrigatório: $k");
        return $a[$k];
    }
    static function point(mixed $p, string $name): array {
        if (!is_array($p) || !isset($p['lat'], $p['lng']) || !is_numeric($p['lat']) || !is_numeric($p['lng'])) throw new HttpError(422, 'VALIDATION', "Coordenadas inválidas em $name");
        $lat = (float)$p['lat']; $lng = (float)$p['lng'];
        if ($lat < -90 || $lat > 90 || $lng < -180 || $lng > 180) throw new HttpError(422, 'VALIDATION', "Coordenadas fora do intervalo em $name");
        return [$lat, $lng, trim((string)($p['address'] ?? ''))];
    }
}
