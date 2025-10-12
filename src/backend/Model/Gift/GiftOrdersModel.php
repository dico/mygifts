<?php
namespace App\Model\Gift;

use App\Model\Core\Database;
use App\Model\Utils\Id;
use App\Model\Tenant\Tenant;
use Illuminate\Database\Capsule\Manager as DB;

class GiftOrdersModel
{
    public function __construct() { Database::init(); }

    /** Liste alle orders for et event – inkludert items og deltakere */
    public function listForEvent(string $requesterUserId, string $eventId): array {
        $hid = Tenant::activeId($requesterUserId);
        Tenant::assertMembership($hid, $requesterUserId);

        $orders = DB::table('gift_orders as o')
            ->where('o.household_id', $hid)
            ->where('o.event_id', $eventId)
            ->orderBy('o.created_at', 'asc')
            ->get();

        return collect($orders)->map(fn($r) => $this->hydrateOrder($r))->toArray();
    }

    public function getOne(string $requesterUserId, string $orderId): array {
        $hid = Tenant::activeId($requesterUserId);
        Tenant::assertMembership($hid, $requesterUserId);

        $r = DB::table('gift_orders')->where('household_id',$hid)->where('id',$orderId)->first();
        if (!$r) throw new \UnexpectedValueException('Gift order not found', 404);
        return $this->hydrateOrder($r);
    }

    public function create(string $requesterUserId, array $payload): string {
        $hid = Tenant::activeId($requesterUserId);
        Tenant::assertManagerOrSysAdmin($hid, $requesterUserId);

        $eventId    = isset($payload['event_id']) ? (string)$payload['event_id'] : null;
        $orderType  = (string)($payload['order_type'] ?? ($payload['direction'] ?? 'outgoing')); // aksepter "direction"
        if (!in_array($orderType, ['outgoing','incoming'], true)) {
            throw new \InvalidArgumentException('invalid order_type', 422);
        }

        if ($eventId) {
            $ev = DB::table('events')->where('id',$eventId)->first();
            if (!$ev || $ev->household_id !== $hid) {
                throw new \RuntimeException('Event not found in tenant', 403);
            }
        }

        $giverIds = array_values(array_filter((array)($payload['giver_user_ids'] ?? [])));
        $recipIds = array_values(array_filter((array)($payload['recipient_user_ids'] ?? [])));
        if (!$giverIds || !$recipIds) {
            throw new \InvalidArgumentException('giver_user_ids and recipient_user_ids are required', 422);
        }

        $id = Id::ulid();
        DB::table('gift_orders')->insert([
            'id'           => $id,
            'household_id' => $hid,
            'event_id'     => $eventId,
            'title'        => isset($payload['title']) ? (string)$payload['title'] : null,
            'order_type'   => $orderType, // <- RIKTIG kolonnenavn
            'notes'        => isset($payload['notes']) ? (string)$payload['notes'] : null,
            'status'       => 'planning',
            'created_by'   => $requesterUserId,
            'created_at'   => DB::raw('CURRENT_TIMESTAMP'),
            'updated_at'   => DB::raw('CURRENT_TIMESTAMP'),
        ]);

        $now = DB::raw('CURRENT_TIMESTAMP');
        foreach ($giverIds as $uid) {
            DB::table('gift_order_participants')->insert([
                'order_id'   => $id,       // <- RIKTIG kolonnenavn
                'user_id'    => (string)$uid,
                'role'       => 'giver',
                'created_at' => $now,
            ]);
        }
        foreach ($recipIds as $uid) {
            DB::table('gift_order_participants')->insert([
                'order_id'   => $id,       // <- RIKTIG kolonnenavn
                'user_id'    => (string)$uid,
                'role'       => 'recipient',
                'created_at' => $now,
            ]);
        }

        return $id;
    }

    public function update(string $requesterUserId, string $orderId, array $payload): void {
        $hid = Tenant::activeId($requesterUserId);
        Tenant::assertManagerOrSysAdmin($hid, $requesterUserId);

        $exists = DB::table('gift_orders')->where('household_id',$hid)->where('id',$orderId)->exists();
        if (!$exists) throw new \UnexpectedValueException('Gift order not found', 404);

        $upd = [];
        if (array_key_exists('order_type',$payload) || array_key_exists('direction',$payload)) {
            $dir = (string)($payload['order_type'] ?? $payload['direction']);
            if (!in_array($dir, ['outgoing','incoming'], true)) {
                throw new \InvalidArgumentException('invalid order_type', 422);
            }
            $upd['order_type'] = $dir;
        }
        if (array_key_exists('event_id',$payload)) {
            $ev = $payload['event_id'];
            if ($ev === '' || $ev === null) {
                $upd['event_id'] = null;
            } else {
                $evr = DB::table('events')->where('id',(string)$ev)->first();
                if (!$evr || $evr->household_id !== $hid) {
                    throw new \RuntimeException('Event not found in tenant', 403);
                }
                $upd['event_id'] = (string)$ev;
            }
        }
        if (array_key_exists('notes',$payload)) {
            $upd['notes'] = $payload['notes'] !== null ? (string)$payload['notes'] : null;
        }
        if (array_key_exists('title',$payload)) {
            $upd['title'] = $payload['title'] !== null ? trim((string)$payload['title']) : null;
        }
        if (array_key_exists('status',$payload)) {
            $st = (string)$payload['status'];
            if (!in_array($st, ['planning','in_progress','completed','cancelled'], true)) {
                throw new \InvalidArgumentException('invalid status', 422);
            }
            $upd['status'] = $st;
        }

        if ($upd) {
            $upd['updated_at'] = DB::raw('CURRENT_TIMESTAMP');
            DB::table('gift_orders')->where('id',$orderId)->update($upd);
        }

        // Oppdater deltakere hvis sendt inn
        if (array_key_exists('giver_user_ids',$payload) || array_key_exists('recipient_user_ids',$payload)) {
            DB::table('gift_order_participants')->where('order_id',$orderId)->delete();
            $now = DB::raw('CURRENT_TIMESTAMP');
            foreach ((array)($payload['giver_user_ids'] ?? []) as $uid) {
                DB::table('gift_order_participants')->insert([
                    'order_id'=>$orderId,'user_id'=>(string)$uid,'role'=>'giver','created_at'=>$now
                ]);
            }
            foreach ((array)($payload['recipient_user_ids'] ?? []) as $uid) {
                DB::table('gift_order_participants')->insert([
                    'order_id'=>$orderId,'user_id'=>(string)$uid,'role'=>'recipient','created_at'=>$now
                ]);
            }
        }
    }

    public function destroy(string $requesterUserId, string $orderId): void {
        $hid = Tenant::activeId($requesterUserId);
        Tenant::assertManagerOrSysAdmin($hid, $requesterUserId);

        $deleted = DB::table('gift_orders')->where('household_id',$hid)->where('id',$orderId)->delete();
        if ($deleted === 0) throw new \UnexpectedValueException('Gift order not found', 404);
        // CASCADE tar gift_items og participants
    }

    /** Hent deltakere + items for en order */
    private function hydrateOrder($r): array {
        // Deltakere
        $parts = DB::table('gift_order_participants as p')
            ->join('users as u','u.id','=','p.user_id')
            ->where('p.order_id', $r->id)
            ->get();

        $givers = [];
        $recips = [];
        foreach ($parts as $p) {
            $name = trim(($p->firstname ?? '').' '.($p->lastname ?? ''));
            $disp = $name !== '' ? $name : ($p->email ?? 'User');
            $u = ['id'=>$p->user_id,'display_name'=>$disp,'email'=>$p->email];
            if ($p->role === 'giver') $givers[] = $u; else $recips[] = $u;
        }

        // Items
        $items = DB::table('gift_items as gi')
            ->leftJoin('products as pr','pr.id','=','gi.product_id')
            ->where('gi.order_id', $r->id)
            ->orderBy('gi.created_at','asc')
            ->get()
            ->map(function($i){
                return [
                    'id'              => $i->id,
                    'order_id'        => $i->order_id,
                    'product_id'      => $i->product_id,
                    'product_name'    => $i->name ?? null,
                    'title'           => $i->title,
                    'notes'           => $i->notes,
                    'status'          => $i->status,
                    'planned_price'   => $i->planned_price,
                    'purchase_price'  => $i->purchase_price,
                    'currency_code'   => $i->currency_code,
                    'purchased_at'    => $i->purchased_at,
                    'given_at'        => $i->given_at,
                    'created_by'      => $i->created_by,
                    'created_at'      => $i->created_at,
                    'updated_at'      => $i->updated_at,
                ];
            })->toArray();

        return [
            'id'         => $r->id,
            'event_id'   => $r->event_id,
            'title'      => $r->title,
            'order_type' => $r->order_type,
            'notes'      => $r->notes,
            'status'     => $r->status,
            'givers'     => $givers,
            'recipients' => $recips,
            'items'      => $items,
            'created_at' => $r->created_at,
            'updated_at' => $r->updated_at,
        ];
    }
}
