<?php
namespace App\Model\Core;

use App\Model\Auth\AuthModel;

class AuthMiddleware
{
    public static function checkAuthentication(): void
    {
        // 1) Les Bearer token
        $token = Http::bearerToken();
        if (!$token) {
            self::deny(401, 'Missing token');
            return;
        }

        try {
            $auth = new AuthModel();

            // 2) Introspekter mot Keycloak
            $claims = $auth->introspect($token);
            error_log('[AuthMiddleware] claims.active=' . (int)($claims['active'] ?? 0)
                . ' sub=' . ($claims['sub'] ?? 'NULL')
                . ' email=' . ($claims['email'] ?? 'NULL'));

            if (!($claims['active'] ?? false)) {
                self::deny(401, 'Invalid or expired token');
                return;
            }

            // 3) Sørg for at lokal bruker + oauth-identity finnes ALLTID
            $sub   = (string)($claims['sub']   ?? '');
            $email = $claims['email'] ?? null;

            error_log('[AuthMiddleware] ensureLocalUser start sub=' . ($sub ?: ''));
            $userId = $auth->ensureLocalUser($sub, $email);
            error_log('[AuthMiddleware] ensureLocalUser done userId=' . $userId);

            // 4) Gjør tilgjengelig for resten av requesten
            $_SERVER['USER_ID']    = $userId;
            $_SERVER['USER_SUB']   = $sub;
            $_SERVER['USER_EMAIL'] = $email;
            $_SERVER['USER_ROLES'] = $claims['realm_access']['roles'] ?? ($claims['roles'] ?? []);

        } catch (\Throwable $e) {
            error_log('[AuthMiddleware] ERROR: ' . $e->getMessage());
            self::deny(401, 'Authentication failed');
        }
    }

    private static function deny(int $code, string $msg): void
    {
        http_response_code($code);
        header('Content-Type: application/json');
        echo json_encode([
            'status'      => 'error',
            'status_code' => $code,
            'message'     => $msg,
        ]);
        exit;
    }
}
