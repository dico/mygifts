<?php
namespace App\Model\Gift;

use App\Model\Core\Database;
use App\Model\Utils\Id;
use App\Model\Tenant\Tenant;
use Illuminate\Database\Capsule\Manager as DB;

class GiftsModel
{
    public function __construct() { Database::init(); }

    /** (Beholdt for kompat.) Liste “gaver” = gift_items i hele tenant (flattened) */
    public function listAll(string $requesterUserId): array {
        $hid = Tenant::activeId($requesterUserId);
        Tenant::assertMembership($hid, $requesterUserId);

        $rows = DB::table('gift_items as gi')
            ->join('gift_orders as o','o.id','=','gi.order_id')
            ->where('o.household_id', $hid)
            ->orderBy('gi.created_at', 'desc')
            ->get();

        return $this->mapRows($rows);
    }

    /** (Beholdt) Liste gift_items for event_id */
    public function listForEvent(string $requesterUserId, string $eventId): array {
        $hid = Tenant::activeId($requesterUserId);
        Tenant::assertMembership($hid, $requesterUserId);

        $rows = DB::table('gift_items as gi')
            ->join('gift_orders as o','o.id','=','gi.order_id')
            ->where('o.household_id', $hid)
            ->where('o.event_id', $eventId)
            ->orderBy('gi.created_at', 'desc')
            ->get();

        return $this->mapRows($rows);
    }

    /** GET /gifts/{id} -> gift_item */
    public function getOne(string $requesterUserId, string $giftItemId): array {
        $hid = Tenant::activeId($requesterUserId);
        Tenant::assertMembership($hid, $requesterUserId);

        $row = DB::table('gift_items as gi')
            ->join('gift_orders as o','o.id','=','gi.order_id')
            ->leftJoin('products as p','p.id','=','gi.product_id')
            ->where('o.household_id',$hid)
            ->where('gi.id',$giftItemId)
            ->select('gi.*','o.event_id','o.order_type','p.name as product_name')
            ->first();

        if (!$row) throw new \UnexpectedValueException('Gift not found', 404);
        return $this->mapRow($row);
    }

    /**
     * POST /gifts
     * - Oppretter alltid gift_item
     * - Oppretter gift_order hvis gift_order_id ikke er sendt inn
     * - Tittel i UI er fjernet -> title = produktnavn
     *
     * For å være bakoverkompatibel tar vi både:
     *   - order_type eller direction (outgoing/incoming)
     *   - product_id eller product_name
     *   - giver_user_ids[], recipient_user_ids[] (krav ved ny order)
     */
    public function create(string $requesterUserId, array $payload): string {
        $hid = Tenant::activeId($requesterUserId);
        Tenant::assertManagerOrSysAdmin($hid, $requesterUserId);

        // Finn/lag order
        $orderId   = isset($payload['gift_order_id']) ? trim((string)$payload['gift_order_id']) : '';
        $eventId   = isset($payload['event_id']) ? trim((string)$payload['event_id']) : null;
        $orderType = (string)($payload['order_type'] ?? ($payload['direction'] ?? 'outgoing'));
        if (!in_array($orderType, ['outgoing','incoming'], true)) {
            throw new \InvalidArgumentException('invalid order_type', 422);
        }

        if ($orderId !== '') {
            $ord = DB::table('gift_orders')->where('id',$orderId)->first();
            if (!$ord || $ord->household_id !== $hid) {
                throw new \RuntimeException('Gift order not found in tenant', 403);
            }
            // eventId fra eksisterende order
            $eventId = $ord->event_id;
        } else {
            if (!$eventId) {
                throw new \InvalidArgumentException('event_id is required when creating a new order', 422);
            }
            $ev = DB::table('events')->where('id',$eventId)->first();
            if (!$ev || $ev->household_id !== $hid) {
                throw new \RuntimeException('Event not found in tenant', 403);
            }
            $giverIds = array_values(array_filter((array)($payload['giver_user_ids'] ?? [])));
            $recipIds = array_values(array_filter((array)($payload['recipient_user_ids'] ?? [])));
            if (!$giverIds || !$recipIds) {
                throw new \InvalidArgumentException('giver_user_ids and recipient_user_ids are required when creating a new order', 422);
            }

            $orderId = Id::ulid();
            DB::table('gift_orders')->insert([
                'id'           => $orderId,
                'household_id' => $hid,
                'event_id'     => $eventId,
                'order_type'   => $orderType,
                'status'       => 'planning',
                'created_by'   => $requesterUserId,
                'created_at'   => DB::raw('CURRENT_TIMESTAMP'),
                'updated_at'   => DB::raw('CURRENT_TIMESTAMP'),
            ]);

            $now = DB::raw('CURRENT_TIMESTAMP');
            foreach ($giverIds as $uid) {
                DB::table('gift_order_participants')->insert([
                    'order_id'=>$orderId,'user_id'=>(string)$uid,'role'=>'giver','created_at'=>$now
                ]);
            }
            foreach ($recipIds as $uid) {
                DB::table('gift_order_participants')->insert([
                    'order_id'=>$orderId,'user_id'=>(string)$uid,'role'=>'recipient','created_at'=>$now
                ]);
            }
        }

        // Produkt
        $productId   = isset($payload['product_id']) ? trim((string)$payload['product_id']) : '';
        $productName = trim((string)($payload['product_name'] ?? ''));
        if ($productId === '' && $productName === '') {
            throw new \InvalidArgumentException('product_id or product_name is required', 422);
        }
        if ($productId === '') {
            $productId = $this->findOrCreateProductByName($hid, $productName);
        } else {
            $prod = DB::table('products')->where('id',$productId)->first();
            if (!$prod || $prod->household_id !== $hid) {
                throw new \RuntimeException('Product not found in tenant', 403);
            }
            if ($productName === '') $productName = (string)$prod->name;
        }

        // Felt for item
        $status  = (string)($payload['status'] ?? 'idea');
        if (!in_array($status, ['idea','reserved','purchased','given','cancelled'], true)) {
            throw new \InvalidArgumentException('invalid status', 422);
        }
        $notes   = isset($payload['notes']) ? trim((string)$payload['notes']) : null;
        $planned = isset($payload['planned_price'])  && $payload['planned_price']  !== '' ? (string)$payload['planned_price']  : null;
        $purch   = isset($payload['purchase_price']) && $payload['purchase_price'] !== '' ? (string)$payload['purchase_price'] : null;
        $ccy     = (isset($payload['currency_code']) && $payload['currency_code'] !== '') ? (string)$payload['currency_code'] : 'NOK';

        $id = Id::ulid();
        DB::table('gift_items')->insert([
            'id'              => $id,
            'order_id'        => $orderId,
            'product_id'      => $productId,
            'title'           => $productName, // tittel = produktnavn
            'notes'           => $notes,
            'status'          => $status,
            'planned_price'   => $planned,
            'purchase_price'  => $purch,
            'currency_code'   => $ccy,
            'purchased_at'    => null,
            'given_at'        => null,
            'created_by'      => $requesterUserId,
            'created_at'      => DB::raw('CURRENT_TIMESTAMP'),
            'updated_at'      => DB::raw('CURRENT_TIMESTAMP'),
        ]);

        return $id;
    }

    /** PATCH /gifts/{id} -> oppdater gift_item */
    public function update(string $requesterUserId, string $giftItemId, array $payload): void {
        $hid = Tenant::activeId($requesterUserId);
        Tenant::assertManagerOrSysAdmin($hid, $requesterUserId);

        $row = DB::table('gift_items as gi')
            ->join('gift_orders as o','o.id','=','gi.order_id')
            ->where('o.household_id',$hid)
            ->where('gi.id',$giftItemId)->select('gi.*')->first();
        if (!$row) throw new \UnexpectedValueException('Gift not found', 404);

        $upd = [];

        if (array_key_exists('product_id',$payload) && $payload['product_id'] !== $row->product_id) {
            $pid = (string)$payload['product_id'];
            if ($pid === '') throw new \InvalidArgumentException('product_id cannot be empty', 422);
            $prod = DB::table('products')->where('id',$pid)->first();
            if (!$prod || $prod->household_id !== $hid) {
                throw new \RuntimeException('Product not found in tenant', 403);
            }
            $upd['product_id'] = $pid;
            $upd['title']      = (string)$prod->name;
        }

        if (array_key_exists('product_name',$payload) && $payload['product_name'] !== '') {
            $newName = trim((string)$payload['product_name']);
            $upd['title'] = $newName;
            if (!empty($row->product_id)) {
                DB::table('products')->where('id',$row->product_id)->update([
                    'name'       => $newName,
                    'updated_at' => DB::raw('CURRENT_TIMESTAMP'),
                ]);
            }
        }

        if (array_key_exists('status',$payload)) {
            $st = (string)$payload['status'];
            if (!in_array($st, ['idea','reserved','purchased','given','cancelled'], true)) {
                throw new \InvalidArgumentException('invalid status', 422);
            }
            $upd['status'] = $st;
        }

        foreach (['notes'] as $f) {
            if (array_key_exists($f,$payload)) $upd[$f] = $payload[$f] !== null ? (string)$payload[$f] : null;
        }
        if (array_key_exists('currency_code',$payload)) {
            $upd['currency_code'] = ($payload['currency_code'] === '' || $payload['currency_code'] === null)
                ? ($row->currency_code ?: 'NOK')
                : (string)$payload['currency_code'];
        }
        if (array_key_exists('planned_price',$payload))
            $upd['planned_price']  = ($payload['planned_price']  === '' || $payload['planned_price']  === null) ? null : (string)$payload['planned_price'];
        if (array_key_exists('purchase_price',$payload))
            $upd['purchase_price'] = ($payload['purchase_price'] === '' || $payload['purchase_price'] === null) ? null : (string)$payload['purchase_price'];

        if (array_key_exists('purchased_at',$payload)) $upd['purchased_at'] = $payload['purchased_at'] ?: null;
        if (array_key_exists('given_at',$payload))     $upd['given_at']     = $payload['given_at'] ?: null;

        if ($upd) {
            $upd['updated_at'] = DB::raw('CURRENT_TIMESTAMP');
            DB::table('gift_items')->where('id',$giftItemId)->update($upd);
        }
    }

    /** DELETE /gifts/{id} -> slett gift_item */
    public function delete(string $requesterUserId, string $giftItemId): void {
        $hid = Tenant::activeId($requesterUserId);
        Tenant::assertManagerOrSysAdmin($hid, $requesterUserId);

        $deleted = DB::table('gift_items as gi')
            ->join('gift_orders as o','o.id','=','gi.order_id')
            ->where('o.household_id',$hid)
            ->where('gi.id',$giftItemId)
            ->delete();
        if ($deleted === 0) throw new \UnexpectedValueException('Gift not found', 404);
    }

    // ---- helpers ----

    private function findOrCreateProductByName(string $householdId, string $name): string {
        $existing = DB::table('products')
            ->where('household_id',$householdId)
            ->whereRaw('LOWER(name)=LOWER(?)', [$name])
            ->first();
        if ($existing) return (string)$existing->id;

        $id = Id::ulid();
        DB::table('products')->insert([
            'id'            => $id,
            'household_id'  => $householdId,
            'name'          => $name,
            'description'   => null,
            'url'           => null,
            'image_url'     => null,
            'default_price' => null,
            'currency_code' => 'NOK',
            'created_at'    => DB::raw('CURRENT_TIMESTAMP'),
            'updated_at'    => DB::raw('CURRENT_TIMESTAMP'),
        ]);
        return $id;
    }

	// I class GiftsModel
	public function listForProduct(string $requesterUserId, string $productId): array {
		$hid = \App\Model\Tenant\Tenant::activeId($requesterUserId);
		\App\Model\Tenant\Tenant::assertMembership($hid, $requesterUserId);

		$rows = \Illuminate\Database\Capsule\Manager::table('gift_items as gi')
			->join('gift_orders as o','o.id','=','gi.order_id')
			->where('o.household_id', $hid)
			->where('gi.product_id', $productId)
			->orderBy('gi.created_at', 'desc')
			->get();

		return $this->mapRows($rows);
	}


    private function mapRows($rows): array {
        return collect($rows)->map(fn($r) => $this->mapRow($r))->toArray();
    }

    private function mapRow($r): array {
        // Hent deltakere på ordren for display
        $parts = DB::table('gift_order_participants as p')
            ->join('users as u','u.id','=','p.user_id')
            ->where('p.order_id',$r->order_id ?? $r->id) // funker både m/ join select og getOne select
            ->get();

        $givers = [];
        $recips = [];
        foreach ($parts as $p) {
            $name = trim(($p->firstname ?? '').' '.($p->lastname ?? ''));
            $disp = $name !== '' ? $name : ($p->email ?? 'User');
            if ($p->role === 'giver') $givers[] = ['id'=>$p->user_id,'display_name'=>$disp];
            else $recips[] = ['id'=>$p->user_id,'display_name'=>$disp];
        }

        return [
            // item
            'id'              => $r->id,
            'order_id'        => $r->order_id,
            'event_id'        => $r->event_id ?? null,
            'product_id'      => $r->product_id ?? null,
            'title'           => $r->title,
            'notes'           => $r->notes,
            'status'          => $r->status,
            'planned_price'   => $r->planned_price,
            'purchase_price'  => $r->purchase_price,
            'currency_code'   => $r->currency_code,
            'purchased_at'    => $r->purchased_at,
            'given_at'        => $r->given_at,
            'created_at'      => $r->created_at,
            'updated_at'      => $r->updated_at,

            // deltakere
            'givers'          => $givers,
            'recipients'      => $recips,

            // syntetisk for gammel frontend (bruker “første”)
            'giver_user_id'     => $givers[0]['id']     ?? null,
            'recipient_user_id' => $recips[0]['id']     ?? null,
        ];
    }
}
