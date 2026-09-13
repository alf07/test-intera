<script setup>
import { ref } from 'vue';
import { useRouter } from 'vue-router';

import { login } from '../services/api';

const router = useRouter();

const email = ref('demo@example.com');
const password = ref('password');

const loading = ref(false);
const error = ref('');
const errors = ref({});

async function submit() {
  loading.value = true;
  error.value = '';
  errors.value = {};

  try {
    await login({
      email: email.value,
      password: password.value,
    });

    await router.push('/');
  } catch (exception) {
    /*
     * Laravel validation:
     *
     * 422:
     * {
     *     errors: {
     *         email: [...]
     *     }
     * }
     */
    if (exception.response?.status === 422) {
      errors.value = exception.response.data.errors ?? {};

      error.value =
          exception.response.data.message ??
          'Проверьте введённые данные.';
    } else {
      error.value =
          'Не удалось выполнить авторизацию.';
    }
  } finally {
    loading.value = false;
  }
}
</script>

<template>
  <main class="auth-page">
    <section class="auth-card">
      <h1>Яндекс Reviews</h1>

      <p class="subtitle">
        Авторизация
      </p>

      <form @submit.prevent="submit">
        <label>
          Email

          <input
              v-model="email"
              type="email"
              autocomplete="username"
              placeholder="demo@example.com"
          />

          <span
              v-if="errors.email"
              class="field-error"
          >
                        {{ errors.email[0] }}
                    </span>
        </label>

        <label>
          Пароль

          <input
              v-model="password"
              type="password"
              autocomplete="current-password"
              placeholder="Пароль"
          />

          <span
              v-if="errors.password"
              class="field-error"
          >
                        {{ errors.password[0] }}
                    </span>
        </label>

        <div
            v-if="error"
            class="error"
        >
          {{ error }}
        </div>

        <button
            type="submit"
            :disabled="loading"
        >
          {{ loading ? 'Вход...' : 'Войти' }}
        </button>
      </form>
    </section>
  </main>
</template>

<style scoped>
.auth-page {
  min-height: 100vh;
  display: grid;
  place-items: center;
  background: #f5f6f8;
}

.auth-card {
  width: min(420px, calc(100% - 32px));
  padding: 32px;
  border-radius: 16px;
  background: white;
  box-shadow: 0 10px 35px rgb(0 0 0 / 8%);
}

.subtitle {
  color: #6b7280;
  margin-bottom: 24px;
}

form {
  display: grid;
  gap: 18px;
}

label {
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

button {
  border: 0;
  border-radius: 10px;
  padding: 12px 16px;
  background: #111827;
  color: white;
  cursor: pointer;
  font: inherit;
}

button:disabled {
  opacity: 0.6;
  cursor: default;
}

.error,
.field-error {
  color: #dc2626;
  font-size: 14px;
}
</style>