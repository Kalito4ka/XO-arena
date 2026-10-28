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
} from './auth.js';

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

const board = document.querySelector('#board');
const inviteLink = document.querySelector('#invite-link');
const copyButton = document.querySelector('#copy-button');

let player = null;
let game = null;

let busy = false;
let synced = false;
let storageReady = false;

let accountRevision = 0;
let turnTimerDeadline = null;

function clearCurrentGame() {
    player = null;
    game = null;
    synced = false;
    turnTimerDeadline = null;
}

window.addEventListener('xo:account-changed', () => {
    accountRevision++;

    clearCurrentGame();
    render();

    setMessage(
        'Аккаунт изменён. Откройте партию из списка '
        + '«Мои партии» или по приглашению.',
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

function invitationUrl(id) {
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
        && game.state.mode === 'classic'
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
            : 'Время истекло. Ожидаем результат сервера...',
    );
}

function render() {
    createButton.disabled = busy || !storageReady;
    joinButton.disabled = busy || !storageReady;
    joinInput.disabled = busy || !storageReady;

    gamePanel.hidden = player === null;

    renderTurnTimer();

    if (player === null) {
        return;
    }

    gamePanel.hidden = player === null;

    if (player === null) {
        return;
    }

    setText(
        playerInfo,
        player.symbol === null
            ? 'Определяем сторону участника...'
            : `Вы играете за ${player.symbol}.`,
    );

    inviteLink.value = invitationUrl(player.id);

    const ultimate = game?.state.mode === 'ultimate';

    board.hidden = ultimate;

    if (ultimate) {
        setText(
            turnInfo,
            'Ультимативная партия сохранена на сервере. '
            + 'Игровое поле этого режима добавим следующим этапом.',
        );

        return;
    }

    let turnText;

    if (game === null) {
        turnText = 'Получаем состояние партии...';
    } else if (!synced) {
        turnText = 'Нужно обновить состояние. Ожидаем ответ сервера...';
    } else if (game.roomStatus === 'waiting') {
        turnText = 'Ожидаем второго игрока. Отправьте ему ссылку.';
    } else if (game.state.status === 'won') {
        if (game.endReason === 'timeout') {
            turnText = game.state.winner === player.symbol
                ? 'Вы победили: соперник не сделал ход за 30 секунд.'
                : 'Вы проиграли: время на ход истекло.';
        } else {
            turnText = game.state.winner === player.symbol
                ? 'Вы победили!'
                : `Победил ${game.state.winner}.`;
        }
    } else if (game.state.status === 'draw') {
        turnText = 'Ничья!';
    } else {
        turnText = isMyTurn()
            ? 'Ваш ход.'
            : `Ход соперника: ${game.state.currentPlayer}.`;
    }

    setText(turnInfo, turnText);

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

        if (error.status === 401 || error.status === 403) {
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
        invitationUrl(id),
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

createButton.addEventListener('click', () => {
    void run(async () => {
        const data = await gamePost('/api/games');

        enterGame(data.game.id, data);

        setMessage('Партия создана. Пригласите второго игрока.');
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

            setMessage('Вы вернулись в свою партию.');

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

        setMessage(`Вы присоединились за ${data.symbol}.`);
    });
});

async function sendMove(cell) {
    if (
        busy
        || !isMyTurn()
        || game.state.board[cell] !== null
    ) {
        return;
    }

    await run(async () => {
        const data = await gamePost(
            `/api/games/${player.id}/moves`,
            {
                cell,
                version: game.version,
            },
        );

        applyState(data);

        setMessage('Ход сохранён.');
    });
}

copyButton.addEventListener('click', async () => {
    try {
        await navigator.clipboard.writeText(inviteLink.value);

        setMessage('Ссылка скопирована.');
    } catch {
        inviteLink.focus();
        inviteLink.select();

        setMessage(
            'Скопируйте выделенную ссылку вручную: Ctrl+C.',
        );
    }
});

async function poll() {
    try {
        if (
            player !== null
            && !busy
            && !document.hidden
            && game?.roomStatus !== 'finished'
        ) {
            await run(refreshGame);
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
            setMessage('Создайте партию или откройте приглашение.');

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
window.setInterval(renderTurnTimer, 250);