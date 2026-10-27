import { request } from './api.js';

import {
    accountReady,
    getAccountSnapshot,
} from './auth.js';

const list = document.querySelector('#history-list');
const message = document.querySelector('#history-message');

const refreshButton = document.querySelector('#history-refresh');
const previousButton = document.querySelector('#history-prev');
const nextButton = document.querySelector('#history-next');
const pageLabel = document.querySelector('#history-page');

const resultNames = {
    waiting: 'Ожидание соперника',
    playing: 'Игра продолжается',
    win: 'Победа',
    loss: 'Поражение',
    draw: 'Ничья',
};

let page = 1;
let hasMore = false;
let busy = false;

// Номер загрузки позволяет игнорировать старый ответ
// после смены аккаунта или запуска нового запроса.
let requestNumber = 0;

function renderNavigation() {
    previousButton.disabled = busy || page <= 1;
    nextButton.disabled = busy || !hasMore;
    refreshButton.disabled = busy;

    pageLabel.textContent = `Страница ${page}`;
}

function renderItems(items) {
    list.replaceChildren();

    for (const item of items) {
        const entry = document.createElement('li');
        const link = document.createElement('a');
        const details = document.createElement('p');

        const url = new URL('/', window.location.origin);
        url.searchParams.set('game', item.id);

        link.href = url.href;

        link.textContent =
            `Классика · вы ${item.symbol} · `
            + (resultNames[item.result] ?? item.result);

        const date = new Date(item.createdAt)
            .toLocaleString('ru-RU');

        details.textContent =
            `Соперник: ${item.opponent}. Создана: ${date}.`;

        entry.append(link, details);
        list.append(entry);
    }
}

async function loadHistory(targetPage = 1) {
    const currentRequest = ++requestNumber;
    const account = getAccountSnapshot();

    list.replaceChildren();

    page = targetPage;
    hasMore = false;
    busy = false;

    if (!account.ready) {
        message.textContent = 'Сначала обновите данные аккаунта.';
        renderNavigation();

        return;
    }

    if (account.user === null) {
        page = 1;

        message.textContent =
            'Войдите в аккаунт, чтобы увидеть свои партии.';

        renderNavigation();

        return;
    }

    const userId = account.user.id;

    busy = true;
    message.textContent = 'Загружаем партии...';
    renderNavigation();

    try {
        const data = await request(
            `/api/games/mine?page=${targetPage}`,
        );

        if (
            currentRequest !== requestNumber
            || getAccountSnapshot().user?.id !== userId
        ) {
            return;
        }

        page = data.page;
        hasMore = data.hasMore;

        renderItems(data.items);

        message.textContent = data.items.length === 0
            ? 'На этой странице партий нет.'
            : 'Выберите партию, чтобы открыть её.';
    } catch (error) {
        if (currentRequest === requestNumber) {
            message.textContent = error.message;
        }
    } finally {
        if (currentRequest === requestNumber) {
            busy = false;
            renderNavigation();
        }
    }
}

refreshButton.addEventListener('click', () => {
    void loadHistory(1);
});

previousButton.addEventListener('click', () => {
    void loadHistory(page - 1);
});

nextButton.addEventListener('click', () => {
    void loadHistory(page + 1);
});

window.addEventListener('xo:account-changed', () => {
    void loadHistory(1);
});

window.addEventListener('xo:games-updated', () => {
    void loadHistory(1);
});

async function bootHistory() {
    await accountReady;
    await loadHistory(1);
}

void bootHistory();