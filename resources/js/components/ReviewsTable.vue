<script setup>
defineProps({
  reviews: {
    type: Array,
    default: () => [],
  },

  total: {
    type: Number,
    default: 0,
  },

  loading: {
    type: Boolean,
    default: false,
  },
});

function formatDate(value) {
  if (! value) {
    return '—';
  }

  const date = new Date(value);

  if (Number.isNaN(date.getTime())) {
    return '—';
  }

  return new Intl.DateTimeFormat(
      'ru-RU',
      {
        dateStyle: 'medium',
        timeStyle: 'short',
      },
  ).format(date);
}
</script>

<template>
  <section class="reviews">
    <div class="section-header">
      <h2>Отзывы</h2>

      <span class="count">
                {{ total }}
            </span>
    </div>

    <div
        v-if="loading"
        class="loading"
    >
      Загрузка отзывов...
    </div>

    <div
        v-else-if="reviews.length === 0"
        class="empty"
    >
      Отзывов пока нет.
    </div>

    <div
        v-else
        class="table-wrapper"
    >
      <table>
        <thead>
        <tr>
          <th>Автор</th>
          <th>Дата</th>
          <th>Оценка</th>
          <th>Текст</th>
        </tr>
        </thead>

        <tbody>
        <tr
            v-for="review in reviews"
            :key="review.id"
        >
          <td>
            {{ review.author || '—' }}
          </td>

          <td class="date">
            {{ formatDate(review.date) }}
          </td>

          <td>
                            <span
                                v-if="review.rating !== null"
                                class="rating"
                            >
                                ★ {{ review.rating }}
                            </span>

            <span v-else>
                                —
                            </span>
          </td>

          <td class="text">
            {{ review.text || '—' }}
          </td>
        </tr>
        </tbody>
      </table>
    </div>
  </section>
</template>

<style scoped>
.reviews {
  overflow: hidden;
  background: white;
  border: 1px solid #e5e7eb;
  border-radius: 14px;
}

.section-header {
  display: flex;
  align-items: center;
  justify-content: space-between;
  padding: 20px 24px;
  border-bottom: 1px solid #e5e7eb;
}

.section-header h2 {
  margin: 0;
}

.count {
  color: #6b7280;
  font-size: 14px;
  font-weight: 600;
}

.table-wrapper {
  overflow-x: auto;
}

table {
  width: 100%;
  border-collapse: collapse;
}

th,
td {
  padding: 14px 16px;
  text-align: left;
  vertical-align: top;
  border-bottom: 1px solid #f1f5f9;
}

th {
  background: #f8fafc;
  white-space: nowrap;
}

tr:last-child td {
  border-bottom: 0;
}

.date {
  white-space: nowrap;
}

.text {
  min-width: 400px;
  white-space: pre-wrap;
  overflow-wrap: anywhere;
}

.rating {
  font-weight: 700;
  white-space: nowrap;
}

.loading,
.empty {
  padding: 32px;
  color: #6b7280;
  text-align: center;
}

@media (max-width: 700px) {
  th,
  td {
    padding: 10px 12px;
  }

  .text {
    min-width: 280px;
  }
}
</style>