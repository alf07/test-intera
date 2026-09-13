<script setup>
import { ref } from 'vue';

defineProps({
  loading: {
    type: Boolean,
    default: false,
  },

  error: {
    type: String,
    default: '',
  },
});

const emit = defineEmits([
  'submit',
]);

const url = ref('');

function submit() {
  const value = url.value.trim();

  if (! value) {
    return;
  }

  emit('submit', value);
}
</script>

<template>
  <section class="form-card">
    <h2>Подключить организацию</h2>

    <form @submit.prevent="submit">
      <label>
        Ссылка на Яндекс.Карты

        <input
            v-model="url"
            type="url"
            placeholder="https://yandex.ru/maps/-/..."
            autocomplete="url"
            :disabled="loading"
        />
      </label>

      <button
          type="submit"
          :disabled="loading || !url.trim()"
      >
        {{ loading ? 'Запуск парсинга...' : 'Сохранить' }}
      </button>
    </form>

    <div
        v-if="error"
        class="error"
    >
      {{ error }}
    </div>
  </section>
</template>

<style scoped>
.form-card {
  padding: 24px;
  border-radius: 14px;
  background: white;
  border: 1px solid #e5e7eb;
}

form {
  display: flex;
  gap: 12px;
  align-items: end;
}

label {
  flex: 1;
  display: grid;
  gap: 8px;
  font-weight: 600;
}

input {
  width: 100%;
  box-sizing: border-box;
  padding: 12px 14px;
  border: 1px solid #d1d5db;
  border-radius: 10px;
  font: inherit;
}

input:focus {
  outline: 2px solid #d1d5db;
  outline-offset: 1px;
}

button {
  padding: 12px 18px;
  border: 0;
  border-radius: 10px;
  background: #111827;
  color: white;
  cursor: pointer;
  font: inherit;
  white-space: nowrap;
}

button:disabled {
  opacity: 0.5;
  cursor: default;
}

.error {
  margin-top: 12px;
  color: #dc2626;
}
</style>