<?php
namespace App\Model\User;

use App\Model\Core\Database;
use Illuminate\Database\Capsule\Manager as DB;

class CurrentUser
{
    /** Per-request cache */
    private static ?string $cachedUserId = null;

    /**
     * Resolve current local user_id (ULID).
     * Requires AuthMiddleware to have validated the token and populated $_SERVER.
     */
    public static function id(): string
    {
        if (self::$cachedUserId !== null) return self::$cachedUserId;

        Database::init();

        $sub   = $_SERVER['USER_SUB']   ?? null;
        $email = $_SERVER['USER_EMAIL'] ?? null;

        if ($sub) {
            $oid = DB::table('oauth_identities')
                ->select('user_id')
                ->where('provider', 'keycloak')
                ->where('provider_user_id', $sub)
                ->first();
            if ($oid && isset($oid->user_id)) {
                return self::$cachedUserId = (string)$oid->user_id;
            }
        }

        // Optional: legacy fallback by email (if you still want it)
        if ($email) {
            $u = DB::table('users')->select('id')->where('email', $email)->first();
            if ($u && isset($u->id)) {
                return self::$cachedUserId = (string)$u->id;
            }
        }

        http_response_code(401);
        echo json_encode(['status' => 'error', 'message' => 'User not linked']);
        exit;
    }

    /** Optional helper: fetch full user row */
    public static function row(): ?object
    {
        $id = self::id();
        return DB::table('users')->where('id', $id)->first();
    }

    /** Active household helpers (stored on users.active_household_id). */
    public static function activeHouseholdId(): ?string
    {
        $uid = self::id();

        // 1) stored choice?
        $hid = DB::table('users')->where('id', $uid)->value('active_household_id');
        if (!empty($hid)) return (string)$hid;

        // 2) auto-pick if exactly one membership → store it to keep things simple
        $list = DB::table('household_members')->where('user_id', $uid)->limit(2)->pluck('household_id');
        if ($list->count() === 1) {
            $only = (string)$list[0];
            DB::table('users')->where('id',$uid)->update([
                'active_household_id' => $only,
                'updated_at'          => DB::raw('CURRENT_TIMESTAMP'),
            ]);
            return $only;
        }

        return null; // none or multiple
    }

    public static function setActiveHousehold(string $householdId): void
    {
        $uid = self::id();
        $isMember = DB::table('household_members')
            ->where('user_id', $uid)
            ->where('household_id', $householdId)
            ->exists();
        if (!$isMember) {
            http_response_code(403);
            echo json_encode(['status'=>'error','message'=>'Not a member of this household']);
            exit;
        }
        DB::table('users')->where('id',$uid)->update([
            'active_household_id' => $householdId,
            'updated_at'          => DB::raw('CURRENT_TIMESTAMP'),
        ]);
    }

    /** Membership checks (rarely needed if you always use active household) */
    public static function isMemberOfHousehold(string $householdId): bool
    {
        $uid = self::id();
        return DB::table('household_members')
            ->where('household_id', $householdId)
            ->where('user_id', $uid)
            ->exists();
    }

    public static function isManagerOfHousehold(string $householdId): bool
    {
        $uid = self::id();
        $v = DB::table('household_members')
            ->where('household_id', $householdId)
            ->where('user_id', $uid)
            ->value('is_manager');
        return (int)$v === 1;
    }

    public static function requireMember(string $householdId): void
    {
        if (!self::isMemberOfHousehold($householdId)) {
            http_response_code(403);
            echo json_encode(['status' => 'error', 'message' => 'Forbidden']);
            exit;
        }
    }

    public static function requireManager(string $householdId): void
    {
        if (!self::isManagerOfHousehold($householdId)) {
            http_response_code(403);
            echo json_encode(['status' => 'error', 'message' => 'Manager rights required']);
            exit;
        }
    }
}
