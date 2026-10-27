// управление аккаунтом в браузере

import { request } from './api.js';

const guestPanel = document.querySelector('#auth-guest');
const userPanel = document.querySelector('#auth-user');
const fields = document.querySelector('#auth-fields');

const loginForm = document.querySelector('#login-form');
const registerForm = document.querySelector('#register-form');

const nicknameLabel = document.querySelector('#current-nickname');
const logoutButton = document.querySelector('#logout-button');
const refreshButton = document.querySelector('#auth-refresh');
const message = document.querySelector('#auth-message');

// переменные для текущего состояния
let user = null;
let csrfToken = null;

let ready = false;
let busy = false;

// вывод сообщения
function setMessage(text, error = false) {
    message.textContent = text;
    message.classList.toggle('error', error);
}

// показывает нужный блок
function render() {
    guestPanel.hidden = !ready || user !== null;
    userPanel.hidden = !ready || user === null;

    fields.disabled = busy || !ready;

    logoutButton.disabled = busy || !ready || user === null;
    refreshButton.disabled = busy;

    nicknameLabel.textContent = user?.nickname ?? '';
}

// охраняет полученные от сервера данные пользователя 
// и CSRF-токен, затем отмечает, что данные готовы
function applyAccount(data) {
    const previousUserId = user?.id ?? null;
    const wasReady = ready;

    user = data.user;
    csrfToken = data.csrfToken;
    ready = true;

    if (
        wasReady
        && previousUserId !== (user?.id ?? null)
    ) {
        window.dispatchEvent(
            new Event('xo:account-changed'),
        );
    }
}

export function getAccountSnapshot() {
    return {
        user,
        csrfToken,
        ready,
    };
}

// общая обёртка для операций
async function run(action) {
    if (busy) {
        return;
    }

    busy = true;
    render();

    try {
        await action();
    } catch (error) {
        if (error.status === 403) {
            ready = false;
            csrfToken = null;
        }

        setMessage(error.message, true);
    } finally {
        busy = false;
        render();
    }
}

// спрашивает сервер, кто сейчас вошёл
async function refreshAccount() {
    const data = await request('/api/auth/me');

    applyAccount(data);

    setMessage(
        user === null
            ? 'Вы не вошли в аккаунт. Можно продолжить гостевую игру.'
            : `Вход выполнен: ${user.nickname}.`,
    );
}

// читает никнейм и пароль из указанной формы и возвращает объект
function readCredentials(form) {
    const data = new FormData(form);

    return {
        nickname: String(data.get('nickname') ?? ''),
        password: String(data.get('password') ?? ''),
    };
}

// действия для форм и кнопок
loginForm.addEventListener('submit', (event) => {
    event.preventDefault();

    const credentials = readCredentials(loginForm);

    void run(async () => {
        const data = await request('/api/auth/login', {
            method: 'POST',
            csrfToken,
            body: credentials,
        });

        applyAccount(data);
        loginForm.reset();

        setMessage('Вы вошли в аккаунт.');
    });
});

registerForm.addEventListener('submit', (event) => {
    event.preventDefault();

    const credentials = readCredentials(registerForm);
    const formData = new FormData(registerForm);

    const confirmation = String(
        formData.get('confirmation') ?? '',
    );

    if (credentials.password !== confirmation) {
        setMessage('Пароли не совпадают.', true);

        return;
    }

    void run(async () => {
        const data = await request('/api/auth/register', {
            method: 'POST',
            csrfToken,
            body: credentials,
        });

        applyAccount(data);
        registerForm.reset();

        setMessage('Аккаунт создан. Вы вошли автоматически.');
    });
});

logoutButton.addEventListener('click', () => {
    void run(async () => {
        const data = await request('/api/auth/logout', {
            method: 'POST',
            csrfToken,
        });

        applyAccount(data);

        loginForm.reset();
        registerForm.reset();

        setMessage('Вы вышли из аккаунта.');
    });
});

refreshButton.addEventListener('click', () => {
    void run(refreshAccount);
});

export const accountReady = run(refreshAccount);