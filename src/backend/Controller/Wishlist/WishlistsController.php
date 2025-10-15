<?php
namespace App\Controller\Wishlist;

use App\Model\Core\BaseController;
use App\Model\Core\Http;
use App\Model\User\CurrentUser;
use App\Model\Wishlist\WishlistsModel;

class WishlistsController extends BaseController
{
    private WishlistsModel $model;
    public function __construct() { $this->model = new WishlistsModel(); }

    // GET /wishlists?include_empty=1
    public function index(): array {
        try {
            $me = CurrentUser::id();
            $includeEmpty = (isset($_GET['include_empty']) && $_GET['include_empty'] !== '0');
            $rows = $this->model->listHouseholdWishlists($me, $includeEmpty);
            return $this->ok(['wishlists' => $rows]);
        } catch (\RuntimeException $e) {
            return $this->error($e->getMessage(), (int)($e->getCode() ?: 403));
        } catch (\Throwable $e) {
            error_log('[WishlistsController.index] '.$e->getMessage());
            return $this->error('Failed to load wishlists', 500);
        }
    }

    // GET /wishlists/{id} (wishlist_item)
    public function show(array $vars): array {
        try {
            $me = CurrentUser::id();
            $id = (string)($vars['id'] ?? '');
            $row = $this->model->getOne($me, $id);
            return $this->ok(['wishlist_item' => $row]);
        } catch (\UnexpectedValueException $e) {
            return $this->error($e->getMessage(), (int)($e->getCode() ?: 404));
        } catch (\RuntimeException $e) {
            return $this->error($e->getMessage(), (int)($e->getCode() ?: 403));
        } catch (\Throwable $e) {
            error_log('[WishlistsController.show] '.$e->getMessage());
            return $this->error('Failed to fetch wishlist item', 500);
        }
    }

    // POST /wishlists
    public function create(): array {
        try {
            $me = CurrentUser::id();
            $body = Http::jsonBody();
            $id = $this->model->create($me, $body);
            return $this->ok(['wishlist_item_id' => $id], 201);
        } catch (\InvalidArgumentException $e) {
            return $this->error($e->getMessage(), (int)($e->getCode() ?: 422));
        } catch (\RuntimeException $e) {
            return $this->error($e->getMessage(), (int)($e->getCode() ?: 403));
        } catch (\Throwable $e) {
            error_log('[WishlistsController.create] '.$e->getMessage());
            return $this->error('Failed to create wishlist item', 500);
        }
    }

    // PATCH /wishlists/{id}
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
            error_log('[WishlistsController.update] '.$e->getMessage());
            return $this->error('Failed to update wishlist item', 500);
        }
    }

    // DELETE /wishlists/{id}
    public function destroy(array $vars): array {
        try {
            $me = CurrentUser::id();
            $id = (string)($vars['id'] ?? '');
            $this->model->delete($me, $id);
            return $this->ok();
        } catch (\UnexpectedValueException $e) {
            return $this->error($e->getMessage(), (int)($e->getCode() ?: 404));
        } catch (\RuntimeException $e) {
            return $this->error($e->getMessage(), (int)($e->getCode() ?: 403));
        } catch (\Throwable $e) {
            error_log('[WishlistsController.destroy] '.$e->getMessage());
            return $this->error('Failed to delete wishlist item', 500);
        }
    }
}
