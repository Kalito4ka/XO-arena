import { request } from './api.js';

const sortSelect = document.querySelector('#leaders-sort');
const refreshButton = document.querySelector('#leaders-refresh');
const message = document.querySelector('#leaders-message');
const tableBody = document.querySelector('#leaders-body');
const miniBody = document.querySelector('#mini-leaders-body');

let requestNumber = 0;
let miniRequestNumber = 0;

// показывает пустое состояние таблицы без вымышленных игроков
function renderEmpty(target, columns, text) {
    const row = document.createElement('tr');
    const cell = document.createElement('td');
    cell.colSpan = columns;
    cell.className = 'empty-cell';
    cell.textContent = text;
    row.append(cell);
    target.replaceChildren(row);
}

// выводит полную или компактную таблицу с данными сервера
function renderLeaders(target, items, compact = false) {
    target.replaceChildren();

    if (items.length === 0) {
        renderEmpty(target, compact ? 4 : 7, 'В рейтинге пока нет игроков.');
        return;
    }

    for (const item of items) {
        const row = document.createElement('tr');
        const rankCell = document.createElement('td');
        const rank = document.createElement('span');
        rank.className = 'rank-number';
        rank.textContent = String(item.position);
        rankCell.append(rank);
        row.append(rankCell);

        const values = compact
            ? [item.nickname, item.played, item.wins]
            : [
                item.nickname, item.played, item.wins, item.losses, item.draws,
                Number(item.winRate).toLocaleString('ru-RU', { maximumFractionDigits: 2 }),
            ];

        for (const value of values) {
            const cell = document.createElement('td');
            cell.textContent = String(value);
            row.append(cell);
        }

        target.append(row);
    }
}

// загружает выбранный рейтинг на странице лидеров
async function loadLeaders() {
    if (!tableBody) return;
    const currentRequest = ++requestNumber;
    const sort = sortSelect.value;
    refreshButton.disabled = true;
    tableBody.replaceChildren();
    message.hidden = false;
    message.textContent = 'Загружаем рейтинг…';

    try {
        const data = await request(`/api/leaderboard?sort=${encodeURIComponent(sort)}`);
        if (currentRequest !== requestNumber) return;

        renderLeaders(tableBody, data.items);
        message.textContent = '';
        message.hidden = true;
    } catch (error) {
        if (currentRequest === requestNumber) {
            message.textContent = error.message;
            renderEmpty(tableBody, 7, 'Не удалось загрузить рейтинг.');
        }
    } finally {
        if (currentRequest === requestNumber) refreshButton.disabled = false;
    }
}

// загружает первые пять игроков для боковой таблицы
async function loadMiniLeaders() {
    if (!miniBody) return;
    const currentRequest = ++miniRequestNumber;

    try {
        const data = await request('/api/leaderboard?sort=wins');
        if (currentRequest !== miniRequestNumber) return;

        renderLeaders(miniBody, data.items.slice(0, 5), true);
    } catch {
        if (currentRequest === miniRequestNumber) {
            renderEmpty(miniBody, 4, 'Рейтинг недоступен');
        }
    }
}

sortSelect?.addEventListener('change', () => void loadLeaders());
refreshButton?.addEventListener('click', () => {
    void loadLeaders();
    void loadMiniLeaders();
});

window.addEventListener('xo:games-updated', () => {
    void loadLeaders();
    void loadMiniLeaders();
});

void loadLeaders();
void loadMiniLeaders();
