<?php
namespace App\Services;
use App\Core\{Env, HttpError};

interface PaymentGateway {
    function ensureCustomer(array $user): string;
    function charge(array $in): array;
    function pixQr(string $paymentId): array;
    function refund(string $paymentId): void;
    function cancel(string $paymentId): void;
}

/** Implementação Asaas (API v3). Nenhum dado de cartão é gravado ou registrado em log. */
final class AsaasGateway implements PaymentGateway {
    private function call(string $method, string $path, ?array $body = null): array {
        $key = Env::get('ASAAS_API_KEY');
        if (!$key) throw new HttpError(500, 'CONFIG', 'ASAAS_API_KEY não configurada');
        $ch = curl_init(rtrim(Env::get('ASAAS_BASE_URL', 'https://api-sandbox.asaas.com/v3'), '/') . $path);
        curl_setopt_array($ch, [CURLOPT_CUSTOMREQUEST => $method, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 30,
            CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'access_token: ' . $key, 'User-Agent: LevaJa/1.0'],
            CURLOPT_POSTFIELDS => $body !== null ? json_encode($body) : null]);
        $raw = curl_exec($ch); $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch);
        $j = is_string($raw) ? (json_decode($raw, true) ?: []) : [];
        if ($code >= 400 || $raw === false) {
            $msg = $j['errors'][0]['description'] ?? 'Falha ao comunicar com o provedor de pagamento';
            error_log("Asaas $method $path -> $code"); // sem corpo: pode conter dados sensíveis
            throw new HttpError($code === 400 ? 422 : 502, 'PAYMENT_PROVIDER', $msg);
        }
        return $j;
    }
    function ensureCustomer(array $u): string {
        if (!empty($u['asaas_customer_id'])) return $u['asaas_customer_id'];
        $c = $this->call('POST', '/customers', ['name' => $u['name'], 'email' => $u['email'], 'cpfCnpj' => preg_replace('/\D/', '', $u['cpf_cnpj']), 'mobilePhone' => preg_replace('/\D/', '', (string)$u['phone']), 'notificationDisabled' => true]);
        \App\Core\Db::run("UPDATE users SET asaas_customer_id=? WHERE id=?", [$c['id'], $u['id']]);
        return $c['id'];
    }
    function charge(array $in): array {
        $body = ['customer' => $in['customer'], 'billingType' => $in['method'] === 'pix' ? 'PIX' : 'CREDIT_CARD',
            'value' => round($in['cents'] / 100, 2), 'dueDate' => gmdate('Y-m-d'), 'description' => $in['description'], 'externalReference' => $in['reference']];
        if ($in['method'] === 'card') { $body['creditCard'] = $in['card']; $body['creditCardHolderInfo'] = $in['holder']; $body['remoteIp'] = $in['ip']; }
        $p = $this->call('POST', '/payments', $body);
        return ['id' => $p['id'], 'status' => $p['status'] ?? 'PENDING', 'invoice_url' => $p['invoiceUrl'] ?? null];
    }
    function pixQr(string $id): array {
        $q = $this->call('GET', "/payments/$id/pixQrCode");
        return ['payload' => $q['payload'] ?? null, 'image' => $q['encodedImage'] ?? null];
    }
    function refund(string $id): void { $this->call('POST', "/payments/$id/refund", []); }
    function cancel(string $id): void { $this->call('DELETE', "/payments/$id"); }
}
