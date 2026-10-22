import { request } from './api.js';

import {
    ensureStorage,
    savePlayer,
    getPlayer,
    getLastGameId,
} from './storage.js';

const ID_PATTERN = /^[a-f0-9]{32}$/;

const createButton = document.querySelector('#create-button');
const joinForm = document.querySelector('#join-form');
const joinInput = document.querySelector('#join-input');
const joinButton = document.querySelector('#join-button');

const message = document.querySelector('#message');
const gamePanel = document.querySelector('#game-panel');
const playerInfo = document.querySelector('#player-info');
const turnInfo = document.querySelector('#turn-info');

const board = document.querySelector('#board');
const inviteLink = document.querySelector('#invite-link');
const copyButton = document.querySelector('#copy-button');

let player = null;
let game = null;

let busy = false;
let synced = false;
let storageReady = false;

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

function render() {
    createButton.disabled = busy || !storageReady;
    joinButton.disabled = busy || !storageReady;
    joinInput.disabled = busy || !storageReady;

    gamePanel.hidden = player === null;

    if (player === null) {
        return;
    }

    setText(playerInfo, `Вы играете за ${player.symbol}.`);

    inviteLink.value = invitationUrl(player.id);

    let turnText;

    if (game === null) {
        turnText = 'Получаем состояние партии...';
    } else if (!synced) {
        turnText = 'Нужно обновить состояние. Ожидаем ответ сервера...';
    } else if (game.roomStatus === 'waiting') {
        turnText = 'Ожидаем второго игрока. Отправьте ему ссылку.';
    } else if (game.state.status === 'won') {
        turnText = game.state.winner === player.symbol
            ? 'Вы победили!'
            : `Победил ${game.state.winner}.`;
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

    busy = true;
    render();

    try {
        await action();
    } catch (error) {
        synced = false;

        setMessage(error.message, true);
    } finally {
        busy = false;
        render();
    }
}

function applyState(data) {
    game = data.game;
    player.symbol = data.symbol;
    synced = true;
}

function enterGame(id, data) {
    const savedPlayer = {
        token: data.playerToken,
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

createButton.addEventListener('click', () => {
    void run(async () => {
        const data = await request('/api/games', {
            method: 'POST',
        });

        enterGame(data.game.id, data);

        setMessage('Партия создана. Пригласите второго игрока.');
    });
});

joinForm.addEventListener('submit', (event) => {
    event.preventDefault();

    void run(async () => {
        const id = parseGameId(joinInput.value);
        const savedPlayer = getPlayer(id);

        if (savedPlayer !== null) {
            const data = await request(`/api/games/${id}`, {
                token: savedPlayer.token,
            });

            enterGame(id, {
                ...data,
                playerToken: savedPlayer.token,
            });

            setMessage('Вы вернулись в свою партию.');

            return;
        }

        const data = await request(`/api/games/${id}/join`, {
            method: 'POST',
        });

        enterGame(id, data);

        setMessage('Вы присоединились к партии за O.');
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
        const data = await request(
            `/api/games/${player.id}/moves`,
            {
                method: 'POST',
                token: player.token,
                body: {
                    cell,
                    version: game.version,
                },
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

        if (savedPlayer === null) {
            setMessage(
                'Приглашение открыто. Нажмите «Присоединиться».',
            );

            return;
        }

        player = {
            id,
            ...savedPlayer,
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