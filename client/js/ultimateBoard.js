// создаёт поля и позволяет просматривать ходы после их завершения
export function createUltimateBoard(root, onMove) {
    const fields = Array.from({ length: 9 }, (_, boardIndex) => {
        const container = document.createElement('div');
        const grid = document.createElement('div');
        const resultButton = document.createElement('button');

        container.className = 'ultimate-field';
        container.setAttribute('role', 'group');
        grid.className = 'ultimate-grid';
        resultButton.type = 'button';
        resultButton.className = 'ultimate-result';
        resultButton.hidden = true;
        resultButton.setAttribute('aria-pressed', 'false');
        resultButton.addEventListener('click', () => {
            const revealed = container.classList.toggle('revealed');
            resultButton.setAttribute('aria-pressed', String(revealed));
        });

        const cells = Array.from({ length: 9 }, (_, cellIndex) => {
            const button = document.createElement('button');
            button.type = 'button';
            button.className = 'cell ultimate-cell';
            button.disabled = true;
            button.addEventListener('click', () => onMove(boardIndex, cellIndex));
            grid.append(button);
            return button;
        });

        container.append(grid, resultButton);
        root.append(container);
        return { container, resultButton, cells };
    });

    // обновляет доступность полей, клетки и символы победителей
    return function renderUltimateBoard(state, { active, canMove, busy }) {
        fields.forEach((field, boardIndex) => {
            const result = state.boardStatuses[boardIndex];
            const closed = result !== 'playing';
            const open = state.status === 'playing' && !closed
                && (state.requiredBoard === null || state.requiredBoard === boardIndex);
            const available = active && open;
            const resultText = !closed ? 'открыто'
                : result === 'draw' ? 'ничья' : `победил ${result}`;

            field.container.setAttribute('aria-label', `Поле ${boardIndex + 1}: ${resultText}`);
            field.container.classList.toggle('available', open);
            field.container.classList.toggle('closed', closed);
            field.container.classList.toggle('winning', (state.winningLine ?? []).includes(boardIndex));
            field.resultButton.hidden = !closed;
            field.resultButton.textContent = result === 'draw' ? '=' : result;
            field.resultButton.dataset.symbol = result;
            field.resultButton.setAttribute('aria-label',
                `Поле ${boardIndex + 1}: ${resultText}. Просмотр ходов`);
            if (!closed) {
                field.container.classList.remove('revealed');
                field.resultButton.setAttribute('aria-pressed', 'false');
            }

            field.cells.forEach((button, cellIndex) => {
                const symbol = state.boards[boardIndex][cellIndex];
                button.textContent = symbol ?? '';
                button.dataset.symbol = symbol ?? '';
                button.disabled = busy || !canMove || !available || symbol !== null;
                button.setAttribute('aria-label',
                    `Поле ${boardIndex + 1}, строка ${Math.floor(cellIndex / 3) + 1}, `
                    + `столбец ${cellIndex % 3 + 1}: ${symbol ?? 'пусто'}`);
            });
        });
    };
}
