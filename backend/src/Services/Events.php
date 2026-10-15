<?php
namespace App\Services;
use App\Core\Db;
final class Events {
    static function add(string $delivery, string $type, string $actor = 'system', ?int $actorId = null, array $meta = []): void {
        Db::run("INSERT INTO delivery_events (delivery_id,event_type,actor_type,actor_id,metadata) VALUES (?,?,?,?,?)",
            [$delivery, $type, $actor, $actorId, $meta ? json_encode($meta) : null]);
    }
}
