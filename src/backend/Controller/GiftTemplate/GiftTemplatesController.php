<?php
// src/backend/Controller/GiftTemplate/GiftTemplatesController.php
namespace App\Controller\GiftTemplate;

use App\Model\Core\BaseController;
use App\Model\Core\Http;
use App\Model\User\CurrentUser;
use App\Model\GiftTemplate\GiftTemplatesModel;

class GiftTemplatesController extends BaseController
{
    private GiftTemplatesModel $model;

    public function __construct() { $this->model = new GiftTemplatesModel(); }

    /** GET /gift-templates */
    public function index(): array
    {
        try {
            $me = CurrentUser::id();
            $templates = $this->model->listTemplates($me);
            return $this->ok(['templates' => $templates]);
        } catch (\RuntimeException $e) {
            return $this->error($e->getMessage(), (int)($e->getCode() ?: 403));
        } catch (\Throwable $e) {
            error_log('[GiftTemplatesController.index] ' . $e->getMessage());
            return $this->error('Failed to list templates', 500);
        }
    }

    /** GET /gift-templates/{id} */
    public function show(array $vars): array
    {
        try {
            $me = CurrentUser::id();
            $templateId = (string)($vars['id'] ?? '');
            $data = $this->model->getTemplate($me, $templateId);
            return $this->ok($data);
        } catch (\UnexpectedValueException $e) {
            return $this->error($e->getMessage(), (int)($e->getCode() ?: 404));
        } catch (\RuntimeException $e) {
            return $this->error($e->getMessage(), (int)($e->getCode() ?: 403));
        } catch (\Throwable $e) {
            error_log('[GiftTemplatesController.show] ' . $e->getMessage());
            return $this->error('Failed to fetch template', 500);
        }
    }

    /** POST /gift-templates */
    public function create(): array
    {
        try {
            $me = CurrentUser::id();
            $body = Http::jsonBody();
            $id = $this->model->createTemplate($me, $body);
            return $this->ok(['template_id' => $id], 201);
        } catch (\InvalidArgumentException $e) {
            return $this->error($e->getMessage(), (int)($e->getCode() ?: 422));
        } catch (\RuntimeException $e) {
            return $this->error($e->getMessage(), (int)($e->getCode() ?: 403));
        } catch (\Throwable $e) {
            error_log('[GiftTemplatesController.create] ' . $e->getMessage());
            return $this->error('Failed to create template', 500);
        }
    }

    /** PATCH /gift-templates/{id} */
    public function update(array $vars): array
    {
        try {
            $me = CurrentUser::id();
            $templateId = (string)($vars['id'] ?? '');
            $body = Http::jsonBody();
            $this->model->updateTemplate($me, $templateId, $body);
            return $this->ok();
        } catch (\InvalidArgumentException $e) {
            return $this->error($e->getMessage(), (int)($e->getCode() ?: 422));
        } catch (\UnexpectedValueException $e) {
            return $this->error($e->getMessage(), (int)($e->getCode() ?: 404));
        } catch (\RuntimeException $e) {
            return $this->error($e->getMessage(), (int)($e->getCode() ?: 403));
        } catch (\Throwable $e) {
            error_log('[GiftTemplatesController.update] ' . $e->getMessage());
            return $this->error('Failed to update template', 500);
        }
    }

    /** DELETE /gift-templates/{id} */
    public function destroy(array $vars): array
    {
        try {
            $me = CurrentUser::id();
            $templateId = (string)($vars['id'] ?? '');
            $this->model->deleteTemplate($me, $templateId);
            return $this->ok();
        } catch (\UnexpectedValueException $e) {
            return $this->error($e->getMessage(), (int)($e->getCode() ?: 404));
        } catch (\RuntimeException $e) {
            return $this->error($e->getMessage(), (int)($e->getCode() ?: 403));
        } catch (\Throwable $e) {
            error_log('[GiftTemplatesController.destroy] ' . $e->getMessage());
            return $this->error('Failed to delete template', 500);
        }
    }

    /** POST /gift-templates/{id}/items */
    public function addItem(array $vars): array
    {
        try {
            $me = CurrentUser::id();
            $templateId = (string)($vars['id'] ?? '');
            $body = Http::jsonBody();
            $itemId = $this->model->addTemplateItem($me, $templateId, $body);
            return $this->ok(['item_id' => $itemId], 201);
        } catch (\InvalidArgumentException $e) {
            return $this->error($e->getMessage(), (int)($e->getCode() ?: 422));
        } catch (\UnexpectedValueException $e) {
            return $this->error($e->getMessage(), (int)($e->getCode() ?: 404));
        } catch (\RuntimeException $e) {
            return $this->error($e->getMessage(), (int)($e->getCode() ?: 403));
        } catch (\Throwable $e) {
            error_log('[GiftTemplatesController.addItem] ' . $e->getMessage());
            return $this->error('Failed to add template item', 500);
        }
    }

    /** PATCH /gift-templates/{templateId}/items/{itemId} */
    public function updateItem(array $vars): array
    {
        try {
            $me = CurrentUser::id();
            $templateId = (string)($vars['id'] ?? '');
            $itemId = (string)($vars['itemId'] ?? '');
            $body = Http::jsonBody();
            $this->model->updateTemplateItem($me, $templateId, $itemId, $body);
            return $this->ok();
        } catch (\InvalidArgumentException $e) {
            return $this->error($e->getMessage(), (int)($e->getCode() ?: 422));
        } catch (\UnexpectedValueException $e) {
            return $this->error($e->getMessage(), (int)($e->getCode() ?: 404));
        } catch (\RuntimeException $e) {
            return $this->error($e->getMessage(), (int)($e->getCode() ?: 403));
        } catch (\Throwable $e) {
            error_log('[GiftTemplatesController.updateItem] ' . $e->getMessage());
            return $this->error('Failed to update template item', 500);
        }
    }

    /** DELETE /gift-templates/{templateId}/items/{itemId} */
    public function deleteItem(array $vars): array
    {
        try {
            $me = CurrentUser::id();
            $templateId = (string)($vars['id'] ?? '');
            $itemId = (string)($vars['itemId'] ?? '');
            $this->model->deleteTemplateItem($me, $templateId, $itemId);
            return $this->ok();
        } catch (\UnexpectedValueException $e) {
            return $this->error($e->getMessage(), (int)($e->getCode() ?: 404));
        } catch (\RuntimeException $e) {
            return $this->error($e->getMessage(), (int)($e->getCode() ?: 403));
        } catch (\Throwable $e) {
            error_log('[GiftTemplatesController.deleteItem] ' . $e->getMessage());
            return $this->error('Failed to delete template item', 500);
        }
    }

    /** POST /gift-templates/{id}/import */
    public function importToEvent(array $vars): array
    {
        try {
            $me = CurrentUser::id();
            $templateId = (string)($vars['id'] ?? '');
            $body = Http::jsonBody();
            $eventId = (string)($body['event_id'] ?? '');

            if (!$eventId) {
                throw new \InvalidArgumentException('event_id is required', 422);
            }

            $result = $this->model->importTemplateToEvent($me, $templateId, $eventId);
            return $this->ok($result);
        } catch (\InvalidArgumentException $e) {
            return $this->error($e->getMessage(), (int)($e->getCode() ?: 422));
        } catch (\UnexpectedValueException $e) {
            return $this->error($e->getMessage(), (int)($e->getCode() ?: 404));
        } catch (\RuntimeException $e) {
            return $this->error($e->getMessage(), (int)($e->getCode() ?: 403));
        } catch (\Throwable $e) {
            error_log('[GiftTemplatesController.importToEvent] ' . $e->getMessage());
            return $this->error('Failed to import template', 500);
        }
    }
}
