<?php
// src/backend/Controller/Product/ProductsController.php
namespace App\Controller\Product;

use App\Model\Core\BaseController;
use App\Model\Core\Http;
use App\Model\User\CurrentUser;
use App\Model\Product\ProductsModel;

class ProductsController extends BaseController
{
    private ProductsModel $model;

    public function __construct() { $this->model = new ProductsModel(); }

    /** GET /products */
    public function index(): array {
        try {
            $me = CurrentUser::id();
            $rows = $this->model->listProducts($me);
            return $this->ok(['products' => $rows]);
        } catch (\RuntimeException $e) {
            return $this->error($e->getMessage(), (int)($e->getCode() ?: 403));
        } catch (\Throwable $e) {
            error_log('[ProductsController.index] '.$e->getMessage());
            return $this->error('Failed to list products', 500);
        }
    }

    /** POST /products */
    public function create(): array {
        try {
            $me = CurrentUser::id();
            $body = Http::jsonBody();
            $id = $this->model->createProduct($me, $body);
            return $this->ok(['product_id' => $id], 201);
        } catch (\InvalidArgumentException $e) {
            return $this->error($e->getMessage(), (int)($e->getCode() ?: 422));
        } catch (\RuntimeException $e) {
            return $this->error($e->getMessage(), (int)($e->getCode() ?: 403));
        } catch (\Throwable $e) {
            error_log('[ProductsController.create] '.$e->getMessage());
            return $this->error('Failed to create product', 500);
        }
    }

    /** GET /products/{id} */
    public function show(array $vars): array {
        try {
            $me = CurrentUser::id();
            $pid = (string)($vars['id'] ?? '');
            $row = $this->model->getProduct($me, $pid);
            return $this->ok($row['data'] ?? $row);
        } catch (\UnexpectedValueException $e) {
            return $this->error($e->getMessage(), (int)($e->getCode() ?: 404));
        } catch (\RuntimeException $e) {
            return $this->error($e->getMessage(), (int)($e->getCode() ?: 403));
        } catch (\Throwable $e) {
            error_log('[ProductsController.show] '.$e->getMessage());
            return $this->error('Failed to fetch product', 500);
        }
    }

    /** PATCH /products/{id} */
    public function update(array $vars): array {
        try {
            $me = CurrentUser::id();
            $pid = (string)($vars['id'] ?? '');
            $body = Http::jsonBody();
            $this->model->updateProduct($me, $pid, $body);
            return $this->ok();
        } catch (\InvalidArgumentException $e) {
            return $this->error($e->getMessage(), (int)($e->getCode() ?: 422));
        } catch (\UnexpectedValueException $e) {
            return $this->error($e->getMessage(), (int)($e->getCode() ?: 404));
        } catch (\RuntimeException $e) {
            return $this->error($e->getMessage(), (int)($e->getCode() ?: 403));
        } catch (\Throwable $e) {
            error_log('[ProductsController.update] '.$e->getMessage());
            return $this->error('Failed to update product', 500);
        }
    }

    /** DELETE /products/{id} */
    public function destroy(array $vars): array {
        try {
            $me = CurrentUser::id();
            $pid = (string)($vars['id'] ?? '');
            $this->model->deleteProduct($me, $pid);
            return $this->ok();
        } catch (\UnexpectedValueException $e) {
            return $this->error($e->getMessage(), (int)($e->getCode() ?: 404));
        } catch (\RuntimeException $e) {
            return $this->error($e->getMessage(), (int)($e->getCode() ?: 403));
        } catch (\Throwable $e) {
            error_log('[ProductsController.destroy] '.$e->getMessage());
            return $this->error('Failed to delete product', 500);
        }
    }


	// I class ProductsController
	public function giftItems(array $vars): array {
		try {
			$me  = \App\Model\User\CurrentUser::id();
			$pid = (string)($vars['id'] ?? '');
			if ($pid === '') return $this->error('Missing product id', 400);

			// sikkerhet: verifiser at produktet finnes i denne household
			$pm = new \App\Model\Product\ProductsModel();
			$pm->getProduct($me, $pid); // vil kaste 404/403 hvis ikke

			$gm = new \App\Model\Gift\GiftsModel();
			$items = $gm->listForProduct($me, $pid);

			return $this->ok(['items' => $items]);
		} catch (\UnexpectedValueException $e) {
			return $this->error($e->getMessage(), (int)($e->getCode() ?: 404));
		} catch (\RuntimeException $e) {
			return $this->error($e->getMessage(), (int)($e->getCode() ?: 403));
		} catch (\Throwable $e) {
			error_log('[ProductsController.giftItems] '.$e->getMessage());
			return $this->error('Failed to fetch gift items for product', 500);
		}
	}

}
