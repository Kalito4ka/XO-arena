<?php

declare(strict_types=1);

use App\Http\ApiException;
use App\Service\ClassicGameService;

require_once __DIR__ . '/../Game/ClassicGame.php';
require_once __DIR__ . '/ApiException.php';
require_once __DIR__ . '/../Service/ClassicGameService.php';

function requireMethod(string $actual, string $allowed): void
{
    if ($actual !== $allowed) {
        header('Allow: ' . $allowed);

        throw new ApiException(
            405,
            'Метод запроса не поддерживается.'
        );
    }
}

function readPlayerToken(): string
{
    $token = $_SERVER['HTTP_X_PLAYER_TOKEN'] ?? '';

    if (
        !is_string($token)
        || preg_match('/^[a-f0-9]{64}$/D', $token) !== 1
    ) {
        throw new ApiException(
            401,
            'Передайте ключ игрока в заголовке X-Player-Token.'
        );
    }

    return $token;
}

function readMoveData(): array
{
    $contentType = strtolower(
        trim(explode(';', $_SERVER['CONTENT_TYPE'] ?? '')[0])
    );

    if ($contentType !== 'application/json') {
        throw new ApiException(
            415,
            'Тело запроса должно иметь формат application/json.'
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

    if (!$data instanceof stdClass) {
        throw new ApiException(400, 'Ожидается JSON-объект.');
    }

    if (
        !isset($data->cell, $data->version)
        || !is_int($data->cell)
        || !is_int($data->version)
        || $data->version < 0
    ) {
        throw new ApiException(
            422,
            'Нужны целые числа cell и version; version не меньше нуля.'
        );
    }

    return [
        'cell' => $data->cell,
        'version' => $data->version,
    ];
}

function handleGamesApi(string $path, string $method): never
{
    try {
        if ($path === '/api/games') {
            requireMethod($method, 'POST');

            $service = new ClassicGameService(
                getDatabaseConnection()
            );

            sendJson($service->create(), 201);
        }

        $matched = preg_match(
            '~^/api/games/([a-f0-9]{32})(?:/(join|moves))?$~D',
            $path,
            $matches
        );

        if ($matched !== 1) {
            throw new ApiException(404, 'Маршрут не найден.');
        }

        $id = $matches[1];
        $action = $matches[2] ?? '';

        requireMethod(
            $method,
            $action === '' ? 'GET' : 'POST'
        );

        $token = null;
        $moveData = null;

        if ($action !== 'join') {
            $token = readPlayerToken();
        }

        if ($action === 'moves') {
            $moveData = readMoveData();
        }

        $service = new ClassicGameService(
            getDatabaseConnection()
        );

        if ($action === 'join') {
            sendJson($service->join($id));
        }

        if ($action === 'moves') {
            sendJson(
                $service->move(
                    $id,
                    $token,
                    $moveData['cell'],
                    $moveData['version']
                )
            );
        }

        sendJson($service->get($id, $token));
    } catch (ApiException $error) {
        sendJson(
            ['message' => $error->getMessage()],
            $error->statusCode
        );
    } catch (DomainException | InvalidArgumentException $error) {
        sendJson(
            ['message' => $error->getMessage()],
            422
        );
    } catch (Throwable $error) {
        error_log((string) $error);

        sendJson(
            ['message' => 'Внутренняя ошибка сервера.'],
            500
        );
    }
}