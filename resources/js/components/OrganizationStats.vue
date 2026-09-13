<script setup>
import { computed } from 'vue';

const props = defineProps({
  organization: {
    type: Object,
    required: true,
  },
});

const statusLabels = {
  pending: 'В очереди',
  processing: 'Парсинг',
  ok: 'Готово',
  unavailable: 'Источник недоступен',
  blocked: 'Доступ ограничен',
  captcha: 'Требуется CAPTCHA',
  markup_changed: 'Формат источника изменился',
  empty: 'Нет данных',
  failed: 'Ошибка',
};

const statusLabel = computed(() => {
  const status = props.organization.parse?.status;

  return statusLabels[status] ?? 'Неизвестно';
});
</script>

<template>
  <section class="stats">
    <div class="header">
      <h2>
        {{ organization.name || 'Организация' }}
      </h2>

      <span
          v-if="organization.rating !== null"
          class="rating"
      >
                ★ {{ organization.rating }}
            </span>
    </div>

    <div class="cards">
      <div class="stat">
                <span class="label">
                    Оценок
                </span>

        <strong>
          {{ organization.ratings_count ?? '—' }}
        </strong>
      </div>

      <div class="stat">
                <span class="label">
                    Отзывов
                </span>

        <strong>
          {{ organization.reviews_count ?? '—' }}
        </strong>
      </div>

      <div class="stat">
                <span class="label">
                    Статус
                </span>

        <strong>
          {{ statusLabel }}
        </strong>
      </div>
    </div>
  </section>
</template>

<style scoped>
.stats {
  padding: 24px;
  background: white;
  border-radius: 14px;
  border: 1px solid #e5e7eb;
}

.header {
  display: flex;
  justify-content: space-between;
  gap: 20px;
  align-items: center;
}

.header h2 {
  margin: 0;
}

.rating {
  font-size: 24px;
  font-weight: 700;
  white-space: nowrap;
}

.cards {
  display: grid;
  grid-template-columns: repeat(3, 1fr);
  gap: 12px;
  margin-top: 20px;
}

.stat {
  padding: 16px;
  background: #f8fafc;
  border-radius: 10px;
}

.label {
  display: block;
  color: #6b7280;
  font-size: 14px;
  margin-bottom: 6px;
}

.stat strong {
  font-size: 22px;
}

@media (max-width: 700px) {
  .cards {
    grid-template-columns: 1fr;
  }

  .header {
    align-items: flex-start;
    flex-direction: column;
  }
}
</style>