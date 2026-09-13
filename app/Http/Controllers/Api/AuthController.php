<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\LoginRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * API авторизации SPA.
 *
 * Sanctum здесь используется как session-based
 * first-party SPA authentication.
 */
final class AuthController extends Controller
{
    /**
     * Авторизация пользователя.
     */
    public function login(LoginRequest $request): JsonResponse
    {
        /**
         * Получаем только валидированные данные.
         */
        $credentials = $request->validated();

        /**
         * Пытаемся авторизовать пользователя
         * через стандартный web guard.
         */
        if (! Auth::guard('web')->attempt($credentials)) {
            return response()->json([
                'message' => 'Invalid credentials.',
            ], 422);
        }

        /**
         * После успешной авторизации обязательно
         * пересоздаём session ID.
         *
         * Это защита от session fixation.
         */
        $request->session()->regenerate();

        /**
         * Возвращаем пользователя.
         *
         * Пароль, конечно, никогда не отдаём.
         */
        return response()->json([
            'user' => $request->user(),
        ]);
    }

    /**
     * Выход из системы.
     */
    public function logout(Request $request): JsonResponse
    {
        /**
         * Удаляем authentication state
         * из текущей Laravel session.
         */
        Auth::guard('web')->logout();

        /**
         * Инвалидируем старую session.
         */
        $request->session()->invalidate();

        /**
         * Создаём новый CSRF token
         * для следующей anonymous session.
         */
        $request->session()->regenerateToken();

        return response()->json([
            'message' => 'Logged out.',
        ]);
    }

    /**
     * Текущий авторизованный пользователь.
     */
    public function me(Request $request): JsonResponse
    {
        return response()->json([
            'user' => $request->user(),
        ]);
    }
}
