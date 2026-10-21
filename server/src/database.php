<?php

declare(strict_types=1);

function getDatabaseConnection(): PDO
{
    $host = getenv('PGHOST');
    $port = getenv('PGPORT');
    $database = getenv('PGDATABASE');
    $user = getenv('PGUSER');
    $password = getenv('PGPASSWORD');

    if (
        $host === false || $host === ''
        || $port === false || $port === ''
        || $database === false || $database === ''
        || $user === false || $user === ''
        || $password === false || $password === ''
    ) {
        throw new RuntimeException(
            'Не заданы обязательные настройки подключения к базе данных.'
        );
    }

    $dsn = sprintf(
        'pgsql:host=%s;port=%s;dbname=%s;connect_timeout=5',
        $host,
        $port,
        $database
    );

    return new PDO(
        $dsn,
        $user,
        $password,
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]
    );
}