<?php
namespace App\Services;
use App\Core\{Db, HttpError, Env};

final class Payments {
    static function gateway(): PaymentGateway { return new AsaasGateway(); }

    static function charge(array $user, array $d, string $method, array $in, string $ip): array {
        if ($d['payment_status'] === 'paid') throw new HttpError(409, 'ALREADY_PAID', 'Entrega já paga');
        if ($d['status'] !== 'created') throw new HttpError(409, 'INVALID_STATE', 'Entrega não aceita pagamento neste estado');
        if (!in_array($method, ['pix', 'card'], true)) throw new HttpError(422, 'VALIDATION', 'Método inválido (pix ou card)');
        if (empty($user['cpf_cnpj'])) throw new HttpError(422, 'CPF_REQUIRED', 'Informe seu CPF/CNPJ no cadastro');
        $gw = self::gateway();

        // reaproveita Pix pendente
        $prev = Db::one("SELECT * FROM payments WHERE delivery_id=? AND method='pix' AND status='PENDING' ORDER BY id DESC LIMIT 1", [$d['id']]);
        if ($method === 'pix' && $prev) return self::view($prev);
        if ($prev) { try { $gw->cancel($prev['asaas_payment_id']); } catch (\Throwable) {} Db::run("UPDATE payments SET status='CANCELLED' WHERE id=?", [$prev['id']]); }

        $req = ['customer' => $gw->ensureCustomer($user), 'method' => $method, 'cents' => (int)$d['total_cents'],
                'description' => 'Entrega ' . substr($d['id'], 0, 8), 'reference' => $d['id'], 'ip' => $ip];
        if ($method === 'card') {
            $c = $in['card'] ?? [];
            foreach (['holder_name', 'number', 'exp_month', 'exp_year', 'cvv', 'postal_code', 'address_number'] as $k)
                if (empty($c[$k])) throw new HttpError(422, 'VALIDATION', "Cartão: campo $k obrigatório (o CVV é solicitado a cada compra)");
            $req['card'] = ['holderName' => $c['holder_name'], 'number' => preg_replace('/\D/', '', $c['number']), 'expiryMonth' => str_pad((string)$c['exp_month'], 2, '0', STR_PAD_LEFT), 'expiryYear' => (string)$c['exp_year'], 'ccv' => (string)$c['cvv']];
            $req['holder'] = ['name' => $user['name'], 'email' => $user['email'], 'cpfCnpj' => preg_replace('/\D/', '', $user['cpf_cnpj']), 'postalCode' => preg_replace('/\D/', '', $c['postal_code']), 'addressNumber' => (string)$c['address_number'], 'mobilePhone' => preg_replace('/\D/', '', (string)$user['phone'])];
        }
        $p = $gw->charge($req);
        unset($req);
        $qr = $method === 'pix' ? $gw->pixQr($p['id']) : ['payload' => null, 'image' => null];
        $id = Db::insert("INSERT INTO payments (delivery_id,method,asaas_payment_id,status,value_cents,pix_payload,pix_qr,invoice_url) VALUES (?,?,?,?,?,?,?,?)",
            [$d['id'], $method, $p['id'], $p['status'], $d['total_cents'], $qr['payload'], $qr['image'], $p['invoice_url']]);
        Events::add($d['id'], 'payment.created', 'customer', (int)$user['id'], ['method' => $method]);
        if (in_array($p['status'], ['CONFIRMED', 'RECEIVED'], true)) self::markPaid($p['id']);
        return self::view(Db::one("SELECT * FROM payments WHERE id=?", [$id]));
    }

    private static function view(array $p): array {
        return ['method' => $p['method'], 'status' => $p['status'], 'pix_payload' => $p['pix_payload'], 'pix_qr' => $p['pix_qr'], 'invoice_url' => $p['invoice_url']];
    }

    /** Idempotente: pode ser chamado pelo webhook e pela resposta do cartão. */
    static function markPaid(string $asaasId): void {
        $deliveryId = Db::tx(function () use ($asaasId) {
            $p = Db::one("SELECT * FROM payments WHERE asaas_payment_id=? FOR UPDATE", [$asaasId]);
            if (!$p || $p['paid_at']) return null;
            Db::run("UPDATE payments SET status='CONFIRMED', paid_at=NOW() WHERE id=?", [$p['id']]);
            $n = Db::run("UPDATE deliveries SET payment_status='paid' WHERE id=? AND payment_status='pending' AND status='created'", [$p['delivery_id']])->rowCount();
            if ($n) Events::add($p['delivery_id'], 'payment.confirmed');
            return $n ? $p['delivery_id'] : null;
        });
        if ($deliveryId) Dispatch::start($deliveryId);
    }

    static function webhook(array $body, ?string $token): void {
        $expected = Env::get('ASAAS_WEBHOOK_TOKEN', '');
        if ($expected === '' || !hash_equals($expected, (string)$token)) throw new HttpError(401, 'INVALID_WEBHOOK_TOKEN', 'Token inválido');
        $event = (string)($body['event'] ?? ''); $pay = $body['payment'] ?? [];
        $pid = (string)($pay['id'] ?? '');
        if ($event === '' || $pid === '') return;
        $key = substr((string)($body['id'] ?? ($event . ':' . $pid)), 0, 120);
        if (!Db::run("INSERT IGNORE INTO webhook_events (event_key) VALUES (?)", [$key])->rowCount()) return; // já processado
        if (in_array($event, ['PAYMENT_CONFIRMED', 'PAYMENT_RECEIVED'], true)) self::markPaid($pid);
        elseif (in_array($event, ['PAYMENT_REFUNDED', 'PAYMENT_CHARGEBACK_REQUESTED'], true)) {
            Db::run("UPDATE payments SET status='REFUNDED' WHERE asaas_payment_id=?", [$pid]);
            Db::run("UPDATE deliveries d JOIN payments p ON p.delivery_id=d.id SET d.payment_status='refunded' WHERE p.asaas_payment_id=?", [$pid]);
        }
    }

    /** Estorna (pago) ou cancela (pendente). Falhas ficam registradas, mas não bloqueiam o cancelamento. */
    static function reverse(array $d): void {
        $p = Db::one("SELECT * FROM payments WHERE delivery_id=? ORDER BY id DESC LIMIT 1", [$d['id']]);
        if (!$p) return;
        try {
            if ($d['payment_status'] === 'paid') { self::gateway()->refund($p['asaas_payment_id']); Db::run("UPDATE deliveries SET payment_status='refunded' WHERE id=?", [$d['id']]); Events::add($d['id'], 'payment.refunded'); }
            elseif ($p['status'] === 'PENDING') { self::gateway()->cancel($p['asaas_payment_id']); Db::run("UPDATE payments SET status='CANCELLED' WHERE id=?", [$p['id']]); }
        } catch (\Throwable $e) { Events::add($d['id'], 'payment.reverse_failed', 'system', null, ['error' => $e->getMessage()]); }
    }
}
