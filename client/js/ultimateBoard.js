// создаёт девять маленьких полей и возвращает функцию их обновления
export function createUltimateBoard(root, onMove) {
    const fields = Array.from({ length: 9 }, (_, boardIndex) => {
        const container = document.createElement('div');
        const title = document.createElement('p');
        const grid = document.createElement('div');

        container.className = 'ultimate-field';
        container.setAttribute('role', 'group');

        title.className = 'ultimate-field-title';

        grid.className = 'ultimate-grid';

        const cells = Array.from({ length: 9 }, (_, cellIndex) => {
            const button = document.createElement('button');

            button.type = 'button';
            button.className = 'cell ultimate-cell';
            button.disabled = true;

            button.addEventListener('click', () => {
                onMove(boardIndex, cellIndex);
            });

            grid.append(button);

            return button;
        });

        container.append(title, grid);
        root.append(container);

        return {
            container,
            title,
            cells,
        };
    });

    // обновляет символы, результаты полей и доступность клеток
    return function renderUltimateBoard(
        state,
        { active, canMove, busy },
    ) {
        const winningLine = state.winningLine ?? [];

        fields.forEach((field, boardIndex) => {
            const result = state.boardStatuses[boardIndex];

            const available = (
                active
                && state.status === 'playing'
                && result === 'playing'
                && (
                    state.requiredBoard === null
                    || state.requiredBoard === boardIndex
                )
            );

            const resultText = result === 'playing'
                ? 'открыто'
                : result === 'draw'
                    ? 'ничья'
                    : `победил ${result}`;

            field.title.textContent =
                `Поле ${boardIndex + 1}: ${resultText}`;

            field.container.setAttribute(
                'aria-label',
                `Поле ${boardIndex + 1}: ${resultText}`,
            );

            field.container.classList.toggle(
                'available',
                available,
            );

            field.container.classList.toggle(
                'closed',
                result !== 'playing',
            );

            field.container.classList.toggle(
                'winning',
                winningLine.includes(boardIndex),
            );

            field.cells.forEach((button, cellIndex) => {
                const symbol = state.boards[boardIndex][cellIndex];

                button.textContent = symbol ?? '';
                button.dataset.symbol = symbol ?? '';

                button.disabled = (
                    busy
                    || !canMove
                    || !available
                    || symbol !== null
                );

                button.setAttribute(
                    'aria-label',
                    `Поле ${boardIndex + 1}, `
                    + `строка ${Math.floor(cellIndex / 3) + 1}, `
                    + `столбец ${cellIndex % 3 + 1}: `
                    + (symbol ?? 'пусто'),
                );
            });
        });
    };
}