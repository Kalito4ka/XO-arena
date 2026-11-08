<?php

declare(strict_types=1);

require_once __DIR__ . '/../database.php';
require_once __DIR__ . '/../Http/ApiException.php';
require_once __DIR__ . '/../Repository/UserRepository.php';
require_once __DIR__ . '/../Repository/GameRepository.php';
require_once __DIR__ . '/../Repository/UserStatsRepository.php';
require_once __DIR__ . '/../Repository/MatchmakingRepository.php';
require_once __DIR__ . '/../Service/AuthService.php';
require_once __DIR__ . '/../Service/MatchmakingService.php';

use App\Entity\Game;
use App\Entity\MatchmakingEntry;
use App\Entity\User;
use App\Entity\UserStats;
use App\Http\ApiException;
use App\Repository\GameRepository;
use App\Repository\MatchmakingRepository;
use App\Repository\UserRepository;
use App\Repository\UserStatsRepository;
use App\Service\AuthService;
use App\Service\MatchmakingService;

function checkEntity(bool $condition, string $message): void
{
    if (!$condition) throw new RuntimeException($message);
}

$db = getDatabaseConnection();
$schema = 'xo_entities_test_' . bin2hex(random_bytes(6));
$db->exec("CREATE SCHEMA $schema");

try {
    $db->exec("SET search_path TO $schema");
    foreach (['users', 'games', 'user_stats', 'matchmaking_queue'] as $table) {
        $db->exec("CREATE TABLE $table (LIKE public.$table INCLUDING ALL)");
    }
    $db->exec('ALTER TABLE games ADD FOREIGN KEY (x_user_id) REFERENCES users(id)');
    $db->exec('ALTER TABLE games ADD FOREIGN KEY (o_user_id) REFERENCES users(id)');
    $db->exec('ALTER TABLE games ADD FOREIGN KEY (rematch_id) REFERENCES games(id)');
    $db->exec('ALTER TABLE user_stats ADD FOREIGN KEY (user_id) REFERENCES users(id)');
    $db->exec('ALTER TABLE matchmaking_queue ADD FOREIGN KEY (user_id) REFERENCES users(id)');
    $db->exec('ALTER TABLE matchmaking_queue ADD FOREIGN KEY (game_id) REFERENCES games(id)');

    $users = new UserRepository($db);
    $games = new GameRepository($db);
    $stats = new UserStatsRepository($db);
    $queue = new MatchmakingRepository($db);
    $auth = new AuthService($db);
    $password = 'entity_test_password';
    $first = $users->create('entity_first', password_hash($password, PASSWORD_DEFAULT));
    $second = $users->create('entity_second', password_hash($password, PASSWORD_DEFAULT));

    checkEntity($first instanceof User && $users->find($first->id)?->id === $first->id,
        'Пользователь не восстановлен как сущность.');
    checkEntity($users->findByNickname('ENTITY_FIRST')?->verifiesPassword($password) === true,
        'Не работает вход без учёта регистра.');
    checkEntity(!$first->verifiesPassword('wrong_password'), 'Принят неверный пароль.');
    checkEntity(array_keys($first->toPublicArray()) === ['id', 'nickname', 'createdAt']
        && !str_contains(json_encode($first, JSON_THROW_ON_ERROR), 'password'),
        'Хеш пароля попал в публичные данные.');
    checkEntity($auth->login('ENTITY_FIRST', $password) === $first->toPublicArray(),
        'Изменился ответ авторизации.');
    try {
        $auth->login('entity_first', 'wrong_password');
        throw new RuntimeException('Ожидался отказ при входе.');
    } catch (ApiException $error) {
        checkEntity($error->statusCode === 401, 'Неверный статус отказа при входе.');
    }
    checkEntity($users->find(PHP_INT_MAX) === null, 'Отсутствующий пользователь должен давать NULL.');

    $empty = $stats->find($first->id, 'classic');
    checkEntity($empty instanceof UserStats && $empty->played() === 0 && $empty->winRate() === 0.0,
        'Неверная статистика нового игрока.');
    $stats->increment(new UserStats($first->id, 'classic', 1, 1, 1));
    $restored = $stats->find($first->id, 'classic');
    checkEntity($restored->played() === 3 && $restored->winRate() === 33.33,
        'Неверно восстановлены счётчики или процент побед.');

    // Все формы истории ходов должны пройти JSONB без изменения структуры.
    foreach ([
        'classic' => [0, 4],
        'ultimate' => [['board' => 4, 'cell' => 2], ['board' => 2, 'cell' => 4]],
        'gobblers' => [['cell' => 0, 'size' => 1, 'from' => null]],
    ] as $mode => $moves) {
        $db->beginTransaction();
        $games->lockPlayers();
        $id = bin2hex(random_bytes(16));
        $game = $games->createWaiting($id, $mode, hash('sha256', 'token-x'), null, 'guest-x');
        checkEntity($game instanceof Game && $game->isWaiting() && $game->moves === []
            && $game->oUserId === null && !$game->rematchX && !$game->rematchO,
            'Неверно восстановлены пустые поля партии.');
        $game = $games->join($id, hash('sha256', 'token-o'), null, 'guest-o');
        $game = $games->saveMoves($id, $moves);
        // JSONB может менять порядок ключей объекта внутри хода, сохраняя их значения.
        checkEntity(!$game->isWaiting() && $games->find($id, true)?->moves == $moves
            && $game->guestKeyFor('O') === 'guest-o', "Потеряны данные партии или ходов: $mode.");
        checkEntity(!str_contains(json_encode($game, JSON_THROW_ON_ERROR), 'TokenHash')
            && !str_contains(json_encode($game, JSON_THROW_ON_ERROR), 'GuestKey'),
            'Закрытые ключи партии попали в публичные данные.');
        $db->rollBack();
        checkEntity($games->find($id) === null, 'Отмена транзакции не откатила партию.');

        $waiting = $queue->touch($first->id, $mode);
        checkEntity($waiting instanceof MatchmakingEntry && !$waiting->isMatched()
            && $queue->find($first->id)?->mode === $mode, 'Не восстановлена заявка подбора.');
        $matchmaking = new MatchmakingService($db);
        $matched = $matchmaking->search($second->id, $mode);
        $game = $games->find($matched['gameId']);
        checkEntity($matched['status'] === 'matched' && $game?->xUserId === $first->id
            && $game->oUserId === $second->id && $game->mode === $mode,
            'Подбор не создал партию нужного режима.');
        checkEntity($queue->findMatched($first->id, $mode)?->gameId === $game->id
            && $queue->find($second->id)?->isMatched() === true,
            'Заявки не связаны с найденной партией.');
        checkEntity($matchmaking->cancel($first->id)['gameId'] === $game->id,
            'Отмена потеряла уже найденную партию.');
        $queue->delete($first->id);
        $queue->delete($second->id);
    }

    fwrite(STDOUT, "Сущности, репозитории, авторизация и подбор во всех режимах проверены\n");
} finally {
    if ($db->inTransaction()) $db->rollBack();
    $db->exec('SET search_path TO public');
    $db->exec("DROP SCHEMA $schema CASCADE");
}
