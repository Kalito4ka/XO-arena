import { request } from './api.js';

import {
    accountReady,
    getAccountSnapshot,
} from './auth.js?v=pages-1';

const activeList = document.querySelector('#active-list');
const recentList = document.querySelector('#history-list');
const statsLabel = document.querySelector('#history-stats');
const message = document.querySelector('#history-message');
const refreshButton = document.querySelector('#history-refresh');

const resultNames = {
    waiting: 'Ожидание соперника',
    playing: 'Игра продолжается',
    win: 'Победа',
    loss: 'Поражение',
    draw: 'Ничья',
};

const modeNames = {
    classic: 'Классика',
    ultimate: 'Ультимативный',
};

let requestNumber = 0;

// показывает партии в указанном списке
function renderItems(target, items) {
    target.replaceChildren();

    if (items.length === 0) {
        const entry = document.createElement('li');
        entry.className = 'empty-history';
        entry.textContent = 'Партий пока нет.';
        target.append(entry);

        return;
    }

    for (const item of items) {
        const entry = document.createElement('li');
        const link = document.createElement('a');
        const details = document.createElement('p');

        const url = new URL('/', window.location.origin);
        url.searchParams.set('game', item.id);
        url.hash = 'games';

        link.href = url.href;

        const modeName = modeNames[item.mode] ?? 'Неизвестный режим';

        link.textContent =
            `${modeName} · ты ${item.symbol} · `
            + (resultNames[item.result] ?? item.result);

        const finished = item.finishedAt !== null;

        const date = new Date(
            finished ? item.finishedAt : item.createdAt,
        ).toLocaleString('ru-RU');

        const dateLabel = finished ? 'Завершена' : 'Создана';

        details.textContent =
            `Соперник: ${item.opponent}. ${dateLabel}: ${date}.`;

        entry.append(link, details);
        target.append(entry);
    }
}

// показывает общую статистику аккаунта
function renderStats(stats) {
    const winRate = Number(stats.winRate).toLocaleString(
        'ru-RU',
        { maximumFractionDigits: 2 },
    );

    statsLabel.replaceChildren();
    const values = [
        ['Партий', stats.played], ['Побед', stats.wins],
        ['Поражений', stats.losses], ['Ничьих', stats.draws],
        ['Побед', `${winRate}%`],
    ];

    for (const [label, value] of values) {
        const card = document.createElement('div');
        const number = document.createElement('strong');
        const caption = document.createElement('span');
        card.className = 'stat-card';
        number.textContent = String(value);
        caption.textContent = label;
        card.append(number, caption);
        statsLabel.append(card);
    }
}

// загружает партии и статистику текущего аккаунта
async function loadHistory() {
    const currentRequest = ++requestNumber;
    const account = getAccountSnapshot();

    activeList.replaceChildren();
    recentList.replaceChildren();
    statsLabel.textContent = '';
    refreshButton.disabled = false;

    if (!account.ready) {
        message.textContent = 'Сначала обновите данные аккаунта.';

        return;
    }

    if (account.user === null) {
        message.textContent =
            'Войди, чтобы просмотреть свои партии.';

        return;
    }

    const userId = account.user.id;

    refreshButton.disabled = true;
    message.textContent = 'Загружаем партии…';

    try {
        const data = await request('/api/games/mine');

        if (
            currentRequest !== requestNumber
            || getAccountSnapshot().user?.id !== userId
        ) {
            return;
        }

        renderItems(activeList, data.active);
        renderItems(recentList, data.recent);
        renderStats(data.stats);

        message.textContent =
            'Статистика классики учитывает все её результаты. '
            + 'Ниже — последние 10 завершённых партий обоих режимов.';
    } catch (error) {
        if (currentRequest === requestNumber) {
            message.textContent = error.message;
        }
    } finally {
        if (currentRequest === requestNumber) {
            refreshButton.disabled = false;
        }
    }
}

refreshButton.addEventListener('click', () => {
    void loadHistory();
});

window.addEventListener('xo:account-changed', () => {
    void loadHistory();
});

window.addEventListener('xo:games-updated', () => {
    void loadHistory();
});

// загружает историю после проверки текущей сессии
async function bootHistory() {
    await accountReady;
    await loadHistory();
}

void bootHistory();
