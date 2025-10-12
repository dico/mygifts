<?php
// src/backend/Model/Core/BaseController.php
namespace App\Model\Core;

class BaseController
{
    /**
     * Supertynne hjelpere som kun returnerer payload.
     * Router tar seg av HTTP-kode, headers og json_encode.
     */
    protected function ok(array $data = [], int $code = 200): array
    {
        return [
            'status'      => 'success',
            'status_code' => $code,
            'data'        => $data,
        ];
    }

    protected function error(string $message, int $code = 400, array $extra = []): array
    {
        $payload = [
            'status'      => 'error',
            'status_code' => $code,
            'message'     => $message,
        ];
        if ($extra) {
            $payload['extra'] = $extra;
        }
        return $payload;
    }
}
