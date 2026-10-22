const LAST_GAME_KEY = 'xo-arena:last-game';

function playerKey(id) {
    return `xo-arena:player:${id}`;
}

export function ensureStorage() {
    const key = 'xo-arena:storage-test';

    localStorage.setItem(key, 'ok');
    localStorage.removeItem(key);
}

export function savePlayer(id, player) {
    localStorage.setItem(
        playerKey(id),
        JSON.stringify({
            token: player.token,
            symbol: player.symbol,
        }),
    );

    localStorage.setItem(LAST_GAME_KEY, id);
}

export function getPlayer(id) {
    const raw = localStorage.getItem(playerKey(id));

    if (raw === null) {
        return null;
    }

    try {
        const player = JSON.parse(raw);

        if (
            player === null
            || typeof player !== 'object'
            || typeof player.token !== 'string'
            || !/^[a-f0-9]{64}$/.test(player.token)
            || !['X', 'O'].includes(player.symbol)
        ) {
            return null;
        }

        return player;
    } catch {
        return null;
    }
}

export function getLastGameId() {
    return localStorage.getItem(LAST_GAME_KEY);
}