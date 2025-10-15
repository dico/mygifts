<?php
// src/backend/Model/Wishlist/WishlistsModel.php
namespace App\Model\Wishlist;

use App\Model\Core\Database;
use App\Model\Tenant\Tenant;
use App\Model\Utils\Id;
use App\Model\Utils\UrlInspector;
use Illuminate\Database\Capsule\Manager as DB;

class WishlistsModel
{
    public function __construct() { Database::init(); }

    private function intPriceString($v): ?string {
        if ($v === null || $v === '') return null;
        $norm = str_replace(',', '.', (string)$v);
        if (!is_numeric($norm)) return null;
        return (string) (int) round((float)$norm, 0);
    }

    public function listHouseholdWishlists(string $requesterUserId, bool $includeEmpty = true): array {
        $hid = Tenant::activeId($requesterUserId);
        Tenant::assertMembership($hid, $requesterUserId);

        $members = DB::table('household_members as hm')
            ->join('users as u','u.id','=','hm.user_id')
            ->where('hm.household_id', $hid)
            ->where('hm.is_family_member', 1)
            ->select('u.id','u.firstname','u.lastname','u.email','u.profile_image_url')
            ->orderBy('u.firstname')->orderBy('u.lastname')
            ->get()
            ->map(function($u){
                $name = trim(($u->firstname ?? '').' '.($u->lastname ?? ''));
                return [
                    'id' => $u->id,
                    'display_name' => $name !== '' ? $name : ($u->email ?? 'User'),
                    'email' => $u->email,
                    'avatar' => $u->profile_image_url,
                ];
            })
            ->toArray();

        $items = DB::table('wishlist_items as w')
            ->leftJoin('products as p','p.id','=','w.product_id')
            ->where('w.household_id', $hid)
            ->where('w.is_active', 1)
            ->orderByRaw('CASE WHEN w.priority IS NULL THEN 1 ELSE 0 END, w.priority ASC')
            ->orderBy('w.created_at', 'asc')
            ->select([
                'w.id as wishlist_item_id',
                'w.recipient_user_id',
                'w.product_id',
                'w.url',
                'w.notes',
                'w.priority',
                'w.created_at',
                'w.updated_at',
                'p.name as product_name',
                'p.image_url as image_url',
                'p.default_price as default_price',
            ])
            ->get()
            ->map(function($w){
                // Fetch active product links with store_name
                $links = DB::table('product_links')
                    ->where('product_id', $w->product_id)
                    ->where('is_active', 1)
                    ->orderBy('created_at', 'asc')
                    ->select(['url','store_name','is_active'])
                    ->get()
                    ->map(fn($r) => [
                        'url' => (string)$r->url,
                        'store_name' => (string)($r->store_name ?? 'Link'),
                        'is_active' => (int)$r->is_active,
                    ])->toArray();

                $row = [
                    'id'                 => $w->wishlist_item_id,
                    'recipient_user_id'  => $w->recipient_user_id,
                    'product_id'         => $w->product_id,
                    'product_name'       => $w->product_name,
                    'image_url'          => $w->image_url ?: null,
                    'url'                => $w->url ?: null,
                    'links'              => $links,
                    'notes'              => $w->notes ?: null,
                    'priority'           => $w->priority,
                    'created_at'         => $w->created_at,
                    'updated_at'         => $w->updated_at,
                ];

                $price = $this->intPriceString($w->default_price);
                if ($price !== null) $row['price'] = $price;

                return $row;
            })->toArray();

        $byUser = [];
        foreach ($items as $it) $byUser[$it['recipient_user_id']][] = $it;

        $out = [];
        foreach ($members as $m) {
            $list = $byUser[$m['id']] ?? [];
            if ($includeEmpty || $list) $out[] = ['user' => $m, 'items' => $list];
        }
        return $out;
    }

    public function getOne(string $requesterUserId, string $wishlistItemId): array {
        $hid = Tenant::activeId($requesterUserId);
        Tenant::assertMembership($hid, $requesterUserId);

        $row = DB::table('wishlist_items as w')
            ->leftJoin('products as p','p.id','=','w.product_id')
            ->where('w.household_id', $hid)
            ->where('w.id', $wishlistItemId)
            ->select('w.*','p.name as product_name','p.default_price as default_price')
            ->first();

        if (!$row) throw new \UnexpectedValueException('Wishlist item not found', 404);

        $links = DB::table('product_links')
            ->where('product_id', $row->product_id)
            ->where('is_active', 1)
            ->orderBy('created_at','asc')
            ->select(['url','store_name','is_active'])
            ->get()
            ->map(fn($r) => [
                'url' => (string)$r->url,
                'store_name' => (string)($r->store_name ?? 'Link'),
                'is_active' => (int)$r->is_active,
            ])->toArray();

        $out = [
            'id'                 => $row->id,
            'household_id'       => $row->household_id,
            'recipient_user_id'  => $row->recipient_user_id,
            'product_id'         => $row->product_id,
            'product_name'       => $row->product_name,
            'url'                => $row->url,
            'links'              => $links,
            'notes'              => $row->notes,
            'priority'           => $row->priority,
            'is_active'          => (int)$row->is_active,
            'created_at'         => $row->created_at,
            'updated_at'         => $row->updated_at,
        ];

        $price = $this->intPriceString($row->default_price);
        if ($price !== null) $out['price'] = $price;

        return $out;
    }

    public function create(string $requesterUserId, array $payload): string {
        $hid = Tenant::activeId($requesterUserId);
        Tenant::assertManagerOrSysAdmin($hid, $requesterUserId);

        $recipientId = trim((string)($payload['recipient_user_id'] ?? ''));
        if ($recipientId === '') throw new \InvalidArgumentException('recipient_user_id is required', 422);

        $hm = DB::table('household_members')->where('household_id',$hid)->where('user_id',$recipientId)->first();
        if (!$hm) throw new \RuntimeException('Recipient not in household', 403);

        $productId   = trim((string)($payload['product_id'] ?? ''));
        $productName = trim((string)($payload['product_name'] ?? ''));
        if ($productId === '' && $productName === '') {
            throw new \InvalidArgumentException('product_id or product_name is required', 422);
        }

        $payloadPrice = $this->intPriceString($payload['default_price'] ?? null);

        if ($productId === '') {
            $productId = $this->findOrCreateProductByName($hid, $productName, [
                'url'           => $payload['url'] ?? null,
                'default_price' => $payloadPrice,
                'image_url'     => $payload['image_url'] ?? null,
            ]);
        } else {
            $prod = DB::table('products')->where('id',$productId)->first();
            if (!$prod || $prod->household_id !== $hid) throw new \RuntimeException('Product not found in tenant', 403);

            if ($payloadPrice !== null) {
                DB::table('products')->where('id',$productId)->update([
                    'default_price' => $payloadPrice,
                    'updated_at'    => DB::raw('CURRENT_TIMESTAMP'),
                ]);
            }
        }

        $id = Id::ulid();
        DB::table('wishlist_items')->insert([
            'id'                 => $id,
            'household_id'       => $hid,
            'recipient_user_id'  => $recipientId,
            'created_by_user_id' => $requesterUserId,
            'product_id'         => $productId,
            'url'                => isset($payload['url']) && $payload['url'] !== '' ? (string)$payload['url'] : null,
            'notes'              => isset($payload['notes']) && $payload['notes'] !== '' ? (string)$payload['notes'] : null,
            'priority'           => isset($payload['priority']) && $payload['priority'] !== '' ? (int)$payload['priority'] : null,
            'is_active'          => 1,
            'created_at'         => DB::raw('CURRENT_TIMESTAMP'),
            'updated_at'         => DB::raw('CURRENT_TIMESTAMP'),
        ]);

        // Multiple links (normalize + store_name)
        $links = array_values(array_filter((array)($payload['links'] ?? []), fn($u) => is_string($u) && trim($u) !== ''));
        if ($links) {
            $now = DB::raw('CURRENT_TIMESTAMP');
            foreach ($links as $u) {
                $norm = UrlInspector::normalize($u);
                if ($norm === null) continue;
                $store = UrlInspector::storeNameFromUrl($norm);
                DB::table('product_links')->updateOrInsert(
                    ['product_id' => $productId, 'url' => $norm],
                    [
                        'id' => Id::ulid(),
                        'store_name' => $store,
                        'price' => null,
                        'currency_code' => 'NOK',
                        'is_active' => 1,
                        'last_checked_at' => null,
                        'created_at' => $now,
                        'updated_at' => $now
                    ]
                );
            }
        }

        return $id;
    }

    public function update(string $requesterUserId, string $wishlistItemId, array $payload): void {
        $hid = Tenant::activeId($requesterUserId);
        Tenant::assertManagerOrSysAdmin($hid, $requesterUserId);

        $row = DB::table('wishlist_items')->where('household_id',$hid)->where('id',$wishlistItemId)->first();
        if (!$row) throw new \UnexpectedValueException('Wishlist item not found', 404);

        $upd = [];

        if (array_key_exists('product_id',$payload)) {
            $pid = trim((string)$payload['product_id']);
            if ($pid === '') throw new \InvalidArgumentException('product_id cannot be empty', 422);
            $prod = DB::table('products')->where('id',$pid)->first();
            if (!$prod || $prod->household_id !== $hid) throw new \RuntimeException('Product not found in tenant', 403);
            $upd['product_id'] = $pid;
        }
        if (array_key_exists('product_name',$payload) && trim((string)$payload['product_name']) !== '') {
            $newName = trim((string)$payload['product_name']);
            if (!empty($row->product_id)) {
                DB::table('products')->where('id',$row->product_id)->update([
                    'name' => $newName,
                    'updated_at' => DB::raw('CURRENT_TIMESTAMP'),
                ]);
            }
        }

        foreach (['url','notes'] as $f) {
            if (array_key_exists($f,$payload)) {
                $v = $payload[$f];
                $upd[$f] = ($v === '' || $v === null) ? null : (string)$v;
            }
        }
        if (array_key_exists('priority',$payload)) {
            $upd['priority'] = ($payload['priority'] === '' || $payload['priority'] === null) ? null : (int)$payload['priority'];
        }
        if (array_key_exists('is_active',$payload)) {
            $upd['is_active'] = (int)((bool)$payload['is_active']);
        }

        if ($upd) {
            $upd['updated_at'] = DB::raw('CURRENT_TIMESTAMP');
            DB::table('wishlist_items')->where('id',$wishlistItemId)->update($upd);
        }

        // Update product price if provided (integer)
        if (array_key_exists('default_price',$payload)) {
            $pidForUpdate = $upd['product_id'] ?? $row->product_id;
            if ($pidForUpdate) {
                $intPrice = $this->intPriceString($payload['default_price']);
                DB::table('products')->where('id',$pidForUpdate)->update([
                    'default_price' => $intPrice,
                    'updated_at'    => DB::raw('CURRENT_TIMESTAMP'),
                ]);
            }
        }

        // Update links (normalize + store_name)
        if (array_key_exists('links', $payload)) {
            $pid = $upd['product_id'] ?? $row->product_id;
            $new = array_values(array_filter((array)$payload['links'], fn($u) => is_string($u) && trim($u) !== ''));
            DB::table('product_links')->where('product_id',$pid)->update([
                'is_active' => 0,
                'updated_at' => DB::raw('CURRENT_TIMESTAMP'),
            ]);
            $now = DB::raw('CURRENT_TIMESTAMP');
            foreach ($new as $u) {
                $norm = UrlInspector::normalize($u);
                if ($norm === null) continue;
                $store = UrlInspector::storeNameFromUrl($norm);
                DB::table('product_links')->updateOrInsert(
                    ['product_id' => $pid, 'url' => $norm],
                    [
                        'id' => Id::ulid(),
                        'store_name' => $store,
                        'price' => null,
                        'currency_code' => 'NOK',
                        'is_active' => 1,
                        'last_checked_at' => null,
                        'created_at' => $now,
                        'updated_at' => $now
                    ]
                );
            }
        }
    }

    public function delete(string $requesterUserId, string $wishlistItemId): void {
        $hid = Tenant::activeId($requesterUserId);
        Tenant::assertManagerOrSysAdmin($hid, $requesterUserId);

        $deleted = DB::table('wishlist_items')->where('household_id',$hid)->where('id',$wishlistItemId)->delete();
        if ($deleted === 0) throw new \UnexpectedValueException('Wishlist item not found', 404);
    }

    private function findOrCreateProductByName(string $householdId, string $name, array $extra = []): string {
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
            'url'           => $extra['url'] ?? null,
            'image_url'     => $extra['image_url'] ?? null,
            'default_price' => $this->intPriceString($extra['default_price'] ?? null),
            'currency_code' => 'NOK',
            'created_at'    => DB::raw('CURRENT_TIMESTAMP'),
            'updated_at'    => DB::raw('CURRENT_TIMESTAMP'),
        ]);
        return $id;
    }
}
