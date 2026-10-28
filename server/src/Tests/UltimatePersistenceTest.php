<?php

declare(strict_types=1);

require_once __DIR__ . '/../database.php';
require_once __DIR__ . '/../Game/ClassicGame.php';
require_once __DIR__ . '/../Game/UltimateGame.php';
require_once __DIR__ . '/../Http/ApiException.php';
require_once __DIR__ . '/../Service/GameResultService.php';
require_once __DIR__ . '/../Service/GameTimeoutService.php';
require_once __DIR__ . '/../Service/ClassicGameService.php';

use App\Service\ClassicGameService;

// останавливает проверку при неверном результате
function checkUltimatePersistence(
    bool $condition,
    string $message
): void {
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$db = getDatabaseConnection();
$id = null;

try {
    $service = new ClassicGameService($db);

    $created = $service->create('ultimate');

    $id = $created['game']['id'];
    $xToken = $created['playerToken'];

    $joined = $service->join($id);
    $oToken = $joined['playerToken'];

    $service->move($id, $xToken, 2, 0, 4);
    $service->move($id, $oToken, 4, 1, 2);

    $reader = new ClassicGameService($db);

    $restored = $reader->get($id, $xToken);
    $state = $restored['game']['state'];

    checkUltimatePersistence(
        $restored['game']['version'] === 2
        && $state['mode'] === 'ultimate'
        && $state['boards'][4][2] === 'X'
        && $state['boards'][2][4] === 'O'
        && $state['requiredBoard'] === 4
        && $state['currentPlayer'] === 'X',
        'Партия неверно восстановлена из базы.'
    );

    $statement = $db->prepare(
        'SELECT mode, moves FROM games WHERE id = :id'
    );

    $statement->execute(['id' => $id]);
    $row = $statement->fetch();

    $moves = json_decode(
        $row['moves'],
        true,
        512,
        JSON_THROW_ON_ERROR
    );

    checkUltimatePersistence(
        $row['mode'] === 'ultimate'
        && is_array($moves)
        && array_is_list($moves)
        && count($moves) === 2
        && is_array($moves[0])
        && is_array($moves[1])
        && count($moves[0]) === 2
        && count($moves[1]) === 2
        && ($moves[0]['board'] ?? null) === 4
        && ($moves[0]['cell'] ?? null) === 2
        && ($moves[1]['board'] ?? null) === 2
        && ($moves[1]['cell'] ?? null) === 4,
        'Неверный формат сохранённых ходов.'
    );

    fwrite(
        STDOUT,
        "Сохранение и восстановление ультимативной партии проверены\n"
    );
} catch (Throwable $error) {
    fwrite(STDERR, $error->getMessage() . PHP_EOL);
    $failed = true;
} finally {
    if ($id !== null) {
        $statement = $db->prepare(
            'DELETE FROM games WHERE id = :id'
        );

        $statement->execute(['id' => $id]);
    }
}

exit(isset($failed) ? 1 : 0);