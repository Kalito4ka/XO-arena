<?php

declare(strict_types=1);

use App\Http\ApiException;
use App\Service\GameService;
use App\Service\MatchmakingService;

require_once __DIR__ . '/../Game/ClassicGame.php';
require_once __DIR__ . '/ApiException.php';
require_once __DIR__ . '/../Service/GameService.php';
require_once __DIR__ . '/../Service/MatchmakingService.php';
require_once __DIR__ . '/../Auth/gameAccount.php';
require_once __DIR__ . '/../Service/GameResultService.php';
require_once __DIR__ . '/../Service/GameTimeoutService.php';
require_once __DIR__ . '/../Game/UltimateGame.php';
require_once __DIR__ . '/../Game/GobblersGame.php';

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

function readPlayerToken(): ?string
{
    $token = $_SERVER['HTTP_X_PLAYER_TOKEN'] ?? null;

    if ($token === null) {
        return null;
    }

    if (
        !is_string($token)
        || preg_match('/^[a-f0-9]{64}$/D', $token) !== 1
    ) {
        throw new ApiException(
            401,
            'Некорректный формат гостевого ключа.'
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

    $board = null;

    if (property_exists($data, 'board')) {
        if (
            !is_int($data->board)
            || $data->board < 0
            || $data->board > 8
        ) {
            throw new ApiException(
                422,
                'Номер поля board должен быть целым числом от 0 до 8.'
            );
        }

        $board = $data->board;
    }

    $size = null;
    $from = null;

    if (property_exists($data, 'size')) {
        if (
            !is_int($data->size)
            || $data->size < 1
            || $data->size > 3
        ) {
            throw new ApiException(
                422,
                'Размер size должен быть целым числом от 1 до 3.'
            );
        }

        $size = $data->size;
    }

    if (property_exists($data, 'from')) {
        if (
            $data->from !== null
            && (
                !is_int($data->from)
                || $data->from < 0
                || $data->from > 8
            )
        ) {
            throw new ApiException(
                422,
                'Исходная клетка from должна быть null или целым числом от 0 до 8.'
            );
        }

        $from = $data->from;
    }

    return [
        'cell' => $data->cell,
        'version' => $data->version,
        'board' => $board,
        'size' => $size,
        'from' => $from,
    ];
}

function handleGamesApi(string $path, string $method): never
{
    try {
        if (
            $path === '/api/games/matchmaking'
            || $path === '/api/games/matchmaking/cancel'
        ) {
            requireMethod($method, 'POST');

            $db = getDatabaseConnection();
            $userId = readGameAccount($db, true);

            if ($userId === null) {
                throw new ApiException(
                    401,
                    'Войдите в аккаунт, чтобы искать соперника.'
                );
            }

            $matchmaking = new MatchmakingService($db);

            if ($path === '/api/games/matchmaking/cancel') {
                sendJson($matchmaking->cancel($userId));
            }

            $mode = $_GET['mode'] ?? 'classic';

            if (!is_string($mode)) {
                throw new ApiException(
                    422,
                    'Параметр mode должен быть строкой.'
                );
            }

            sendJson($matchmaking->search($userId, $mode));
        }

        $isCreate = $path === '/api/games';
        $isMine = $path === '/api/games/mine';

        $id = null;
        $action = '';

        if ($isCreate) {
            requireMethod($method, 'POST');
        } elseif ($isMine) {
            requireMethod($method, 'GET');
        } else {
            $matched = preg_match(
                '~^/api/games/([a-f0-9]{32})(?:/(join|moves|rematch))?$~D',
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
        }

        $db = getDatabaseConnection();

        $userId = readGameAccount(
            $db,
            $method === 'POST'
        );

        $service = new GameService(
            $db, $userId, $userId === null ? hash('sha256', session_id()) : null
        );

        if ($isCreate) {
            $mode = $_GET['mode'] ?? 'classic';

            if (!is_string($mode)) {
                throw new ApiException(
                    422,
                    'Параметр mode должен быть строкой.'
                );
            }

            sendJson($service->create($mode), 201);
        }

        if ($isMine) {
            sendJson($service->mine());
        }

        $token = readPlayerToken();

        if ($action === 'join') {
            sendJson($service->join($id));
        }

        if ($action === 'rematch') {
            sendJson($service->rematch($id, $token));
        }

        if ($action === 'moves') {
            $data = readMoveData();

            sendJson(
                $service->move(
                    $id,
                    $token,
                    $data['cell'],
                    $data['version'],
                    $data['board'],
                    $data['size'],
                    $data['from']
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
        error_log(sprintf(
            '%s: %s in %s:%d',
            get_class($error),
            $error->getMessage(),
            $error->getFile(),
            $error->getLine()
        ));

        sendJson(
            ['message' => 'Внутренняя ошибка сервера.'],
            500
        );
    }
}