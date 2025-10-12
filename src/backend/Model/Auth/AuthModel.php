<?php
namespace App\Model\Auth;

use App\Model\Core\Database;
use App\Model\Utils\Id;
use GuzzleHttp\Client;
use Illuminate\Database\Capsule\Manager as DB;

class AuthModel
{
    public function exchangeCodeForTokens(string $code): array
    {
        $base = rtrim(getenv('KEYCLOAK_BASE_URL'), '/');
        $url  = "{$base}/protocol/openid-connect/token";

        $cli = new Client(['timeout' => 8]);
        $res = $cli->post($url, [
            'form_params' => [
                'grant_type'    => 'authorization_code',
                'code'          => $code,
                'redirect_uri'  => getenv('KEYCLOAK_REDIRECT_URI'),
                'client_id'     => getenv('KEYCLOAK_CLIENT_ID'),
                'client_secret' => getenv('KEYCLOAK_CLIENT_SECRET'),
            ],
        ]);

        return json_decode((string)$res->getBody(), true);
    }

    public function introspect(string $accessToken): array
    {
        $base = rtrim(getenv('KEYCLOAK_BASE_URL'), '/');
        $url  = "{$base}/protocol/openid-connect/token/introspect";

        $cli = new Client(['timeout' => 8]);
        $res = $cli->post($url, [
            'form_params' => [
                'token'         => $accessToken,
                'client_id'     => getenv('KEYCLOAK_CLIENT_ID'),
                'client_secret' => getenv('KEYCLOAK_CLIENT_SECRET'),
            ],
        ]);

        return json_decode((string)$res->getBody(), true);
    }

    /**
     * Sørger for at lokal user + oauth-identity finnes. Returnerer user_id (ULID).
     * Matcher KUN på (provider, sub) – ikke e-post.
     */
    public function ensureLocalUser(string $providerSub, ?string $email): string
    {
        Database::init();

        error_log('[ensureLocalUser] ENTER sub=' . $providerSub . ' email=' . ($email ?? 'NULL'));

        if ($providerSub === '') {
            error_log('[ensureLocalUser] ERROR: Missing sub');
            throw new \InvalidArgumentException('Missing Keycloak subject (sub)');
        }

        // 1) Finn eksisterende identity
        $identity = DB::table('oauth_identities')
            ->where('provider', 'keycloak')
            ->where('provider_user_id', $providerSub)
            ->first();

        if ($identity) {
            // Logg hvilken DB vi faktisk er koblet til + om users-raden finnes
            try {
                $dbName = DB::select('SELECT DATABASE() AS db')[0]->db ?? 'UNKNOWN';
            } catch (\Throwable $e) {
                $dbName = 'UNKNOWN';
            }

            $userExists = (int) DB::table('users')->where('id', $identity->user_id)->count();

            DB::table('oauth_identities')
                ->where('id', $identity->id)
                ->update([
                    'last_login_at' => DB::raw('CURRENT_TIMESTAMP'),
                    'updated_at'    => DB::raw('CURRENT_TIMESTAMP'),
                ]);

            error_log('[ensureLocalUser] FOUND identity in DB=' . $dbName
                . ' user_id=' . $identity->user_id
                . ' users.exists=' . $userExists);

            // OPTIONAL: Self-heal hvis identity peker på manglende users-rad (orphan)
            if ($userExists === 0) {
                error_log('[ensureLocalUser] ORPHAN identity detected → creating users row for user_id=' . $identity->user_id);
                try {
                    DB::table('users')->insert([
                        'id'            => (string)$identity->user_id,
                        'firstname'     => $email && strpos($email, '@') !== false ? explode('@', $email, 2)[0] : 'User',
                        'lastname'      => '',
                        'email'         => $email,   // kan være NULL
                        'mobile'        => null,
                        'can_login'     => 1,
                        'password_hash' => null,
                        'is_active'     => 1,
                        'is_admin'      => 0,
                        'created_at'    => DB::raw('CURRENT_TIMESTAMP'),
                        'updated_at'    => DB::raw('CURRENT_TIMESTAMP'),
                    ]);
                    error_log('[ensureLocalUser] SELF-HEAL users.insert ok=1 for user_id=' . $identity->user_id);
                } catch (\Throwable $e) {
                    error_log('[ensureLocalUser] SELF-HEAL FAILED: ' . $e->getMessage());
                    // Ikke kast – la appen fortsette
                }
            }

            return (string)$identity->user_id;
        }

        // 2) Opprett bruker (første innlogging uten identity)
        $userId    = Id::ulid();
        $firstname = 'User';
        $lastname  = '';
        if ($email && strpos($email, '@') !== false) {
            $firstname = explode('@', $email, 2)[0] ?: 'User';
        }

        try {
            $dbName = DB::select('SELECT DATABASE() AS db')[0]->db ?? 'UNKNOWN';
            error_log('[ensureLocalUser] INSERT users into DB=' . $dbName . ' userId=' . $userId);

            $ok = DB::table('users')->insert([
                'id'            => $userId,
                'firstname'     => $firstname,
                'lastname'      => $lastname,
                'email'         => $email,
                'mobile'        => null,
                'can_login'     => 1,
                'password_hash' => null,
                'is_active'     => 1,
                'is_admin'      => 0,
                'created_at'    => DB::raw('CURRENT_TIMESTAMP'),
                'updated_at'    => DB::raw('CURRENT_TIMESTAMP'),
            ]);

            error_log('[ensureLocalUser] users.insert ok=' . (int)$ok);
        } catch (\Illuminate\Database\QueryException $e) {
            error_log('[ensureLocalUser] users.insert FAILED: ' . $e->getMessage());
            throw $e;
        } catch (\Throwable $e) {
            error_log('[ensureLocalUser] users.insert FAILED (generic): ' . $e->getMessage());
            throw $e;
        }

        // 3) Opprett oauth-identity
        try {
            $oid = Id::ulid();
            error_log('[ensureLocalUser] INSERT oauth_identities id=' . $oid . ' for userId=' . $userId);

            $ok = DB::table('oauth_identities')->insert([
                'id'               => $oid,
                'user_id'          => $userId,
                'provider'         => 'keycloak',
                'provider_user_id' => $providerSub,
                'realm'            => null,
                'email'            => $email,
                'claims_json'      => null,
                'last_login_at'    => DB::raw('CURRENT_TIMESTAMP'),
                'created_at'       => DB::raw('CURRENT_TIMESTAMP'),
                'updated_at'       => DB::raw('CURRENT_TIMESTAMP'),
            ]);

            error_log('[ensureLocalUser] oauth_identities.insert ok=' . (int)$ok);
        } catch (\Throwable $e) {
            error_log('[ensureLocalUser] oauth_identities.insert FAILED: ' . $e->getMessage());
            try { DB::table('users')->where('id', $userId)->delete(); } catch (\Throwable $ignored) {}
            throw $e;
        }

        error_log('[ensureLocalUser] EXIT userId=' . $userId);
        return $userId;
    }

    /** Har brukeren medlemskap i NOE household? */
    public function hasAnyHousehold(string $userId): bool
    {
        Database::init();
        $count = DB::table('household_members')->where('user_id', $userId)->count();
        return (int)$count > 0;
    }

    /** Bygger profil-payload for /auth/me. */
    public function buildProfilePayload(array $claims, string $userId): array
    {
        Database::init();

        $u = DB::table('users')
            ->select('id','firstname','lastname','email','is_admin','is_active','active_household_id')
            ->where('id', $userId)
            ->first();

        $memberships = DB::table('household_members as hm')
            ->join('households as h', 'h.id', '=', 'hm.household_id')
            ->where('hm.user_id', $userId)
            ->select('hm.household_id','h.name','hm.is_family_member','hm.is_manager')
            ->orderBy('h.created_at','asc')
            ->get()
            ->map(fn($row) => [
                'household_id'     => $row->household_id,
                'household_name'   => $row->name,
                'is_family_member' => (bool)$row->is_family_member,
                'is_manager'       => (bool)$row->is_manager,
            ])
            ->toArray();

        return [
            'user_id'             => $userId,
            'firstname'           => $u->firstname ?? null,
            'lastname'            => $u->lastname ?? null,
            'email'               => $u->email ?? ($claims['email'] ?? null),
            'is_admin'            => (bool)($u->is_admin ?? 0),
            'is_active'           => (bool)($u->is_active ?? 1),
            'active_household_id' => $u->active_household_id ?? null,
            'memberships'         => $memberships,
            'needs_setup'         => empty($memberships),
            'sub'                 => $claims['sub'] ?? null,
            'roles'               => $claims['realm_access']['roles'] ?? ($claims['roles'] ?? []),
        ];
    }
}
