<?php

declare(strict_types=1);

ini_set('zend.exception_ignore_args', '1');

require_once __DIR__ . '/../src/database.php';
require_once __DIR__ . '/../src/Http/games.php';
require_once __DIR__ . '/../src/Http/auth.php';

function sendJson(array $data, int $status = 200): never
{
    http_response_code($status);

    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');

    echo json_encode(
        $data,
        JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR
    );

    exit;
}

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

$path = parse_url(
    $_SERVER['REQUEST_URI'] ?? '/',
    PHP_URL_PATH
);

if (
    is_string($path)
    && (
        $path === '/api/auth'
        || str_starts_with($path, '/api/auth/')
    )
) {
    handleAuthApi($path, $method);
}

if (
    is_string($path)
    && (
        $path === '/api/games'
        || str_starts_with($path, '/api/games/')
    )
) {
    handleGamesApi($path, $method);
}

$knownPaths = [
    '/api/health',
    '/api/health/database',
    '/api/game-modes',
];

if (!in_array($path, $knownPaths, true)) {
    sendJson(
        ['message' => 'Маршрут не найден.'],
        404
    );
}

if ($method !== 'GET') {
    header('Allow: GET');

    sendJson(
        ['message' => 'Метод запроса не поддерживается.'],
        405
    );
}

switch ($path) {
    case '/api/health':
        sendJson([
            'status' => 'ok',
            'service' => 'xo-arena-php',
        ]);

    case '/api/health/database':
        try {
            $connection = getDatabaseConnection();

            $connection->query('SELECT 1');

            sendJson([
                'status' => 'ok',
                'database' => 'connected',
            ]);
        } catch (Throwable $exception) {
            error_log((string) $exception);

            sendJson(
                [
                    'status' => 'error',
                    'database' => 'unavailable',
                    'message' => 'База данных временно недоступна',
                ],
                503
            );
        }

    case '/api/game-modes':
        sendJson([
            'modes' => [
                [
                    'id' => 'classic',
                    'name' => 'Классика',
                    'description' =>
                        'Поле 3×3. Соберите три своих символа в ряд.',
                ],
                [
                    'id' => 'ultimate',
                    'name' => 'Ультимативный',
                    'description' =>
                        'Девять маленьких полей. Выиграйте три поля в ряд.',
                ],
                [
                    'id' => 'gobblers',
                    'name' => 'Пожирающий',
                    'description' =>
                        'Фишки трёх размеров. Накрывайте меньшие и перемещайте свои.',
                ],
            ],
        ]);
}