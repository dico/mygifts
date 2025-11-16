<?php
namespace App\Model\Tenant;

use App\Model\Core\Database;
use Illuminate\Database\Capsule\Manager as DB;

final class Tenant
{
    /** Returner aktiv tenant (household-id) for gitt bruker. Kaster 403 hvis ingen. */
    public static function activeId(string $userId): string
    {
        Database::init();

        $hid = DB::table('users')->where('id', $userId)->value('active_household_id');

        // Fallback: Auto-set active_household_id hvis brukeren ikke har en, men har medlemskap
        if (!$hid) {
            $firstMembership = DB::table('household_members')
                ->where('user_id', $userId)
                ->orderBy('created_at', 'asc')
                ->first();

            if ($firstMembership) {
                error_log('[Tenant] Auto-setting active_household_id=' . $firstMembership->household_id . ' for userId=' . $userId);

                DB::table('users')
                    ->where('id', $userId)
                    ->update([
                        'active_household_id' => $firstMembership->household_id,
                        'updated_at' => DB::raw('CURRENT_TIMESTAMP'),
                    ]);

                $hid = $firstMembership->household_id;
            }
        }

        if (!$hid) {
            throw new \RuntimeException('No active household/tenant', 403);
        }

        return (string)$hid;
    }

    /** Sjekk om bruker er systemadmin. */
    public static function isSystemAdmin(string $userId): bool
    {
        Database::init();

        return (int)DB::table('users')->where('id', $userId)->value('is_admin') === 1;
    }

    /** Sjekk om bruker er medlem i tenant. */
    public static function isMember(string $householdId, string $userId): bool
    {
        Database::init();

        return DB::table('household_members')
            ->where('household_id', $householdId)
            ->where('user_id', $userId)
            ->exists();
    }

    /** Sjekk om bruker er manager i tenant. */
    public static function isManager(string $householdId, string $userId): bool
    {
        Database::init();

        return (int)DB::table('household_members')
            ->where('household_id', $householdId)
            ->where('user_id', $userId)
            ->value('is_manager') === 1;
    }

    /** Kaster 403 hvis ikke medlem. */
    public static function assertMembership(string $householdId, string $userId): void
    {
        if (!self::isMember($householdId, $userId)) {
            throw new \RuntimeException('Forbidden', 403);
        }
    }

    /** Kaster 403 hvis ikke manager ELLER systemadmin. */
    public static function assertManagerOrSysAdmin(string $householdId, string $userId): void
    {
        if (!self::isSystemAdmin($userId) && !self::isManager($householdId, $userId)) {
            throw new \RuntimeException('Forbidden', 403);
        }
    }
}
