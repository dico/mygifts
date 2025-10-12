<?php
namespace App\Model\Household;

use App\Model\Core\Database;
use App\Model\Utils\Id;
use Illuminate\Database\Capsule\Manager as DB;

class HouseholdModel
{
    public function __construct()
    {
        Database::init();
    }

    public function createHousehold(string $creatorUserId, string $name): string
    {
        $name = trim($name);
        if ($name === '') {
            throw new \InvalidArgumentException('Household name is required', 422);
        }

        return DB::connection()->transaction(function () use ($creatorUserId, $name) {
            $hid = Id::ulid();

            DB::table('households')->insert([
                'id'         => $hid,
                'name'       => $name,
                'created_at' => DB::raw('CURRENT_TIMESTAMP'),
            ]);

            DB::table('household_members')->insert([
                'household_id'     => $hid,
                'user_id'          => $creatorUserId,
                'is_family_member' => 1,
                'is_manager'       => 1,
                'created_at'       => DB::raw('CURRENT_TIMESTAMP'),
                'updated_at'       => DB::raw('CURRENT_TIMESTAMP'),
            ]);

            DB::table('users')
                ->where('id', $creatorUserId)
                ->update([
                    'active_household_id' => $hid,
                    'updated_at'          => DB::raw('CURRENT_TIMESTAMP'),
                ]);

            return $hid;
        });
    }

    /** Single read (requires membership) */
    public function get(string $householdId, string $requesterUserId): array
    {
        $this->assertHouseholdExists($householdId);
        if (!$this->isMember($householdId, $requesterUserId)) {
            throw new \RuntimeException('Forbidden', 403);
        }

        $row = DB::table('households')->where('id', $householdId)->first();
        return [
            'id'   => $row->id,
            'name' => $row->name,
        ];
    }

    /** List my households (with flags + counts) */
    public function listMyHouseholds(string $userId): array
    {
        $rows = DB::table('household_members as hm')
            ->join('households as h', 'h.id', '=', 'hm.household_id')
            ->where('hm.user_id', $userId)
            ->select('h.id','h.name','hm.is_family_member','hm.is_manager','h.created_at')
            ->orderBy('h.created_at','asc')
            ->get();

        if ($rows->isEmpty()) return [];

        $ids = $rows->pluck('id')->all();
        $counts = DB::table('household_members')
            ->select('household_id', DB::raw('COUNT(*) as c'))
            ->whereIn('household_id', $ids)
            ->groupBy('household_id')
            ->pluck('c', 'household_id');

        $out = [];
        foreach ($rows as $r) {
            $out[] = [
                'id'               => $r->id,
                'name'             => $r->name,
                'is_family_member' => (bool)$r->is_family_member,
                'is_manager'       => (bool)$r->is_manager,
                'member_count'     => (int)($counts[$r->id] ?? 0),
            ];
        }
        return $out;
    }

    /** Update name (manager/sysadmin) */
    public function update(string $householdId, string $requesterUserId, array $payload): void
    {
        $this->assertHouseholdExists($householdId);
        if (!$this->isManager($householdId, $requesterUserId) && !$this->isSystemAdmin($requesterUserId)) {
            throw new \RuntimeException('Forbidden', 403);
        }

        $name = trim((string)($payload['name'] ?? ''));
        if ($name === '') throw new \InvalidArgumentException('name is required', 422);

        DB::table('households')->where('id', $householdId)->update([
            'name'       => $name,
            'created_at' => DB::raw('created_at'), // keep
        ]);
    }

    /** Delete (manager/sysadmin) */
    public function delete(string $householdId, string $requesterUserId): void
    {
        $this->assertHouseholdExists($householdId);
        if (!$this->isManager($householdId, $requesterUserId) && !$this->isSystemAdmin($requesterUserId)) {
            throw new \RuntimeException('Forbidden', 403);
        }

        DB::table('households')->where('id', $householdId)->delete();
    }

    /** Helpers */
    private function assertHouseholdExists(string $householdId): void
    {
        $exists = DB::table('households')->where('id', $householdId)->exists();
        if (!$exists) throw new \UnexpectedValueException('Household not found', 404);
    }

    private function isMember(string $householdId, string $userId): bool
    {
        return DB::table('household_members')
            ->where('household_id', $householdId)
            ->where('user_id', $userId)
            ->exists();
    }

    private function isManager(string $householdId, string $userId): bool
    {
        return (int)DB::table('household_members')
            ->where('household_id', $householdId)
            ->where('user_id', $userId)
            ->value('is_manager') === 1;
    }

    private function isSystemAdmin(string $userId): bool
    {
        return (int)DB::table('users')->where('id', $userId)->value('is_admin') === 1;
    }
}
