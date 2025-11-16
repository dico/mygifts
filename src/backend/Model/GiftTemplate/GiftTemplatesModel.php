<?php
// src/backend/Model/GiftTemplate/GiftTemplatesModel.php
namespace App\Model\GiftTemplate;

use App\Model\Core\Database;
use App\Model\Utils\Id;
use App\Model\Tenant\Tenant;
use Illuminate\Database\Capsule\Manager as DB;

class GiftTemplatesModel
{
    public function __construct() { Database::init(); }

    /** GET /gift-templates */
    public function listTemplates(string $requesterUserId): array
    {
        $hid = Tenant::activeId($requesterUserId);
        Tenant::assertMembership($hid, $requesterUserId);

        $templates = DB::table('gift_templates')
            ->where('household_id', $hid)
            ->orderBy('name')
            ->get();

        $out = [];
        foreach ($templates as $t) {
            $itemCount = DB::table('gift_template_items')
                ->where('template_id', $t->id)
                ->count();

            $out[] = [
                'id'          => (string)$t->id,
                'name'        => (string)$t->name,
                'description' => $t->description,
                'item_count'  => (int)$itemCount,
                'created_at'  => $t->created_at,
                'updated_at'  => $t->updated_at,
            ];
        }

        return $out;
    }

    /** GET /gift-templates/{id} */
    public function getTemplate(string $requesterUserId, string $templateId): array
    {
        $hid = Tenant::activeId($requesterUserId);
        Tenant::assertMembership($hid, $requesterUserId);

        $template = DB::table('gift_templates')
            ->where('household_id', $hid)
            ->where('id', $templateId)
            ->first();

        if (!$template) {
            throw new \UnexpectedValueException('Template not found', 404);
        }

        // Fetch template items
        $items = DB::table('gift_template_items as gti')
            ->where('gti.template_id', $templateId)
            ->select(['gti.id', 'gti.notes'])
            ->get();

        // Fetch all participants for these items
        $itemIds = $items->pluck('id')->toArray();
        $participants = [];
        if (!empty($itemIds)) {
            $participantRows = DB::table('gift_template_item_participants as gtip')
                ->leftJoin('users as u', 'gtip.user_id', '=', 'u.id')
                ->whereIn('gtip.item_id', $itemIds)
                ->select([
                    'gtip.item_id',
                    'gtip.user_id',
                    'gtip.role',
                    'u.display_name',
                    'u.profile_image_url',
                ])
                ->get();

            foreach ($participantRows as $p) {
                $participants[(string)$p->item_id][] = [
                    'user_id' => (string)$p->user_id,
                    'role' => (string)$p->role,
                    'display_name' => (string)$p->display_name,
                    'profile_image_url' => $p->profile_image_url,
                ];
            }
        }

        // Build items array with grouped participants
        $itemsArray = [];
        foreach ($items as $item) {
            $itemId = (string)$item->id;
            $itemParticipants = $participants[$itemId] ?? [];

            $givers = array_filter($itemParticipants, fn($p) => $p['role'] === 'giver');
            $recipients = array_filter($itemParticipants, fn($p) => $p['role'] === 'recipient');

            $itemsArray[] = [
                'id' => $itemId,
                'notes' => $item->notes,
                'givers' => array_values($givers),
                'recipients' => array_values($recipients),
            ];
        }

        return [
            'template' => [
                'id'          => (string)$template->id,
                'name'        => (string)$template->name,
                'description' => $template->description,
                'created_at'  => $template->created_at,
                'updated_at'  => $template->updated_at,
            ],
            'items' => $itemsArray,
        ];
    }

    /** POST /gift-templates */
    public function createTemplate(string $requesterUserId, array $payload): string
    {
        $hid = Tenant::activeId($requesterUserId);
        Tenant::assertMembership($hid, $requesterUserId);

        $name = trim((string)($payload['name'] ?? ''));
        if ($name === '') {
            throw new \InvalidArgumentException('name is required', 422);
        }

        $desc = isset($payload['description']) ? (string)$payload['description'] : null;

        $id = Id::ulid();
        DB::table('gift_templates')->insert([
            'id'           => $id,
            'household_id' => $hid,
            'name'         => $name,
            'description'  => $desc,
            'created_by'   => $requesterUserId,
            'created_at'   => DB::raw('CURRENT_TIMESTAMP'),
            'updated_at'   => DB::raw('CURRENT_TIMESTAMP'),
        ]);

        return $id;
    }

    /** PATCH /gift-templates/{id} */
    public function updateTemplate(string $requesterUserId, string $templateId, array $payload): void
    {
        $hid = Tenant::activeId($requesterUserId);
        Tenant::assertMembership($hid, $requesterUserId);

        $exists = DB::table('gift_templates')
            ->where('household_id', $hid)
            ->where('id', $templateId)
            ->exists();

        if (!$exists) {
            throw new \UnexpectedValueException('Template not found', 404);
        }

        $upd = [];
        if (array_key_exists('name', $payload)) {
            $n = trim((string)$payload['name']);
            if ($n === '') {
                throw new \InvalidArgumentException('name cannot be empty', 422);
            }
            $upd['name'] = $n;
        }

        if (array_key_exists('description', $payload)) {
            $upd['description'] = $payload['description'] !== null ? (string)$payload['description'] : null;
        }

        if ($upd) {
            $upd['updated_at'] = DB::raw('CURRENT_TIMESTAMP');
            DB::table('gift_templates')
                ->where('household_id', $hid)
                ->where('id', $templateId)
                ->update($upd);
        }
    }

    /** DELETE /gift-templates/{id} */
    public function deleteTemplate(string $requesterUserId, string $templateId): void
    {
        $hid = Tenant::activeId($requesterUserId);
        Tenant::assertMembership($hid, $requesterUserId);

        $deleted = DB::table('gift_templates')
            ->where('household_id', $hid)
            ->where('id', $templateId)
            ->delete();

        if ($deleted === 0) {
            throw new \UnexpectedValueException('Template not found', 404);
        }
    }

    /** POST /gift-templates/{id}/items */
    public function addTemplateItem(string $requesterUserId, string $templateId, array $payload): string
    {
        $hid = Tenant::activeId($requesterUserId);
        Tenant::assertMembership($hid, $requesterUserId);

        // Verify template exists in household
        $exists = DB::table('gift_templates')
            ->where('household_id', $hid)
            ->where('id', $templateId)
            ->exists();

        if (!$exists) {
            throw new \UnexpectedValueException('Template not found', 404);
        }

        // Accept both arrays and single values for backward compatibility
        $giverIds = isset($payload['giver_ids'])
            ? (array)$payload['giver_ids']
            : (isset($payload['giver_id']) ? [(string)$payload['giver_id']] : []);

        $recipientIds = isset($payload['recipient_ids'])
            ? (array)$payload['recipient_ids']
            : (isset($payload['recipient_id']) ? [(string)$payload['recipient_id']] : []);

        if (empty($giverIds) || empty($recipientIds)) {
            throw new \InvalidArgumentException('giver_ids and recipient_ids are required', 422);
        }

        // Verify all users are in household
        foreach ($giverIds as $giverId) {
            $this->verifyUserInHousehold($hid, (string)$giverId);
        }
        foreach ($recipientIds as $recipientId) {
            $this->verifyUserInHousehold($hid, (string)$recipientId);
        }

        $notes = isset($payload['notes']) ? (string)$payload['notes'] : null;

        return DB::connection()->transaction(function() use ($templateId, $giverIds, $recipientIds, $notes) {
            $id = Id::ulid();
            $now = DB::raw('CURRENT_TIMESTAMP');

            // Create the template item
            DB::table('gift_template_items')->insert([
                'id'           => $id,
                'template_id'  => $templateId,
                'giver_id'     => null, // Legacy column, can be removed later
                'recipient_id' => null, // Legacy column, can be removed later
                'notes'        => $notes,
                'created_at'   => $now,
            ]);

            // Insert participants
            foreach ($giverIds as $giverId) {
                DB::table('gift_template_item_participants')->insert([
                    'item_id'    => $id,
                    'user_id'    => (string)$giverId,
                    'role'       => 'giver',
                    'created_at' => $now,
                ]);
            }

            foreach ($recipientIds as $recipientId) {
                DB::table('gift_template_item_participants')->insert([
                    'item_id'    => $id,
                    'user_id'    => (string)$recipientId,
                    'role'       => 'recipient',
                    'created_at' => $now,
                ]);
            }

            return $id;
        });
    }

    /** DELETE /gift-templates/{templateId}/items/{itemId} */
    public function deleteTemplateItem(string $requesterUserId, string $templateId, string $itemId): void
    {
        $hid = Tenant::activeId($requesterUserId);
        Tenant::assertMembership($hid, $requesterUserId);

        // Verify template exists in household
        $exists = DB::table('gift_templates')
            ->where('household_id', $hid)
            ->where('id', $templateId)
            ->exists();

        if (!$exists) {
            throw new \UnexpectedValueException('Template not found', 404);
        }

        $deleted = DB::table('gift_template_items')
            ->where('template_id', $templateId)
            ->where('id', $itemId)
            ->delete();

        if ($deleted === 0) {
            throw new \UnexpectedValueException('Template item not found', 404);
        }
    }

    /** PATCH /gift-templates/{templateId}/items/{itemId} */
    public function updateTemplateItem(string $requesterUserId, string $templateId, string $itemId, array $payload): void
    {
        $hid = Tenant::activeId($requesterUserId);
        Tenant::assertMembership($hid, $requesterUserId);

        // Verify template exists in household
        $exists = DB::table('gift_templates')
            ->where('household_id', $hid)
            ->where('id', $templateId)
            ->exists();

        if (!$exists) {
            throw new \UnexpectedValueException('Template not found', 404);
        }

        // Verify item exists
        $itemExists = DB::table('gift_template_items')
            ->where('template_id', $templateId)
            ->where('id', $itemId)
            ->exists();

        if (!$itemExists) {
            throw new \UnexpectedValueException('Template item not found', 404);
        }

        DB::connection()->transaction(function() use ($hid, $itemId, $payload) {
            $upd = [];

            // Handle notes update
            if (array_key_exists('notes', $payload)) {
                $upd['notes'] = $payload['notes'] !== null ? (string)$payload['notes'] : null;
            }

            if ($upd) {
                DB::table('gift_template_items')
                    ->where('id', $itemId)
                    ->update($upd);
            }

            // Handle participants update
            $now = DB::raw('CURRENT_TIMESTAMP');

            if (isset($payload['giver_ids'])) {
                $giverIds = (array)$payload['giver_ids'];
                // Verify all users
                foreach ($giverIds as $gid) {
                    $this->verifyUserInHousehold($hid, (string)$gid);
                }
                // Remove existing givers
                DB::table('gift_template_item_participants')
                    ->where('item_id', $itemId)
                    ->where('role', 'giver')
                    ->delete();
                // Insert new givers
                foreach ($giverIds as $gid) {
                    DB::table('gift_template_item_participants')->insert([
                        'item_id'    => $itemId,
                        'user_id'    => (string)$gid,
                        'role'       => 'giver',
                        'created_at' => $now,
                    ]);
                }
            }

            if (isset($payload['recipient_ids'])) {
                $recipientIds = (array)$payload['recipient_ids'];
                // Verify all users
                foreach ($recipientIds as $rid) {
                    $this->verifyUserInHousehold($hid, (string)$rid);
                }
                // Remove existing recipients
                DB::table('gift_template_item_participants')
                    ->where('item_id', $itemId)
                    ->where('role', 'recipient')
                    ->delete();
                // Insert new recipients
                foreach ($recipientIds as $rid) {
                    DB::table('gift_template_item_participants')->insert([
                        'item_id'    => $itemId,
                        'user_id'    => (string)$rid,
                        'role'       => 'recipient',
                        'created_at' => $now,
                    ]);
                }
            }
        });
    }

    /** POST /gift-templates/{id}/import - Import template into an event */
    public function importTemplateToEvent(string $requesterUserId, string $templateId, string $eventId): array
    {
        $hid = Tenant::activeId($requesterUserId);
        Tenant::assertMembership($hid, $requesterUserId);

        return DB::connection()->transaction(function() use ($hid, $requesterUserId, $templateId, $eventId) {
            // Verify template exists in household
            $template = DB::table('gift_templates')
                ->where('household_id', $hid)
                ->where('id', $templateId)
                ->first();

            if (!$template) {
                throw new \UnexpectedValueException('Template not found', 404);
            }

            // Verify event exists in household
            $event = DB::table('events')
                ->where('household_id', $hid)
                ->where('id', $eventId)
                ->first();

            if (!$event) {
                throw new \UnexpectedValueException('Event not found', 404);
            }

            // Get all template items
            $items = DB::table('gift_template_items')
                ->where('template_id', $templateId)
                ->get();

            if ($items->isEmpty()) {
                throw new \InvalidArgumentException('Template has no items to import', 422);
            }

            // Get all participants for these items
            $itemIds = array_map(fn($i) => $i->id, $items->all());
            $participants = DB::table('gift_template_item_participants')
                ->whereIn('item_id', $itemIds)
                ->get();

            // Group participants by item_id
            $participantsByItem = [];
            foreach ($participants as $p) {
                $participantsByItem[(string)$p->item_id][] = $p;
            }

            // Create gift orders from template items
            $createdOrderIds = [];
            $now = DB::raw('CURRENT_TIMESTAMP');

            foreach ($items as $item) {
                $itemParticipants = $participantsByItem[(string)$item->id] ?? [];

                if (empty($itemParticipants)) {
                    // Skip items with no participants
                    continue;
                }

                // Create gift order
                $orderId = Id::ulid();

                DB::table('gift_orders')->insert([
                    'id'           => $orderId,
                    'household_id' => $hid,
                    'event_id'     => $eventId,
                    'title'        => $item->notes ? (string)$item->notes : null,
                    'order_type'   => 'outgoing',
                    'product_id'   => null,
                    'price'        => null,
                    'status'       => 'idea',
                    'notes'        => null,
                    'purchased_at' => null,
                    'given_at'     => null,
                    'created_by'   => $requesterUserId,
                    'created_at'   => $now,
                    'updated_at'   => $now,
                ]);

                // Add all participants from template
                foreach ($itemParticipants as $p) {
                    DB::table('gift_order_participants')->insert([
                        'order_id'   => $orderId,
                        'user_id'    => (string)$p->user_id,
                        'role'       => (string)$p->role,
                        'created_at' => $now,
                    ]);
                }

                $createdOrderIds[] = $orderId;
            }

            return [
                'created_count' => count($createdOrderIds),
                'order_ids'     => $createdOrderIds,
            ];
        });
    }

    private function verifyUserInHousehold(string $householdId, string $userId): void
    {
        $exists = DB::table('household_members')
            ->where('household_id', $householdId)
            ->where('user_id', $userId)
            ->exists();

        if (!$exists) {
            throw new \InvalidArgumentException('User not in household', 422);
        }
    }
}
