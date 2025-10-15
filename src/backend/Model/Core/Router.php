<?php
namespace App\Model\Core;

use FastRoute\RouteCollector;
use App\Controller\Auth\AuthController;
use App\Controller\Household\HouseholdController;
use App\Controller\User\UsersController;
use App\Controller\Event\EventsController;
use App\Controller\Product\ProductsController;
use App\Controller\Gift\GiftsController;
use App\Controller\Gift\GiftOrdersController;
use App\Controller\Wishlist\WishlistsController;

class Router
{
    /** Dispatch all /api routes. */
    public static function dispatchApi(): void
    {
        self::dispatchFromPath('/api');
    }

    public static function dispatchFromPath(string $prefix): void
    {
        try {
            $publicRoutes = [
                '/api/health',
                '/api/auth/token',
                '/api/auth/refresh',
                '/api/public/*',
            ];

            $dispatcher = \FastRoute\simpleDispatcher(function (RouteCollector $r) {

                // Healthcheck
                $r->addRoute('GET', '/health', function () {
                    return ['status' => 'success', 'data' => ['ok' => true]];
                });

                // Frontend config
                $r->addRoute('GET', '/public/config', function () {
                    return [
                        'status'      => 'success',
                        'status_code' => 200,
                        'data'        => [
                            'keycloak_base' => getenv('KEYCLOAK_BASE_URL'),
                            'client_id'     => getenv('KEYCLOAK_CLIENT_ID'),
                            'redirect_uri'  => getenv('KEYCLOAK_REDIRECT_URI'),
                        ],
                    ];
                });

                // AUTH
                $r->addRoute('POST', '/auth/token',   [AuthController::class, 'tokenExchange']);
                $r->addRoute('POST', '/auth/refresh', [AuthController::class, 'refresh']);
                $r->addRoute('GET',  '/auth/me',      [AuthController::class, 'me']);

                // ULID regex (26 chars, Crockford base32)
                $ULID = '[0-9A-HJKMNP-TV-Z]{26}';

                // HOUSEHOLDS
                $r->addRoute('POST',  '/households',      [HouseholdController::class, 'create']);
                $r->addRoute('GET',   '/households/mine', [HouseholdController::class, 'mine']);
                $r->addRoute('GET',   '/household',       [HouseholdController::class, 'showActive']);
                $r->addRoute('PATCH', '/household',       [HouseholdController::class, 'updateActive']);
                $r->addRoute('DELETE','/household',       [HouseholdController::class, 'destroyActive']);

                // USERS
                $r->addRoute('GET',    '/users',               [UsersController::class, 'index']);
                $r->addRoute('POST',   '/users',               [UsersController::class, 'create']);
                $r->addRoute('GET',    "/users/{id:$ULID}",    [UsersController::class, 'show']);
                $r->addRoute('PATCH',  "/users/{id:$ULID}",    [UsersController::class, 'update']);
                $r->addRoute('DELETE', "/users/{id:$ULID}",    [UsersController::class, 'destroy']);
                $r->addRoute('POST',   "/users/{id:$ULID}/avatar", [UsersController::class, 'uploadAvatar']);
                $r->addRoute('DELETE', "/users/{id:$ULID}/avatar", [UsersController::class, 'deleteAvatar']);

                // EVENTS
                $r->addRoute('GET',    '/events',                [EventsController::class, 'index']);
                $r->addRoute('POST',   '/events',                [EventsController::class, 'create']);
                $r->addRoute('GET',    "/events/{id:$ULID}",     [EventsController::class, 'show']);
                $r->addRoute('PATCH',  "/events/{id:$ULID}",     [EventsController::class, 'update']);
                $r->addRoute('DELETE', "/events/{id:$ULID}",     [EventsController::class, 'destroy']);

                // PRODUCTS
                $r->addRoute('GET',    '/products',            [ProductsController::class, 'index']);
                $r->addRoute('POST',   '/products',            [ProductsController::class, 'create']);
                $r->addRoute('GET',    "/products/{id:$ULID}", [ProductsController::class, 'show']);
                $r->addRoute('PATCH',  "/products/{id:$ULID}", [ProductsController::class, 'update']);
                $r->addRoute('DELETE', "/products/{id:$ULID}", [ProductsController::class, 'destroy']);
                $r->addRoute('GET',    "/products/{id:$ULID}/gift-items", [ProductsController::class, 'giftItems']); // kan beholdes hvis UI viser "relaterte ordrer"

                $r->addRoute('POST',   "/products/{id:$ULID}/image", [ProductsController::class, 'uploadImage']);
                $r->addRoute('DELETE', "/products/{id:$ULID}/image", [ProductsController::class, 'deleteImage']);

                // GIFT ORDERS (uten item-ruter)
                $r->addRoute('GET',    '/gift-orders',            [GiftOrdersController::class, 'index']);
                $r->addRoute('POST',   '/gift-orders',            [GiftOrdersController::class, 'create']);
                $r->addRoute('GET',    "/gift-orders/{id:$ULID}", [GiftOrdersController::class, 'show']);
                $r->addRoute('PATCH',  "/gift-orders/{id:$ULID}", [GiftOrdersController::class, 'update']);
                $r->addRoute('DELETE', "/gift-orders/{id:$ULID}", [GiftOrdersController::class, 'destroy']);

                // Liste orders for event
                $r->addRoute('GET',    "/events/{id:$ULID}/gift-orders", [GiftOrdersController::class, 'index']);

				// WISHLISTS
				$r->addRoute('GET',    '/wishlists',            [WishlistsController::class, 'index']);
				$r->addRoute('POST',   '/wishlists',            [WishlistsController::class, 'create']);
				$r->addRoute('GET',    "/wishlists/{id:$ULID}", [WishlistsController::class, 'show']);
				$r->addRoute('PATCH',  "/wishlists/{id:$ULID}", [WishlistsController::class, 'update']);
				$r->addRoute('DELETE', "/wishlists/{id:$ULID}", [WishlistsController::class, 'destroy']);

				// PRODUCTS – gift history for a product (across events)
				$r->addRoute('GET', "/products/{id:$ULID}/gift-orders", [ProductsController::class, 'giftHistory']);

				// USERS – received gifts for a user (across events)
				$r->addRoute('GET', "/users/{id:$ULID}/received-gifts", [UsersController::class, 'receivedGifts']);


            });

            // Klargjør request
            $httpMethod = $_SERVER['REQUEST_METHOD'] ?? 'GET';
            $uri = $_SERVER['REQUEST_URI'] ?? '/';

            if (false !== $pos = strpos($uri, '?')) {
                $uri = substr($uri, 0, $pos);
            }
            $uri = rawurldecode($uri);

            $trimmed = $uri;
            if (str_starts_with($trimmed, $prefix)) {
                $trimmed = substr($trimmed, strlen($prefix));
                if ($trimmed === '') $trimmed = '/';
            }

            if ($httpMethod === 'OPTIONS') {
                self::sendCorsHeaders();
                http_response_code(204);
                echo '';
                return;
            }

            if (!self::isPublic($uri, $publicRoutes)) {
                AuthMiddleware::checkAuthentication();
            }

            $routeInfo = $dispatcher->dispatch($httpMethod, $trimmed);

            switch ($routeInfo[0]) {
                case \FastRoute\Dispatcher::FOUND:
                    $handler = $routeInfo[1];
                    $vars    = $routeInfo[2];

                    if (is_array($handler) && is_string($handler[0])) {
                        [$class, $method] = $handler;
                        $instance = new $class();
                        $handler  = [$instance, $method];
                    }

                    $args = !empty($vars) ? [$vars] : [];
                    $result = call_user_func_array($handler, $args);

                    if (is_array($result)) {
                        $status = (int)($result['status_code'] ?? 200);
                        self::json($status, $result);
                    } else {
                        self::json(200, $result);
                    }
                    break;
            }
        } catch (\Throwable $e) {
            error_log('[Router] ' . $e->getMessage());
            self::json(500, [
                'status'      => 'error',
                'status_code' => 500,
                'message'     => 'Server error. Please try again later.',
            ]);
        }
    }

    private static function isPublic(string $fullUri, array $patterns): bool
    {
        foreach ($patterns as $pattern) {
            if (fnmatch($pattern, $fullUri)) return true;
        }
        return false;
    }

    private static function json(int $status, $payload): void
    {
        if (ob_get_length()) @ob_clean();
        self::sendCorsHeaders();
        http_response_code($status);
        header('Content-Type: application/json');
        echo json_encode($payload);
    }

    private static function sendCorsHeaders(): void
    {
        header('Access-Control-Allow-Origin: *');
        header('Access-Control-Allow-Methods: GET, POST, PUT, PATCH, DELETE, OPTIONS');
        header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With');
    }
}
