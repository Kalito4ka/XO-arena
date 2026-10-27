<?php

// код определяет, какой аккаунт связан с текущим запросом к игре

declare(strict_types=1);

use App\Http\ApiException;
use App\Service\AuthService;

require_once __DIR__ . '/session.php';
require_once __DIR__ . '/../Http/ApiException.php';
require_once __DIR__ . '/../Service/AuthService.php';

function readGameAccount(
    PDO $db,
    bool $checkCsrf
): ?int {
    startAuthSession();

    try {
        $userId = null;

        if (isset($_SESSION['user_id'])) {
            $service = new AuthService($db);

            $user = $service->find(
                (int) $_SESSION['user_id']
            );

            if ($user === null) {
                resetAuthSession(null);
            } else {
                $userId = $user['id'];
            }
        }

        if ($checkCsrf) {
            $token = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';

            if (
                !is_string($token)
                || !hash_equals(
                    $_SESSION['csrf_token'],
                    $token
                )
            ) {
                throw new ApiException(
                    403,
                    'Данные сессии изменились. '
                    . 'Обновите данные аккаунта.'
                );
            }
        }
        return $userId;

    } finally {
        session_write_close();
    }
}