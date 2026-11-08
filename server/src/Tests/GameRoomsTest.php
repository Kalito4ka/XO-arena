<?php

declare(strict_types=1);

require_once __DIR__ . '/../database.php';
require_once __DIR__ . '/../Http/ApiException.php';
require_once __DIR__ . '/../Game/ClassicGame.php';
require_once __DIR__ . '/../Game/UltimateGame.php';
require_once __DIR__ . '/../Game/GobblersGame.php';
require_once __DIR__ . '/../Service/AuthService.php';
require_once __DIR__ . '/../Service/GameService.php';
require_once __DIR__ . '/../Service/GameResultService.php';
require_once __DIR__ . '/../Service/GameTimeoutService.php';
require_once __DIR__ . '/../Service/MatchmakingService.php';
require_once __DIR__ . '/../Service/LeaderboardService.php';

use App\Http\ApiException;
use App\Service\AuthService;
use App\Service\GameService;
use App\Service\GameTimeoutService;
use App\Service\MatchmakingService;
use App\Service\LeaderboardService;

// останавливает проверку при неправильном результате
function checkRooms(bool $condition, string $message): void
{
    if (!$condition) throw new RuntimeException($message);
}

// проверяет ожидаемый отказ сервера
function expectRoomError(callable $action, int $status): string
{
    try {
        $action();
    } catch (ApiException $error) {
        checkRooms($error->statusCode === $status, $error->getMessage());
        return $error->getMessage();
    }

    throw new RuntimeException('Ожидался отказ сервера.');
}

$db = getDatabaseConnection();
$schema = 'xo_rooms_test_' . bin2hex(random_bytes(6));
$db->exec("CREATE SCHEMA $schema");

try {
    $db->exec("SET search_path TO $schema");
    foreach (['users', 'games', 'user_stats', 'matchmaking_queue'] as $table) {
        $db->exec("CREATE TABLE $table (LIKE public.$table INCLUDING ALL)");
    }
    $db->exec('ALTER TABLE games ADD FOREIGN KEY (rematch_id)
               REFERENCES games(id) ON DELETE SET NULL');
    $db->exec('ALTER TABLE matchmaking_queue ADD FOREIGN KEY (game_id)
               REFERENCES games(id) ON DELETE CASCADE');

    $auth = new AuthService($db);
    $first = $auth->register('rooms_first', 'rooms_test_password');
    $second = $auth->register('rooms_second', 'rooms_test_password');
    $third = $auth->register('rooms_third', 'rooms_test_password');
    $duplicate = expectRoomError(
        fn () => $auth->register('ROOMS_FIRST', 'rooms_test_password'), 409
    );
    checkRooms($duplicate === 'Это имя пользователя уже занято.', 'Неверный текст ошибки имени.');

    $x = new GameService($db, $first['id']);
    $o = new GameService($db, $second['id']);
    $other = new GameService($db, $third['id']);
    $waiting = $x->create('classic')['game']['id'];
    expectRoomError(fn () => $x->create('classic'), 409);
    $replacement = $x->create('gobblers')['game']['id'];
    expectRoomError(fn () => $x->get($waiting, null), 404);

    $db->exec("UPDATE games SET created_at = clock_timestamp() - INTERVAL '181 seconds'
               WHERE id = '$replacement'");
    expectRoomError(fn () => $o->join($replacement), 404);
    (new GameTimeoutService($db))->expireBatch();
    checkRooms((int) $db->query("SELECT COUNT(*) FROM games WHERE id = '$replacement'")->fetchColumn() === 0,
        'Просроченная комната не удалена.');

    $id = $x->create()['game']['id'];
    $o->join($id);
    expectRoomError(fn () => $x->create(), 409);
    $otherId = $other->create()['game']['id'];
    expectRoomError(fn () => $o->join($otherId), 409);
    $queue = new MatchmakingService($db);
    expectRoomError(fn () => $queue->search($first['id'], 'classic'), 409);

    foreach ([0, 3, 1, 4, 2] as $version => $cell) {
        ($version % 2 === 0 ? $x : $o)->move($id, null, $cell, $version);
    }
    $result = $x->get($id, null)['game'];
    checkRooms($result['roomStatus'] === 'finished' && $result['rematch']['remainingSeconds'] > 0,
        'Нет предложения повторной игры.');
    $accepted = $x->rematch($id, null)['game'];
    checkRooms($accepted['rematch']['X'] && $accepted['rematch']['gameId'] === null,
        'Одно согласие не должно создавать игру.');
    $next = $o->rematch($id, null)['game']['rematch']['gameId'];
    checkRooms(is_string($next) && $x->get($next, null)['game']['version'] === 0,
        'Новая партия не создана.');
    checkRooms($x->rematch($id, null)['game']['rematch']['gameId'] === $next,
        'Повторный запрос создал лишнюю партию.');
    checkRooms((int) $db->query("SELECT wins FROM user_stats WHERE user_id = {$first['id']} AND mode = 'classic'")->fetchColumn() === 1,
        'Результат учтён повторно.');

    $db->exec("UPDATE games SET rematch_id = NULL, rematch_x = FALSE, rematch_o = FALSE,
               finished_at = clock_timestamp() - INTERVAL '31 seconds' WHERE id = '$id'");
    expectRoomError(fn () => $o->rematch($id, null), 409);
    expectRoomError(fn () => $other->rematch($id, null), 403);

    $guestX = new GameService($db, null, hash('sha256', 'guest-x'));
    $guestO = new GameService($db, null, hash('sha256', 'guest-o'));
    $guestGame = $guestX->create();
    $guestId = $guestGame['game']['id'];
    $xToken = $guestGame['playerToken'];
    expectRoomError(fn () => $guestX->create(), 409);
    $oToken = $guestO->join($guestId)['playerToken'];
    foreach ([0, 3, 1, 4, 2] as $version => $cell) {
        ($version % 2 === 0 ? $guestX : $guestO)->move(
            $guestId, $version % 2 === 0 ? $xToken : $oToken, $cell, $version
        );
    }
    $guestX->rematch($guestId, $xToken);
    $guestNext = $guestO->rematch($guestId, $oToken)['game']['rematch']['gameId'];
    checkRooms($guestX->get($guestNext, $xToken)['symbol'] === 'X'
        && $guestO->get($guestNext, $oToken)['symbol'] === 'O', 'Потерян гостевой доступ.');

    checkRooms($queue->search($first['id'], 'ultimate')['status'] === 'waiting', 'Нет ожидания подбора.');
    $matched = $queue->search($second['id'], 'ultimate');
    checkRooms($matched['status'] === 'matched', 'Подбор не создал партию.');
    checkRooms($queue->search($first['id'], 'ultimate')['gameId'] === $matched['gameId'],
        'Подбор создал повторную партию.');

    $db->exec('DELETE FROM user_stats');
    $db->exec("INSERT INTO user_stats (user_id, mode, wins, losses, draws) VALUES
        ({$first['id']}, 'classic', 8, 2, 0),
        ({$second['id']}, 'classic', 6, 0, 0),
        ({$second['id']}, 'ultimate', 5, 0, 0),
        ({$third['id']}, 'gobblers', 10, 0, 0)");
    $leaders = new LeaderboardService($db);
    checkRooms($leaders->get('wins')['items'][0]['userId'] === $second['id'], 'Неверный рейтинг по победам.');
    checkRooms($leaders->get('played')['items'][0]['played'] === 11, 'Неверный рейтинг по партиям.');
    checkRooms($leaders->get('winRate')['items'][0]['winRate'] === 100.0, 'Неверный процент побед.');
    expectRoomError(fn () => $leaders->get('invalid'), 422);

    foreach (['ultimate' => 45, 'gobblers' => 30] as $mode => $seconds) {
        $left = new GameService($db, null, hash('sha256', $mode . '-left'));
        $right = new GameService($db, null, hash('sha256', $mode . '-right'));
        $created = $left->create($mode);
        $modeId = $created['game']['id'];
        $leftToken = $created['playerToken'];
        $rightToken = $right->join($modeId)['playerToken'];

        for ($turn = 0; $turn < 101; $turn++) {
            if ($left->get($modeId, $leftToken)['game']['roomStatus'] === 'finished') break;
            $db->exec("UPDATE games SET updated_at = clock_timestamp() - INTERVAL '60 seconds'
                       WHERE id = '$modeId'");
            (new GameTimeoutService($db))->expireBatch();
        }

        checkRooms($left->get($modeId, $leftToken)['game']['roomStatus'] === 'finished',
            "Автоматические ходы не завершили режим $mode.");
        $left->rematch($modeId, $leftToken);
        $newId = $right->rematch($modeId, $rightToken)['game']['rematch']['gameId'];
        $newGame = $left->get($newId, $leftToken)['game'];
        checkRooms($newGame['state']['mode'] === $mode && $newGame['version'] === 0
            && $newGame['remainingSeconds'] > $seconds - 2,
            "Повторная игра потеряла режим или время $mode.");
    }

    fwrite(STDOUT, "Проверки комнат, повторной игры, регистрации и рейтинга пройдены\n");
} finally {
    if ($db->inTransaction()) $db->rollBack();
    $db->exec('SET search_path TO public');
    $db->exec("DROP SCHEMA $schema CASCADE");
}
