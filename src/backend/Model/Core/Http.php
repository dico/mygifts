<?php
// src/backend/Model/Core/Http.php
namespace App\Model\Core;

class Http
{
    /**
     * Returnerer Authorization: Bearer <token> om satt, ellers null.
     * Leser fra $_SERVER, apache_request_headers() og miljøvariabelen som .htaccess setter.
     */
    public static function bearerToken(): ?string
    {
        // 1) Standard vei
        $header = $_SERVER['HTTP_AUTHORIZATION'] ?? null;

        // 2) Miljøvariabel satt av .htaccess
        if (!$header) {
            $header = $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? null;
        }

        // 3) Fallback via apache_request_headers()
        if (!$header && function_exists('apache_request_headers')) {
            $headers = apache_request_headers();
            foreach ($headers as $k => $v) {
                if (strcasecmp($k, 'Authorization') === 0) {
                    $header = $v;
                    break;
                }
            }
        }

        if (!$header) return null;

        if (stripos($header, 'Bearer ') === 0) {
            return trim(substr($header, 7));
        }

        return null;
    }

    /** Trygg parse av JSON-body */
    public static function jsonBody(): array
    {
        $raw = file_get_contents('php://input') ?: '';
        $j = json_decode($raw, true);
        return is_array($j) ? $j : [];
    }
}
