<?php
namespace App\Controller\Auth;

use App\Model\Core\BaseController;
use App\Model\Core\Http;
use App\Model\Auth\AuthModel;

class AuthController extends BaseController
{
    private AuthModel $auth;

    public function __construct()
    {
        $this->auth = new AuthModel();
    }

    /**
     * POST /api/auth/token
     * Bytter code->tokens. (Valgfritt) gjør tidlig ensureLocalUser.
     */
    public function tokenExchange(): array
    {
        $body = \App\Model\Core\Http::jsonBody();
        $code = $body['code'] ?? null;
        if (!$code) {
            return $this->error('Missing authorization code', 400);
        }

        try {
            $tokens = $this->auth->exchangeCodeForTokens($code);
            if (!$tokens || empty($tokens['access_token'])) {
                return $this->error('Token exchange failed', 400);
            }

            // Valgfritt: tidlig ensure, feiler ikke token-exchange om noe går galt her.
            try {
                $claims = $this->auth->introspect($tokens['access_token']);
                error_log('[auth.tokenExchange] claims.active=' . (int)($claims['active'] ?? 0)
                    . ' sub=' . ($claims['sub'] ?? 'NULL')
                    . ' email=' . ($claims['email'] ?? 'NULL'));
                if (!empty($claims['active'])) {
                    $uid = $this->auth->ensureLocalUser($claims['sub'] ?? '', $claims['email'] ?? null);
                    error_log('[auth.tokenExchange] ensured userId=' . $uid);
                }
            } catch (\Throwable $ignore) {
                error_log('[auth.tokenExchange] ensureLocalUser skipped: ' . $ignore->getMessage());
            }

            return $this->ok(['tokens' => $tokens], 200);
        } catch (\GuzzleHttp\Exception\ClientException $e) {
            $resp = $e->getResponse();
            $msg  = 'Token exchange failed';
            $key  = null;
            if ($resp) {
                $body = (string)$resp->getBody();
                $j = json_decode($body, true);
                if (!empty($j['error_description'])) $msg = $j['error_description'];
                if (!empty($j['error'])) $key = $j['error'];
            }
            error_log('[auth.tokenExchange] ' . $e->getMessage());
            return $this->error($msg, 400, $key ? ['error' => $key] : []);
        } catch (\Throwable $e) {
            error_log('[auth.tokenExchange] ' . $e->getMessage());
            return $this->error('Token exchange failed', 400);
        }
    }


	public function refresh(): array
	{
		$body = \App\Model\Core\Http::jsonBody();
		$rt = $body['refresh_token'] ?? null;
		if (!$rt) return $this->error('Missing refresh_token', 400);

		try {
			$base = rtrim(getenv('KEYCLOAK_BASE_URL'), '/');
			$url  = "{$base}/protocol/openid-connect/token";

			$cli = new \GuzzleHttp\Client(['timeout' => 8]);
			$res = $cli->post($url, [
				'form_params' => [
					'grant_type'    => 'refresh_token',
					'refresh_token' => $rt,
					'client_id'     => getenv('KEYCLOAK_CLIENT_ID'),
					'client_secret' => getenv('KEYCLOAK_CLIENT_SECRET'),
				],
			]);

			$tokens = json_decode((string)$res->getBody(), true);
			if (empty($tokens['access_token'])) {
				return $this->error('Refresh failed', 400);
			}

			// (Valgfritt) tidlig ensure som i tokenExchange()
			try {
				$claims = (new \App\Model\Auth\AuthModel())->introspect($tokens['access_token']);
				if (!empty($claims['active'])) {
					(new \App\Model\Auth\AuthModel())->ensureLocalUser($claims['sub'] ?? '', $claims['email'] ?? null);
				}
			} catch (\Throwable $ignore) {}

			return $this->ok(['tokens' => $tokens], 200);
		} catch (\GuzzleHttp\Exception\ClientException $e) {
			return $this->error('Refresh failed', 400);
		} catch (\Throwable $e) {
			return $this->error('Refresh failed', 400);
		}
	}

    /**
     * GET /api/auth/me
     */
    public function me(): array
    {
        $token = Http::bearerToken();
        if (!$token) return $this->error('Missing token', 401);

        try {
            $claims = $this->auth->introspect($token);
            error_log('[auth.me] claims.active=' . (int)($claims['active'] ?? 0)
                . ' sub=' . ($claims['sub'] ?? 'NULL')
                . ' email=' . ($claims['email'] ?? 'NULL'));

            if (!($claims['active'] ?? false)) {
                return $this->error('Invalid or expired token', 401);
            }

            $userId = $this->auth->ensureLocalUser($claims['sub'] ?? '', $claims['email'] ?? null);
            error_log('[auth.me] ensured userId=' . $userId);

            $profile = $this->auth->buildProfilePayload($claims, $userId);
            return $this->ok($profile, 200);
        } catch (\Throwable $e) {
            // Ikke 401 på serverfeil; 401 gir lett redirect-loop i SPA
            error_log('[auth.me] ERROR: ' . $e->getMessage());
            return $this->error('Auth failed', 500);
        }
    }



}
