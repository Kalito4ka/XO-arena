// создаёт поле с запасом монстриков и выбором фишек нажатием
export function createGobblersBoard(root, onMove) {
    let state = null;
    let playerSymbol = null;
    let canMove = false;
    let selected = null;
    let lastRenderKey = null;

    root.classList.add('gobblers-layout');

    const left = document.createElement('div');
    const board = document.createElement('div');
    const right = document.createElement('div');

    left.className = 'gobblers-reserve';
    right.className = 'gobblers-reserve';
    board.className = 'gobblers-board';

    root.append(left, board, right);

    const cells = [];

    for (let cell = 0; cell < 9; cell++) {
        const button = document.createElement('button');

        button.type = 'button';
        button.className = 'gobblers-cell';

        button.addEventListener('click', () => {
            const piece = state?.board[cell];

            if (selected !== null && allowed(cell)) {
                submit(cell);
            } else if (
                canMove
                && piece?.symbol === playerSymbol
            ) {
                selectPiece({
                    size: piece.size,
                    from: cell,
                });
            }
        });

        board.append(button);
        cells.push(button);
    }

    // создаёт изображение монстрика нужной стороны и размера
    function image(symbol, size) {
        const img = document.createElement('img');

        img.src = `/assets/monster-${symbol.toLowerCase()}.svg`;
        img.alt = '';
        img.className = 'monster-image';
        img.dataset.size = String(size);
        img.draggable = false;

        return img;
    }

    // проверяет возможность установки выбранной фишки в клетку
    function allowed(cell) {
        if (
            !canMove
            || selected === null
            || selected.from === cell
        ) {
            return false;
        }

        const target = state.board[cell];

        return target === null || target.size < selected.size;
    }

    // выбирает фишку повторным нажатием или отменяет выбор
    function selectPiece(piece) {
        const same = selected !== null
            && selected.size === piece.size
            && selected.from === piece.from;

        selected = same ? null : piece;
        highlight();
    }

    // подсвечивает выбранную фишку и подходящие клетки
    function highlight() {
        cells.forEach((button, cell) => {
            button.classList.toggle('allowed', allowed(cell));
            button.classList.toggle(
                'selected',
                selected !== null && selected.from === cell,
            );
        });

        root.querySelectorAll('[data-reserve-size]').forEach(
            (button) => {
                button.classList.toggle(
                    'selected',
                    selected !== null
                        && selected.from === null
                        && button.dataset.symbol === playerSymbol
                        && Number(button.dataset.reserveSize)
                            === selected.size,
                );
            },
        );
    }

    // передаёт выбранный ход основному модулю приложения
    function submit(cell) {
        const move = {
            cell,
            size: selected.size,
            from: selected.from,
        };

        selected = null;
        canMove = false;
        highlight();

        onMove(move);
    }

    // показывает оставшиеся фишки выбранного игрока
    function renderReserve(target, symbol) {
        target.replaceChildren();
        target.dataset.symbol = symbol;

        const heading = document.createElement('h3');
        heading.textContent = symbol;
        target.append(heading);

        for (const size of [3, 2, 1]) {
            const count = state.reserves[symbol][size];

            for (let index = 0; index < count; index++) {
                const button = document.createElement('button');

                button.type = 'button';
                button.className = 'monster-piece';
                button.dataset.reserveSize = String(size);
                button.dataset.symbol = symbol;
                button.disabled = !canMove || symbol !== playerSymbol;

                button.setAttribute(
                    'aria-label',
                    `Фишка ${symbol}, размер ${size}`,
                );

                button.append(image(symbol, size));

                button.addEventListener('click', () => {
                    selectPiece({ size, from: null });
                });

                target.append(button);
            }
        }
    }

    // обновляет поле по подтверждённому состоянию сервера
    return function renderGobblersBoard(nextState, options) {
        const renderKey = JSON.stringify([
            options.gameId,
            nextState,
            options.symbol,
            options.canMove,
            options.busy,
        ]);

        if (renderKey === lastRenderKey) {
            return;
        }

        lastRenderKey = renderKey;

        state = nextState;
        playerSymbol = options.symbol;
        canMove = options.canMove && !options.busy;

        selected = null;

        renderReserve(left, 'X');
        renderReserve(right, 'O');

        cells.forEach((button, cell) => {
            const piece = state.board[cell];

            button.replaceChildren();

            button.setAttribute(
                'aria-label',
                `Клетка ${cell + 1}: `
                    + (piece === null
                        ? 'пусто'
                        : `${piece.symbol}, размер ${piece.size}`),
            );

            if (piece !== null) {
                button.append(image(piece.symbol, piece.size));
            }

            const covered = state.stacks[cell].length - 1;

            if (covered > 0) {
                const label = document.createElement('span');

                label.className = 'stack-count';
                label.textContent = `ещё ${covered}`;
                button.append(label);
            }

            button.classList.toggle(
                'winning',
                (state.winningLine ?? []).includes(cell),
            );
        });

        highlight();
    };
}