<?php

use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\OrganizationController;
use Illuminate\Support\Facades\Route;

/**
 * Авторизация.
 *
 * Login доступен anonymous пользователю.
 */
Route::post('/login', [
    AuthController::class,
    'login',
]);

/**
 * Всё ниже требует Sanctum authentication.
 */
Route::middleware('auth:sanctum')->group(function (): void {

    /**
     * Текущий пользователь.
     */
    Route::get('/me', [
        AuthController::class,
        'me',
    ]);

    /**
     * Logout.
     */
    Route::post('/logout', [
        AuthController::class,
        'logout',
    ]);

    /**
     * Организация.
     */
    Route::get('/organization', [
        OrganizationController::class,
        'show',
    ]);

    /**
     * Сохранение URL + запуск фонового парсинга.
     */
    Route::put('/organization', [
        OrganizationController::class,
        'store',
    ]);

    /**
     * Текущий статус парсинга.
     */
    Route::get('/organization/status', [
        OrganizationController::class,
        'status',
    ]);

    /**
     * Пагинация отзывов.
     *
     * Всегда 50 записей:
     *
     * ?page=1
     * ?page=2
     * ...
     */
    Route::get('/organization/reviews', [
        OrganizationController::class,
        'reviews',
    ]);
});
