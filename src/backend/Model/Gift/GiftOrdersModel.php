<?php
// src/public/app/src/backend/Model/Gift/GiftOrdersModel.php
namespace App\Model\Gift;

use App\Model\Core\Database;
use App\Model\Utils\Id;
use App\Model\Tenant\Tenant;
use Illuminate\Database\Capsule\Manager as DB;

class GiftOrdersModel
{
    public function __construct() { Database::init(); }

    // --- HJELPERE ---
    private function statusClass(string $s): string {
        return match ($s) {
            'idea'      => 'text-bg-secondary',
            'reserved'  => 'text-bg-warning',
            'purchased' => 'text-bg-primary',
            'given'     => 'text-bg-success',
            'cancelled' => 'text-bg-dark',
            default     => 'text-bg-light',
        };
    }

    private function userDisplay($u): string {
		$explicit = trim((string)($u['display_name'] ?? ''));
		if ($explicit !== '') return $explicit;

		$first = trim((string)($u['firstname'] ?? ''));
		$last  = trim((string)($u['lastname'] ?? ''));
		return trim($first.' '.$last); // kan bli '' hvis begge er tomme – det er OK
	}


    private function withDerivedUser($u): array {
        $disp = $this->userDisplay($u);
        return [
            'id'               => (string)($u['id'] ?? ''),
            'display_name'     => $disp,
            'email'            => $u['email'] ?? null,
            'profile_image_url'=> $u['profile_image_url'] ?? null,
            'initials'         => $u['initials'] ?? mb_strtoupper(mb_substr($disp, 0, 2)),
            'is_family_member' => !empty($u['is_family_member']),
        ];
    }

    /**
     * Returnerer:
     * {
     *   "give":     [ { user:{...}, gifts:[...]} ],
     *   "received": [ { user:{...}, gifts:[...]} ]
     * }
     *
     * Regler:
     *  - GIVE:      (ingen givere) ELLER (minst én giver er is_family_member=1)
     *  - RECEIVED:  (minst én mottaker er is_family_member=1)
     */
    public function listGroupedForEvent(string $requesterUserId, string $eventId): array {
		$hid = Tenant::activeId($requesterUserId);
		Tenant::assertMembership($hid, $requesterUserId);

		// 1) Ordrer for event
		$orders = DB::table('gift_orders as o')
			->leftJoin('products as p', 'p.id', '=', 'o.product_id')
			->where('o.household_id', $hid)
			->where('o.event_id', $eventId)
			->orderBy('o.created_at', 'asc')
			->select('o.*','p.name as product_name','p.image_url as product_image_url')
			->get();

		if ($orders->isEmpty()) {
			return [
				'give' => [], 'received' => [],
				'give_grouped' => [], 'received_grouped' => [],
			];
		}

		$orderIds = array_map(fn($r)=>(string)$r->id, iterator_to_array($orders));

		// 2) Deltakere (inkl. users.display_name)
		$parts = DB::table('gift_order_participants as gp')
			->join('users as u', 'u.id', '=', 'gp.user_id')
			->leftJoin('household_members as hm', function($j) use ($hid) {
				$j->on('hm.user_id','=','u.id')->where('hm.household_id','=',$hid);
			})
			->whereIn('gp.order_id', $orderIds)
			->select(
				'gp.order_id','gp.role',
				'u.id as uid','u.firstname','u.lastname','u.display_name','u.email','u.profile_image_url',
				DB::raw('CASE WHEN hm.is_family_member=1 THEN 1 ELSE 0 END as is_family_member')
			)
			->orderBy('gp.created_at','asc')
			->get();

		$byOrder = [];
		foreach ($parts as $p) {
			$oid  = (string)$p->order_id;
			$role = (string)$p->role; // giver|recipient
			$byOrder[$oid] ??= ['givers'=>[], 'recipients'=>[]];

			// display_name → fornavn+etternavn → epost → 'User'
			$explicit = trim((string)($p->display_name ?? ''));
			$fnln     = trim(trim((string)$p->firstname).' '.trim((string)$p->lastname));
			$disp     = $explicit !== '' ? $explicit : ($fnln !== '' ? $fnln : ((string)($p->email ?? '') ?: 'User'));

			$byOrder[$oid][$role === 'giver' ? 'givers' : 'recipients'][] = [
				'id'                => (string)$p->uid,
				'display_name'      => $disp,
				'email'             => $p->email ?: null,
				'profile_image_url' => $p->profile_image_url ?: null,
				'is_family_member'  => ((int)$p->is_family_member)===1,
			];
		}

		// 3) Flat gaveliste + fane-tilhørighet
		$giftsWeGive = [];
		$giftsWeReceived = [];

		foreach ($orders as $r) {
			$oid = (string)$r->id;
			$gvs = array_map(fn($u) => $this->withDerivedUser($u), $byOrder[$oid]['givers'] ?? []);
			$rcs = array_map(fn($u) => $this->withDerivedUser($u), $byOrder[$oid]['recipients'] ?? []);

			$gift = [
				'id'                => $oid,
				'order_id'          => $oid,
				'event_id'          => (string)$r->event_id,
				'title'             => $r->title,
				'product_id'        => $r->product_id ? (string)$r->product_id : null,
				'product_name'      => $r->product_name ?? null,
				'product_image_url' => $r->product_image_url ?? null,
				'image_url'         => $r->product_image_url ?? null,
				'price'             => isset($r->price) ? (string)$r->price : null,
				'status'            => (string)$r->status,
				'notes'             => $r->notes,
				'purchased_at'      => $r->purchased_at,
				'given_at'          => $r->given_at,
				'givers'            => $gvs,
				'recipients'        => $rcs,
				'givers_display'    => implode(', ', array_filter(array_map(fn($u)=>$u['display_name'] ?? '', $gvs))),
				'recipients_display'=> implode(', ', array_filter(array_map(fn($u)=>$u['display_name'] ?? '', $rcs))),
				'status_class'      => $this->statusClass((string)$r->status),
			];

			$hasAnyGivers       = !empty($gvs);
			$hasFamilyGiver     = array_reduce($gvs, fn($c,$u)=> $c || !empty($u['is_family_member']), false);
			$hasFamilyRecipient = array_reduce($rcs, fn($c,$u)=> $c || !empty($u['is_family_member']), false);

			$inGive     = (!$hasAnyGivers) || $hasFamilyGiver;
			$inReceived = $hasFamilyRecipient;

			if ($inGive)     $giftsWeGive[]     = $gift;
			if ($inReceived) $giftsWeReceived[] = $gift;
		}

		// 4) Grupper per person (uendret, bruker display_name)
		$group = function(array $gifts, string $role) {
			$map = [];
			foreach ($gifts as $g) {
				$arr = $role === 'recipient' ? ($g['recipients'] ?? []) : ($g['givers'] ?? []);
				if (empty($arr)) {
					$arr = [[
						'id' => '__unknown__',
						'display_name' => $role === 'recipient' ? 'Other recipients' : 'Other givers',
						'profile_image_url'=> null,
					]];
				}
				foreach ($arr as $pick) {
					$pid = $pick['id'] ?? '__unknown__';
					$user = [
						'id' => $pid,
						'display_name' => (string)($pick['display_name'] ?? ''),
						'profile_image_url' => $pick['profile_image_url'] ?? null,
						'initials' => strtoupper(substr((string)($pick['display_name'] ?? ''), 0, 2)) ?: ($role === 'recipient' ? 'OR' : 'OG'),
					];
					if (!isset($map[$pid])) {
						if ($pid === '__unknown__' && $user['display_name'] === '') {
							$user['display_name'] = $role === 'recipient' ? 'Other recipients' : 'Other givers';
						}
						$map[$pid] = ['user' => $user, 'gifts' => []];
					}
					// unngå duplikat per person
					$exists = false;
					foreach ($map[$pid]['gifts'] as $ex) {
						if (($ex['order_id'] ?? null) === ($g['order_id'] ?? null)) { $exists = true; break; }
					}
					if (!$exists) $map[$pid]['gifts'][] = $g;
				}
			}
			$list = array_values($map);
			usort($list, fn($a,$b)=>strcasecmp($a['user']['display_name'] ?? '', $b['user']['display_name'] ?? ''));

			$statusOrder = ['idea'=>1,'reserved'=>2,'purchased'=>3,'given'=>4,'cancelled'=>9];
			foreach ($list as &$grp) {
				usort($grp['gifts'], function($x,$y) use ($statusOrder) {
					$sx = $statusOrder[$x['status']] ?? 99;
					$sy = $statusOrder[$y['status']] ?? 99;
					if ($sx !== $sy) return $sx - $sy;
					return strcasecmp($x['title'] ?? $x['product_name'] ?? '', $y['title'] ?? $y['product_name'] ?? '');
				});
			}
			unset($grp);
			return $list;
		};

		// 5) Grupper per *sett* av personer (ingen short_display_name)
		$groupBySet = function(array $gifts, string $role) {
			$map = [];

			foreach ($gifts as $g) {
				$people = $role === 'recipient' ? ($g['recipients'] ?? []) : ($g['givers'] ?? []);
				if (empty($people)) {
					$key = 'set:__unknown__';
					$map[$key] ??= [
						'user' => [
							'id'              => $key,
							'display_name'    => $role === 'recipient' ? 'Other recipients' : 'Other givers',
							'initials'        => $role === 'recipient' ? 'OR' : 'OG',
							'members'         => [],
						],
						'gifts' => [],
					];
					$map[$key]['gifts'][$g['order_id']] = $g;
					continue;
				}

				// stabil nøkkel
				$ids = array_map(fn($u)=>(string)($u['id'] ?? ''), $people);
				sort($ids, SORT_STRING);
				$key = 'set:'.implode('|', $ids);

				// label direkte fra display_name
				$names = array_map(fn($u)=>(string)($u['display_name'] ?? ''), $people);
				$label = implode(' og ', array_filter($names, fn($s)=>$s !== ''));

				$members = array_map(function($u) {
					$dn = (string)($u['display_name'] ?? '');
					return [
						'id'                => (string)($u['id'] ?? ''),
						'display_name'      => $dn,
						'profile_image_url' => $u['profile_image_url'] ?? null,
						'initials'          => strtoupper(mb_substr($dn !== '' ? $dn : 'U', 0, 2)),
					];
				}, $people);

				if (!isset($map[$key])) {
					$map[$key] = [
						'user' => [
							'id'              => $key,
							'display_name'    => $label,
							'initials'        => strtoupper(mb_substr($label !== '' ? $label : 'U', 0, 2)),
							'members'         => $members,
						],
						'gifts' => [],
					];
				}
				$map[$key]['gifts'][$g['order_id']] = $g;
			}

			// Flatten + sorter
			$list = array_values(array_map(function($grp){
				$grp['gifts'] = array_values($grp['gifts']); return $grp;
			}, $map));

			usort($list, fn($a,$b)=>strcasecmp($a['user']['display_name'] ?? '', $b['user']['display_name'] ?? ''));

			$statusOrder = ['idea'=>1,'reserved'=>2,'purchased'=>3,'given'=>4,'cancelled'=>9];
			foreach ($list as &$grp) {
				usort($grp['gifts'], function($x,$y) use ($statusOrder) {
					$sx = $statusOrder[$x['status']] ?? 99;
					$sy = $statusOrder[$y['status']] ?? 99;
					if ($sx !== $sy) return $sx - $sy;
					return strcasecmp($x['title'] ?? $x['product_name'] ?? '', $y['title'] ?? $y['product_name'] ?? '');
				});
			}
			unset($grp);

			return $list;
		};

		// 6) Returnér begge varianter
		return [
			'give'             => $group($giftsWeGive, 'recipient'),
			'received'         => $group($giftsWeReceived, 'recipient'),
			'give_grouped'     => $groupBySet($giftsWeGive, 'recipient'),
			'received_grouped' => $groupBySet($giftsWeReceived, 'recipient'),
		];
	}









    public function listByProduct(string $requesterUserId, string $productId): array {
        $hid = \App\Model\Tenant\Tenant::activeId($requesterUserId);
        \App\Model\Tenant\Tenant::assertMembership($hid, $requesterUserId);

        // 1) Orders with this product
        $orders = DB::table('gift_orders as o')
            ->leftJoin('events as e','e.id','=','o.event_id')
            ->leftJoin('products as p','p.id','=','o.product_id')
            ->where('o.household_id',$hid)
            ->where('o.product_id',$productId)
            ->orderBy('o.created_at','desc')
            ->select(
                'o.*',
                'e.name as event_name',
                'p.name as product_name',
                'p.image_url as product_image_url'
            )
            ->get();

        if ($orders->isEmpty()) return ['rows'=>[]];

        $orderIds = array_map(fn($r)=>(string)$r->id, iterator_to_array($orders));

        // 2) Participants for these orders
        $parts = DB::table('gift_order_participants as gp')
            ->join('users as u','u.id','=','gp.user_id')
            ->leftJoin('household_members as hm', function($j) use ($hid){
                $j->on('hm.user_id','=','u.id')->where('hm.household_id','=',$hid);
            })
            ->whereIn('gp.order_id',$orderIds)
            ->select(
                'gp.order_id','gp.role',
                'u.id as uid','u.firstname','u.lastname','u.email','u.profile_image_url',
                DB::raw('CASE WHEN hm.is_family_member=1 THEN 1 ELSE 0 END as is_family_member')
            )
            ->orderBy('gp.created_at','asc')
            ->get();

        $byOrder = [];
        foreach ($parts as $p) {
            $oid = (string)$p->order_id;
            $role = (string)$p->role;
            $byOrder[$oid] ??= ['givers'=>[], 'recipients'=>[]];
            $byOrder[$oid][$role==='giver'?'givers':'recipients'][] = [
                'id'               => (string)$p->uid,
                'display_name'     => trim(($p->firstname??'').' '.($p->lastname??'')) ?: ((string)$p->email ?: 'User'),
                'email'            => $p->email ?: null,
                'profile_image_url'=> $p->profile_image_url ?: null,
                'is_family_member' => ((int)$p->is_family_member)===1,
            ];
        }

        // 3) Format compact rows for modal
        $rows = [];
        foreach ($orders as $o) {
            $oid = (string)$o->id;
            $givers = $byOrder[$oid]['givers'] ?? [];
            $recips = $byOrder[$oid]['recipients'] ?? [];
            $rows[] = [
                'order_id'           => $oid,
                'event'              => ['id'=>(string)$o->event_id, 'name'=>$o->event_name ?? '—'],
                'product'            => [
                    'id'   => $o->product_id ? (string)$o->product_id : null,
                    'name' => $o->product_name ?? null,
                    'image_url' => $o->product_image_url ?? null,
                ],
                'title'              => $o->title,
                'status'             => (string)$o->status,
                'price'              => isset($o->price) ? (string)$o->price : null,
                'notes'              => $o->notes,
                'givers'             => $givers,
                'recipients'         => $recips,
                'givers_display'     => implode(', ', array_map(fn($u)=>$u['display_name'],$givers)),
                'recipients_display' => implode(', ', array_map(fn($u)=>$u['display_name'],$recips)),
            ];
        }

        return ['rows'=>$rows];
    }

    public function listReceivedByUser(string $requesterUserId, string $userId): array {
        $hid = \App\Model\Tenant\Tenant::activeId($requesterUserId);
        \App\Model\Tenant\Tenant::assertMembership($hid, $requesterUserId);

        // 1) Orders where this user is a recipient
        $orders = DB::table('gift_orders as o')
            ->join('gift_order_participants as gp', function($j) use ($userId){
                $j->on('gp.order_id','=','o.id')->where('gp.role','=','recipient')->where('gp.user_id','=',$userId);
            })
            ->leftJoin('events as e','e.id','=','o.event_id')
            ->leftJoin('products as p','p.id','=','o.product_id')
            ->where('o.household_id',$hid)
            ->orderBy('o.created_at','desc')
            ->select(
                'o.*',
                'e.name as event_name',
                'p.name as product_name',
                'p.image_url as product_image_url'
            )
            ->get();

        if ($orders->isEmpty()) return ['user_id'=>$userId,'rows'=>[]];

        $orderIds = array_map(fn($r)=>(string)$r->id, iterator_to_array($orders));

        // 2) Participants for these orders
        $parts = DB::table('gift_order_participants as gp')
            ->join('users as u','u.id','=','gp.user_id')
            ->leftJoin('household_members as hm', function($j) use ($hid){
                $j->on('hm.user_id','=','u.id')->where('hm.household_id','=',$hid);
            })
            ->whereIn('gp.order_id',$orderIds)
            ->select(
                'gp.order_id','gp.role',
                'u.id as uid','u.firstname','u.lastname','u.email','u.profile_image_url',
                DB::raw('CASE WHEN hm.is_family_member=1 THEN 1 ELSE 0 END as is_family_member')
            )
            ->orderBy('gp.created_at','asc')
            ->get();

        $byOrder = [];
        foreach ($parts as $p) {
            $oid = (string)$p->order_id;
            $role = (string)$p->role;
            $byOrder[$oid] ??= ['givers'=>[], 'recipients'=>[]];
            $byOrder[$oid][$role==='giver'?'givers':'recipients'][] = [
                'id'               => (string)$p->uid,
                'display_name'     => trim(($p->firstname??'').' '.($p->lastname??'')) ?: ((string)$p->email ?: 'User'),
                'email'            => $p->email ?: null,
                'profile_image_url'=> $p->profile_image_url ?: null,
                'is_family_member' => ((int)$p->is_family_member)===1,
            ];
        }

        // 3) Rows for modal
        $rows = [];
        foreach ($orders as $o) {
            $oid = (string)$o->id;
            $givers = $byOrder[$oid]['givers'] ?? [];
            $recips = $byOrder[$oid]['recipients'] ?? [];
            $rows[] = [
                'order_id'           => $oid,
                'event'              => ['id'=>(string)$o->event_id, 'name'=>$o->event_name ?? '—'],
                'product'            => [
                    'id'   => $o->product_id ? (string)$o->product_id : null,
                    'name' => $o->product_name ?? null,
                    'image_url' => $o->product_image_url ?? null,
                ],
                'title'              => $o->title,
                'status'             => (string)$o->status,
                'price'              => isset($o->price) ? (string)$o->price : null,
                'notes'              => $o->notes,
                'givers'             => $givers,
                'recipients'         => $recips,
                'givers_display'     => implode(', ', array_map(fn($u)=>$u['display_name'],$givers)),
                'recipients_display' => implode(', ', array_map(fn($u)=>$u['display_name'],$recips)),
            ];
        }

        return ['user_id'=>$userId,'rows'=>$rows];
    }

    public function getOne(string $requesterUserId, string $orderId): array {
        $hid = Tenant::activeId($requesterUserId);
        Tenant::assertMembership($hid, $requesterUserId);

        $r = DB::table('gift_orders as o')
            ->leftJoin('products as p', 'p.id', '=', 'o.product_id')
            ->where('o.household_id',$hid)
            ->where('o.id',$orderId)
            ->select('o.*', 'p.name as product_name', 'p.image_url as product_image_url')
            ->first();

        if (!$r) throw new \UnexpectedValueException('Gift order not found', 404);
        return $this->hydrateOrder($r);
    }

    // Opprett ordre (én rad = én gave/plan)
    public function create(string $requesterUserId, array $payload): string {
        $hid = Tenant::activeId($requesterUserId);
        Tenant::assertManagerOrSysAdmin($hid, $requesterUserId);

        return DB::connection()->transaction(function() use ($hid, $requesterUserId, $payload) {
            $eventId   = isset($payload['event_id']) ? (string)$payload['event_id'] : null;
            $orderType = (string)($payload['order_type'] ?? ($payload['direction'] ?? 'outgoing'));
            if (!in_array($orderType, ['outgoing','incoming'], true)) {
                throw new \InvalidArgumentException('invalid order_type', 422);
            }

            if ($eventId) {
                $ev = DB::table('events')->where('id',$eventId)->first();
                if (!$ev || $ev->household_id !== $hid) {
                    throw new \RuntimeException('Event not found in tenant', 403);
                }
            }

            // Tillat tom på én side, men begge kan ikke være tomme
            $giverIds = array_values(array_filter((array)($payload['giver_user_ids'] ?? [])));
            $recipIds = array_values(array_filter((array)($payload['recipient_user_ids'] ?? [])));
            if (empty($giverIds) && empty($recipIds)) {
                throw new \InvalidArgumentException('at least one of giver_user_ids or recipient_user_ids is required', 422);
            }

            // ---- Produkt: bruk id hvis gitt, ellers opprett/finne via product_name ----
            $productId = isset($payload['product_id']) && $payload['product_id'] !== '' ? (string)$payload['product_id'] : null;
            if (!$productId) {
                // Support both product_name and product_name_new (from quick-add modal)
                $pname = trim((string)($payload['product_name_new'] ?? $payload['product_name'] ?? ''));
                if ($pname !== '') {
                    // Opprett eller finn eksisterende (case-insensitivt innenfor husholdning)
                    $productId = $this->findOrCreateProductByName($hid, $pname);
                }
            }

            // Hvis vi endte med et productId, verifisér at det tilhører husholdningen
            if ($productId) {
                $prod = DB::table('products')->where('id', $productId)->first();
                if (!$prod || $prod->household_id !== $hid) {
                    throw new \RuntimeException('Product not found in tenant', 403);
                }

                // Håndter photo upload hvis produktet ble opprettet og vi har en fil
                if (isset($payload['photo']) && is_array($payload['photo'])) {
                    $productsModel = new \App\Model\Product\ProductsModel();
                    try {
                        $productsModel->uploadImage($requesterUserId, $productId, $payload['photo']);
                    } catch (\Throwable $e) {
                        error_log('[GiftOrdersModel.create] Photo upload failed: '.$e->getMessage());
                        // Continue even if photo upload fails
                    }
                }
            }

            $status = (string)($payload['status'] ?? 'idea');
            if (!in_array($status, ['idea','reserved','purchased','given','received','cancelled'], true)) {
                throw new \InvalidArgumentException('invalid status', 422);
            }

            // Pris: bruk payload hvis spesifisert, ellers default fra produkt (om finnes)
            $price = null;
            if (array_key_exists('price', $payload) && $payload['price'] !== '' && $payload['price'] !== null) {
                $price = (string)$payload['price'];
            } elseif ($productId) {
                $dp = DB::table('products')->where('id', $productId)->value('default_price');
                if ($dp !== null) $price = (string)$dp;
            }

            $orderId = Id::ulid();
            DB::table('gift_orders')->insert([
                'id'           => $orderId,
                'household_id' => $hid,
                'event_id'     => $eventId,
                'title'        => isset($payload['title']) ? (string)$payload['title'] : null,
                'order_type'   => $orderType,
                'product_id'   => $productId,
                'price'        => $price,
                'status'       => $status,
                'notes'        => isset($payload['notes']) ? (string)$payload['notes'] : null,
                'purchased_at' => $payload['purchased_at'] ?? null,
                'given_at'     => $payload['given_at'] ?? null,
                'created_by'   => $requesterUserId,
                'created_at'   => DB::raw('CURRENT_TIMESTAMP'),
                'updated_at'   => DB::raw('CURRENT_TIMESTAMP'),
            ]);

            $now = DB::raw('CURRENT_TIMESTAMP');
            foreach ($giverIds as $uid) {
                DB::table('gift_order_participants')->insert([
                    'order_id' => $orderId, 'user_id' => (string)$uid, 'role' => 'giver', 'created_at' => $now
                ]);
            }
            foreach ($recipIds as $uid) {
                DB::table('gift_order_participants')->insert([
                    'order_id' => $orderId, 'user_id' => (string)$uid, 'role' => 'recipient', 'created_at' => $now
                ]);
            }

            return $orderId;
        });
    }

    public function update(string $requesterUserId, string $orderId, array $payload): void {
        $hid = Tenant::activeId($requesterUserId);
        Tenant::assertManagerOrSysAdmin($hid, $requesterUserId);

        $row = DB::table('gift_orders')
            ->where('household_id', $hid)
            ->where('id', $orderId)
            ->first();

        if (!$row) {
            throw new \UnexpectedValueException('Gift order not found', 404);
        }

        $upd = [];

        // order_type / direction (beholdt for kompatibilitet)
        if (array_key_exists('order_type', $payload) || array_key_exists('direction', $payload)) {
            $dir = (string)($payload['order_type'] ?? $payload['direction']);
            if (!in_array($dir, ['outgoing','incoming'], true)) {
                throw new \InvalidArgumentException('invalid order_type', 422);
            }
            $upd['order_type'] = $dir;
        }

        // event_id (null => fjern kobling, '' => behold)
        if (array_key_exists('event_id', $payload)) {
            $ev = $payload['event_id'];
            if ($ev === null) {
                $upd['event_id'] = null;
            } elseif ($ev !== '') {
                $evr = DB::table('events')->where('id', (string)$ev)->first();
                if (!$evr || $evr->household_id !== $hid) {
                    throw new \RuntimeException('Event not found in tenant', 403);
                }
                $upd['event_id'] = (string)$ev;
            }
        }

        // ---------- PRODUKT (støtt både id og navn) ----------
        // Prioritet:
        //   1) product_id hvis satt og ikke ''/null
        //   2) product_name hvis satt og ikke blankt -> finn/lag produkt
        //   3) tomt product_id og tomt/blankt product_name => nullstill
        $wantsProductChange =
            array_key_exists('product_id', $payload) ||
            array_key_exists('product_name', $payload);

        if ($wantsProductChange) {
            $desiredPid = null;

            // case 1: eksplisitt product_id
            if (array_key_exists('product_id', $payload)) {
                $pid = $payload['product_id'];
                if ($pid === null) {
                    $desiredPid = null;
                } elseif ($pid !== '') {
                    $pid = (string)$pid;
                    $prod = DB::table('products')->where('id', $pid)->first();
                    if (!$prod || $prod->household_id !== $hid) {
                        throw new \RuntimeException('Product not found in tenant', 403);
                    }
                    $desiredPid = $pid;
                }
            }

            // case 2: hvis ingen gyldig id ble valgt, men navn er sendt og ikke blankt
            if ($desiredPid === null && array_key_exists('product_name', $payload)) {
                $pn = trim((string)($payload['product_name'] ?? ''));
                if ($pn !== '') {
                    $desiredPid = $this->findOrCreateProductByName($hid, $pn);
                }
            }

            // sett feltet
            $upd['product_id'] = $desiredPid;

            // hvis pris ikke eksplisitt patchet, og vi har et produkt med default_price → bruk det
            if (!array_key_exists('price', $payload) && $desiredPid) {
                $dp = DB::table('products')->where('id', $desiredPid)->value('default_price');
                if ($dp !== null) {
                    $upd['price'] = (string)$dp;
                }
            }
        }

        // Tekstfelter
        if (array_key_exists('notes', $payload)) {
            $upd['notes'] = $payload['notes'] !== null ? (string)$payload['notes'] : null;
        }
        if (array_key_exists('title', $payload)) {
            $upd['title'] = $payload['title'] !== null ? trim((string)$payload['title']) : null;
        }

        // status
        if (array_key_exists('status', $payload)) {
            $st = (string)$payload['status'];
            if (!in_array($st, ['idea','reserved','purchased','given','cancelled'], true)) {
                throw new \InvalidArgumentException('invalid status', 422);
            }
            $upd['status'] = $st;
        }

        // pris (valgfri – tom streng/null = null)
        if (array_key_exists('price', $payload)) {
            $upd['price'] = ($payload['price'] === '' || $payload['price'] === null)
                ? null
                : (string)$payload['price'];
        }

        // datoer
        if (array_key_exists('purchased_at', $payload)) {
            $upd['purchased_at'] = $payload['purchased_at'] ?: null;
        }
        if (array_key_exists('given_at', $payload)) {
            $upd['given_at'] = $payload['given_at'] ?: null;
        }

        // Skriv hovedendringer
        if ($upd) {
            $upd['updated_at'] = DB::raw('CURRENT_TIMESTAMP');
            DB::table('gift_orders')->where('id', $orderId)->update($upd);
        }

        // ---------- DELTAKERE ----------
        // Full reset dersom en av listene følger med i payload
        if (array_key_exists('giver_user_ids', $payload) || array_key_exists('recipient_user_ids', $payload)) {
            DB::table('gift_order_participants')->where('order_id', $orderId)->delete();
            $now = DB::raw('CURRENT_TIMESTAMP');

            foreach ((array)($payload['giver_user_ids'] ?? []) as $uid) {
                DB::table('gift_order_participants')->insert([
                    'order_id'  => $orderId,
                    'user_id'   => (string)$uid,
                    'role'      => 'giver',
                    'created_at'=> $now,
                ]);
            }
            foreach ((array)($payload['recipient_user_ids'] ?? []) as $uid) {
                DB::table('gift_order_participants')->insert([
                    'order_id'  => $orderId,
                    'user_id'   => (string)$uid,
                    'role'      => 'recipient',
                    'created_at'=> $now,
                ]);
            }
        }
    }

    public function destroy(string $requesterUserId, string $orderId): void {
        $hid = Tenant::activeId($requesterUserId);
        Tenant::assertManagerOrSysAdmin($hid, $requesterUserId);

        $deleted = DB::table('gift_orders')->where('household_id',$hid)->where('id',$orderId)->delete();
        if ($deleted === 0) throw new \UnexpectedValueException('Gift order not found', 404);
    }

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

    private function hydrateOrder($r): array {
        $parts = DB::table('gift_order_participants as p')
            ->join('users as u','u.id','=','p.user_id')
            ->where('p.order_id', $r->id)
            ->get();

        $givers = []; $recips = [];
        foreach ($parts as $p) {
            $u = [
                'id'           => (string)$p->user_id,
                'firstname'    => (string)($p->firstname ?? ''),
                'lastname'     => (string)($p->lastname ?? ''),
                'email'        => $p->email !== null ? (string)$p->email : null,
                'display_name' => $this->displayName([
                    'firstname'=>(string)($p->firstname ?? ''),
                    'lastname' =>(string)($p->lastname ?? ''),
                    'email'    =>$p->email !== null ? (string)$p->email : null
                ]),
            ];
            if ($p->role === 'giver') $givers[] = $u; else $recips[] = $u;
        }

        return [
            'id'                => (string)$r->id,
            'event_id'          => (string)$r->event_id,
            'title'             => $r->title,
            'order_type'        => (string)$r->order_type,
            'notes'             => $r->notes,
            'status'            => (string)$r->status,
            'product_id'        => $r->product_id !== null ? (string)$r->product_id : null,
            'product_name'      => $r->product_name ?? null,
            'product_image_url' => $r->product_image_url ?? null,
            'price'             => isset($r->price) ? (string)$r->price : null,
            'purchased_at'      => $r->purchased_at,
            'given_at'          => $r->given_at,
            'givers'            => $givers,
            'recipients'        => $recips,
            'items'             => [], // bakoverkompat
            'created_at'        => $r->created_at,
            'updated_at'        => $r->updated_at,
        ];
    }

    /* ================================ Helpers ================================ */

    private function fetchFamilyIds(string $householdId): array
    {
        $rows = DB::table('household_members')
            ->where('household_id', $householdId)
            ->where('is_family_member', 1)
            ->select('user_id')
            ->get();

        return array_map(fn($r) => (string)$r->user_id, iterator_to_array($rows));
    }

    private function displayName(array $u): string
    {
        $fn = trim((string)($u['firstname'] ?? ''));
        $ln = trim((string)($u['lastname'] ?? ''));
        $nm = trim($fn.' '.$ln);
        if ($nm !== '') return $nm;
        $email = (string)($u['email'] ?? '');
        return $email !== '' ? $email : 'User';
    }

    private function namesCsv(array $users): string
    {
        $names = [];
        foreach ($users as $u) {
            $d = trim((string)($u['display_name'] ?? ''));
            if ($d !== '') $names[] = $d;
        }
        return implode(', ', $names);
    }

    /** Returner en "gruppe-bruker" for headeren i UI-et (inkl. fallback) */
    private function groupUserPayload(string $id, array $userMap, string $roleForFallback): array
    {
        if ($id !== '__unknown__' && isset($userMap[$id])) {
            $u = $userMap[$id];
            // UI bruker display_name + profile_image_url (+ evt. initials i templaten)
            return [
                'id'                => $u['id'],
                'display_name'      => $u['display_name'],
                'profile_image_url' => $u['profile_image_url'] ?? null,
                'initials'          => strtoupper(substr($u['display_name'], 0, 2)),
            ];
        }
        $label = $roleForFallback === 'recipient' ? 'Other recipients' : 'Other givers';
        return [
            'id'                => '__unknown__',
            'display_name'      => $label,
            'profile_image_url' => null,
            'initials'          => $roleForFallback === 'recipient' ? 'OR' : 'OG',
        ];
    }

    /** Formater én gave slik templaten forventer felt */
    private function giftForTemplate(array $g): array
    {
        // status_class lages her for å slippe FE-duplicat
        $statusClass = match ($g['status']) {
            'idea'      => 'text-bg-secondary',
            'reserved'  => 'text-bg-warning',
            'purchased' => 'text-bg-primary',
            'given'     => 'text-bg-success',
            'cancelled' => 'text-bg-dark',
            default     => 'text-bg-light',
        };

        return [
            'id'                 => $g['id'],
            'order_id'           => $g['order_id'],
            'event_id'           => $g['event_id'],
            'product_id'         => $g['product_id'],
            'product_name'       => $g['product_name'],
            'image_url'          => $g['image_url'],
            'title'              => $g['title'] ?? ($g['product_name'] ?? '—'),
            'notes'              => $g['notes'],
            'status'             => $g['status'],
            'price'              => $g['price'],
            'status_class'       => $statusClass,
            'givers'             => $g['givers'],
            'recipients'         => $g['recipients'],
            'givers_display'     => $g['givers_display'],
            'recipients_display' => $g['recipients_display'],
        ];
    }

    /** Sorter grupper alfabetisk + sortér gaver etter status+title */
    private function sortGroups(array $groups): array
    {
        // sort group headers
        usort($groups, function($a, $b) {
            return strcmp(
                (string)($a['user']['display_name'] ?? ''),
                (string)($b['user']['display_name'] ?? '')
            );
        });

        // sort gifts inside groups
        $statusOrder = ['idea'=>1,'reserved'=>2,'purchased'=>3,'given'=>4,'cancelled'=>9];
        foreach ($groups as &$grp) {
            usort($grp['gifts'], function($a, $b) use ($statusOrder) {
                $sa = $statusOrder[$a['status']] ?? 99;
                $sb = $statusOrder[$b['status']] ?? 99;
                if ($sa !== $sb) return $sa - $sb;
                return strcmp(strtolower($a['title'] ?? ''), strtolower($b['title'] ?? ''));
            });
        }
        unset($grp);

        return $groups;
    }
}
