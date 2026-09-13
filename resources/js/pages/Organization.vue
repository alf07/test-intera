<script setup>
import { computed } from 'vue';
import { useRouter } from 'vue-router';

import OrganizationForm from '../components/OrganizationForm.vue';
import OrganizationStats from '../components/OrganizationStats.vue';
import Pagination from '../components/Pagination.vue';
import ParseProgress from '../components/ParseProgress.vue';
import ReviewsTable from '../components/ReviewsTable.vue';

import { logout } from '../services/api';
import { useOrganization } from '../composables/useOrganization';

const router = useRouter();

const {
  organization,
  reviews,
  pagination,
  loading,
  reviewsLoading,
  saving,
  error,
  connect,
  changePage,
} = useOrganization();

/**
 * Текущий статус парсинга.
 *
 * Отдельное computed-свойство позволяет
 * не повторять длинные обращения к organization
 * в шаблоне.
 */
const parseStatus = computed(() => {
  return organization.value?.parse?.status ?? '';
});

/**
 * Текущий прогресс парсинга.
 */
const parseProgress = computed(() => {
  return organization.value?.parse?.progress ?? 0;
});

/**
 * Текст ошибки парсинга.
 */
const parseError = computed(() => {
  return organization.value?.parse?.error ?? '';
});

/**
 * Есть ли смысл отображать таблицу отзывов.
 */
const parsingCompleted = computed(() => {
  return parseStatus.value === 'ok';
});

async function submit(url) {
  await connect(url);
}

/**
 * Выполняет logout и возвращает пользователя
 * на страницу авторизации.
 */
async function handleLogout() {
  try {
    await logout();
  } finally {
    await router.push('/login');
  }
}
</script>

<template>
  <main class="page">
    <header class="topbar">
      <h1>Отзывы организаций</h1>

      <button
          class="logout"
          type="button"
          @click="handleLogout"
      >
        Выйти
      </button>
    </header>

    <div class="content">
      <div
          v-if="loading"
          class="loading"
      >
        Загрузка...
      </div>

      <template v-else>
        <OrganizationForm
            :loading="saving"
            :error="error"
            @submit="submit"
        />

        <template v-if="organization">
          <OrganizationStats
              :organization="organization"
          />

          <ParseProgress
              :status="parseStatus"
              :progress="parseProgress"
              :error="parseError"
          />

          <template v-if="parsingCompleted">
            <ReviewsTable
                :reviews="reviews"
                :total="pagination.total"
                :loading="reviewsLoading"
            />

            <Pagination
                :current-page="pagination.current_page"
                :last-page="pagination.last_page"
                :disabled="reviewsLoading"
                @change="changePage"
            />
          </template>
        </template>
      </template>
    </div>
  </main>
</template>

<style scoped>
.page {
  min-height: 100vh;
  background: #f5f6f8;
}

.topbar {
  height: 64px;
  padding: 0 28px;
  display: flex;
  align-items: center;
  justify-content: space-between;
  background: white;
  border-bottom: 1px solid #e5e7eb;
}

.topbar h1 {
  margin: 0;
  font-size: 20px;
}

.logout {
  padding: 8px 14px;
  border: 1px solid #d1d5db;
  background: white;
  border-radius: 8px;
  cursor: pointer;
  font: inherit;
}

.logout:hover {
  background: #f8fafc;
}

.content {
  width: min(1200px, calc(100% - 32px));
  margin: 24px auto;
  display: grid;
  gap: 20px;
}

.loading {
  padding: 40px;
  text-align: center;
  color: #6b7280;
}
</style>