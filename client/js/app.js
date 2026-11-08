import { request } from './api.js';

import {
    ensureStorage,
    savePlayer,
    getPlayer,
    getLastGameId,
} from './storage.js';

import {
    accountReady,
    getAccountSnapshot,
} from './auth.js?v=layout-3';

import { createUltimateBoard } from './ultimateBoard.js?v=symbols-2';

import { createGobblersBoard } from './gobblersBoard.js?v=gobblers-2';

const ID_PATTERN = /^[a-f0-9]{32}$/;

const createButton = document.querySelector('#create-button');
const joinForm = document.querySelector('#join-form');
const joinInput = document.querySelector('#join-input');
const joinButton = document.querySelector('#join-button');

const message = document.querySelector('#message');
const gamePanel = document.querySelector('#game-panel');
const playerInfo = document.querySelector('#player-info');
const turnInfo = document.querySelector('#turn-info');
const turnTimer = document.querySelector('#turn-timer');
const rematchPanel = document.querySelector('#rematch-panel');
const rematchButton = document.querySelector('#rematch-button');
const rematchMessage = document.querySelector('#rematch-message');

const board = document.querySelector('#board');

const modeSelect = document.querySelector('#game-mode');
const gameTitle = document.querySelector('#game-title');

const ultimateRoot = document.querySelector('#ultimate-board');
const ultimateHint = document.querySelector('#ultimate-hint');
const modeDescription = document.querySelector('#mode-description');
const gobblersRoot = document.querySelector('#gobblers-board');
const gobblersHint = document.querySelector('#gobblers-hint');

const matchmakingStart = document.querySelector('#matchmaking-start');
const matchmakingCancel = document.querySelector('#matchmaking-cancel');
const matchmakingMessage = document.querySelector('#matchmaking-message');

// объясняет выбранный режим и его ограничение времени
function renderModeDescription() {
    const descriptions = {
        classic: 'Собери три символа в ряд. На ход — 15 секунд.',
        ultimate: 'Девять связанных полей. На ход — 45 секунд.',
        gobblers: 'Накрывай меньшие фишки и перемещай свои. На ход — 30 секунд.',
    };

    modeDescription.textContent = descriptions[modeSelect.value];
}

modeSelect.addEventListener('change', renderModeDescription);
renderModeDescription();

let player = null;
let game = null;

let busy = false;
let synced = false;
let storageReady = false;
let searchMode = null;
let searchTimer = null;

let accountRevision = 0;
let turnTimerDeadline = null;
let rematchDeadline = null;

function clearCurrentGame() {
    player = null;
    game = null;
    synced = false;
    turnTimerDeadline = null;
    rematchDeadline = null;
}

window.addEventListener('xo:account-changed', () => {
    accountRevision++;
    stopMatchmaking();
    matchmakingMessage.textContent = '';

    clearCurrentGame();
    render();

    setMessage(
        'Аккаунт изменился. Открой свою партию в профиле '
        + 'или присоединись по приглашению.',
    );
});

function setMessage(text, error = false) {
    if (message.textContent !== text) {
        message.textContent = text;
    }

    message.classList.toggle('error', error);
}

function setText(element, text) {
    if (element.textContent !== text) {
        element.textContent = text;
    }
}

function gameUrl(id) {
    const url = new URL(window.location.pathname, window.location.origin);

    url.searchParams.set('game', id);

    return url.href;
}

function parseGameId(value) {
    const trimmed = value.trim();

    if (ID_PATTERN.test(trimmed)) {
        return trimmed;
    }

    try {
        const url = new URL(trimmed);
        const id = url.searchParams.get('game');

        if (id !== null && ID_PATTERN.test(id)) {
            return id;
        }
    } catch {
        // Ни кодом, ни корректной ссылкой значение не оказалось.
    }

    throw new Error(
        'Вставьте код из 32 символов или ссылку приглашения.',
    );
}

function isMyTurn() {
    return (
        player !== null
        && game !== null
        && synced
        && game.roomStatus === 'active'
        && game.state.status === 'playing'
        && game.state.currentPlayer === player.symbol
    );
}

const cells = Array.from({ length: 9 }, (_, index) => {
    const button = document.createElement('button');

    button.type = 'button';
    button.className = 'cell';

    button.addEventListener('click', () => {
        void sendMove(index);
    });

    board.append(button);

    return button;
});

const renderUltimateBoard = createUltimateBoard(
    ultimateRoot,
    (boardIndex, cellIndex) => {
        void sendMove(cellIndex, boardIndex);
    },
);

const renderGobblersBoard = createGobblersBoard(
    gobblersRoot,
    (move) => {
        void sendGobblersMove(move);
    },
);

// показывает оставшееся время хода по последнему ответу сервера
function renderTurnTimer() {
    const visible = (
        player !== null
        && game !== null
        && synced
        && game.roomStatus === 'active'
        && turnTimerDeadline !== null
    );

    turnTimer.hidden = !visible;

    if (!visible) {
        turnTimer.textContent = '';
        turnTimer.classList.remove('warning');

        return;
    }

    const remaining = Math.max(
        0,
        Math.ceil(
            (turnTimerDeadline - performance.now()) / 1000,
        ),
    );

    turnTimer.classList.toggle('warning', remaining <= 10);

    setText(
        turnTimer,
        remaining > 0
            ? `На ход ${game.state.currentPlayer} осталось: ${remaining} с`
            : 'Время вышло. Ожидаем результат…',
    );
}

// показывает кнопку повторной игры только в течение тридцати секунд
function renderRematch() {
    const remaining = rematchDeadline === null
        ? 0 : Math.max(0, Math.ceil((rematchDeadline - performance.now()) / 1000));
    const visible = player !== null && game?.roomStatus === 'finished'
        && remaining > 0 && !game.rematch.gameId;

    rematchPanel.hidden = !visible;
    if (!visible) return;

    const agreed = game.rematch[player.symbol];
    rematchButton.disabled = busy || !synced || agreed;
    setText(rematchMessage, agreed
        ? `Ждём согласия соперника. Осталось ${remaining} с`
        : `Сыграть ещё раз можно в течение ${remaining} с`);
}

// показывает состояние партии и поле соответствующего режима
function render() {
    const searching = searchMode !== null;
    const controlsLocked = busy || !storageReady || searching;
    const signedIn = getAccountSnapshot().user !== null;

    createButton.disabled = controlsLocked;
    modeSelect.disabled = controlsLocked;
    joinButton.disabled = controlsLocked;
    joinInput.disabled = controlsLocked;

    matchmakingStart.hidden = searching;
    matchmakingStart.disabled = controlsLocked || !signedIn;

    matchmakingCancel.hidden = !searching;
    matchmakingCancel.disabled = busy;

    if (!signedIn) {
        setText(
            matchmakingMessage,
            'Войди в аккаунт, чтобы найти соперника.',
        );
    } else if (
        matchmakingMessage.textContent ===
        'Войди в аккаунт, чтобы найти соперника.'
    ) {
        setText(matchmakingMessage, '');
    }

    gamePanel.hidden = player === null;

    renderTurnTimer();
    renderRematch();

    if (player === null) {
        return;
    }

    const mode = game?.state.mode;
    const ultimate = mode === 'ultimate';
    const gobblers = mode === 'gobblers';

    board.hidden = ultimate || gobblers;
    ultimateRoot.hidden = !ultimate;
    ultimateHint.hidden = !ultimate;
    gobblersRoot.hidden = !gobblers;
    gobblersHint.hidden = !gobblers;

    const titles = {
        classic: 'Классическая партия',
        ultimate: 'Ультимативная партия',
        gobblers: 'Пожирающая партия',
    };

    setText(
        gameTitle,
        titles[mode] ?? 'Загрузка партии',
    );

    setText(
        playerInfo,
        player.symbol === null
            ? 'Определяем сторону игрока…'
            : `Ты играешь за ${player.symbol}`,
    );

    let turnText;

    if (game === null) {
        turnText = 'Загружаем партию…';
    } else if (!synced) {
        turnText = 'Обновляем состояние поля…';
    } else if (game.roomStatus === 'waiting') {
        turnText = 'Ожидаем второго игрока.';
    } else if (game.state.status === 'won') {
        if (game.endReason === 'timeout') {
            turnText = game.state.winner === player.symbol
                ? `Победа! Соперник не сделал ход за ${game.state.mode === 'classic' ? 15 : 45} секунд.`
                : 'Поражение по времени: срок хода истёк.';
        } else {
            turnText = game.state.winner === player.symbol
                ? 'Победа!'
                : `Победил ${game.state.winner}.`;
        }
    } else if (game.state.status === 'draw') {
        turnText = 'Ничья!';
    } else {
        turnText = isMyTurn()
            ? 'Твой ход.'
            : `Ход соперника: ${game.state.currentPlayer}.`;

        if (ultimate) {
            turnText += game.state.requiredBoard === null
                ? ' Можно выбрать любое открытое поле.'
                : ` Играть нужно на поле ${game.state.requiredBoard + 1}.`;
        }
    }

    setText(turnInfo, turnText);

    if (gobblers) {
        renderGobblersBoard(game.state, {
            gameId: game.id,
            symbol: player.symbol,
            canMove: isMyTurn(),
            busy,
        });

        return;
    }

    if (ultimate) {
        renderUltimateBoard(game.state, {
            active: synced && game.roomStatus === 'active',
            canMove: isMyTurn(),
            busy,
        });

        return;
    }

    const state = game?.state;
    const winningLine = state?.winningLine ?? [];

    cells.forEach((button, index) => {
        const symbol = state?.board[index] ?? null;

        setText(button, symbol ?? '');

        button.dataset.symbol = symbol ?? '';

        button.classList.toggle(
            'winning',
            winningLine.includes(index),
        );

        button.disabled = (
            busy
            || !isMyTurn()
            || symbol !== null
        );

        button.setAttribute(
            'aria-label',
            `Строка ${Math.floor(index / 3) + 1}, `
            + `столбец ${index % 3 + 1}: `
            + (symbol ?? 'пусто'),
        );
    });
}

async function run(action) {
    if (busy) {
        return;
    }

    const revision = accountRevision;

    busy = true;
    render();

    try {
        await action();
    } catch (error) {
        synced = false;

        if ([401, 403, 404].includes(error.status)) {
            clearCurrentGame();
        }

        setMessage(error.message, true);
    } finally {
        if (revision !== accountRevision) {
            clearCurrentGame();

            setMessage(
                'Аккаунт изменён. Откройте нужную партию заново.',
            );
        }

        busy = false;
        render();
    }
}

function applyState(data) {
    const wasFinished = game?.roomStatus === 'finished';

    game = data.game;
    player.symbol = data.symbol;
    synced = true;

    turnTimerDeadline = (
        game.roomStatus === 'active'
        && typeof game.remainingSeconds === 'number'
    )
        ? performance.now() + game.remainingSeconds * 1000
        : null;

    rematchDeadline = game.rematch.remainingSeconds > 0
        ? performance.now() + game.rematch.remainingSeconds * 1000 : null;

    if (!wasFinished && game.roomStatus === 'finished') {
        window.dispatchEvent(
            new Event('xo:games-updated'),
        );
    }
}

function enterGame(id, data) {
    const ownerId = data.game.userIds[data.symbol];

    const savedPlayer = {
        token: ownerId === null
            ? (
                data.playerToken
                ?? getPlayer(id)?.token
                ?? null
            )
            : null,

        symbol: data.symbol,
    };

    savePlayer(id, savedPlayer);

    player = {
        id,
        ...savedPlayer,
    };

    applyState(data);

    joinInput.value = id;

    window.history.replaceState(
        null,
        '',
        gameUrl(id),
    );

    window.dispatchEvent(
        new Event('xo:games-updated'),
    );
}

async function refreshGame() {
    const data = await request(`/api/games/${player.id}`, {
        token: player.token,
    });

    const wasOutOfSync = !synced;

    applyState(data);
    await openRematch();

    if (wasOutOfSync) {
        setMessage('Актуальное состояние партии загружено.');
    }
}

async function gamePost(path, body = undefined) {
    const account = getAccountSnapshot();

    if (!account.ready || account.csrfToken === null) {
        throw new Error(
            'Сначала обновите данные аккаунта.',
        );
    }

    return request(path, {
        method: 'POST',
        token: player?.token ?? null,
        csrfToken: account.csrfToken,
        body,
    });
}

// останавливает повторные запросы поиска
function stopMatchmaking() {
    window.clearTimeout(searchTimer);
    searchTimer = null;
    searchMode = null;
}

// загружает найденную партию и открывает её для участника
async function openMatchedGame(gameId, revision) {
    const data = await request(`/api/games/${gameId}`);

    if (revision !== accountRevision) {
        return;
    }

    enterGame(gameId, data);
    stopMatchmaking();
    setText(matchmakingMessage, 'Соперник найден.');
}

// обновляет заявку и открывает партию, когда найдена пара
async function checkMatchmaking() {
    if (searchMode === null) {
        return;
    }

    if (busy) {
        searchTimer = window.setTimeout(checkMatchmaking, 2000);
        return;
    }

    const mode = searchMode;
    const revision = accountRevision;

    await run(async () => {
        try {
            const result = await gamePost(
                `/api/games/matchmaking?mode=${encodeURIComponent(mode)}`,
            );

            if (revision !== accountRevision) {
                return;
            }

            if (result.status === 'matched') {
                await openMatchedGame(result.gameId, revision);
                return;
            }

            if (result.status !== 'waiting') {
                throw new Error('Сервер вернул неизвестный статус поиска.');
            }

            searchTimer = window.setTimeout(checkMatchmaking, 2000);
        } catch (error) {
            if (revision === accountRevision) {
                stopMatchmaking();
                setText(matchmakingMessage, error.message);
            }
        }
    });
}

matchmakingStart.addEventListener('click', () => {
    const account = getAccountSnapshot();

    if (
        busy
        || !storageReady
        || searchMode !== null
        || !account.ready
        || account.user === null
    ) {
        return;
    }

    searchMode = modeSelect.value;
    setText(matchmakingMessage, 'Ищем соперника…');
    void checkMatchmaking();
});

matchmakingCancel.addEventListener('click', () => {
    if (busy || searchMode === null) {
        return;
    }

    window.clearTimeout(searchTimer);
    searchTimer = null;

    const revision = accountRevision;

    void run(async () => {
        try {
            const result = await gamePost(
                '/api/games/matchmaking/cancel',
            );

            if (revision !== accountRevision) {
                return;
            }

            if (result.status === 'matched') {
                await openMatchedGame(result.gameId, revision);
                return;
            }

            if (result.status !== 'cancelled') {
                throw new Error('Сервер вернул неизвестный статус отмены.');
            }

            stopMatchmaking();
            setText(matchmakingMessage, 'Поиск отменён.');
        } catch (error) {
            if (revision === accountRevision) {
                setText(matchmakingMessage, error.message);
            }
        }
    });
});

createButton.addEventListener('click', () => {
    const mode = modeSelect.value;

    void run(async () => {
        const data = await gamePost(
            `/api/games?mode=${encodeURIComponent(mode)}`,
        );

        enterGame(data.game.id, data);

        setMessage('Партия создана.');
    });
});

joinForm.addEventListener('submit', (event) => {
    event.preventDefault();

    const invitation = joinInput.value;

    void run(async () => {
        const id = parseGameId(invitation);
        const savedPlayer = getPlayer(id);

        // Сначала проверяем, не являемся ли мы
        // уже участником по аккаунту или гостевому ключу.
        try {
            const data = await request(`/api/games/${id}`, {
                token: savedPlayer?.token ?? null,
            });

            enterGame(id, {
                ...data,
                playerToken: savedPlayer?.token ?? null,
            });

            setMessage('Партия открыта.');

            return;
        } catch (error) {
            if (error.status !== 401 && error.status !== 403) {
                throw error;
            }
        }

        // Если участия ещё нет, пробуем занять место O.
        const data = await gamePost(
            `/api/games/${id}/join`,
        );

        enterGame(id, data);

        setMessage(`Ты присоединился за ${data.symbol}.`);
    });
});

// отправляет выставление или перемещение монстрика на сервер
async function sendGobblersMove(move) {
    if (
        busy
        || !isMyTurn()
        || game.state.mode !== 'gobblers'
    ) {
        return;
    }

    const body = {
        cell: move.cell,
        size: move.size,
        from: move.from,
        version: game.version,
    };

    await run(async () => {
        const data = await gamePost(
            `/api/games/${player.id}/moves`,
            body,
        );

        applyState(data);

        setMessage(
            game.roomStatus === 'finished'
                ? 'Партия завершена.'
                : 'Ход сохранён.',
        );
    });
}

// отправляет ход в формате текущего режима
async function sendMove(cell, boardIndex = null) {
    if (busy || !isMyTurn()) {
        return;
    }

    const ultimate = game.state.mode === 'ultimate';

    if (ultimate) {
        if (
            !Number.isInteger(boardIndex)
            || boardIndex < 0
            || boardIndex > 8
            || game.state.boardStatuses[boardIndex] !== 'playing'
            || (
                game.state.requiredBoard !== null
                && game.state.requiredBoard !== boardIndex
            )
            || game.state.boards[boardIndex][cell] !== null
        ) {
            return;
        }
    } else if (
        boardIndex !== null
        || game.state.board[cell] !== null
    ) {
        return;
    }

    const body = {
        cell,
        version: game.version,
    };

    if (ultimate) {
        body.board = boardIndex;
    }

    await run(async () => {
        const data = await gamePost(
            `/api/games/${player.id}/moves`,
            body,
        );

        applyState(data);

        setMessage(game.roomStatus === 'finished' ? 'Партия завершена.' : 'Ход сохранён.');
    });
}

// открывает новую партию с прежним гостевым ключом или аккаунтом
async function openRematch() {
    const nextId = game?.rematch.gameId;
    if (!nextId || game.rematch.remainingSeconds <= 0) return;

    const previousId = player.id;
    const revision = accountRevision;
    const token = player.token;
    const data = await request(`/api/games/${nextId}`, { token });
    if (player?.id !== previousId || revision !== accountRevision) return;

    enterGame(nextId, { ...data, playerToken: token });
    setMessage('Новая партия началась.');
}

rematchButton.addEventListener('click', () => {
    void run(async () => {
        const data = await gamePost(`/api/games/${player.id}/rematch`);
        applyState(data);
        await openRematch();
    });
});

// получает состояние в фоне без блокировки выбора фишки
async function poll() {
    try {
        if (
            player === null
            || busy
            || document.hidden
            || (game?.roomStatus === 'finished' && game.rematch.remainingSeconds <= 0)
        ) {
            return;
        }

        const id = player.id;
        const revision = accountRevision;

        const data = await request(`/api/games/${id}`, {
            token: player.token,
        });

        if (
            busy
            || player?.id !== id
            || accountRevision !== revision
            || (
                game !== null
                && data.game.version < game.version
            )
        ) {
            return;
        }

        const wasOutOfSync = !synced;

        applyState(data);
        await openRematch();
        render();

        if (wasOutOfSync) {
            setMessage('Актуальное состояние партии загружено.');
        }
    } catch (error) {
        if (!busy) {
            if (error.status === 404) clearCurrentGame();
            synced = false;
            render();
            setMessage(error.message, true);
        }
    } finally {
        window.setTimeout(poll, 1500);
    }
}

async function boot() {
    try {
        await accountReady;

        ensureStorage();

        storageReady = true;
        render();

        const url = new URL(window.location.href);

        const id = url.searchParams.get('game')
            ?? getLastGameId();

        if (id === null) {
            setMessage('Создай партию или присоединись по ссылке.');

            return;
        }

        if (!ID_PATTERN.test(id)) {
            throw new Error('В ссылке указан некорректный код партии.');
        }

        joinInput.value = id;

        const savedPlayer = getPlayer(id);
        const account = getAccountSnapshot();

        if (savedPlayer === null && account.user === null) {
            setMessage(
                'Приглашение открыто. Нажмите «Присоединиться».',
            );

            return;
        }

        player = {
            id,
            token: savedPlayer?.token ?? null,
            symbol: savedPlayer?.symbol ?? null,
        };

        await run(refreshGame);
    } catch (error) {
        setMessage(error.message, true);
    } finally {
        render();

        window.setTimeout(poll, 1500);
    }
}

void boot();
window.setInterval(() => {
    renderTurnTimer();
    renderRematch();
}, 250);
