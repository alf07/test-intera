<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Валидация URL карточки организации.
 */
final class SaveOrganizationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            /**
             * На этом уровне проверяем именно URL.
             *
             * Короткая ссылка:
             *
             * https://yandex.ru/maps/-/CTh9mLkf
             *
             * проходит правило url.
             *
             * Проверку, что это действительно поддерживаемая
             * ссылка Яндекс.Карт, сделает Parser::resolve().
             */
            'url' => [
                'required',
                'string',
                'url',
                'max:2048',
            ],
        ];
    }
}
