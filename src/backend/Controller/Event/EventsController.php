<?php
namespace App\Controller\Event;

use App\Model\Core\BaseController;
use App\Model\Core\Http;
use App\Model\User\CurrentUser;
use App\Model\Event\EventsModel;

class EventsController extends BaseController
{
    private EventsModel $model;

    public function __construct()
    {
        $this->model = new EventsModel();
    }

    /** GET /events */
    public function index(): array
    {
        try {
            $me = CurrentUser::id();
            $list = $this->model->listEvents($me);
            return $this->ok(['events' => $list]);
        } catch (\RuntimeException $e) {
            return $this->error($e->getMessage(), $e->getCode() ?: 403);
        } catch (\Throwable $e) {
            error_log('[EventsController.index] '.$e->getMessage());
            return $this->error('Failed to list events', 500);
        }
    }

    /** POST /events */
    public function create(): array
    {
        try {
            $me = CurrentUser::id();
            $body = Http::jsonBody();
            $id = $this->model->createEvent($me, $body);
            return $this->ok(['event_id' => $id], 201);
        } catch (\InvalidArgumentException $e) {
            return $this->error($e->getMessage(), $e->getCode() ?: 422);
        } catch (\RuntimeException $e) {
            return $this->error($e->getMessage(), $e->getCode() ?: 403);
        } catch (\Throwable $e) {
            error_log('[EventsController.create] '.$e->getMessage());
            return $this->error('Failed to create event', 500);
        }
    }

    /** GET /events/{id} */
    public function show(array $vars): array
    {
        try {
            $me = CurrentUser::id();
            $eid = (string)($vars['id'] ?? '');
            $row = $this->model->getEvent($me, $eid);
            return $this->ok(['event' => $row]);
        } catch (\UnexpectedValueException $e) {
            return $this->error($e->getMessage(), $e->getCode() ?: 404);
        } catch (\RuntimeException $e) {
            return $this->error($e->getMessage(), $e->getCode() ?: 403);
        } catch (\Throwable $e) {
            error_log('[EventsController.show] '.$e->getMessage());
            return $this->error('Failed to fetch event', 500);
        }
    }

    /** PATCH /events/{id} */
    public function update(array $vars): array
    {
        try {
            $me = CurrentUser::id();
            $eid = (string)($vars['id'] ?? '');
            $body = Http::jsonBody();
            $this->model->updateEvent($me, $eid, $body);
            return $this->ok();
        } catch (\InvalidArgumentException $e) {
            return $this->error($e->getMessage(), $e->getCode() ?: 422);
        } catch (\UnexpectedValueException $e) {
            return $this->error($e->getMessage(), $e->getCode() ?: 404);
        } catch (\RuntimeException $e) {
            return $this->error($e->getMessage(), $e->getCode() ?: 403);
        } catch (\Throwable $e) {
            error_log('[EventsController.update] '.$e->getMessage());
            return $this->error('Failed to update event', 500);
        }
    }

    /** DELETE /events/{id} */
    public function destroy(array $vars): array
    {
        try {
            $me = CurrentUser::id();
            $eid = (string)($vars['id'] ?? '');
            $this->model->deleteEvent($me, $eid);
            return $this->ok();
        } catch (\UnexpectedValueException $e) {
            return $this->error($e->getMessage(), $e->getCode() ?: 404);
        } catch (\RuntimeException $e) {
            return $this->error($e->getMessage(), $e->getCode() ?: 403);
        } catch (\Throwable $e) {
            error_log('[EventsController.destroy] '.$e->getMessage());
            return $this->error('Failed to delete event', 500);
        }
    }
}
