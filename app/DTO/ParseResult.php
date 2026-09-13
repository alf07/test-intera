<?php

namespace App\DTO;

use App\Enums\ParseStatus;

/**
 * Результат парсинга одной или нескольких страниц.
 *
 * Для parsePage():
 *   reviews содержит отзывы только текущей страницы.
 *
 * Для parseAll():
 *   reviews содержит итоговый список всех собранных отзывов.
 */
final readonly class ParseResult
{
    /**
     * @param  ReviewData[]  $reviews
     */
    public function __construct(
        /**
         * Статус операции.
         */
        public ParseStatus $status,

        /**
         * Данные организации.
         *
         * При parsePage() могут присутствовать на каждой странице,
         * поскольку Яндекс отдаёт ratingData вместе со страницей.
         *
         * При parseAll() берём данные с первой страницы.
         */
        public ?OrganizationData $organization = null,

        /**
         * Список отзывов.
         */
        public array $reviews = [],

        /**
         * Номер обработанной страницы.
         *
         * Для полного парсинга здесь остаётся 1,
         * потому что итоговый результат относится ко всей операции.
         */
        public int $page = 1,

        /**
         * Есть ли ещё страницы с отзывами.
         *
         * Для пустой страницы:
         *
         * hasMore = false
         */
        public bool $hasMore = true,
    ) {}

    /**
     * Успешный ли результат.
     */
    public function isOk(): bool
    {
        return $this->status === ParseStatus::Ok;
    }

    /**
     * Создаём результат с ошибкой.
     */
    public static function failure(
        ParseStatus $status,
        int $page = 1,
    ): self {
        return new self(
            status: $status,
            page: $page,
            hasMore: false,
        );
    }
}
