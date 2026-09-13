<?php

namespace App\Services\Yandex;

use App\DTO\OrganizationData;
use App\DTO\ParseResult;
use App\DTO\ReviewData;
use App\Enums\ParseStatus;
use Carbon\CarbonImmutable;

/**
 * Преобразует HTML страницы Яндекс.Карт
 * в нормализованные DTO приложения.
 *
 * ResponseParser отвечает только за разбор структуры
 * ответа внешнего источника.
 *
 * Он не выполняет HTTP-запросы и не работает с БД.
 */
final class ResponseParser
{
    /**
     * Разбирает HTML одной страницы отзывов.
     */
    public function parse(
        string $html,
        int $page = 1,
    ): ParseResult {
        if (trim($html) === '') {
            return ParseResult::failure(
                ParseStatus::Empty,
                $page,
            );
        }

        $node = $this->findOrganizationNode($html);

        if ($node === null) {
            return ParseResult::failure(
                ParseStatus::MarkupChanged,
                $page,
            );
        }

        /**
         * ratingData является обязательной частью структуры,
         * по которой мы идентифицируем объект организации.
         */
        if (
            ! isset($node['ratingData'])
            || ! is_array($node['ratingData'])
        ) {
            return ParseResult::failure(
                ParseStatus::MarkupChanged,
                $page,
            );
        }

        $ratingData = $node['ratingData'];

        $organization = new OrganizationData(
            name: $this->nullableString(
                $node['name'] ?? $node['title'] ?? null,
            ),

            rating: isset($ratingData['ratingValue'])
                ? round((float) $ratingData['ratingValue'], 2)
                : null,

            ratingsCount: isset($ratingData['ratingCount'])
                ? (int) $ratingData['ratingCount']
                : null,

            reviewsCount: isset($ratingData['reviewCount'])
                ? (int) $ratingData['reviewCount']
                : null,
        );

        /**
         * reviewResults должен существовать и содержать
         * массив reviews.
         *
         * Пустой массив — корректная страница без отзывов.
         *
         * Любая другая структура означает изменение
         * формата ответа источника.
         */
        if (
            ! isset($node['reviewResults'])
            || ! is_array($node['reviewResults'])
            || ! isset($node['reviewResults']['reviews'])
            || ! is_array($node['reviewResults']['reviews'])
        ) {
            return ParseResult::failure(
                ParseStatus::MarkupChanged,
                $page,
            );
        }

        return new ParseResult(
            status: ParseStatus::Ok,
            organization: $organization,
            reviews: $this->parseReviews($node),
            page: $page,
            hasMore: false,
        );
    }

    /**
     * Ищет объект организации во всех JSON-блоках страницы.
     *
     * Не привязываемся к CSS-классам страницы.
     *
     * Ориентируемся на логическую структуру данных:
     *
     * ratingData + rating/review counters.
     */
    private function findOrganizationNode(string $html): ?array
    {
        if (! preg_match_all(
            '#<script\b[^>]*type=["\']application/json["\'][^>]*>(.*?)</script>#si',
            $html,
            $matches,
        )) {
            return null;
        }

        $blocks = $matches[1];

        /**
         * Сначала проверяем наиболее крупные JSON-блоки.
         * Обычно там находится основной state страницы.
         */
        usort(
            $blocks,
            static fn (string $a, string $b): int => strlen($b) <=> strlen($a),
        );

        foreach ($blocks as $block) {
            $block = trim($block);

            if ($block === '') {
                continue;
            }

            $decoded = json_decode(
                html_entity_decode(
                    $block,
                    ENT_QUOTES | ENT_HTML5,
                ),
                true,
            );

            /**
             * Если вариант с HTML entities не подошёл,
             * пробуем исходный JSON.
             */
            if (! is_array($decoded)) {
                $decoded = json_decode(
                    $block,
                    true,
                );
            }

            if (! is_array($decoded)) {
                continue;
            }

            $node = $this->searchOrganizationNode($decoded);

            if ($node !== null) {
                return $node;
            }
        }

        return null;
    }

    /**
     * Рекурсивно ищет объект организации.
     *
     * Не зависит от конкретной глубины вложенности JSON.
     */
    private function searchOrganizationNode(
        array $node,
    ): ?array {
        if (
            isset($node['ratingData'])
            && is_array($node['ratingData'])
            && (
                array_key_exists(
                    'ratingCount',
                    $node['ratingData'],
                )
                || array_key_exists(
                    'reviewCount',
                    $node['ratingData'],
                )
            )
        ) {
            return $node;
        }

        foreach ($node as $value) {
            if (! is_array($value)) {
                continue;
            }

            $found = $this->searchOrganizationNode($value);

            if ($found !== null) {
                return $found;
            }
        }

        return null;
    }

    /**
     * Преобразует отзывы страницы
     * в нормализованные ReviewData.
     *
     * @return ReviewData[]
     */
    private function parseReviews(array $node): array
    {
        $rawReviews = $node['reviewResults']['reviews'];

        $reviews = [];

        foreach ($rawReviews as $rawReview) {
            if (! is_array($rawReview)) {
                continue;
            }

            $review = $this->parseReview($rawReview);

            if ($review === null) {
                continue;
            }

            $reviews[] = $review;
        }

        return $reviews;
    }

    /**
     * Нормализует один отзыв.
     */
    private function parseReview(array $raw): ?ReviewData
    {
        $id = $raw['reviewId'] ?? null;

        if ($id === null || $id === '') {
            return null;
        }

        /**
         * Автор может быть как строкой,
         * так и вложенным объектом.
         */
        $author = $raw['author'] ?? null;

        if (is_array($author)) {
            $author = $author['name'] ?? null;
        }

        $author = $this->nullableString($author);

        /**
         * В первую очередь используем updatedTime.
         * Если его нет — fallback на time.
         */
        $time = $raw['updatedTime'] ?? $raw['time'] ?? null;

        $date = null;

        if (is_string($time) && $time !== '') {
            try {
                $date = CarbonImmutable::parse($time);
            } catch (\Throwable) {
                /**
                 * Некорректная дата одного отзыва
                 * не должна ломать всю страницу.
                 */
                $date = null;
            }
        }

        return new ReviewData(
            id: (string) $id,

            author: $author,

            rating: isset($raw['rating'])
                ? (int) $raw['rating']
                : null,

            text: $this->nullableString(
                $raw['text'] ?? null,
            ),

            date: $date,
        );
    }

    /**
     * Безопасно приводит значение к ?string.
     */
    private function nullableString(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        if (! is_scalar($value)) {
            return null;
        }

        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }
}
