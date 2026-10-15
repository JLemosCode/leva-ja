<?php
namespace App\Core;
final class Auth {
    private static function b64(string $s): string { return rtrim(strtr(base64_encode($s), '+/', '-_'), '='); }
    private static function secret(): string {
        $s = Env::get('JWT_SECRET', '');
        if (strlen($s) < 32) throw new HttpError(500, 'CONFIG', 'JWT_SECRET ausente ou curto (mín. 32 caracteres)');
        return $s;
    }
    static function token(int $uid, string $role): string {
        $h = self::b64('{"alg":"HS256","typ":"JWT"}');
        $p = self::b64(json_encode(['sub' => $uid, 'role' => $role, 'exp' => time() + 7 * 86400]));
        return "$h.$p." . self::b64(hash_hmac('sha256', "$h.$p", self::secret(), true));
    }
    static function user(Request $q, array $roles = []): array {
        $u = null;
        if ($key = $q->header('x-api-key')) {
            $u = Db::one("SELECT u.* FROM api_keys k JOIN users u ON u.id=k.user_id WHERE k.key_hash=? AND k.revoked_at IS NULL", [hash('sha256', $key)]);
            if ($u && $u['role'] !== 'customer') $u = null;
        } elseif ($t = $q->bearer()) {
            $p = explode('.', $t);
            if (count($p) === 3) {
                $sig = self::b64(hash_hmac('sha256', "$p[0].$p[1]", self::secret(), true));
                $pl = json_decode((string)base64_decode(strtr($p[1], '-_', '+/')), true);
                if (hash_equals($sig, $p[2]) && is_array($pl) && ($pl['exp'] ?? 0) > time())
                    $u = Db::one("SELECT * FROM users WHERE id=?", [$pl['sub']]);
            }
        }
        if (!$u) throw new HttpError(401, 'UNAUTHORIZED', 'Autenticação necessária');
        if ($roles && !in_array($u['role'], $roles, true)) throw new HttpError(403, 'FORBIDDEN', 'Operação não permitida');
        return $u;
    }
    static function public(array $u): array {
        return ['id' => (int)$u['id'], 'role' => $u['role'], 'name' => $u['name'], 'email' => $u['email'], 'phone' => $u['phone'], 'cpf_cnpj' => $u['cpf_cnpj']];
    }
}
