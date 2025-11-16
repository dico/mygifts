<?php
// src/backend/Model/Product/ProductsModel.php
namespace App\Model\Product;

use App\Model\Core\Database;
use App\Model\Utils\Id;
use App\Model\Tenant\Tenant;
use App\Model\Utils\UrlInspector;
use Illuminate\Database\Capsule\Manager as DB;

class ProductsModel
{
    public function __construct() { Database::init(); }

    private function intPriceString($v): ?string {
        if ($v === null || $v === '') return null;
        $norm = str_replace(',', '.', (string)$v);
        if (!is_numeric($norm)) return null;
        return (string) (int) round((float)$norm, 0);
    }

    /** Normaliser, valider og begrens lenker (max 3). Returnerer unike, gyldige URL-er. */
    private function normalizeLinks($raw): array
    {
        $out = [];
        $seen = [];
        foreach ((array)$raw as $url) {
            $n = UrlInspector::normalize((string)$url);
            if (!$n) continue;
            if (isset($seen[$n])) continue;
            $seen[$n] = true;
            $out[] = $n;
            if (count($out) >= 3) break;
        }
        return $out;
    }

    /**
     * Ekstraher og normaliser domenet fra en URL for duplikatdeteksjon.
     * Fjerner www. prefix og returnerer bare domenet (f.eks. "netonnet.no").
     */
    private function extractDomain(?string $url): ?string
    {
        if (!$url) return null;

        $parsed = parse_url($url);
        $host = $parsed['host'] ?? null;

        if (!$host) return null;

        // Fjern www. prefix for konsistent matching
        $normalized = preg_replace('/^www\./i', '', $host);

        return strtolower($normalized);
    }

    /**
     * Finn produkt basert på opprinnelig skrapet tittel og domene (for duplikatdeteksjon).
     * Matcher på source_title (ikke name) slik at vi finner produktet selv om brukeren har endret navnet.
     * Returnerer produktet hvis funnet, ellers null.
     */
    public function findByNameAndDomain(string $requesterUserId, string $name, string $url): ?array
    {
        $hid = Tenant::activeId($requesterUserId);
        Tenant::assertMembership($hid, $requesterUserId);

        $domain = $this->extractDomain($url);
        if (!$domain) return null;

        $normalizedTitle = trim(strtolower($name));
        if ($normalizedTitle === '') return null;

        // Søk etter produkt med matchende source_title og domene
        // source_title er den opprinnelige skrapede tittelen som aldri endres
        $r = DB::table('products')
            ->where('household_id', $hid)
            ->where('source_domain', $domain)
            ->whereRaw('LOWER(TRIM(source_title)) = ?', [$normalizedTitle])
            ->first();

        if (!$r) return null;

        $links = $this->fetchLinks((string)$r->id);

        return [
            'id'            => (string)$r->id,
            'name'          => (string)$r->name,  // returnerer brukerens redigerte navn
            'description'   => $r->description,
            'url'           => $r->url,
            'source_title'  => $r->source_title,
            'source_domain' => $r->source_domain,
            'image_url'     => $r->image_url,
            'default_price' => $this->intPriceString($r->default_price),
            'links'         => array_map(fn($x) => $x['url'], $links),
        ];
    }

    /** Skriv/overskriv product_links for et produkt. */
    private function persistLinks(string $productId, array $links): void
    {
        DB::table('product_links')->where('product_id', $productId)->delete();

        foreach ($links as $url) {
            $id   = Id::ulid();
            $name = UrlInspector::storeNameFromUrl($url);

            DB::table('product_links')->insert([
                'id'            => $id,
                'product_id'    => $productId,
                'store_name'    => $name,
                'url'           => $url,
                'price'         => null,
                'currency_code' => 'NOK',
                'is_active'     => 1,
                'last_checked_at'=> null,
                'created_at'    => DB::raw('CURRENT_TIMESTAMP'),
                'updated_at'    => DB::raw('CURRENT_TIMESTAMP'),
            ]);
        }
    }

    /** Hent alle lenker for en liste produkter i én query og grupper per produkt_id. */
    private function fetchLinksForProducts(array $productIds): array
    {
        if (empty($productIds)) return [];

        $rows = DB::table('product_links')
            ->whereIn('product_id', $productIds)
            ->orderBy('created_at', 'asc')
            ->get();

        $grouped = [];
        foreach ($rows as $r) {
            $pid = (string)$r->product_id;
            $grouped[$pid] ??= [];
            $grouped[$pid][] = [
                'id'         => (string)$r->id,
                'store_name' => (string)$r->store_name,
                'url'        => (string)$r->url,
                'is_active'  => (bool)$r->is_active,
                'price'      => isset($r->price) ? (string)$r->price : null,
                'currency'   => (string)($r->currency_code ?? 'NOK'),
            ];
        }
        return $grouped;
    }

    /** Hent alle lenker for ett produkt. */
    private function fetchLinks(string $productId): array
    {
        $rows = DB::table('product_links')
            ->where('product_id', $productId)
            ->orderBy('created_at', 'asc')
            ->get();

        $out = [];
        foreach ($rows as $r) {
            $out[] = [
                'id'         => (string)$r->id,
                'store_name' => (string)$r->store_name,
                'url'        => (string)$r->url,
                'is_active'  => (bool)$r->is_active,
                'price'      => isset($r->price) ? (string)$r->price : null,
                'currency'   => (string)($r->currency_code ?? 'NOK'),
            ];
        }
        return $out;
    }

    /** GET /products */
    public function listProducts(string $requesterUserId): array
    {
        $hid = Tenant::activeId($requesterUserId);
        Tenant::assertMembership($hid, $requesterUserId);

        $q     = isset($_GET['q']) ? trim((string)$_GET['q']) : '';
        $limit = max(1, min(50, (int)($_GET['limit'] ?? 20)));

        $qb = DB::table('products')->where('household_id', $hid);
        if ($q !== '') {
            $like = '%'.strtolower($q).'%';
            $qb->whereRaw('LOWER(name) LIKE ?', [$like]);
        }

        // Sorter etter sist endret/opprettet (nyeste først)
        $rows = $qb->orderBy('updated_at', 'desc')
                   ->orderBy('created_at', 'desc')
                   ->limit($limit)
                   ->get();

        // Unngå N+1: hent lenker for alle produktene i én runde
        $ids = array_map(fn($r) => (string)$r->id, iterator_to_array($rows));
        $linksGrouped = $this->fetchLinksForProducts($ids);

        $out = [];
        foreach ($rows as $r) {
            $pid   = (string)$r->id;
            $links = $linksGrouped[$pid] ?? [];

            // Primærlenke = første aktive; fallback til første uansett
            $primary = null;
            foreach ($links as $lnk) {
                if ($lnk['is_active']) { $primary = $lnk; break; }
            }
            if ($primary === null && !empty($links)) $primary = $links[0];

            $price = $this->intPriceString($r->default_price);

            $out[] = [
                'id'                 => $pid,
                'name'               => (string)$r->name,
                'description'        => $r->description,
                // behold toppnivå url for bakoverkompatibilitet:
                'url'                => $primary['url'] ?? (string)$r->url,
                'image_url'          => $r->image_url,
                'price'              => $price,
                'primary_store_name' => $primary
                    ? ($primary['store_name'] ?: UrlInspector::prettyDomain(parse_url($primary['url'], PHP_URL_HOST) ?: ''))
                    : ($r->url ? UrlInspector::storeNameFromUrl($r->url) : null),
                // NYTT: alle lenker i et array
                'links'              => $links,
            ];
        }

        return $out;
    }

    /** POST /products */
    public function createProduct(string $requesterUserId, array $payload): string
    {
        $hid = Tenant::activeId($requesterUserId);
        Tenant::assertMembership($hid, $requesterUserId);

        $name = trim((string)($payload['name'] ?? ''));
        if ($name === '') throw new \InvalidArgumentException('name is required', 422);

        $desc   = isset($payload['description']) ? (string)$payload['description'] : null;
        $img    = isset($payload['image_url']) ? (string)$payload['image_url'] : null;
        $price  = $this->intPriceString($payload['default_price'] ?? $payload['price'] ?? null);

        $links = $this->normalizeLinks($payload['links'] ?? []);
        if (empty($links) && !empty($payload['url'])) {
            $n = UrlInspector::normalize((string)$payload['url']);
            if ($n) $links[] = $n;
        }
        $primaryUrl = $links[0] ?? null;

        // Ekstraher domene og lagre opprinnelig tittel for duplikatdeteksjon
        $sourceDomain = $this->extractDomain($primaryUrl);
        // Bruk source_title fra frontend hvis den finnes, ellers bruk name
        $sourceTitle = isset($payload['source_title']) ? (string)$payload['source_title'] : $name;

        $id = Id::ulid();
        DB::table('products')->insert([
            'id'            => $id,
            'household_id'  => $hid,
            'name'          => $name,
            'description'   => $desc,
            'url'           => $primaryUrl, // speil primærlenke i products.url
            'source_title'  => $sourceTitle, // opprinnelig tittel for duplikatdeteksjon
            'source_domain' => $sourceDomain,
            'image_url'     => $img,
            'default_price' => $price,
            'currency_code' => 'NOK',
            'created_at'    => DB::raw('CURRENT_TIMESTAMP'),
            'updated_at'    => DB::raw('CURRENT_TIMESTAMP'),
        ]);

        if (!empty($links)) {
            $this->persistLinks($id, $links);
        }

        return $id;
    }

    /** GET /products/{id} */
    public function getProduct(string $requesterUserId, string $productId): array
    {
        $hid = Tenant::activeId($requesterUserId);
        Tenant::assertMembership($hid, $requesterUserId);

        $r = DB::table('products')->where('household_id',$hid)->where('id',$productId)->first();
        if (!$r) throw new \UnexpectedValueException('Product not found', 404);

        $links = $this->fetchLinks($productId);

        return [
            'status' => 'success',
            'data' => [
                'id'          => (string)$r->id,
                'name'        => (string)$r->name,
                'description' => $r->description,
                'url'         => $r->url,
                'image_url'   => $r->image_url,
                'price'       => $this->intPriceString($r->default_price),
                // eksponer begge varianter:
                'links'       => array_map(fn($x) => $x['url'], $links),
                'links_full'  => $links,
            ]
        ];
    }

    /** PATCH /products/{id} */
    public function updateProduct(string $requesterUserId, string $productId, array $payload): void
    {
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
        foreach (['description','image_url'] as $f) {
            if (array_key_exists($f, $payload)) {
                $upd[$f] = $payload[$f] !== null ? (string)$payload[$f] : null;
            }
        }
        if (array_key_exists('default_price', $payload) || array_key_exists('price', $payload)) {
            $v = $payload['default_price'] ?? $payload['price'] ?? null;
            $upd['default_price'] = $this->intPriceString($v);
        }

        $linksProvided = array_key_exists('links', $payload);
        $links = $linksProvided ? $this->normalizeLinks($payload['links']) : null;

        if ($linksProvided && empty($links) && !empty($payload['url'])) {
            $n = UrlInspector::normalize((string)$payload['url']);
            if ($n) $links = [$n];
        }

        if ($linksProvided) {
            $upd['url'] = $links[0] ?? null; // speil primærlenke
            $upd['source_domain'] = $this->extractDomain($links[0] ?? null);
        } elseif (array_key_exists('url', $payload)) {
            $upd['url'] = UrlInspector::normalize((string)$payload['url']);
            $upd['source_domain'] = $this->extractDomain($upd['url']);
        }

        if ($upd) {
            $upd['updated_at'] = DB::raw('CURRENT_TIMESTAMP');
            DB::table('products')->where('household_id',$hid)->where('id',$productId)->update($upd);
        }

        if ($linksProvided) {
            $this->persistLinks($productId, $links ?? []);
        }
    }

    /** DELETE /products/{id} */
    public function deleteProduct(string $requesterUserId, string $productId): void
    {
        $hid = Tenant::activeId($requesterUserId);
        Tenant::assertMembership($hid, $requesterUserId);

        $deleted = DB::table('products')->where('household_id',$hid)->where('id',$productId)->delete();
        if ($deleted === 0) throw new \UnexpectedValueException('Product not found', 404);
    }

    public function assertProductInHousehold(string $hid, string $productId): void
    {
        $ok = DB::table('products')->where('household_id',$hid)->where('id',$productId)->exists();
        if (!$ok) throw new \InvalidArgumentException('invalid product_id for this household', 422);
    }

    public function setImageUrl(string $requesterUserId, string $productId, ?string $url): void
    {
        $hid = Tenant::activeId($requesterUserId);
        Tenant::assertMembership($hid, $requesterUserId);

        DB::table('products')
            ->where('household_id', $hid)
            ->where('id', $productId)
            ->update([
                'image_url'  => $url,
                'updated_at' => DB::raw('CURRENT_TIMESTAMP'),
            ]);
    }

    public function uploadImage(string $requesterUserId, string $productId, ?array $file): array
    {
        $hid = Tenant::activeId($requesterUserId);
        Tenant::assertMembership($hid, $requesterUserId);

        $exists = DB::table('products')->where('household_id',$hid)->where('id',$productId)->exists();
        if (!$exists) throw new \UnexpectedValueException('Product not found', 404);

        if (!$file) throw new \InvalidArgumentException('Missing file', 400);
        if (!empty($file['error'])) {
            $errMap = [
                UPLOAD_ERR_INI_SIZE   => 'File too large (ini limit)',
                UPLOAD_ERR_FORM_SIZE  => 'File too large (form limit)',
                UPLOAD_ERR_PARTIAL    => 'Partial upload',
                UPLOAD_ERR_NO_FILE    => 'No file',
                UPLOAD_ERR_NO_TMP_DIR => 'Server missing temp dir',
                UPLOAD_ERR_CANT_WRITE => 'Server cannot write file',
                UPLOAD_ERR_EXTENSION  => 'Upload blocked by extension',
            ];
            $msg = $errMap[$file['error']] ?? 'Upload error';
            throw new \InvalidArgumentException($msg, 400);
        }

        $tmpPath = (string)($file['tmp_name'] ?? '');
        if (!is_uploaded_file($tmpPath)) throw new \InvalidArgumentException('Invalid upload', 400);

        $mime = (string)($file['type'] ?? '');
        $orig = (string)($file['name'] ?? '');
        $ext  = strtolower(pathinfo($orig, PATHINFO_EXTENSION));

        $allowed = [
            'jpg'  => 'image/jpeg',
            'jpeg' => 'image/jpeg',
            'png'  => 'image/png',
            'webp' => 'image/webp',
        ];
        if (!isset($allowed[$ext]) || ($mime && $allowed[$ext] !== $mime)) {
            $byMime = array_search($mime, $allowed, true);
            $ext = $byMime !== false ? $byMime : 'jpg';
        }

        $baseDir = '/var/www/html/public/upload/products';
        if (!is_dir($baseDir) && !mkdir($baseDir, 0775, true) && !is_dir($baseDir)) {
            throw new \RuntimeException('Could not create upload dir', 500);
        }
        $prodDir = $baseDir . '/' . $productId;
        if (!is_dir($prodDir) && !mkdir($prodDir, 0775, true) && !is_dir($prodDir)) {
            throw new \RuntimeException('Could not create upload dir', 500);
        }

        foreach (glob($prodDir . '/image.*') ?: [] as $old) { @unlink($old); }

        $destPath = $prodDir . '/image.' . $ext;
        if (!@move_uploaded_file($tmpPath, $destPath)) {
            throw new \RuntimeException('Failed to store uploaded file', 500);
        }
        @chmod($destPath, 0664);

        $publicUrl = "/upload/products/{$productId}/image.{$ext}";
        $this->setImageUrl($requesterUserId, $productId, $publicUrl);

        return ['url' => $publicUrl, 'filename' => "image.$ext"];
    }

    public function removeImage(string $requesterUserId, string $productId): void
    {
        $hid = Tenant::activeId($requesterUserId);
        Tenant::assertMembership($hid, $requesterUserId);

        $prodDir = "/var/www/html/public/upload/products/{$productId}";
        foreach (glob($prodDir . '/image.*') ?: [] as $p) { @unlink($p); }

        DB::table('products')
          ->where('household_id',$hid)
          ->where('id',$productId)
          ->update([
              'image_url'  => null,
              'updated_at' => DB::raw('CURRENT_TIMESTAMP'),
          ]);
    }
}
