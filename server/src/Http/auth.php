<?php

// обработчик api авторизации
// задача: принять запрос браузера, вызвать нужные действия и вернуть ответ

declare(strict_types=1);

use App\Http\ApiException;
use App\Service\AuthService;

require_once __DIR__ . '/ApiException.php';
require_once __DIR__ . '/../Auth/session.php';
require_once __DIR__ . '/../Service/AuthService.php';

// сохраняет сессию и json-ответ
function sendAuthJson(array $data, int $status = 200): never
{
    // если сессия открыта, сохраняем её данные и закрываем её
    if (session_status() === PHP_SESSION_ACTIVE){
        session_write_close();
    }

    sendJson($data, $status);
}

// читает никнейм и пароль из тела http-запроса
function readAuthCredentials(): array
{
    $contentType = strtolower(
        trim(explode(';', $_SERVER['CONTENT_TYPE'] ?? '')[0])
    );

    if ($contentType !== 'application/json') {
        throw new ApiException(
            415,
            'Ожидается application/json.'
        );
    }

    $raw = file_get_contents(
        'php://input',
        false,
        null,
        0,
        4097
    );

    if ($raw === false) {
        throw new ApiException(400, 'Не удалось прочитать запрос.');
    }

    if (strlen($raw) > 4096) {
        throw new ApiException(413, 'Слишком большое тело запроса.');
    }

    try {
        $data = json_decode(
            $raw,
            false,
            512,
            JSON_THROW_ON_ERROR
        );
    } catch (JsonException) {
        throw new ApiException(400, 'Некорректный JSON.');
    }

    // проверяем структуру полученных данных
    if (
        !$data instanceof stdClass
        || !isset($data->nickname, $data->password)
        || !is_string($data->nickname)
        || !is_string($data->password)
    ) {
        throw new ApiException(
            422,
            'Передайте строки nickname и password.'
        );
    }

    return [
        'nickname' => $data->nickname,
        'password' => $data->password,
    ];
}

// проверяет CSRF-токен запроса
// вызывается после открытия сессии и создания токена
function checkAuthCsrf(): void
{
    $token = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';

    if (
        !is_string($token)
        || !hash_equals($_SESSION['csrf_token'], $token)
    ) {
        throw new ApiException(
            403,
            'Данные сессии изменились. '
            . 'Нажмите «Обновить данные аккаунта».'
        );
    }
}

// главная функция обработки запросов авторизации
function handleAuthApi(string $path, string $method): never
{
    try {
        // таблица разрешенных маршрутов
        $routes = [
            '/api/auth/me' => 'GET',
            '/api/auth/register' => 'POST',
            '/api/auth/login' => 'POST',
            '/api/auth/logout' => 'POST',
        ];

        if (!isset($routes[$path])) {
            throw new ApiException(404, 'Маршрут не найден.');
        }

        if ($method !== $routes[$path]) {
            header('Allow: ' . $routes[$path]);

            throw new ApiException(
                405,
                'Метод запроса не поддерживается.'
            );
        }

        // настраиваем и открываем сессию
        startAuthSession();

        // обработка GET /api/auth/me
        // узнать, кто сейчас авторизован и получить CSRF-токен
        if ($path === '/api/auth/me') {
            $user = null;

            if (isset($_SESSION['user_id'])) {
                $service = new AuthService(
                    getDatabaseConnection()
                );

                $user = $service->find(
                    (int) $_SESSION['user_id']
                );

                if ($user === null) {
                    resetAuthSession(null);
                }
            }

            sendAuthJson([
                'user' => $user,
                'csrfToken' => $_SESSION['csrf_token'],
            ]);
        }

        checkAuthCsrf();

        // обработка POST /api/auth/logout
        if ($path === '/api/auth/logout') {
            resetAuthSession(null);

            sendAuthJson([
                'user' => null,
                'csrfToken' => $_SESSION['csrf_token'],
            ]);
        }

        // если пользователь уже вошёл, запрещаем повторный вход
        // или регистрацию, пока он не выйдет
        if (isset($_SESSION['user_id'])) {
            throw new ApiException(
                409,
                'Сначала выйдите из текущего аккаунта.'
            );
        }

        $credentials = readAuthCredentials();

        // создаем объект для работы с пользователями в базе
        $service = new AuthService(
            getDatabaseConnection()
        );

        $user = $path === '/api/auth/register'
            ? $service->register(
                $credentials['nickname'],
                $credentials['password']
            )
            : $service->login(
                $credentials['nickname'],
                $credentials['password']
            );

        resetAuthSession($user['id']);

        sendAuthJson(
            [
                'user' => $user,
                'csrfToken' => $_SESSION['csrf_token'],
            ],

            $path === '/api/auth/register' ? 201 : 200
        );
    } catch (ApiException $error) {
        sendAuthJson(
            ['message' => $error->getMessage()],
            $error->statusCode
        );
    } catch (Throwable $error) {
        error_log(sprintf(
            '%s: %s in %s:%d',
            get_class($error),
            $error->getMessage(),
            $error->getFile(),
            $error->getLine()
        ));

        sendAuthJson(
            ['message' => 'Внутренняя ошибка сервера.'],
            500
        );
    }
}