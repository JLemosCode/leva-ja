<?php
use App\Core\{Auth, Db, HttpError, Request, Router, Util};
use App\Services\{Settings, Pricing, Deliveries, Dispatch, Payments, Events};

return function (Router $r): void {
    $cust = fn(Request $q) => Auth::user($q, ['customer', 'admin']);
    $str = fn($v, int $max = 160) => substr(trim((string)$v), 0, $max);

    $r->add('GET', '/v1/health', fn() => ['ok' => true, 'time' => gmdate('c')]);

    // ---------- Autenticação ----------
    $r->add('POST', '/v1/auth/register', function (Request $q) use ($str) {
        $b = $q->body;
        $role = ($b['role'] ?? 'customer') === 'courier' ? 'courier' : 'customer';
        $email = strtolower($str(Util::need($b, 'email')));
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) throw new HttpError(422, 'VALIDATION', 'E-mail inválido');
        $pw = (string)Util::need($b, 'password');
        if (strlen($pw) < 8) throw new HttpError(422, 'VALIDATION', 'A senha precisa ter ao menos 8 caracteres');
        $cpf = preg_replace('/\D/', '', (string)($b['cpf_cnpj'] ?? ''));
        if (!in_array(strlen($cpf), [11, 14], true)) throw new HttpError(422, 'VALIDATION', 'CPF/CNPJ inválido');
        if (Db::val("SELECT id FROM users WHERE email=?", [$email])) throw new HttpError(409, 'EMAIL_TAKEN', 'E-mail já cadastrado');
        $id = (int)Db::tx(function () use ($b, $role, $email, $pw, $cpf, $str) {
            $id = Db::insert("INSERT INTO users (role,name,email,phone,cpf_cnpj,password_hash) VALUES (?,?,?,?,?,?)",
                [$role, $str(Util::need($b, 'name'), 120), $email, $str($b['phone'] ?? '', 30), $cpf, password_hash($pw, PASSWORD_DEFAULT)]);
            if ($role === 'courier') Db::run("INSERT INTO couriers (user_id, vehicle) VALUES (?,?)", [$id, $str($b['vehicle'] ?? '', 40)]);
            return $id;
        });
        $u = Db::one("SELECT * FROM users WHERE id=?", [$id]);
        return [201, ['token' => Auth::token($id, $role), 'user' => Auth::public($u)]];
    });
    $r->add('POST', '/v1/auth/login', function (Request $q) {
        $u = Db::one("SELECT * FROM users WHERE email=?", [strtolower((string)($q->body['email'] ?? ''))]);
        if (!$u || !password_verify((string)($q->body['password'] ?? ''), $u['password_hash'])) { usleep(300000); throw new HttpError(401, 'INVALID_CREDENTIALS', 'E-mail ou senha incorretos'); }
        return ['token' => Auth::token((int)$u['id'], $u['role']), 'user' => Auth::public($u)];
    });
    $r->add('GET', '/v1/me', fn(Request $q) => ['user' => Auth::public(Auth::user($q))]);

    // ---------- Cotação e entregas (contratante / API de parceiros) ----------
    $r->add('POST', '/v1/quotes', function (Request $q) use ($cust) {
        $cust($q);
        [$a, $b] = Util::point($q->body['pickup'] ?? null, 'coleta'); [$c, $d] = Util::point($q->body['dropoff'] ?? null, 'destino');
        return Pricing::quote($a, $b, $c, $d);
    });
    $r->add('POST', '/v1/deliveries', fn(Request $q) => [201, Deliveries::create($cust($q), $q->body, $q->header('idempotency-key'))]);
    $r->add('GET', '/v1/deliveries', function (Request $q) {
        $u = Auth::user($q);
        $rows = match ($u['role']) {
            'customer' => Db::all("SELECT * FROM deliveries WHERE customer_id=? ORDER BY created_at DESC LIMIT 50", [$u['id']]),
            'courier' => Db::all("SELECT * FROM deliveries WHERE courier_id=? ORDER BY created_at DESC LIMIT 50", [$u['id']]),
            default => Db::all("SELECT * FROM deliveries ORDER BY created_at DESC LIMIT 100"),
        };
        return ['data' => array_map(fn($d) => Deliveries::view($d, $u['role']), $rows)];
    });
    $r->add('GET', '/v1/deliveries/{id}', function (Request $q) {
        $u = Auth::user($q); Dispatch::tick();
        $d = Deliveries::load($q->params['id']); Deliveries::authorize($d, $u);
        return Deliveries::view($d, $u['role']);
    });
    $r->add('GET', '/v1/deliveries/{id}/events', function (Request $q) {
        $u = Auth::user($q); $d = Deliveries::load($q->params['id']); Deliveries::authorize($d, $u);
        return ['data' => array_map([Util::class, 'iso'], Db::all("SELECT id,event_type,actor_type,created_at FROM delivery_events WHERE delivery_id=? ORDER BY id", [$d['id']]))];
    });
    $r->add('GET', '/v1/deliveries/{id}/tracking', function (Request $q) {
        $u = Auth::user($q); $d = Deliveries::load($q->params['id']); Deliveries::authorize($d, $u);
        return Deliveries::tracking($d, true);
    });
    $r->add('GET', '/v1/track/{token}', function (Request $q) {
        $d = Db::one("SELECT * FROM deliveries WHERE tracking_token=?", [$q->params['token']]);
        if (!$d) throw new HttpError(404, 'NOT_FOUND', 'Link de rastreamento inválido');
        return Deliveries::tracking($d, false);
    });
    $r->add('POST', '/v1/deliveries/{id}/pay', function (Request $q) use ($cust) {
        $u = $cust($q); $d = Deliveries::load($q->params['id']); Deliveries::authorize($d, $u);
        $p = Payments::charge($u, $d, (string)($q->body['method'] ?? ''), $q->body, $q->ip());
        return ['payment' => $p, 'delivery' => Deliveries::view(Deliveries::load($d['id']), 'customer')];
    });
    $r->add('POST', '/v1/deliveries/{id}/cancel', function (Request $q) { return Deliveries::cancel($q->params['id'], Auth::user($q, ['customer', 'admin'])); });
    $r->add('POST', '/v1/webhooks/asaas', function (Request $q) { Payments::webhook($q->body, $q->header('asaas-access-token')); return ['received' => true]; });

    // ---------- Favoritos ----------
    $r->add('GET', '/v1/favorites', function (Request $q) use ($cust) {
        $u = $cust($q);
        return ['data' => Db::all("SELECT u.id,u.name,u.email,c.availability FROM courier_favorites f JOIN users u ON u.id=f.courier_id JOIN couriers c ON c.user_id=u.id WHERE f.customer_id=?", [$u['id']])];
    });
    $r->add('POST', '/v1/favorites', function (Request $q) use ($cust) {
        $u = $cust($q); $c = Db::one("SELECT id FROM users WHERE email=? AND role='courier'", [strtolower((string)($q->body['courier_email'] ?? ''))]);
        if (!$c) throw new HttpError(404, 'NOT_FOUND', 'Entregador não encontrado');
        Db::run("INSERT IGNORE INTO courier_favorites (customer_id,courier_id) VALUES (?,?)", [$u['id'], $c['id']]);
        return [201, ['ok' => true]];
    });
    $r->add('DELETE', '/v1/favorites/{id}', function (Request $q) use ($cust) {
        Db::run("DELETE FROM courier_favorites WHERE customer_id=? AND courier_id=?", [$cust($q)['id'], $q->params['id']]); return [204, null];
    });

    // ---------- Chaves de API (parceiros) ----------
    $r->add('GET', '/v1/api-keys', function (Request $q) { return ['data' => array_map([Util::class, 'iso'], Db::all("SELECT id,name,key_prefix,created_at FROM api_keys WHERE user_id=? AND revoked_at IS NULL", [Auth::user($q, ['customer'])['id']]))]; });
    $r->add('POST', '/v1/api-keys', function (Request $q) use ($str) {
        $u = Auth::user($q, ['customer']); if ($q->header('x-api-key')) throw new HttpError(403, 'FORBIDDEN', 'Use login para gerar chaves');
        $key = 'lj_' . bin2hex(random_bytes(24));
        Db::run("INSERT INTO api_keys (user_id,name,key_prefix,key_hash) VALUES (?,?,?,?)", [$u['id'], $str($q->body['name'] ?? 'Integração', 80), substr($key, 0, 8), hash('sha256', $key)]);
        return [201, ['key' => $key, 'warning' => 'Guarde a chave: ela não será exibida novamente.']];
    });
    $r->add('DELETE', '/v1/api-keys/{id}', function (Request $q) { Db::run("UPDATE api_keys SET revoked_at=NOW() WHERE id=? AND user_id=?", [$q->params['id'], Auth::user($q, ['customer'])['id']]); return [204, null]; });

    // ---------- Entregador ----------
    $cour = fn(Request $q) => Auth::user($q, ['courier']);
    $r->add('GET', '/v1/couriers/me', function (Request $q) use ($cour) {
        $u = $cour($q); return ['availability' => Db::val("SELECT availability FROM couriers WHERE user_id=?", [$u['id']]), 'user' => Auth::public($u)];
    });
    $r->add('PUT', '/v1/couriers/me/availability', function (Request $q) use ($cour) {
        $u = $cour($q); $to = $q->body['availability'] ?? '';
        if (!in_array($to, ['available', 'offline'], true)) throw new HttpError(422, 'VALIDATION', 'Valor inválido');
        $n = Db::run("UPDATE couriers SET availability=? WHERE user_id=? AND availability IN ('available','offline')", [$to, $u['id']])->rowCount();
        $cur = Db::val("SELECT availability FROM couriers WHERE user_id=?", [$u['id']]);
        if (!$n && $cur !== $to) throw new HttpError(409, 'COURIER_BUSY', 'Indisponível enquanto houver entrega ativa ou conta suspensa');
        if ($to === 'offline') Db::run("DELETE FROM courier_location_current WHERE courier_id=?", [$u['id']]); // minimização: sem rastreio offline
        return ['availability' => $cur];
    });
    $r->add('POST', '/v1/couriers/me/location', function (Request $q) use ($cour) {
        $u = $cour($q); $b = $q->body;
        $lat = $b['latitude'] ?? null; $lng = $b['longitude'] ?? null;
        if (!is_numeric($lat) || !is_numeric($lng) || abs($lat) > 90 || abs($lng) > 180) throw new HttpError(422, 'VALIDATION', 'Coordenadas inválidas');
        $ts = strtotime((string)($b['captured_at'] ?? '')) ?: time();
        if (abs($ts - time()) > 300) throw new HttpError(422, 'CLOCK_SKEW', 'Horário do dispositivo inconsistente');
        $seq = (int)($b['sequence_no'] ?? 0);
        $cap = gmdate('Y-m-d H:i:s', $ts);
        $c = Db::one("SELECT availability FROM couriers WHERE user_id=?", [$u['id']]);
        if (!$c || in_array($c['availability'], ['offline', 'suspended'], true)) throw new HttpError(409, 'NOT_SHARING', 'Fique online para compartilhar a localização');
        $acc = isset($b['accuracy_m']) && is_numeric($b['accuracy_m']) ? (float)$b['accuracy_m'] : null;
        $did = Db::val("SELECT id FROM deliveries WHERE courier_id=? AND status IN ('assigned','at_pickup','picked_up','in_transit','at_dropoff') LIMIT 1", [$u['id']]);
        $n = Db::run("INSERT INTO courier_location_current (courier_id,lat,lng,accuracy_m,captured_at,received_at,sequence_no,delivery_id) VALUES (?,?,?,?,?,NOW(),?,?)
            ON DUPLICATE KEY UPDATE lat=IF(VALUES(sequence_no)>sequence_no,VALUES(lat),lat), lng=IF(VALUES(sequence_no)>sequence_no,VALUES(lng),lng),
            accuracy_m=IF(VALUES(sequence_no)>sequence_no,VALUES(accuracy_m),accuracy_m), captured_at=IF(VALUES(sequence_no)>sequence_no,VALUES(captured_at),captured_at),
            received_at=IF(VALUES(sequence_no)>sequence_no,NOW(),received_at), delivery_id=IF(VALUES(sequence_no)>sequence_no,VALUES(delivery_id),delivery_id),
            sequence_no=GREATEST(sequence_no,VALUES(sequence_no))", [$u['id'], $lat, $lng, $acc, $cap, $seq, $did])->rowCount();
        if ($n === 0) return ['accepted' => false, 'reason' => 'stale_sequence'];
        Db::run("INSERT INTO courier_location_history (courier_id,delivery_id,lat,lng,accuracy_m,captured_at,received_at) VALUES (?,?,?,?,?,?,NOW())", [$u['id'], $did, $lat, $lng, $acc, $cap]);
        return ['accepted' => true, 'low_accuracy' => $acc !== null && $acc > Settings::num('max_accuracy_m')];
    });
    $r->add('GET', '/v1/couriers/me/offers', function (Request $q) use ($cour) {
        $u = $cour($q); Dispatch::tick();
        $rows = Db::all("SELECT o.id AS offer_id,o.stage,o.expires_at,d.pickup_address,d.dropoff_address,d.distance_m,d.price_cents,d.pickup_lat,d.pickup_lng,
            (SELECT 6371*ACOS(LEAST(1,COS(RADIANS(d.pickup_lat))*COS(RADIANS(l.lat))*COS(RADIANS(l.lng)-RADIANS(d.pickup_lng))+SIN(RADIANS(d.pickup_lat))*SIN(RADIANS(l.lat)))) FROM courier_location_current l WHERE l.courier_id=o.courier_id) AS km_to_pickup
            FROM delivery_offers o JOIN deliveries d ON d.id=o.delivery_id WHERE o.courier_id=? AND o.status='offered' AND o.expires_at>NOW() AND d.status IN ('dispatching_favorites','dispatching_network') ORDER BY o.id", [$u['id']]);
        $share = Settings::num('courier_share_pct') / 100;
        return ['data' => array_map(function ($o) use ($share) { $o['earn_cents'] = (int)round($o['price_cents'] * $share); unset($o['price_cents']); $o['km_to_pickup'] = $o['km_to_pickup'] !== null ? round((float)$o['km_to_pickup'], 1) : null; return Util::iso($o); }, $rows)];
    });
    $r->add('GET', '/v1/couriers/me/active', function (Request $q) use ($cour) {
        $u = $cour($q);
        $d = Db::one("SELECT * FROM deliveries WHERE courier_id=? AND status IN ('assigned','at_pickup','picked_up','in_transit','at_dropoff') LIMIT 1", [$u['id']]);
        return ['delivery' => $d ? Deliveries::view($d, 'courier') : null];
    });
    $r->add('POST', '/v1/offers/{id}/accept', fn(Request $q) => Dispatch::accept((int)$q->params['id'], (int)$cour($q)['id']));
    $r->add('POST', '/v1/offers/{id}/reject', function (Request $q) use ($cour) { Dispatch::reject((int)$q->params['id'], (int)$cour($q)['id']); return [204, null]; });
    $r->add('POST', '/v1/deliveries/{id}/advance', fn(Request $q) => Deliveries::advance($q->params['id'], $cour($q), (string)($q->body['to'] ?? ''), isset($q->body['pin']) ? (string)$q->body['pin'] : null));

    // ---------- Admin ----------
    $adm = fn(Request $q) => Auth::user($q, ['admin']);
    $r->add('GET', '/v1/admin/settings', fn(Request $q) => ['settings' => $adm($q) ? Settings::all() : []]);
    $r->add('PUT', '/v1/admin/settings', function (Request $q) use ($adm) { $adm($q); Settings::save($q->body); return ['settings' => Settings::all()]; });
    $r->add('GET', '/v1/admin/overview', function (Request $q) use ($adm) {
        $adm($q);
        return ['deliveries_by_status' => Db::all("SELECT status, COUNT(*) AS n FROM deliveries GROUP BY status"),
            'revenue_fee_cents' => (int)Db::val("SELECT COALESCE(SUM(fee_cents),0) FROM deliveries WHERE payment_status='paid' AND status='delivered'"),
            'gross_cents' => (int)Db::val("SELECT COALESCE(SUM(total_cents),0) FROM deliveries WHERE payment_status='paid' AND status='delivered'"),
            'couriers' => Db::all("SELECT availability, COUNT(*) AS n FROM couriers GROUP BY availability"),
            'couriers_list' => Db::all("SELECT u.id,u.name,u.email,c.availability FROM couriers c JOIN users u ON u.id=c.user_id ORDER BY u.name LIMIT 100")];
    });
    $r->add('PATCH', '/v1/admin/couriers/{id}', function (Request $q) use ($adm) {
        $adm($q); $to = $q->body['availability'] ?? '';
        if (!in_array($to, ['suspended', 'offline'], true)) throw new HttpError(422, 'VALIDATION', 'Use suspended ou offline');
        Db::run("UPDATE couriers SET availability=? WHERE user_id=? AND availability<>'busy'", [$to, $q->params['id']]); return ['ok' => true];
    });

    // ---------- Geocodificação (proxy com cache) ----------
    $geo = function (string $url, string $key): array {
        $f = sys_get_temp_dir() . '/lj_geo_' . md5($key);
        if (is_file($f) && filemtime($f) > time() - 86400) return json_decode(file_get_contents($f), true) ?: [];
        $ctx = stream_context_create(['http' => ['header' => 'User-Agent: ' . \App\Core\Env::get('NOMINATIM_UA', 'LevaJa/1.0') . "\r\n", 'timeout' => 8]]);
        $raw = @file_get_contents($url, false, $ctx);
        if ($raw === false) throw new HttpError(502, 'GEOCODER', 'Serviço de endereços indisponível');
        file_put_contents($f, $raw); return json_decode($raw, true) ?: [];
    };
    $base = fn() => rtrim(\App\Core\Env::get('NOMINATIM_URL', 'https://nominatim.openstreetmap.org'), '/');
    $r->add('GET', '/v1/geocode', function (Request $q) use ($geo, $base) {
        Auth::user($q); $s = trim((string)($q->query['q'] ?? '')); if (strlen($s) < 3) return ['data' => []];
        $j = $geo($base() . '/search?format=jsonv2&limit=5&countrycodes=br&q=' . urlencode($s), 's:' . strtolower($s));
        return ['data' => array_map(fn($x) => ['address' => $x['display_name'], 'lat' => (float)$x['lat'], 'lng' => (float)$x['lon']], $j)];
    });
    $r->add('GET', '/v1/geocode/reverse', function (Request $q) use ($geo, $base) {
        Auth::user($q); $la = (float)($q->query['lat'] ?? 0); $ln = (float)($q->query['lng'] ?? 0);
        $j = $geo($base() . "/reverse?format=jsonv2&lat=$la&lon=$ln", "r:" . round($la, 4) . round($ln, 4));
        return ['address' => $j['display_name'] ?? sprintf('%.5f, %.5f', $la, $ln), 'lat' => $la, 'lng' => $ln];
    });
};
