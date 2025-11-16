<?php
namespace App\Model\User;

use App\Model\Core\Database;
use App\Model\Utils\Id;
use App\Model\Tenant\Tenant;
use Illuminate\Database\Capsule\Manager as DB;

class UsersModel
{
    public function __construct()
    {
        Database::init();
    }

    /** Små-helper: trim + tom streng => NULL */
    private function nvl($v): ?string
    {
        if (!isset($v)) return null;
        $v = trim((string)$v);
        return ($v === '') ? null : $v;
    }

    /** Lag visningsnavn fra fn/ln/email (fallback) */
    private function displayName(?string $firstname, ?string $lastname, ?string $email): string
    {
        $fn = trim((string)$firstname);
        $ln = trim((string)$lastname);
        $disp = trim($fn . ' ' . $ln);
        if ($disp === '' && !empty($email)) $disp = (string)$email;
        return $disp === '' ? 'User' : $disp;
    }

    /** Lag initialer (maks 2 tegn) fra navn/e-post */
    private function initials(?string $firstname, ?string $lastname, ?string $email): string
    {
        $fn = trim((string)$firstname);
        $ln = trim((string)$lastname);

        $take = function (?string $s): string {
            $s = trim((string)$s);
            if ($s === '') return '';
            return mb_strtoupper(mb_substr($s, 0, 1, 'UTF-8'), 'UTF-8');
        };

        $a = $take($fn);
        $b = $take($ln);

        if ($a !== '' || $b !== '') {
            return $a . $b;
        }

        if (!empty($email)) {
            $local = preg_replace('/@.*$/', '', (string)$email);
            $parts = preg_split('/[.\s_\-]+/', $local ?: '', -1, PREG_SPLIT_NO_EMPTY) ?: [];
            $letters = array_slice(array_map($take, $parts), 0, 2);
            $res = implode('', $letters);
            if ($res !== '') return $res;
        }

        return '•';
    }

    /** Sjekk om requester er manager i aktiv tenant */
    private function requesterIsManager(string $hid, string $requesterUserId): bool
    {
        $hm = DB::table('household_members')
            ->where('household_id', $hid)
            ->where('user_id', $requesterUserId)
            ->select('is_manager')
            ->first();

        return (bool)($hm->is_manager ?? 0);
    }

    /** List alle brukere i requester sin aktive tenant. */
    public function listUsers(string $requesterUserId): array
    {
        $hid = Tenant::activeId($requesterUserId);
        Tenant::assertMembership($hid, $requesterUserId);

        $rows = DB::table('household_members as hm')
            ->join('users as u', 'u.id', '=', 'hm.user_id')
            ->where('hm.household_id', $hid)
            ->orderBy('u.firstname', 'asc')
            ->orderBy('u.lastname', 'asc')
            ->select(
                'u.id','u.firstname','u.lastname','u.display_name','u.email','u.mobile',
                'u.profile_image_url',
                'hm.is_family_member','hm.is_manager'
            )
            ->get();

        return $rows->map(function ($r) {
            // velg eksplisitt display_name hvis satt, ellers fallback
            $explicit = trim((string)($r->display_name ?? ''));
            $fallback = $this->displayName($r->firstname ?? '', $r->lastname ?? '', $r->email ?? null);
            $display  = $explicit !== '' ? $explicit : $fallback;

            // initialer fra display_name (to første tokens)
            $initialsFromDisplay = function (string $name): string {
                $parts = preg_split('/\s+/', trim($name)) ?: [];
                $take  = fn($s) => $s !== '' ? mb_strtoupper(mb_substr($s, 0, 1)) : '';
                if (count($parts) === 0) return '•';
                $a = $take($parts[0]);
                $b = $take($parts[1] ?? '');
                return ($a.$b) !== '' ? ($a.$b) : '•';
            };

            return [
                'id'                 => (string)$r->id,
                'display_name'       => $display,
                'firstname'          => $r->firstname,
                'lastname'           => $r->lastname,
                'email'              => $r->email,
                'mobile'             => $r->mobile,
                'profile_image_url'  => $r->profile_image_url,
                'initials'           => $initialsFromDisplay($display),
                'is_family_member'   => (bool)$r->is_family_member,
                'is_manager'         => (bool)$r->is_manager,
            ];
        })->toArray();

    }

    /** Opprett bruker globalt og knytt til aktiv tenant (upsert membership). */
    public function createUser(string $requesterUserId, array $payload): string
    {
        $hid = Tenant::activeId($requesterUserId);
        Tenant::assertManagerOrSysAdmin($hid, $requesterUserId);

        $firstname = trim((string)($payload['firstname'] ?? ''));
        $lastname  = trim((string)($payload['lastname']  ?? ''));
        $display   = $this->nvl($payload['display_name'] ?? null);
        $email     = $this->nvl($payload['email']  ?? null);
        $mobile    = $this->nvl($payload['mobile'] ?? null);
        $isFamily  = (int)($payload['is_family_member'] ?? 1);
        $isManager = (int)($payload['is_manager'] ?? 0);

        if ($firstname === '' || $lastname === '') {
            throw new \InvalidArgumentException('firstname and lastname are required', 422);
        }
        if (!in_array($isFamily, [0,1], true) || !in_array($isManager, [0,1], true)) {
            throw new \InvalidArgumentException('Invalid flags', 422);
        }

        // Reuse by email if exists
        $userId = null;
        if ($email !== null) {
            $existing = DB::table('users')->where('email', $email)->first();
            if ($existing) {
                $userId = (string)$existing->id;
                $upd = [];
                if ($existing->firstname !== $firstname) $upd['firstname'] = $firstname;
                if ($existing->lastname  !== $lastname)  $upd['lastname']  = $lastname;
                if ($mobile !== null && $existing->mobile !== $mobile) $upd['mobile'] = $mobile;
                if ($display !== null && trim((string)$existing->display_name) !== $display) $upd['display_name'] = $display;
                if ($upd) {
                    $upd['updated_at'] = DB::raw('CURRENT_TIMESTAMP');
                    DB::table('users')->where('id', $userId)->update($upd);
                }
            }
        }

        if (!$userId) {
            $userId = Id::ulid();
            $displayInsert = $display ?? trim($firstname.' '.$lastname);
            DB::table('users')->insert([
                'id'                  => $userId,
                'firstname'           => $firstname,
                'lastname'            => $lastname,
                'display_name'        => $displayInsert,
                'email'               => $email,
                'mobile'              => $mobile,
                'profile_image_url'   => null,
                'can_login'           => 0,
                'password_hash'       => null,
                'is_active'           => 1,
                'is_admin'            => 0,
                'active_household_id' => null,
                'created_at'          => DB::raw('CURRENT_TIMESTAMP'),
                'updated_at'          => DB::raw('CURRENT_TIMESTAMP'),
            ]);
        }

        // Upsert membership
        $exists = DB::table('household_members')
            ->where('household_id', $hid)
            ->where('user_id', $userId)
            ->exists();

        if ($exists) {
            DB::table('household_members')
                ->where('household_id', $hid)
                ->where('user_id', $userId)
                ->update([
                    'is_family_member' => $isFamily,
                    'is_manager'       => $isManager,
                    'updated_at'       => DB::raw('CURRENT_TIMESTAMP'),
                ]);
        } else {
            DB::table('household_members')->insert([
                'household_id'     => $hid,
                'user_id'          => $userId,
                'is_family_member' => $isFamily,
                'is_manager'       => $isManager,
                'created_at'       => DB::raw('CURRENT_TIMESTAMP'),
                'updated_at'       => DB::raw('CURRENT_TIMESTAMP'),
            ]);
        }

        return $userId;
    }

    /** Hent én bruker fra aktiv tenant. */
    public function getUser(string $requesterUserId, string $userId): array
    {
        $hid = Tenant::activeId($requesterUserId);
        Tenant::assertMembership($hid, $requesterUserId);

        $row = DB::table('household_members as hm')
            ->join('users as u', 'u.id', '=', 'hm.user_id')
            ->where('hm.household_id', $hid)
            ->where('u.id', $userId)
            ->select(
                'u.id','u.firstname','u.lastname','u.display_name','u.email','u.mobile',
                'u.profile_image_url',
                'hm.is_family_member','hm.is_manager'
            )
            ->first();

        if (!$row) throw new \UnexpectedValueException('User not found in tenant', 404);

        $explicit = trim((string)($row->display_name ?? ''));
        $display  = $explicit !== '' ? $explicit : $this->displayName($row->firstname ?? '', $row->lastname ?? '', $row->email ?? null);

        return [
            'id'                 => (string)$row->id,
            'display_name'       => $display,
            'firstname'          => $row->firstname,
            'lastname'           => $row->lastname,
            'email'              => $row->email,
            'mobile'             => $row->mobile,
            'profile_image_url'  => $row->profile_image_url,
            'initials'           => $this->initials($row->firstname ?? '', $row->lastname ?? '', $row->email ?? null),
            'is_family_member'   => (bool)$row->is_family_member,
            'is_manager'         => (bool)$row->is_manager,
        ];
    }

    /** Oppdater brukerfelt / medlemskapsflagg i aktiv tenant. */
    public function updateUser(string $requesterUserId, string $userId, array $payload): void
    {
        $hid = Tenant::activeId($requesterUserId);
        Tenant::assertManagerOrSysAdmin($hid, $requesterUserId);

        $inTenant = DB::table('household_members')
            ->where('household_id', $hid)
            ->where('user_id', $userId)
            ->exists();
        if (!$inTenant) throw new \UnexpectedValueException('User not in tenant', 404);

        $updUser = [];
        if (array_key_exists('firstname', $payload)) {
            $v = trim((string)$payload['firstname']);
            if ($v === '') throw new \InvalidArgumentException('firstname cannot be empty', 422);
            $updUser['firstname'] = $v;
        }
        if (array_key_exists('lastname', $payload)) {
            $v = trim((string)$payload['lastname']);
            if ($v === '') throw new \InvalidArgumentException('lastname cannot be empty', 422);
            $updUser['lastname'] = $v;
        }
        if (array_key_exists('display_name', $payload)) {
            $updUser['display_name'] = $this->nvl($payload['display_name']); // tom streng => NULL
        }
        if (array_key_exists('email', $payload)) {
            $newEmail = $this->nvl($payload['email']);
            // Check if email is already in use by another user
            if ($newEmail !== null) {
                $existingUser = DB::table('users')
                    ->where('email', $newEmail)
                    ->where('id', '!=', $userId)
                    ->first();
                if ($existingUser) {
                    throw new \InvalidArgumentException(
                        "E-postadressen '{$newEmail}' er allerede i bruk av en annen bruker. " .
                        "Vennligst bruk en annen e-postadresse, eller slett duplikatet fra People-listen først.",
                        409
                    );
                }
            }
            $updUser['email'] = $newEmail;
        }
        if (array_key_exists('mobile', $payload)) {
            $updUser['mobile'] = $this->nvl($payload['mobile']);
        }

        if ($updUser) {
            $updUser['updated_at'] = DB::raw('CURRENT_TIMESTAMP');
            DB::table('users')->where('id', $userId)->update($updUser);
        }

        $updHm = [];
        if (array_key_exists('is_family_member', $payload)) {
            $v = (int)$payload['is_family_member'];
            if (!in_array($v, [0,1], true)) throw new \InvalidArgumentException('Invalid is_family_member', 422);
            $updHm['is_family_member'] = $v;
        }
        if (array_key_exists('is_manager', $payload)) {
            $v = (int)$payload['is_manager'];
            if (!in_array($v, [0,1], true)) throw new \InvalidArgumentException('Invalid is_manager', 422);
            $updHm['is_manager'] = $v;
        }
        if ($updHm) {
            $updHm['updated_at'] = DB::raw('CURRENT_TIMESTAMP');
            DB::table('household_members')
                ->where('household_id', $hid)
                ->where('user_id', $userId)
                ->update($updHm);
        }
    }

    /** Frakoble en bruker fra aktiv tenant (sletter ikke global user-rad). */
    public function removeUser(string $requesterUserId, string $userId): void
    {
        $hid = Tenant::activeId($requesterUserId);
        Tenant::assertManagerOrSysAdmin($hid, $requesterUserId);

        $deleted = DB::table('household_members')
            ->where('household_id', $hid)
            ->where('user_id', $userId)
            ->delete();

        if ($deleted === 0) {
            throw new \UnexpectedValueException('User not in tenant', 404);
        }
    }

    /** Sett profilbilde-URL etter opplasting */
    public function setProfileImageUrl(string $requesterUserId, string $userId, string $url): void
    {
        $hid = Tenant::activeId($requesterUserId);
        Tenant::assertManagerOrSysAdmin($hid, $requesterUserId);

        $inTenant = DB::table('household_members')
            ->where('household_id', $hid)
            ->where('user_id', $userId)
            ->exists();
        if (!$inTenant) {
            throw new \UnexpectedValueException('User not in tenant', 404);
        }

        DB::table('users')
            ->where('id', $userId)
            ->update([
                'profile_image_url' => $url,
                'updated_at'        => DB::raw('CURRENT_TIMESTAMP'),
            ]);
    }

    /**
     * Last opp avatar (flyttet hit fra controller).
     * Returnerer ['url' => ..., 'filename' => ...]
     */
    public function uploadAvatar(string $requesterUserId, string $userId, ?array $file): array
    {
        $hid = Tenant::activeId($requesterUserId);
        // Må være medlem av tenant for å se/endre bruker
        $this->getUser($requesterUserId, $userId);

        // Policy: manager eller eier selv
        $isManager = $this->requesterIsManager($hid, $requesterUserId);
        if (!$isManager && $requesterUserId !== $userId) {
            throw new \RuntimeException('Forbidden', 403);
        }

        if (!$file) {
            throw new \InvalidArgumentException('Missing file', 400);
        }
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
        if (!is_uploaded_file($tmpPath)) {
            throw new \InvalidArgumentException('Invalid upload', 400);
        }

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

        $baseDir = '/var/www/html/public/upload/users';
        if (!is_dir($baseDir) && !mkdir($baseDir, 0775, true) && !is_dir($baseDir)) {
            throw new \RuntimeException('Could not create upload dir', 500);
        }

        $userDir = $baseDir . '/' . $userId;
        if (!is_dir($userDir) && !mkdir($userDir, 0775, true) && !is_dir($userDir)) {
            throw new \RuntimeException('Could not create upload dir', 500);
        }

        foreach (glob($userDir . '/avatar.*') ?: [] as $old) {
            @unlink($old);
        }

        $destPath = $userDir . '/avatar.' . $ext;
        if (!@move_uploaded_file($tmpPath, $destPath)) {
            throw new \RuntimeException('Failed to store uploaded file', 500);
        }
        @chmod($destPath, 0664);

        $publicUrl = "/upload/users/{$userId}/avatar.{$ext}";
        $this->setProfileImageUrl($requesterUserId, $userId, $publicUrl);

        return ['url' => $publicUrl, 'filename' => "avatar.$ext"];
    }

    /** Fjern avatar (flyttet hit fra controller) */
    public function removeAvatar(string $requesterUserId, string $userId): void
    {
        $hid = Tenant::activeId($requesterUserId);
        // må minst være medlem for å referere til brukeren
        $this->getUser($requesterUserId, $userId);

        // Policy: manager eller eier selv
        $isManager = $this->requesterIsManager($hid, $requesterUserId);
        if (!$isManager && $requesterUserId !== $userId) {
            throw new \RuntimeException('Forbidden', 403);
        }

        $userDir = "/var/www/html/public/upload/users/{$userId}";
        foreach (glob($userDir . '/avatar.*') ?: [] as $p) { @unlink($p); }

        DB::table('users')
            ->where('id', $userId)
            ->update([
                'profile_image_url' => null,
                'updated_at'        => DB::raw('CURRENT_TIMESTAMP'),
            ]);
    }
}
