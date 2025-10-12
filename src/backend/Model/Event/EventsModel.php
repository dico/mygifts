<?php
namespace App\Model\Event;

use App\Model\Core\Database;
use App\Model\Utils\Id;
use App\Model\Tenant\Tenant;
use Illuminate\Database\Capsule\Manager as DB;

class EventsModel
{
    public function __construct()
    {
        Database::init();
    }

    public function listEvents(string $requesterUserId): array
    {
        $hid = Tenant::activeId($requesterUserId);
        Tenant::assertMembership($hid, $requesterUserId);

        $rows = DB::table('events')
            ->where('household_id', $hid)
            ->orderBy('event_date','asc')
            ->orderBy('created_at','asc')
            ->get();

        return $rows->map(function ($r) {
            return [
                'id'              => $r->id,
                'name'            => $r->name,
                'event_date'      => $r->event_date,
                'event_type'      => $r->event_type,
                'honoree_user_id' => $r->honoree_user_id,
                'notes'           => $r->notes,
            ];
        })->toArray();
    }

    public function createEvent(string $requesterUserId, array $payload): string
    {
        $hid = Tenant::activeId($requesterUserId);
        Tenant::assertManagerOrSysAdmin($hid, $requesterUserId);

        $name  = trim((string)($payload['name'] ?? ''));
        $etype = (string)($payload['event_type'] ?? 'other');
        $date  = trim((string)($payload['event_date'] ?? ''));
        $notes = isset($payload['notes']) ? trim((string)$payload['notes']) : null;
        $hon   = isset($payload['honoree_user_id']) ? trim((string)$payload['honoree_user_id']) : null;

        if ($name === '') throw new \InvalidArgumentException('name is required', 422);
        if ($date !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
            throw new \InvalidArgumentException('event_date must be YYYY-MM-DD', 422);
        }
        if (!in_array($etype, ['christmas','birthday','other'], true)) {
            throw new \InvalidArgumentException('invalid event_type', 422);
        }

        $id = Id::ulid();
        DB::table('events')->insert([
            'id'            => $id,
            'household_id'  => $hid,
            'name'          => $name,
            'event_date'    => $date !== '' ? $date : null,
            'event_type'    => $etype,
            'honoree_user_id'=> $hon ?: null,
            'notes'         => $notes,
            'created_by'    => $requesterUserId,
            'created_at'    => DB::raw('CURRENT_TIMESTAMP'),
            'updated_at'    => DB::raw('CURRENT_TIMESTAMP'),
        ]);

        return $id;
    }

    public function getEvent(string $requesterUserId, string $eventId): array
    {
        $hid = Tenant::activeId($requesterUserId);
        Tenant::assertMembership($hid, $requesterUserId);

        $row = DB::table('events')
            ->where('household_id', $hid)
            ->where('id', $eventId)
            ->first();

        if (!$row) throw new \UnexpectedValueException('Event not found', 404);

        return [
            'id'              => $row->id,
            'name'            => $row->name,
            'event_date'      => $row->event_date,
            'event_type'      => $row->event_type,
            'honoree_user_id' => $row->honoree_user_id,
            'notes'           => $row->notes,
            'created_by'      => $row->created_by,
        ];
    }

    public function updateEvent(string $requesterUserId, string $eventId, array $payload): void
    {
        $hid = Tenant::activeId($requesterUserId);
        Tenant::assertManagerOrSysAdmin($hid, $requesterUserId);

        $exists = DB::table('events')
            ->where('household_id', $hid)
            ->where('id', $eventId)
            ->exists();
        if (!$exists) throw new \UnexpectedValueException('Event not found', 404);

        $upd = [];
        if (array_key_exists('name', $payload)) {
            $n = trim((string)$payload['name']);
            if ($n === '') throw new \InvalidArgumentException('name cannot be empty', 422);
            $upd['name'] = $n;
        }
        if (array_key_exists('event_date', $payload)) {
            $d = trim((string)$payload['event_date']);
            if ($d !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $d)) {
                throw new \InvalidArgumentException('event_date must be YYYY-MM-DD', 422);
            }
            $upd['event_date'] = $d !== '' ? $d : null;
        }
        if (array_key_exists('event_type', $payload)) {
            $t = (string)$payload['event_type'];
            if (!in_array($t, ['christmas','birthday','other'], true)) {
                throw new \InvalidArgumentException('invalid event_type', 422);
            }
            $upd['event_type'] = $t;
        }
        if (array_key_exists('notes', $payload)) {
            $upd['notes'] = $payload['notes'] !== null ? trim((string)$payload['notes']) : null;
        }
        if (array_key_exists('honoree_user_id', $payload)) {
            $u = $payload['honoree_user_id'];
            $upd['honoree_user_id'] = $u !== null ? (string)$u : null;
        }

        if ($upd) {
            $upd['updated_at'] = DB::raw('CURRENT_TIMESTAMP');
            DB::table('events')
                ->where('household_id', $hid)
                ->where('id', $eventId)
                ->update($upd);
        }
    }

    public function deleteEvent(string $requesterUserId, string $eventId): void
    {
        $hid = Tenant::activeId($requesterUserId);
        Tenant::assertManagerOrSysAdmin($hid, $requesterUserId);

        $deleted = DB::table('events')
            ->where('household_id', $hid)
            ->where('id', $eventId)
            ->delete();

        if ($deleted === 0) throw new \UnexpectedValueException('Event not found', 404);
    }
}
