<?php
// src/backend/Model/Product/ProductsModel.php
namespace App\Model\Product;

use App\Model\Core\Database;
use App\Model\Utils\Id;
use App\Model\Tenant\Tenant;
use Illuminate\Database\Capsule\Manager as DB;

class ProductsModel
{
    public function __construct() { Database::init(); }

    /**
     * GET /products
     * Støtter søk via ?q= og begrensning via ?limit=
     */
    public function listProducts(string $requesterUserId): array {
        $hid = Tenant::activeId($requesterUserId);
        Tenant::assertMembership($hid, $requesterUserId);

        $q     = isset($_GET['q']) ? trim((string)$_GET['q']) : '';
        $limit = max(1, min(50, (int)($_GET['limit'] ?? 20)));

        $qb = DB::table('products')->where('household_id', $hid);
        if ($q !== '') {
            $like = '%'.strtolower($q).'%';
            $qb->whereRaw('LOWER(name) LIKE ?', [$like]);
        }

        $rows = $qb->orderBy('name')->limit($limit)->get();

        return collect($rows)->map(function ($r) {
            return [
                'id'            => $r->id,
                'name'          => $r->name,
                'description'   => $r->description,
                'url'           => $r->url,
                'image_url'     => $r->image_url,
                'default_price' => $r->default_price,
                'currency_code' => $r->currency_code,
            ];
        })->toArray();
    }

    /** POST /products */
    public function createProduct(string $requesterUserId, array $payload): string {
        $hid = Tenant::activeId($requesterUserId);
        Tenant::assertMembership($hid, $requesterUserId);

        $name = trim((string)($payload['name'] ?? ''));
        if ($name === '') throw new \InvalidArgumentException('name is required', 422);

        $desc   = isset($payload['description']) ? (string)$payload['description'] : null;
        $url    = isset($payload['url']) ? (string)$payload['url'] : null;
        $img    = isset($payload['image_url']) ? (string)$payload['image_url'] : null;
        $price  = isset($payload['default_price']) && $payload['default_price'] !== '' ? (string)$payload['default_price'] : null;
        $ccy    = (string)($payload['currency_code'] ?? 'NOK');

        $id = Id::ulid();
        DB::table('products')->insert([
            'id'            => $id,
            'household_id'  => $hid,
            'name'          => $name,
            'description'   => $desc,
            'url'           => $url,
            'image_url'     => $img,
            'default_price' => $price,
            'currency_code' => $ccy,
            'created_at'    => DB::raw('CURRENT_TIMESTAMP'),
            'updated_at'    => DB::raw('CURRENT_TIMESTAMP'),
        ]);

        return $id;
    }

    /** GET /products/{id} */
    public function getProduct(string $requesterUserId, string $productId): array {
        $hid = Tenant::activeId($requesterUserId);
        Tenant::assertMembership($hid, $requesterUserId);

        $r = DB::table('products')->where('household_id',$hid)->where('id',$productId)->first();
        if (!$r) throw new \UnexpectedValueException('Product not found', 404);

        return [
            'status' => 'success',
            'data' => [
                'id'            => $r->id,
                'name'          => $r->name,
                'description'   => $r->description,
                'url'           => $r->url,
                'image_url'     => $r->image_url,
                'default_price' => $r->default_price,
                'currency_code' => $r->currency_code,
            ]
        ];
    }

    /** PATCH /products/{id} */
    public function updateProduct(string $requesterUserId, string $productId, array $payload): void {
        $hid = Tenant::activeId($requesterUserId);
        Tenant::assertMembership($hid, $requesterUserId);

        $exists = DB::table('products')->where('household_id',$hid)->where('id',$productId)->exists();
        if (!$exists) throw new \UnexpectedValueException('Product not found', 404);

        $upd = [];
        if (array_key_exists('name', $payload)) {
            $n = trim((string)$payload['name']);
            if ($n === '') throw new \InvalidArgumentException('name cannot be empty', 422);
            $upd['name'] = $n;
        }
        foreach (['description','url','image_url','currency_code'] as $f) {
            if (array_key_exists($f, $payload)) $upd[$f] = $payload[$f] !== null ? (string)$payload[$f] : null;
        }
        if (array_key_exists('default_price', $payload)) {
            $v = $payload['default_price'];
            $upd['default_price'] = ($v === '' || $v === null) ? null : (string)$v;
        }

        if ($upd) {
            $upd['updated_at'] = DB::raw('CURRENT_TIMESTAMP');
            DB::table('products')->where('household_id',$hid)->where('id',$productId)->update($upd);
        }
    }

    /** DELETE /products/{id} */
    public function deleteProduct(string $requesterUserId, string $productId): void {
        $hid = Tenant::activeId($requesterUserId);
        Tenant::assertMembership($hid, $requesterUserId);

        $deleted = DB::table('products')->where('household_id',$hid)->where('id',$productId)->delete();
        if ($deleted === 0) throw new \UnexpectedValueException('Product not found', 404);
    }

    /** Helper for andre modeller */
    public function assertProductInHousehold(string $hid, string $productId): void {
        $ok = DB::table('products')->where('household_id',$hid)->where('id',$productId)->exists();
        if (!$ok) throw new \InvalidArgumentException('invalid product_id for this household', 422);
    }
}
