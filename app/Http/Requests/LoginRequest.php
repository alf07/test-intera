<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Валидация данных авторизации.
 */
final class LoginRequest extends FormRequest
{
    /**
     * Для login endpoint авторизация ещё не требуется.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Правила валидации.
     */
    public function rules(): array
    {
        return [
            /**
             * Email используется стандартной Laravel-моделью User
             * как идентификатор пользователя.
             */
            'email' => [
                'required',
                'email',
            ],

            /**
             * Пароль не принимаем пустым.
             */
            'password' => [
                'required',
                'string',
            ],
        ];
    }
}
