<?php

namespace App\Services\Yandex;

/**
 * Нормализованный URL организации Яндекс.Карт.
 *
 * Этот класс занимается только URL.
 *
 * Он НЕ:
 * - отправляет HTTP запросы;
 * - парсит HTML;
 * - ищет отзывы.
 *
 * Его задача — хранить business ID / slug
 * и уметь построить URL страницы отзывов.
 */
final readonly class Url
{
    /**
     * Допустимые домены Яндекса.
     *
     * Мы не используем просто "*.yandex.*",
     * потому что слишком широкая проверка потенциально опасна.
     */
    private const ALLOWED_HOSTS = [
        'yandex.ru',
        'www.yandex.ru',

        'yandex.com',
        'www.yandex.com',

        'yandex.eu',
        'www.yandex.eu',

        'yandex.kz',
        'www.yandex.kz',

        'yandex.by',
        'www.yandex.by',

        'yandex.uz',
        'www.yandex.uz',

        'yandex.com.tr',
        'www.yandex.com.tr',
    ];

    public function __construct(
        /**
         * Главный идентификатор организации.
         */
        public string $businessId,

        /**
         * Slug организации.
         *
         * Он необязателен.
         * Для нас businessId важнее.
         */
        public ?string $slug = null,
    ) {}

    /**
     * Пытаемся создать объект из уже НОРМАЛИЗОВАННОГО URL.
     *
     * Обрати внимание:
     *
     * https://yandex.ru/maps/-/CTh9mLkf
     *
     * сюда НЕ подходит.
     *
     * Короткую ссылку сначала должен разрешить Parser,
     * потому что для неё нужен HTTP redirect.
     */
    public static function tryFrom(string $url): ?self
    {
        $url = trim($url);

        if ($url === '') {
            return null;
        }

        $parsed = parse_url($url);

        if ($parsed === false) {
            return null;
        }

        $scheme = strtolower($parsed['scheme'] ?? '');
        $host = strtolower($parsed['host'] ?? '');
        $path = $parsed['path'] ?? '';

        // Принимаем только http/https.
        if (! in_array($scheme, ['http', 'https'], true)) {
            return null;
        }

        // Проверяем, что это действительно домен Яндекса.
        if (! in_array($host, self::ALLOWED_HOSTS, true)) {
            return null;
        }

        /**
         * Ожидаемые варианты:
         *
         * /maps/org/foo/123456789/
         * /maps/org/foo/123456789/reviews/
         * /maps/org/123456789/
         * /maps/org/123456789/reviews/
         */
        if (! preg_match('#^/maps/org/(?:([^/]+)/)?(\d+)(?:/|$)#u', $path, $matches)) {
            return null;
        }

        $slug = $matches[1] ?? null;

        if ($slug === '') {
            $slug = null;
        }

        return new self(
            businessId: $matches[2],
            slug: $slug,
        );
    }

    /**
     * Строит URL страницы отзывов.
     *
     * page=1:
     *
     * /maps/org/foo/123456789/reviews/
     *
     * page=2:
     *
     * /maps/org/foo/123456789/reviews/?page=2
     */
    public function reviewsUrl(
        string $baseUrl,
        int $page = 1,
    ): string {
        if ($page < 1) {
            throw new \InvalidArgumentException(
                'Review page must be greater than or equal to 1.'
            );
        }

        /**
         * Если slug известен — используем его.
         * Если нет — строим URL только через ID.
         */
        $path = $this->slug !== null
            ? "/maps/org/{$this->slug}/{$this->businessId}/reviews/"
            : "/maps/org/{$this->businessId}/reviews/";

        $url = rtrim($baseUrl, '/').$path;

        // Для первой страницы параметр page не нужен.
        if ($page === 1) {
            return $url;
        }

        return $url.'?page='.$page;
    }
}
