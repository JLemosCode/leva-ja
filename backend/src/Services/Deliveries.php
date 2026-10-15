<?php
namespace App\Services;
use App\Core\{Db, HttpError, Util};

final class Deliveries {
    const FLOW = ['assigned' => 'at_pickup', 'at_pickup' => 'picked_up', 'picked_up' => 'in_transit', 'in_transit' => 'at_dropoff', 'at_dropoff' => 'delivered'];
    const STAMP = ['picked_up' => 'picked_up_at', 'delivered' => 'delivered_at'];

    static function load(string $id): array {
        $d = Db::one("SELECT * FROM deliveries WHERE id=?", [$id]);
        if (!$d) throw new HttpError(404, 'NOT_FOUND', 'Entrega não encontrada');
        return $d;
    }
    /** Autorização por recurso: UUID não é prova de acesso. */
    static function authorize(array $d, array $u): void {
        $ok = $u['role'] === 'admin' || ($u['role'] === 'customer' && (int)$d['customer_id'] === (int)$u['id'])
            || ($u['role'] === 'courier' && (int)$d['courier_id'] === (int)$u['id']);
        if (!$ok) throw new HttpError(404, 'NOT_FOUND', 'Entrega não encontrada');
    }
    static function view(array $d, string $role): array {
        $o = ['id', 'external_reference', 'status', 'payment_status', 'pickup_address', 'pickup_lat', 'pickup_lng', 'dropoff_address', 'dropoff_lat', 'dropoff_lng', 'recipient_name', 'recipient_phone', 'notes', 'distance_m', 'price_cents', 'fee_cents', 'total_cents', 'courier_id', 'tracking_token', 'created_at', 'assigned_at', 'picked_up_at', 'delivered_at', 'cancelled_at', 'favorites_deadline_at'];
        $r = array_intersect_key($d, array_flip($o));
        if ($role === 'customer' || $role === 'admin') {
            $r['delivery_pin'] = $d['delivery_pin'];
            if ($d['courier_id']) $r['courier_name'] = Db::val("SELECT name FROM users WHERE id=?", [$d['courier_id']]);
        }
        if ($role === 'courier') {
            unset($r['tracking_token'], $r['fee_cents'], $r['total_cents'], $r['price_cents']);
            $r['earn_cents'] = (int)round($d['price_cents'] * Settings::num('courier_share_pct') / 100);
        }
        foreach (['pickup_lat', 'pickup_lng', 'dropoff_lat', 'dropoff_lng'] as $k) $r[$k] = (float)$r[$k];
        return Util::iso($r);
    }

    static function create(array $u, array $b, ?string $idemKey): array {
        [$pa, $po, $pad] = Util::point($b['pickup'] ?? null, 'coleta');
        [$da, $do, $dad] = Util::point($b['dropoff'] ?? null, 'destino');
        if ($pad === '' || $dad === '') throw new HttpError(422, 'VALIDATION', 'Endereços de coleta e destino são obrigatórios');
        $hash = hash('sha256', json_encode($b));
        if ($idemKey) {
            $prev = Db::one("SELECT * FROM idempotency_keys WHERE user_id=? AND idem_key=?", [$u['id'], $idemKey]);
            if ($prev) {
                if ($prev['request_hash'] !== $hash) throw new HttpError(409, 'IDEMPOTENCY_MISMATCH', 'Chave reutilizada com conteúdo diferente');
                return self::view(self::load($prev['delivery_id']), 'customer');
            }
        }
        $q = Pricing::quote($pa, $po, $da, $do);
        if ($q['distance_m'] > 100000) throw new HttpError(422, 'TOO_FAR', 'Distância acima do limite de 100 km');
        $id = Util::uuid();
        $ref = isset($b['external_reference']) && $b['external_reference'] !== '' ? substr((string)$b['external_reference'], 0, 80) : null;
        try {
            Db::tx(function () use ($id, $u, $ref, $pa, $po, $pad, $da, $do, $dad, $b, $q, $idemKey, $hash) {
                Db::run("INSERT INTO deliveries (id,customer_id,external_reference,pickup_address,pickup_lat,pickup_lng,dropoff_address,dropoff_lat,dropoff_lng,recipient_name,recipient_phone,notes,distance_m,price_cents,fee_cents,total_cents,delivery_pin,tracking_token) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)",
                    [$id, $u['id'], $ref, substr($pad, 0, 255), $pa, $po, substr($dad, 0, 255), $da, $do, substr((string)($b['recipient_name'] ?? ''), 0, 120), substr((string)($b['recipient_phone'] ?? ''), 0, 30), substr((string)($b['notes'] ?? ''), 0, 500),
                     $q['distance_m'], $q['price_cents'], $q['fee_cents'], $q['total_cents'], str_pad((string)random_int(0, 9999), 4, '0', STR_PAD_LEFT), bin2hex(random_bytes(16))]);
                if ($idemKey) Db::run("INSERT INTO idempotency_keys (user_id,idem_key,request_hash,delivery_id) VALUES (?,?,?,?)", [$u['id'], substr($idemKey, 0, 80), $hash, $id]);
                Events::add($id, 'delivery.created', 'customer', (int)$u['id']);
            });
        } catch (\PDOException $e) {
            if ($e->getCode() === '23000') throw new HttpError(409, 'DUPLICATE_REFERENCE', 'Referência externa já utilizada');
            throw $e;
        }
        return self::view(self::load($id), 'customer');
    }

    static function advance(string $id, array $u, string $to, ?string $pin): array {
        return Db::tx(function () use ($id, $u, $to, $pin) {
            $d = Db::one("SELECT * FROM deliveries WHERE id=? FOR UPDATE", [$id]);
            if (!$d || (int)$d['courier_id'] !== (int)$u['id']) throw new HttpError(404, 'NOT_FOUND', 'Entrega não encontrada');
            if ((self::FLOW[$d['status']] ?? null) !== $to) throw new HttpError(409, 'INVALID_TRANSITION', "Transição {$d['status']} → $to não permitida");
            if ($to === 'delivered' && !hash_equals($d['delivery_pin'], (string)$pin)) throw new HttpError(422, 'INVALID_PIN', 'PIN de entrega incorreto');
            $stamp = isset(self::STAMP[$to]) ? ', ' . self::STAMP[$to] . '=NOW()' : '';
            Db::run("UPDATE deliveries SET status=?$stamp WHERE id=?", [$to, $id]);
            if ($to === 'delivered') Db::run("UPDATE couriers SET availability='available' WHERE user_id=?", [$u['id']]);
            Events::add($id, "status.$to", 'courier', (int)$u['id']);
            return self::view(self::load($id), 'courier');
        });
    }

    static function cancel(string $id, array $u): array {
        $d = Db::tx(function () use ($id, $u) {
            $d = Db::one("SELECT * FROM deliveries WHERE id=? FOR UPDATE", [$id]);
            if (!$d) throw new HttpError(404, 'NOT_FOUND', 'Entrega não encontrada');
            self::authorize($d, $u);
            if ($u['role'] === 'courier') throw new HttpError(403, 'FORBIDDEN', 'Entregador não cancela por aqui');
            if (!in_array($d['status'], ['created', 'dispatching_favorites', 'dispatching_network', 'assigned', 'at_pickup'], true))
                throw new HttpError(409, 'INVALID_STATE', 'A entrega não pode mais ser cancelada');
            Db::run("UPDATE deliveries SET status='cancelled', cancelled_at=NOW() WHERE id=?", [$id]);
            Db::run("UPDATE delivery_offers SET status='revoked', responded_at=NOW() WHERE delivery_id=? AND status='offered'", [$id]);
            if ($d['courier_id']) Db::run("UPDATE couriers SET availability='available' WHERE user_id=? AND availability='busy'", [$d['courier_id']]);
            Events::add($id, 'delivery.cancelled', $u['role'], (int)$u['id'], ['from' => $d['status']]);
            return $d;
        });
        Payments::reverse($d);
        return self::view(self::load($id), $u['role']);
    }

    /** Posição só é exposta entre atribuição e chegada ao destino; sem histórico de coordenadas. */
    static function tracking(array $d, bool $full): array {
        Dispatch::tick();
        $d = self::load($d['id']);
        $live = in_array($d['status'], ['assigned', 'at_pickup', 'picked_up', 'in_transit', 'at_dropoff'], true);
        $out = ['status' => $d['status'], 'dropoff_lat' => (float)$d['dropoff_lat'], 'dropoff_lng' => (float)$d['dropoff_lng'], 'pickup_lat' => (float)$d['pickup_lat'], 'pickup_lng' => (float)$d['pickup_lng'], 'courier' => null, 'eta_min' => null];
        if ($full) { $out['id'] = $d['id']; $out['pickup_address'] = $d['pickup_address']; $out['dropoff_address'] = $d['dropoff_address']; $out['delivery_pin'] = $d['delivery_pin']; $out['payment_status'] = $d['payment_status']; $out['total_cents'] = (int)$d['total_cents']; $out['tracking_token'] = $d['tracking_token']; }
        if ($live && $d['courier_id']) {
            $l = Db::one("SELECT lat,lng,received_at,TIMESTAMPDIFF(SECOND,received_at,NOW()) AS age FROM courier_location_current WHERE courier_id=?", [$d['courier_id']]);
            $name = explode(' ', (string)Db::val("SELECT name FROM users WHERE id=?", [$d['courier_id']]))[0];
            $out['courier'] = ['first_name' => $name, 'lat' => $l ? (float)$l['lat'] : null, 'lng' => $l ? (float)$l['lng'] : null,
                'last_update_at' => $l ? Util::iso(['x_at' => $l['received_at']])['x_at'] : null, 'stale' => !$l || (int)$l['age'] > Settings::int('stale_after_s')];
            if ($l) {
                $toPickup = in_array($d['status'], ['assigned', 'at_pickup'], true);
                $km = Util::km((float)$l['lat'], (float)$l['lng'], (float)$d[$toPickup ? 'pickup_lat' : 'dropoff_lat'], (float)$d[$toPickup ? 'pickup_lng' : 'dropoff_lng']) * Settings::num('road_factor');
                $out['eta_min'] = max(1, (int)ceil($km / max(1, Settings::num('avg_speed_kmh')) * 60));
                $out['distance_km'] = round($km, 1);
            }
        }
        return $out;
    }
}
