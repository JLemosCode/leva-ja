<?php
namespace App\Services;
use App\Core\{Db, HttpError};
final class Dispatch {
    const ACTIVE = "('assigned','at_pickup','picked_up','in_transit','at_dropoff')";

    /** Chamado após confirmação do pagamento. */
    static function start(string $id): void {
        Db::tx(function () use ($id) {
            $d = Db::one("SELECT * FROM deliveries WHERE id=? FOR UPDATE", [$id]);
            if (!$d || $d['status'] !== 'created' || $d['payment_status'] !== 'paid') return;
            $win = Settings::int('favorites_window_s');
            $favs = $win > 0 ? self::eligible($d, true, 0, []) : [];
            if ($favs) {
                Db::run("UPDATE deliveries SET status='dispatching_favorites', dispatch_started_at=NOW(), favorites_deadline_at=DATE_ADD(NOW(), INTERVAL ? SECOND) WHERE id=?", [$win, $id]);
                Events::add($id, 'dispatch.favorites_started', 'system', null, ['couriers' => count($favs), 'window_s' => $win]);
                self::offer($id, $favs, 'favorites', $win);
            } else {
                Db::run("UPDATE deliveries SET dispatch_started_at=NOW() WHERE id=?", [$id]);
                self::openNetwork($d);
            }
        });
    }

    private static function openNetwork(array $d): void {
        Db::run("UPDATE deliveries SET status='dispatching_network', network_opened_at=NOW() WHERE id=?", [$d['id']]);
        Events::add($d['id'], 'dispatch.network_opened');
        self::wave($d, 0);
    }

    /** Ofertas para quem ainda não recebeu; o raio cresce 2 km por minuto desde a abertura. */
    private static function wave(array $d, int $elapsed): void {
        $radius = min(25.0, Settings::num('network_radius_km') + 2 * intdiv($elapsed, 60));
        $skip = array_column(Db::all("SELECT courier_id FROM delivery_offers WHERE delivery_id=?", [$d['id']]), 'courier_id');
        $list = self::eligible($d, false, $radius, $skip);
        if ($list) self::offer($d['id'], $list, 'network', Settings::int('offer_ttl_s'));
    }

    private static function offer(string $id, array $couriers, string $stage, int $ttl): void {
        foreach ($couriers as $c) {
            Db::run("INSERT IGNORE INTO delivery_offers (delivery_id,courier_id,stage,expires_at) VALUES (?,?,?,DATE_ADD(NOW(), INTERVAL ? SECOND))", [$id, $c['user_id'], $stage, max(10, $ttl)]);
        }
        Events::add($id, 'offers.sent', 'system', null, ['stage' => $stage, 'courier_ids' => array_column($couriers, 'user_id')]);
    }

    /** Entregadores disponíveis, com GPS recente e preciso, ordenados por distância à coleta. */
    private static function eligible(array $d, bool $favoritesOnly, float $radiusKm, array $skip): array {
        $dist = "6371*ACOS(LEAST(1,COS(RADIANS(:plat))*COS(RADIANS(l.lat))*COS(RADIANS(l.lng)-RADIANS(:plng))+SIN(RADIANS(:plat2))*SIN(RADIANS(l.lat))))";
        $sql = "SELECT c.user_id, $dist AS km FROM couriers c JOIN courier_location_current l ON l.courier_id=c.user_id";
        $p = ['plat' => $d['pickup_lat'], 'plng' => $d['pickup_lng'], 'plat2' => $d['pickup_lat'], 'stale' => Settings::int('stale_after_s'), 'acc' => Settings::int('max_accuracy_m')];
        if ($favoritesOnly) { $sql .= " JOIN courier_favorites f ON f.courier_id=c.user_id AND f.customer_id=:cust"; $p['cust'] = $d['customer_id']; }
        $sql .= " WHERE c.availability='available' AND l.received_at >= DATE_SUB(NOW(), INTERVAL :stale SECOND) AND (l.accuracy_m IS NULL OR l.accuracy_m <= :acc)";
        if (!$favoritesOnly) { $sql .= " HAVING km <= :rad"; $p['rad'] = $radiusKm; }
        $sql .= " ORDER BY km LIMIT 30";
        $rows = Db::all($sql, $p);
        return array_values(array_filter($rows, fn($r) => !in_array($r['user_id'], $skip)));
    }

    /** Avança relógios: expira ofertas, abre a rede após os 60 s e envia novas ondas. Idempotente. */
    static function tick(): void {
        Db::run("UPDATE delivery_offers SET status='expired', responded_at=NOW() WHERE status='offered' AND expires_at < NOW()");
        $due = Db::all("SELECT id FROM deliveries WHERE status='dispatching_favorites' AND favorites_deadline_at <= NOW() LIMIT 50");
        foreach ($due as $r) Db::tx(function () use ($r) {
            $d = Db::one("SELECT * FROM deliveries WHERE id=? FOR UPDATE", [$r['id']]);
            if ($d && $d['status'] === 'dispatching_favorites') {
                Db::run("UPDATE delivery_offers SET status='expired', responded_at=NOW() WHERE delivery_id=? AND status='offered'", [$d['id']]);
                self::openNetwork($d);
            }
        });
        $net = Db::all("SELECT *, TIMESTAMPDIFF(SECOND, network_opened_at, NOW()) AS el FROM deliveries WHERE status='dispatching_network' LIMIT 50");
        foreach ($net as $d) Db::tx(fn() => self::wave($d, (int)$d['el']));
    }

    static function accept(int $offerId, int $courierId): array {
        return Db::tx(function () use ($offerId, $courierId) {
            $o = Db::one("SELECT * FROM delivery_offers WHERE id=? AND courier_id=?", [$offerId, $courierId]);
            if (!$o) throw new HttpError(404, 'OFFER_NOT_FOUND', 'Oferta não encontrada');
            $d = Db::one("SELECT * FROM deliveries WHERE id=? FOR UPDATE", [$o['delivery_id']]);
            $o = Db::one("SELECT *, expires_at > NOW() AS valid FROM delivery_offers WHERE id=? FOR UPDATE", [$offerId]);
            if (!in_array($d['status'], ['dispatching_favorites', 'dispatching_network'], true) || $o['status'] !== 'offered')
                throw new HttpError(409, 'OFFER_ALREADY_TAKEN', 'Esta entrega já foi aceita ou cancelada');
            if (!$o['valid']) throw new HttpError(409, 'OFFER_EXPIRED', 'Oferta expirada');
            $c = Db::one("SELECT availability FROM couriers WHERE user_id=? FOR UPDATE", [$courierId]);
            if (!$c || $c['availability'] !== 'available') throw new HttpError(409, 'COURIER_UNAVAILABLE', 'Você precisa estar disponível');
            Db::run("UPDATE deliveries SET status='assigned', courier_id=?, assigned_at=NOW() WHERE id=?", [$courierId, $d['id']]);
            Db::run("UPDATE delivery_offers SET status='accepted', responded_at=NOW() WHERE id=?", [$offerId]);
            Db::run("UPDATE delivery_offers SET status='revoked', responded_at=NOW() WHERE delivery_id=? AND status='offered' AND id<>?", [$d['id'], $offerId]);
            Db::run("UPDATE couriers SET availability='busy' WHERE user_id=?", [$courierId]);
            Events::add($d['id'], 'courier.assigned', 'courier', $courierId, ['stage' => $o['stage']]);
            return ['delivery_id' => $d['id']];
        });
    }

    static function reject(int $offerId, int $courierId): void {
        $n = Db::run("UPDATE delivery_offers SET status='rejected', responded_at=NOW() WHERE id=? AND courier_id=? AND status='offered'", [$offerId, $courierId])->rowCount();
        if (!$n) throw new HttpError(404, 'OFFER_NOT_FOUND', 'Oferta indisponível');
        Events::add((string)Db::val("SELECT delivery_id FROM delivery_offers WHERE id=?", [$offerId]), 'offer.rejected', 'courier', $courierId);
    }
}
