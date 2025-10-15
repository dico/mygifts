<?php
namespace App\Controller\Gift;

use App\Model\Core\BaseController;
use App\Model\User\CurrentUser;
use App\Model\Gift\GiftsModel;
use App\Model\Core\Http;

class GiftsController extends BaseController
{
    private GiftsModel $model;

    public function __construct()
    {
        $this->model = new GiftsModel();
    }

    /** GET /gifts?event_id=...  (valgfri filter) */
    public function index(): array
    {
        try {
            $me = CurrentUser::id();
            $eventId = isset($_GET['event_id']) ? (string)$_GET['event_id'] : null;

            if ($eventId) {
                $list = $this->model->listForEvent($me, $eventId);
            } else {
                $list = $this->model->listAll($me);
            }
            return $this->ok(['gifts' => $list]);
        } catch (\RuntimeException $e) {
            return $this->error($e->getMessage(), (int)($e->getCode() ?: 403));
        } catch (\Throwable $e) {
            error_log('[GiftsController.index] '.$e->getMessage());
            return $this->error('Failed to list gifts', 500);
        }
    }

    /** GET /events/{id}/gifts */
    public function listForEvent(array $vars): array
    {
        try {
            $me = CurrentUser::id();
            $eid = (string)($vars['id'] ?? '');
            $list = $this->model->listForEvent($me, $eid);
            return $this->ok(['gifts' => $list]);
        } catch (\RuntimeException $e) {
            return $this->error($e->getMessage(), (int)($e->getCode() ?: 403));
        } catch (\Throwable $e) {
            error_log('[GiftsController.listForEvent] '.$e->getMessage());
            return $this->error('Failed to list gifts for event', 500);
        }
    }

    /** POST /gifts */
    public function create(): array
    {
        try {
            $me   = CurrentUser::id();
            $body = Http::jsonBody();
            $id   = $this->model->create($me, $body);
            return $this->ok(['gift_id' => $id], 201);
        } catch (\InvalidArgumentException $e) {
            return $this->error($e->getMessage(), (int)($e->getCode() ?: 422));
        } catch (\RuntimeException $e) {
            return $this->error($e->getMessage(), (int)($e->getCode() ?: 403));
        } catch (\Throwable $e) {
            error_log('[GiftsController.create] '.$e->getMessage());
            return $this->error('Failed to create gift', 500);
        }
    }

    /** GET /gifts/{id} */
    public function show(array $vars): array
    {
        try {
            $me = CurrentUser::id();
            $gid = (string)($vars['id'] ?? '');
            $row = $this->model->getOne($me, $gid);
            return $this->ok(['gift' => $row]);
        } catch (\UnexpectedValueException $e) {
            return $this->error($e->getMessage(), (int)($e->getCode() ?: 404));
        } catch (\RuntimeException $e) {
            return $this->error($e->getMessage(), (int)($e->getCode() ?: 403));
        } catch (\Throwable $e) {
            error_log('[GiftsController.show] '.$e->getMessage());
            return $this->error('Failed to fetch gift', 500);
        }
    }

    /** PATCH /gifts/{id} */
    public function update(array $vars): array
    {
        try {
            $me   = CurrentUser::id();
            $gid  = (string)($vars['id'] ?? '');
            $body = Http::jsonBody();
            $this->model->update($me, $gid, $body);
            return $this->ok();
        } catch (\InvalidArgumentException $e) {
            return $this->error($e->getMessage(), (int)($e->getCode() ?: 422));
        } catch (\UnexpectedValueException $e) {
            return $this->error($e->getMessage(), (int)($e->getCode() ?: 404));
        } catch (\RuntimeException $e) {
            return $this->error($e->getMessage(), (int)($e->getCode() ?: 403));
        } catch (\Throwable $e) {
            error_log('[GiftsController.update] '.$e->getMessage());
            return $this->error('Failed to update gift', 500);
        }
    }

    /** DELETE /gifts/{id} */
    public function destroy(array $vars): array
    {
        try {
            $me  = CurrentUser::id();
            $gid = (string)($vars['id'] ?? '');
            $this->model->delete($me, $gid);
            return $this->ok();
        } catch (\UnexpectedValueException $e) {
            return $this->error($e->getMessage(), (int)($e->getCode() ?: 404));
        } catch (\RuntimeException $e) {
            return $this->error($e->getMessage(), (int)($e->getCode() ?: 403));
        } catch (\Throwable $e) {
            error_log('[GiftsController.destroy] '.$e->getMessage());
            return $this->error('Failed to delete gift', 500);
        }
    }
}
