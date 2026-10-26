<?php

// задача: запоминать, какой пользователь вошёл

declare(strict_types=1);

function startAuthSession(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        return;
    }

    ini_set('session.use_strict_mode', '1');
    ini_set('session.use_only_cookies', '1');
    ini_set('session.gc_maxlifetime', '7200');

    session_name('XOARENA_SESSION');

    session_save_path('/var/lib/php/sessions');

    $secure = (
        ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? 'http') === 'https'
    );

    session_set_cookie_params([
        'lifetime' => 0,
        'path' => '/api/',
        'secure' => $secure,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);

    if (!session_start()) {
        throw new RuntimeException(
            'Не удалось открыть сессию.'
        );
    }

    $lastActivity = $_SESSION['last_activity'] ?? null;

    if (is_int($lastActivity) && time() - $lastActivity > 7200){
        resetAuthSession(null);
    }

    $_SESSION['last_activity'] = time();

    if (!isset($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
}

function resetAuthSession(?int $userId): void 
{
    if (!session_regenerate_id(true)) {
        throw new RuntimeException(
            'Не удалось обновить идентификатор сессии.'
        );
    }

    $_SESSION = [
        'csrf_token' => bin2hex(random_bytes(32)),
        'last_activity' => time(),
    ];

    if ($userId !== null) {
        $_SESSION['user_id'] = $userId;
    }
}
