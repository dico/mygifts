<?php
namespace App\Controller\User;

use App\Model\Core\BaseController;
use App\Model\User\UsersModel;
use App\Model\User\CurrentUser;

class UsersController extends BaseController
{
    private UsersModel $model;

    public function __construct()
    {
        $this->model = new UsersModel();
    }

    /** GET /users – list tenant users (active_household_id) */
    public function index(): array
    {
        try {
            $me = CurrentUser::id();
            $list = $this->model->listUsers($me);
            return $this->ok(['users' => $list]);
        } catch (\RuntimeException $e) {
            $code = $e->getCode() ?: 403;
            return $this->error($e->getMessage(), $code);
        } catch (\Throwable $e) {
            error_log('[UsersController.index] '.$e->getMessage());
            return $this->error('Failed to list users', 500);
        }
    }

    /** POST /users – create user and attach to tenant */
    public function create(): array
    {
        try {
            $me   = CurrentUser::id();
            $body = \App\Model\Core\Http::jsonBody();
            $uid  = $this->model->createUser($me, $body);
            return $this->ok(['user_id' => $uid], 201);
        } catch (\InvalidArgumentException $e) {
            $code = $e->getCode() ?: 422;
            return $this->error($e->getMessage(), $code);
        } catch (\RuntimeException $e) {
            $code = $e->getCode() ?: 403;
            return $this->error($e->getMessage(), $code);
        } catch (\Throwable $e) {
            error_log('[UsersController.create] '.$e->getMessage());
            return $this->error('Failed to create user', 500);
        }
    }

    /** GET /users/{id} – fetch one tenant user */
    public function show(array $vars): array
    {
        try {
            $me  = CurrentUser::id();
            $uid = (string)($vars['id'] ?? '');
            $row = $this->model->getUser($me, $uid);
            return $this->ok(['user' => $row]);
        } catch (\UnexpectedValueException $e) {
            $code = $e->getCode() ?: 404;
            return $this->error($e->getMessage(), $code);
        } catch (\RuntimeException $e) {
            $code = $e->getCode() ?: 403;
            return $this->error($e->getMessage(), $code);
        } catch (\Throwable $e) {
            error_log('[UsersController.show] '.$e->getMessage());
            return $this->error('Failed to fetch user', 500);
        }
    }

    /** PATCH /users/{id} – update basic fields / membership flags */
    public function update(array $vars): array
    {
        try {
            $me   = CurrentUser::id();
            $uid  = (string)($vars['id'] ?? '');
            $body = \App\Model\Core\Http::jsonBody();
            $this->model->updateUser($me, $uid, $body);
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
            error_log('[UsersController.update] '.$e->getMessage());
            return $this->error('Failed to update user', 500);
        }
    }

    /** DELETE /users/{id} – detach from tenant (does not delete global user row) */
    public function destroy(array $vars): array
    {
        try {
            $me  = CurrentUser::id();
            $uid = (string)($vars['id'] ?? '');
            $this->model->removeUser($me, $uid);
            return $this->ok();
        } catch (\UnexpectedValueException $e) {
            $code = $e->getCode() ?: 404;
            return $this->error($e->getMessage(), $code);
        } catch (\RuntimeException $e) {
            $code = $e->getCode() ?: 403;
            return $this->error($e->getMessage(), $code);
        } catch (\Throwable $e) {
            error_log('[UsersController.destroy] '.$e->getMessage());
            return $this->error('Failed to remove user', 500);
        }
    }

    /**
     * POST /users/{id}/avatar – upload avatar (multipart/form-data, field "file")
     */
    public function uploadAvatar(array $vars): array
    {
        try {
            $me  = CurrentUser::id();
            $uid = (string)($vars['id'] ?? '');
            // la modellen gjøre jobben (inkl. validering, lagring, DB-oppdatering)
            $result = $this->model->uploadAvatar($me, $uid, $_FILES['file'] ?? null);
            return $this->ok($result, 201);
        } catch (\InvalidArgumentException $e) {
            return $this->error($e->getMessage(), $e->getCode() ?: 400);
        } catch (\UnexpectedValueException $e) {
            return $this->error($e->getMessage(), $e->getCode() ?: 404);
        } catch (\RuntimeException $e) {
            return $this->error($e->getMessage(), $e->getCode() ?: 403);
        } catch (\Throwable $e) {
            error_log('[UsersController.uploadAvatar] '.$e->getMessage());
            return $this->error('Upload failed', 500);
        }
    }

    /**
     * DELETE /users/{id}/avatar – remove avatar
     * (kun delegasjon; all logikk i model)
     */
    public function deleteAvatar(array $vars): array
    {
        try {
            $me  = CurrentUser::id();
            $uid = (string)($vars['id'] ?? '');
            $this->model->removeAvatar($me, $uid);
            return $this->ok();
        } catch (\UnexpectedValueException $e) {
            return $this->error($e->getMessage(), $e->getCode() ?: 404);
        } catch (\RuntimeException $e) {
            return $this->error($e->getMessage(), $e->getCode() ?: 403);
        } catch (\Throwable $e) {
            error_log('[UsersController.deleteAvatar] '.$e->getMessage());
            return $this->error('Failed to remove photo', 500);
        }
    }
}
