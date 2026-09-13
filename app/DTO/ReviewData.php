<?php

namespace App\DTO;

use Carbon\CarbonImmutable;

/**
 * Нормализованный отзыв.
 *
 * Внутри Яндекса поле может называться updatedTime,
 * автор может быть строкой или объектом и т.д.
 */
final readonly class ReviewData
{
    public function __construct(
        /**
         * Уникальный идентификатор отзыва на стороне Яндекса
         */
        public string $id,

        /**
         * Имя автора отзыва.
         */
        public ?string $author,

        /**
         * Оценка от 1 до 5.
         */
        public ?int $rating,

        /**
         * Текст отзыва.
         */
        public ?string $text,

        /**
         * Дата отзыва / последнего обновления,
         * которую удалось получить от источника.
         */
        public ?CarbonImmutable $date,
    ) {}
}
