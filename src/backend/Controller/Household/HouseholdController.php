<?php
// src/backend/Controller/Household/HouseholdController.php
namespace App\Controller\Household;

use App\Model\Core\BaseController;
use App\Model\Core\Http;
use App\Model\Household\HouseholdModel;
use App\Model\User\CurrentUser;
use App\Model\Tenant\Tenant;

class HouseholdController extends BaseController
{
    private HouseholdModel $model;

    public function __construct()
    {
        $this->model = new HouseholdModel();
    }

    /** POST /households  Body: { "name": "Familien Hansen" } */
    public function create(): array
    {
        try {
            $me   = CurrentUser::id();
            $body = Http::jsonBody();
            $hid  = $this->model->createHousehold($me, (string)($body['name'] ?? ''));
            return $this->ok(['household_id' => $hid], 201);
        } catch (\InvalidArgumentException $e) {
            $code = $e->getCode() ?: 422;
            return $this->error($e->getMessage(), $code);
        } catch (\Throwable $e) {
            error_log('[HouseholdController.create] '.$e->getMessage());
            return $this->error('Failed to create household', 500);
        }
    }

    /** GET /households/mine – households where I am a member */
    public function mine(): array
    {
        try {
            $me   = CurrentUser::id();
            $list = $this->model->listMyHouseholds($me);
            return $this->ok(['households' => $list], 200);
        } catch (\Throwable $e) {
            error_log('[HouseholdController.mine] '.$e->getMessage());
            return $this->error('Failed to list households', 500);
        }
    }

    // --------------------------
    // ACTIVE household endpoints
    // --------------------------

    /** GET /household – read active household */
    public function showActive(): array
    {
        try {
            $me  = CurrentUser::id();
            $hid = Tenant::activeId($me);                 // Hent aktiv tenant
            $row = $this->model->get($hid, $me);          // Valider medlemskap i modellen
            return $this->ok(['household' => $row], 200);
        } catch (\UnexpectedValueException $e) {
            $code = $e->getCode() ?: 404;
            return $this->error($e->getMessage(), $code);
        } catch (\RuntimeException $e) {
            $code = $e->getCode() ?: 403;
            return $this->error($e->getMessage(), $code);
        } catch (\Throwable $e) {
            error_log('[HouseholdController.showActive] '.$e->getMessage());
            return $this->error('Failed to fetch household', 500);
        }
    }

    /** PATCH /household  Body: { name } (manager or sysadmin) */
    public function updateActive(): array
    {
        try {
            $me   = CurrentUser::id();
            $hid  = Tenant::activeId($me);
            $body = Http::jsonBody();
            $this->model->update($hid, $me, $body);
            return $this->ok();
        } catch (\InvalidArgumentException $e) {
            $code = $e->getCode() ?: 422;
            return $this->error($e->getMessage(), $code);
        } catch (\UnexpectedValueException $e) {
            $code = $e->getCode() ?: 404;
            return $this->error($e->getMessage(), $code);
        } catch (\RuntimeException $e) {
            $code = $e->getCode() ?: 403;
            return $this->error($e->getMessage(), $code);
        } catch (\Throwable $e) {
            error_log('[HouseholdController.updateActive] '.$e->getMessage());
            return $this->error('Failed to update household', 500);
        }
    }

    /** DELETE /household (manager or sysadmin) */
    public function destroyActive(): array
    {
        try {
            $me  = CurrentUser::id();
            $hid = Tenant::activeId($me);
            $this->model->delete($hid, $me);
            return $this->ok();
        } catch (\UnexpectedValueException $e) {
            $code = $e->getCode() ?: 404;
            return $this->error($e->getMessage(), $code);
        } catch (\RuntimeException $e) {
            $code = $e->getCode() ?: 403;
            return $this->error($e->getMessage(), $code);
        } catch (\Throwable $e) {
            error_log('[HouseholdController.destroyActive] '.$e->getMessage());
            return $this->error('Failed to delete household', 500);
        }
    }

    // --------------------------
    // ACTIVE household members
    // --------------------------

    /** GET /household/members – list members in active household */
    public function membersActive(): array
    {
        try {
            $me  = CurrentUser::id();
            $hid = Tenant::activeId($me);
            $members = $this->model->listMembers($hid, $me);
            return $this->ok(['members' => $members]);
        } catch (\UnexpectedValueException $e) {
            $code = $e->getCode() ?: 404;
            return $this->error($e->getMessage(), $code);
        } catch (\RuntimeException $e) {
            $code = $e->getCode() ?: 403;
            return $this->error($e->getMessage(), $code);
        } catch (\Throwable $e) {
            error_log('[HouseholdController.membersActive] '.$e->getMessage());
            return $this->error('Failed to fetch members', 500);
        }
    }

    /** POST /household/members – add or update a member in active household */
    public function addMemberActive(): array
    {
        try {
            $me   = CurrentUser::id();
            $hid  = Tenant::activeId($me);
            $body = Http::jsonBody();
            $userId = $this->model->addMember($hid, $me, $body);
            return $this->ok(['user_id' => $userId], 201);
        } catch (\UnexpectedValueException $e) {
            $code = $e->getCode() ?: 404;
            return $this->error($e->getMessage(), $code);
        } catch (\InvalidArgumentException $e) {
            $code = $e->getCode() ?: 422;
            return $this->error($e->getMessage(), $code);
        } catch (\RuntimeException $e) {
            $code = $e->getCode() ?: 403;
            return $this->error($e->getMessage(), $code);
        } catch (\Throwable $e) {
            error_log('[HouseholdController.addMemberActive] '.$e->getMessage());
            return $this->error('Failed to add member', 500);
        }
    }

    /** DELETE /household/members/{userId} – detach member from active household */
    public function removeMemberActive(array $vars): array
    {
        try {
            $me     = CurrentUser::id();
            $hid    = Tenant::activeId($me);
            $userId = (string)($vars['userId'] ?? '');
            $this->model->removeMember($hid, $me, $userId);
            return $this->ok();
        } catch (\UnexpectedValueException $e) {
            $code = $e->getCode() ?: 404;
            return $this->error($e->getMessage(), $code);
        } catch (\RuntimeException $e) {
            $code = $e->getCode() ?: 403;
            return $this->error($e->getMessage(), $code);
        } catch (\Throwable $e) {
            error_log('[HouseholdController.removeMemberActive] '.$e->getMessage());
            return $this->error('Failed to remove member', 500);
        }
    }
}
