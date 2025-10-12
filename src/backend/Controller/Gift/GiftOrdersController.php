<?php
namespace App\Controller\Gift;

use App\Model\Core\BaseController;
use App\Model\Core\Http;
use App\Model\User\CurrentUser;
use App\Model\Gift\GiftOrdersModel;

class GiftOrdersController extends BaseController
{
    private GiftOrdersModel $model;
    public function __construct() { $this->model = new GiftOrdersModel(); }

    // GET /gift-orders?event_id=...  eller /events/{id}/gift-orders
    public function index(array $vars = []): array {
        try {
            $me = CurrentUser::id();
            $eid = isset($_GET['event_id']) ? (string)$_GET['event_id'] : null;
            if (!$eid && !empty($vars['id'])) $eid = (string)$vars['id'];
            if (!$eid) throw new \InvalidArgumentException('event_id required', 422);

            $rows = $this->model->listForEvent($me, $eid);
            return $this->ok(['orders' => $rows]);
        } catch (\InvalidArgumentException $e) {
            return $this->error($e->getMessage(), (int)($e->getCode() ?: 422));
        } catch (\RuntimeException $e) {
            return $this->error($e->getMessage(), (int)($e->getCode() ?: 403));
        } catch (\Throwable $e) {
            error_log('[GiftOrdersController.index] '.$e->getMessage());
            return $this->error('Failed to list gift orders', 500);
        }
    }

    public function create(): array {
        try {
            $me = CurrentUser::id();
            $body = Http::jsonBody();
            $id = $this->model->create($me, $body);
            return $this->ok(['gift_order_id' => $id], 201);
        } catch (\InvalidArgumentException $e) {
            return $this->error($e->getMessage(), (int)($e->getCode() ?: 422));
        } catch (\RuntimeException $e) {
            return $this->error($e->getMessage(), (int)($e->getCode() ?: 403));
        } catch (\Throwable $e) {
            error_log('[GiftOrdersController.create] '.$e->getMessage());
            return $this->error('Failed to create gift order', 500);
        }
    }

    public function show(array $vars): array {
        try {
            $me = CurrentUser::id();
            $id = (string)($vars['id'] ?? '');
            $row = $this->model->getOne($me, $id);
            return $this->ok(['order' => $row]);
        } catch (\UnexpectedValueException $e) {
            return $this->error($e->getMessage(), (int)($e->getCode() ?: 404));
        } catch (\RuntimeException $e) {
            return $this->error($e->getMessage(), (int)($e->getCode() ?: 403));
        } catch (\Throwable $e) {
            error_log('[GiftOrdersController.show] '.$e->getMessage());
            return $this->error('Failed to fetch gift order', 500);
        }
    }

    public function update(array $vars): array {
        try {
            $me = CurrentUser::id();
            $id = (string)($vars['id'] ?? '');
            $body = Http::jsonBody();
            $this->model->update($me, $id, $body);
            return $this->ok();
        } catch (\InvalidArgumentException $e) {
            return $this->error($e->getMessage(), (int)($e->getCode() ?: 422));
        } catch (\UnexpectedValueException $e) {
            return $this->error($e->getMessage(), (int)($e->getCode() ?: 404));
        } catch (\RuntimeException $e) {
            return $this->error($e->getMessage(), (int)($e->getCode() ?: 403));
        } catch (\Throwable $e) {
            error_log('[GiftOrdersController.update] '.$e->getMessage());
            return $this->error('Failed to update gift order', 500);
        }
    }

    public function destroy(array $vars): array {
        try {
            $me = CurrentUser::id();
            $id = (string)($vars['id'] ?? '');
            $this->model->destroy($me, $id);
            return $this->ok();
        } catch (\UnexpectedValueException $e) {
            return $this->error($e->getMessage(), (int)($e->getCode() ?: 404));
        } catch (\RuntimeException $e) {
            return $this->error($e->getMessage(), (int)($e->getCode() ?: 403));
        } catch (\Throwable $e) {
            error_log('[GiftOrdersController.destroy] '.$e->getMessage());
            return $this->error('Failed to delete gift order', 500);
        }
    }
}
