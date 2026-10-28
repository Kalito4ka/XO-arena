import { request } from './api.js';

const sortSelect = document.querySelector('#leaders-sort');
const refreshButton = document.querySelector('#leaders-refresh');
const message = document.querySelector('#leaders-message');
const tableBody = document.querySelector('#leaders-body');

let requestNumber = 0;

// выводит игроков в таблицу лидеров
function renderLeaders(items) {
    tableBody.replaceChildren();

    for (const item of items) {
        const row = document.createElement('tr');

        const values = [
            item.position,
            item.nickname,
            item.played,
            item.wins,
            item.losses,
            item.draws,
            Number(item.winRate).toLocaleString(
                'ru-RU',
                { maximumFractionDigits: 2 },
            ),
        ];

        for (const value of values) {
            const cell = document.createElement('td');

            cell.textContent = String(value);
            row.append(cell);
        }

        tableBody.append(row);
    }
}

// загружает рейтинг с выбранной сортировкой
async function loadLeaders() {
    const currentRequest = ++requestNumber;
    const sort = sortSelect.value;

    refreshButton.disabled = true;
    tableBody.replaceChildren();
    message.textContent = 'Загружаем рейтинг...';

    try {
        const data = await request(
            `/api/leaderboard?sort=${encodeURIComponent(sort)}`,
        );

        if (currentRequest !== requestNumber) {
            return;
        }

        renderLeaders(data.items);

        const rule =
            `Для участия нужно завершить не менее `
            + `${data.minimumGames} партий.`;

        message.textContent = data.items.length === 0
            ? `В рейтинге пока нет игроков. ${rule}`
            : rule;
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

sortSelect.addEventListener('change', () => {
    void loadLeaders();
});

refreshButton.addEventListener('click', () => {
    void loadLeaders();
});

window.addEventListener('xo:games-updated', () => {
    void loadLeaders();
});

void loadLeaders();