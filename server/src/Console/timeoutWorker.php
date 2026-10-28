<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

ini_set('zend.exception_ignore_args', '1');

require_once __DIR__ . '/../database.php';
require_once __DIR__ . '/../Service/GameResultService.php';
require_once __DIR__ . '/../Service/GameTimeoutService.php';

try {
    $db = getDatabaseConnection();

    $service = new \App\Service\GameTimeoutService($db);

    fwrite(STDOUT, "Проверка времени партий запущена\n");

    while (true) {
        $count = $service->expireBatch();

        if ($count > 0) {
            fwrite(
                STDOUT,
                sprintf(
                    "Завершено партий по времени: %d\n",
                    $count
                )
            );
        }

        sleep(1);
    }
} catch (Throwable $error) {
    fwrite(
        STDERR,
        sprintf(
            "%s: %s in %s:%d\n",
            get_class($error),
            $error->getMessage(),
            $error->getFile(),
            $error->getLine()
        )
    );

    exit(1);
}