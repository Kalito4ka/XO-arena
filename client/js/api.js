// функция для отправки запросов из браузера на PHP-сервер

export async function request(
    path,
    {
        method = 'GET',
        token = null,
        csrfToken = null,
        body = undefined,
    } = {},
) {
    const headers = {
        Accept: 'application/json',
    };

    if (token !== null) {
        headers['X-Player-Token'] = token;
    }

    if (csrfToken !== null) {
        headers['X-CSRF-Token'] = csrfToken;
    }

    if (body !== undefined) {
        headers['Content-Type'] = 'application/json';
    }

    let response;

    try {
        response = await fetch(path, {
            method,
            headers,
            credentials: 'same-origin',
            body: body === undefined
                ? undefined
                : JSON.stringify(body),
            cache: 'no-store',
            signal: AbortSignal.timeout(10000),
        });
    } catch {
        throw new Error(
            'Сервер не ответил. Проверьте соединение '
            + 'и обновите данные перед повторной попыткой.',
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
        const error = new Error(
            data.message ?? `Ошибка HTTP ${response.status}`,
        );

        error.status = response.status;

        throw error;
    }

    return data;
}