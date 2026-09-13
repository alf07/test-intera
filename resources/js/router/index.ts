import {
    createRouter,
    createWebHistory,
    createMemoryHistory,
} from 'vue-router';

import Login from '../pages/Login.vue';
import Organization from '../pages/Organization.vue';

/**
 * Frontend router приложения.
 *
 * В браузере используем обычный Web History.
 * При SSR используем Memory History, потому что window там отсутствует.
 */
const router = createRouter({
    history: typeof window === 'undefined'
        ? createMemoryHistory()
        : createWebHistory(),

    routes: [
        {
            path: '/login',
            name: 'login',
            component: Login,
        },

        {
            path: '/',
            name: 'organization',
            component: Organization,

            meta: {
                requiresAuth: true,
            },
        },
    ],
});

export default router;