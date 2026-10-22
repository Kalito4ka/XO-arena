export async function request(
    path,
    {
        method = 'GET',
        token = null,
        body = undefined,
    } = {},
) {
    const headers = {
        Accept: 'application/json',
    };

    if (token !== null) {
        headers['X-Player-Token'] = token;
    }

    if (body !== undefined) {
        headers['Content-Type'] = 'application/json';
    }

    let response;

    try {
        response = await fetch(path, {
            method,
            headers,
            body: body === undefined
                ? undefined
                : JSON.stringify(body),
            cache: 'no-store',
            signal: AbortSignal.timeout(10000),
        });
    } catch {
        throw new Error(
            'Сервер не ответил. Проверьте соединение. '
            + 'Не повторяйте ход до обновления состояния.',
        );
    }

    let data;

    try {
        data = await response.json();
    } catch {
        throw new Error(
            `Сервер вернул ответ не в формате JSON `
            + `(HTTP ${response.status}).`,
        );
    }

    if (!response.ok) {
        throw new Error(
            data.message ?? `Ошибка HTTP ${response.status}`,
        );
    }

    return data;
}