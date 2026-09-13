import axios from 'axios';

/*
|--------------------------------------------------------------------------
| Axios instance
|--------------------------------------------------------------------------
|
| withCredentials нужен для Sanctum cookie/session authentication.
|
| withXSRFToken позволяет Axios автоматически использовать
| XSRF-TOKEN cookie, которую выдаёт Laravel.
|
*/

const api = axios.create({
    baseURL: '/api',
    withCredentials: true,
    withXSRFToken: true,
    headers: {
        Accept: 'application/json',
        'X-Requested-With': 'XMLHttpRequest',
    },
});

/**
 * Получаем CSRF cookie перед login/logout и другими
 * state-changing запросами.
 *
 * Sanctum использует /sanctum/csrf-cookie для установки
 * XSRF-TOKEN.
 */
export async function csrf() {
    await axios.get('/sanctum/csrf-cookie', {
        withCredentials: true,
    });
}

/**
 * Авторизация.
 */
export async function login(credentials) {
    await csrf();

    const { data } = await api.post('/login', credentials);

    return data;
}

/**
 * Выход.
 */
export async function logout() {
    await csrf();

    const { data } = await api.post('/logout');

    return data;
}

/**
 * Текущий пользователь.
 */
export async function getCurrentUser() {
    const { data } = await api.get('/me');

    return data;
}

/**
 * Текущая организация.
 */
export async function getOrganization() {
    const { data } = await api.get('/organization');

    return data;
}

/**
 * Сохраняем URL и запускаем Job.
 */
export async function saveOrganization(url) {
    const { data } = await api.put('/organization', {
        url,
    });

    return data;
}

/**
 * Статус фонового парсинга.
 */
export async function getParseStatus() {
    const { data } = await api.get('/organization/status');

    return data;
}

/**
 * Получаем локальные отзывы.
 */
export async function getReviews(page = 1) {
    const { data } = await api.get('/organization/reviews', {
        params: {
            page,
        },
    });

    return data;
}

export default api;