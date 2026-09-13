<script setup>
import { computed } from 'vue';

const props = defineProps({
  status: {
    type: String,
    default: '',
  },

  progress: {
    type: Number,
    default: 0,
  },

  error: {
    type: String,
    default: '',
  },
});

const statusMessages = {
  pending: 'Парсинг ожидает выполнения',
  processing: 'Парсинг отзывов...',
  unavailable: 'Яндекс временно недоступен',
  blocked: 'Яндекс ограничил доступ',
  captcha: 'Яндекс запросил CAPTCHA',
  markup_changed: 'Формат ответа Яндекса изменился',
  empty: 'Источник вернул пустой ответ',
  failed: 'Не удалось завершить парсинг',
};

const message = computed(() => {
  return statusMessages[props.status] ?? 'Парсинг';
});
</script>

<template>
  <section
      v-if="status && status !== 'ok'"
      class="progress-card"
  >
    <div class="row">
      <strong>
        {{ message }}
      </strong>

      <span>
                {{ progress }}%
            </span>
    </div>

    <div class="bar">
      <div
          class="value"
          :style="{ width: `${progress}%` }"
      />
    </div>

    <p v-if="error">
      {{ error }}
    </p>
  </section>

  <section
      v-else-if="status === 'ok'"
      class="success"
  >
    Данные обновлены
  </section>
</template>

<style scoped>
.progress-card {
  padding: 18px;
  border-radius: 12px;
  background: white;
  border: 1px solid #e5e7eb;
}

.row {
  display: flex;
  justify-content: space-between;
  gap: 16px;
  margin-bottom: 10px;
}

.row span {
  white-space: nowrap;
}

.bar {
  height: 10px;
  overflow: hidden;
  border-radius: 999px;
  background: #e5e7eb;
}

.value {
  height: 100%;
  background: #111827;
  transition: width 0.3s ease;
}

p {
  margin: 12px 0 0;
  color: #dc2626;
}

.success {
  padding: 14px;
  border-radius: 10px;
  background: #ecfdf5;
  color: #047857;
}
</style>