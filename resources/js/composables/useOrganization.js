import {
    onMounted,
    onUnmounted,
    ref,
} from 'vue';

import {
    getOrganization,
    getParseStatus,
    getReviews,
    saveOrganization,
} from '../services/api';

export function useOrganization() {
    const organization = ref(null);

    const reviews = ref([]);

    const pagination = ref({
        current_page: 1,
        last_page: 1,
        per_page: 50,
        total: 0,
    });

    const loading = ref(false);
    const reviewsLoading = ref(false);
    const saving = ref(false);

    const error = ref('');

    let pollingTimer = null;
    let pollingInProgress = false;

    /**
     * Загружает текущую организацию.
     */
    async function loadOrganization() {
        loading.value = true;
        error.value = '';

        try {
            const response = await getOrganization();

            organization.value = response.data;
        } catch (exception) {
            if (exception.response?.status === 404) {
                /**
                 * Организация ещё не подключена.
                 */
                organization.value = null;

                return;
            }

            if (exception.response?.status === 401) {
                error.value =
                    'Сессия истекла. Выполните вход снова.';

                return;
            }

            error.value =
                'Не удалось загрузить организацию.';
        } finally {
            loading.value = false;
        }
    }

    /**
     * Загружает отзывы текущей страницы.
     */
    async function loadReviews(page = 1) {
        reviewsLoading.value = true;

        try {
            const response = await getReviews(page);

            reviews.value = response.data;

            pagination.value = {
                current_page: response.meta.current_page,
                last_page: response.meta.last_page,
                per_page: response.meta.per_page,
                total: response.meta.total,
            };
        } catch (exception) {
            if (exception.response?.status === 401) {
                error.value =
                    'Сессия истекла. Выполните вход снова.';

                return;
            }

            error.value =
                'Не удалось загрузить отзывы.';
        } finally {
            reviewsLoading.value = false;
        }
    }

    /**
     * Сохраняет URL и запускает фоновый Job.
     */
    async function connect(url) {
        saving.value = true;
        error.value = '';

        try {
            const response = await saveOrganization(
                url.trim(),
            );

            organization.value = response.data;

            /**
             * После подключения новой организации
             * старые отзывы больше не должны отображаться.
             */
            reviews.value = [];

            pagination.value = {
                current_page: 1,
                last_page: 1,
                per_page: 50,
                total: 0,
            };

            startPolling();
        } catch (exception) {
            if (exception.response?.status === 401) {
                error.value =
                    'Сессия истекла. Выполните вход снова.';

                return;
            }

            if (exception.response?.status === 422) {
                error.value =
                    exception.response.data.message ??
                    'Некорректная ссылка.';

                return;
            }

            error.value =
                'Не удалось запустить парсинг.';
        } finally {
            saving.value = false;
        }
    }

    /**
     * Проверяет состояние фонового парсинга.
     */
    async function poll() {
        /**
         * Не допускаем несколько одновременно выполняющихся
         * запросов статуса.
         */
        if (pollingInProgress) {
            return;
        }

        pollingInProgress = true;

        try {
            const response = await getParseStatus();

            if (organization.value) {
                organization.value.parse = response;
            }

            /**
             * Пока Job работает — продолжаем polling.
             */
            if (
                response.status === 'processing' ||
                response.status === 'pending'
            ) {
                return;
            }

            /**
             * Job завершилась.
             */
            stopPolling();

            /**
             * Перечитываем организацию, чтобы получить
             * финальные данные и parsed_at.
             */
            await loadOrganization();

            /**
             * Отзывы нужны только при успешном завершении.
             */
            if (response.status === 'ok') {
                await loadReviews(1);
            }
        } catch (exception) {
            /**
             * Session закончилась.
             */
            if (exception.response?.status === 401) {
                stopPolling();

                error.value =
                    'Сессия истекла. Выполните вход снова.';

                return;
            }

            /**
             * Единичную сетевую ошибку не считаем
             * причиной остановки polling.
             */
        } finally {
            pollingInProgress = false;
        }
    }

    /**
     * Запускает polling раз в 1.5 секунды.
     */
    function startPolling() {
        stopPolling();

        void poll();

        pollingTimer = window.setInterval(
            () => {
                void poll();
            },
            1500,
        );
    }

    /**
     * Останавливает polling.
     */
    function stopPolling() {
        if (pollingTimer === null) {
            return;
        }

        window.clearInterval(pollingTimer);

        pollingTimer = null;
    }

    /**
     * Загружает выбранную страницу отзывов.
     */
    async function changePage(page) {
        await loadReviews(page);
    }

    onMounted(async () => {
        await loadOrganization();

        if (
            organization.value?.parse?.status === 'ok'
        ) {
            await loadReviews(1);
        }

        if (
            organization.value?.parse?.status === 'processing' ||
            organization.value?.parse?.status === 'pending'
        ) {
            startPolling();
        }
    });

    onUnmounted(() => {
        stopPolling();
    });

    return {
        organization,
        reviews,
        pagination,
        loading,
        reviewsLoading,
        saving,
        error,
        connect,
        changePage,
    };
}