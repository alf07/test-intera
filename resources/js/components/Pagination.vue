<script setup>
import { computed } from 'vue';

const props = defineProps({
  currentPage: {
    type: Number,
    required: true,
  },

  lastPage: {
    type: Number,
    required: true,
  },

  disabled: {
    type: Boolean,
    default: false,
  },
});

const emit = defineEmits([
  'change',
]);

const pages = computed(() => {
  const total = props.lastPage;
  const current = props.currentPage;

  if (total <= 7) {
    return Array.from(
        { length: total },
        (_, index) => index + 1,
    );
  }

  const result = [1];

  /**
   * Окно вокруг текущей страницы.
   */
  const start = Math.max(
      2,
      current - 1,
  );

  const end = Math.min(
      total - 1,
      current + 1,
  );

  if (start > 2) {
    result.push('...');
  }

  for (
      let page = start;
      page <= end;
      page++
  ) {
    result.push(page);
  }

  if (end < total - 1) {
    result.push('...');
  }

  result.push(total);

  return result;
});

function goTo(page) {
  if (
      typeof page !== 'number' ||
      page < 1 ||
      page > props.lastPage ||
      props.disabled ||
      page === props.currentPage
  ) {
    return;
  }

  emit('change', page);
}
</script>

<template>
  <nav
      v-if="lastPage > 1"
      class="pagination"
      aria-label="Пагинация отзывов"
  >
    <button
        type="button"
        :disabled="
                disabled ||
                currentPage === 1
            "
        aria-label="Предыдущая страница"
        @click="goTo(currentPage - 1)"
    >
      ←
    </button>

    <template
        v-for="(page, index) in pages"
        :key="`${page}-${index}`"
    >
            <span
                v-if="page === '...'"
                class="ellipsis"
            >
                …
            </span>

      <button
          v-else
          type="button"
          :class="{
                    active: page === currentPage,
                }"
          :disabled="
                    disabled ||
                    page === currentPage
                "
          :aria-current="
                    page === currentPage
                        ? 'page'
                        : undefined
                "
          @click="goTo(page)"
      >
        {{ page }}
      </button>
    </template>

    <button
        type="button"
        :disabled="
                disabled ||
                currentPage === lastPage
            "
        aria-label="Следующая страница"
        @click="goTo(currentPage + 1)"
    >
      →
    </button>
  </nav>
</template>

<style scoped>
.pagination {
  display: flex;
  justify-content: center;
  align-items: center;
  gap: 6px;
  padding: 20px;
}

button {
  min-width: 38px;
  height: 38px;
  padding: 0 10px;
  border: 1px solid #d1d5db;
  border-radius: 8px;
  background: white;
  cursor: pointer;
  font: inherit;
}

button.active {
  border-color: #111827;
  background: #111827;
  color: white;
}

button:disabled {
  opacity: 0.45;
  cursor: default;
}

.ellipsis {
  min-width: 20px;
  text-align: center;
  color: #6b7280;
}
</style>