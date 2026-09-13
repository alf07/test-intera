<?php

namespace App\DTO;

/**
 * Данные самой организации.
 */
final readonly class OrganizationData
{
    public function __construct(
        /**
         * Название организации.
         */
        public ?string $name,

        /**
         * Средний рейтинг, например 4.8.
         */
        public ?float $rating,

        /**
         * Количество всех оценок.
         *
         * Важно: это НЕ количество текстовых отзывов.
         */
        public ?int $ratingsCount,

        /**
         * Количество отзывов.
         *
         * Это отдельный показатель Яндекса.
         */
        public ?int $reviewsCount,
    ) {}
}
