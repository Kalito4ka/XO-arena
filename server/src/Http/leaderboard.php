<?php

// обрабатывает публичный запрос таблицы лидеров

declare(strict_types=1);

use App\Http\ApiException;
use App\Service\LeaderboardService;

require_once __DIR__ . '/ApiException.php';
require_once __DIR__ . '/../Service/LeaderboardService.php';

function handleLeaderboardApi(string $method): never
{
    try {
        if ($method !== 'GET') {
            header('Allow: GET');

            throw new ApiException(
                405,
                'Метод запроса не поддерживается.'
            );
        }

        $sort = $_GET['sort'] ?? 'wins';

        if (!is_string($sort)) {
            throw new ApiException(
                422,
                'Параметр sort должен быть строкой.'
            );
        }

        $service = new LeaderboardService(
            getDatabaseConnection()
        );

        sendJson($service->get($sort));
    } catch (ApiException $error) {
        sendJson(
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

        sendJson(
            ['message' => 'Не удалось загрузить таблицу лидеров.'],
            500
        );
    }
}