<?php

namespace App\Services\Yandex;

use App\DTO\ParseResult;
use App\Enums\ParseStatus;
use GuzzleHttp\TransferStats;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Support\Facades\Log;

/**
 * Главный сервис парсинга Яндекс.Карт.
 *
 * Отвечает за:
 * - разрешение пользовательского URL;
 * - HTTP-запросы к Яндекс.Картам;
 * - обработку HTTP и сетевых ошибок;
 * - определение CAPTCHA;
 * - последовательный сбор страниц;
 * - передачу HTML в ResponseParser.
 *
 * Parser НЕ знает:
 * - о БД;
 * - о Queue;
 * - о Eloquent-моделях;
 * - о progress в БД.
 *
 * Результат возвращается через DTO.
 */
final class Parser
{
    public function __construct(
        private readonly HttpFactory $http,
        private readonly ResponseParser $responseParser,
    ) {}

    /**
     * Парсит одну страницу отзывов.
     *
     * Метод отвечает за orchestration:
     *
     * 1. строит URL;
     * 2. получает HTML;
     * 3. обрабатывает сетевые и HTTP-ошибки;
     * 4. определяет CAPTCHA;
     * 5. передаёт HTML в ResponseParser.
     *
     * Саму структуру JSON этот класс не разбирает.
     */
    public function parsePage(
        Url $url,
        int $page = 1,
    ): ParseResult {
        $baseUrl = config(
            'yandex.base_url',
            'https://yandex.com',
        );

        $targetUrl = $url->reviewsUrl(
            baseUrl: $baseUrl,
            page: $page,
        );

        try {
            $response = $this->http
                ->withHeaders([
                    'User-Agent' => config(
                        'yandex.user_agent',
                        'Mozilla/5.0 (Windows NT 10.0; Win64; x64) '
                        .'AppleWebKit/537.36 '
                        .'(KHTML, like Gecko) '
                        .'Chrome/140.0 Safari/537.36',
                    ),

                    'Accept-Language' => 'ru-RU,ru;q=0.9,en;q=0.8',

                    'Accept' => 'text/html,application/xhtml+xml,'
                        .'application/xml;q=0.9,image/avif,'
                        .'image/webp,*/*;q=0.8',

                    'Referer' => rtrim($baseUrl, '/').'/',
                ])
                ->timeout(
                    (int) config(
                        'yandex.timeout',
                        20,
                    )
                )
                ->get($targetUrl);
        } catch (ConnectionException $e) {
            /**
             * Ответ от Яндекса вообще не был получен.
             *
             * Например:
             * - timeout;
             * - DNS error;
             * - connection refused;
             * - network unreachable.
             *
             * Это временная внешняя ошибка, которую Job
             * может передать на retry.
             */
            Log::warning(
                'Yandex connection failed',
                [
                    'business_id' => $url->businessId,
                    'page' => $page,
                    'url' => $targetUrl,
                    'exception' => $e::class,
                    'message' => $e->getMessage(),
                ],
            );

            return ParseResult::failure(
                ParseStatus::Unavailable,
                $page,
            );
        } catch (\Throwable $e) {
            /**
             * Неожиданная ошибка самого приложения или HTTP-слоя.
             *
             * Не маскируем её под Unavailable:
             * Queue должна получить exception и обработать
             * его как технический сбой.
             */
            Log::error(
                'Unexpected Yandex parser error',
                [
                    'business_id' => $url->businessId,
                    'page' => $page,
                    'url' => $targetUrl,
                    'exception' => $e::class,
                    'message' => $e->getMessage(),
                ],
            );

            throw $e;
        }

        $status = $response->status();

        /**
         * 403 Forbidden.
         *
         * Источник отказал в доступе.
         */
        if ($status === 403) {
            Log::warning(
                'Yandex returned 403',
                [
                    'business_id' => $url->businessId,
                    'page' => $page,
                    'url' => $targetUrl,
                    'status' => $status,
                ],
            );

            return ParseResult::failure(
                ParseStatus::Blocked,
                $page,
            );
        }

        /**
         * 429 Too Many Requests.
         *
         * Яндекс ограничил частоту запросов.
         *
         * Retry-After сохраняем в лог для диагностики.
         */
        if ($status === 429) {
            Log::warning(
                'Yandex rate limit reached',
                [
                    'business_id' => $url->businessId,
                    'page' => $page,
                    'url' => $targetUrl,
                    'status' => $status,
                    'retry_after' => $response->header('Retry-After'),
                ],
            );

            return ParseResult::failure(
                ParseStatus::Blocked,
                $page,
            );
        }

        /**
         * Остальные неуспешные HTTP-ответы.
         *
         * Например:
         * - 500;
         * - 502;
         * - 503;
         * - 504.
         */
        if (! $response->successful()) {
            Log::warning(
                'Yandex returned non-success status',
                [
                    'business_id' => $url->businessId,
                    'page' => $page,
                    'url' => $targetUrl,
                    'status' => $status,
                ],
            );

            return ParseResult::failure(
                ParseStatus::Unavailable,
                $page,
            );
        }

        $html = $response->body();

        /**
         * HTTP 200 сам по себе ещё не гарантирует,
         * что мы получили корректную страницу.
         */
        if (trim($html) === '') {
            Log::warning(
                'Yandex returned empty response',
                [
                    'business_id' => $url->businessId,
                    'page' => $page,
                    'url' => $targetUrl,
                ],
            );

            return ParseResult::failure(
                ParseStatus::Empty,
                $page,
            );
        }

        /**
         * CAPTCHA проверяем до передачи HTML
         * в ResponseParser.
         */
        if ($this->looksLikeCaptcha($html)) {
            Log::warning(
                'Yandex captcha detected',
                [
                    'business_id' => $url->businessId,
                    'page' => $page,
                    'url' => $targetUrl,
                ],
            );

            return ParseResult::failure(
                ParseStatus::Captcha,
                $page,
            );
        }

        /**
         * Сетевой и HTTP-слой закончен.
         *
         * Передаём HTML специализированному parser-у,
         * который отвечает только за структуру данных.
         */
        return $this->responseParser->parse(
            html: $html,
            page: $page,
        );
    }

    /**
     * Полностью собирает доступные отзывы организации.
     *
     * Алгоритм:
     *
     * page 1 → page 2 → page 3 → ...
     *
     * Останавливаемся:
     *
     * 1. если собрали максимальное количество уникальных отзывов;
     * 2. если получили корректную страницу без отзывов;
     * 3. если достигли технического лимита страниц.
     *
     * При ошибке страницы частичный результат
     * не возвращается как успешный.
     *
     * Callback позволяет Job получать информацию
     * о ходе выполнения без связи Parser с БД.
     */
    public function parseAll(
        Url $url,
        ?\Closure $onProgress = null,
    ): ParseResult {
        $maxReviews = (int) config(
            'yandex.review_cap',
            600,
        );

        $reviewsPerPage = (int) config(
            'yandex.reviews_per_page',
            50,
        );

        /**
         * Технический максимум страниц.
         *
         * При лимите 600 и 50 отзывах на странице:
         *
         * 600 / 50 = 12.
         *
         * Максимум 13 страниц нужен только в случае,
         * когда лимит не был достигнут раньше и необходимо
         * подтвердить окончание доступного списка.
         */
        $maxPages = (int) ceil(
            $maxReviews / $reviewsPerPage,
        ) + 1;

        /**
         * --------------------------------------------------------------
         * PAGE 1
         * --------------------------------------------------------------
         */
        $firstPage = $this->parsePage(
            url: $url,
            page: 1,
        );

        /**
         * Если первая страница не распарсилась,
         * дальнейший сбор невозможен.
         */
        if (! $firstPage->isOk()) {
            return $firstPage;
        }

        /**
         * Без данных организации считаем,
         * что структура ответа изменилась.
         */
        if ($firstPage->organization === null) {
            return ParseResult::failure(
                ParseStatus::MarkupChanged,
                page: 1,
            );
        }

        /**
         * --------------------------------------------------------------
         * DEDUPLICATION
         * --------------------------------------------------------------
         *
         * Отзывы хранятся по external ID.
         */
        $reviewsById = [];

        foreach ($firstPage->reviews as $review) {
            $reviewsById[$review->id] = $review;
        }

        /**
         * --------------------------------------------------------------
         * PROGRESS: PAGE 1
         * --------------------------------------------------------------
         */
        if ($onProgress !== null) {
            $onProgress(
                1,
                $maxPages,
                count($reviewsById),
            );
        }

        /**
         * --------------------------------------------------------------
         * NEXT PAGES
         * --------------------------------------------------------------
         */
        for (
            $page = 2;
            $page <= $maxPages;
            $page++
        ) {
            /**
             * Максимум уже достигнут.
             */
            if (count($reviewsById) >= $maxReviews) {
                break;
            }

            /**
             * Небольшая задержка между запросами страниц.
             */
            $delay = (int) config(
                'yandex.page_delay_ms',
                400,
            );

            if ($delay > 0) {
                usleep($delay * 1000);
            }

            $result = $this->parsePage(
                url: $url,
                page: $page,
            );

            /**
             * Ошибка страницы не считается нормальным
             * окончанием списка.
             *
             * Частичный результат не возвращаем как успешный.
             */
            if (! $result->isOk()) {
                return ParseResult::failure(
                    status: $result->status,
                    page: $page,
                );
            }

            /**
             * Корректно распарсенная пустая страница
             * означает окончание доступного списка.
             */
            if ($result->reviews === []) {
                break;
            }

            $before = count($reviewsById);

            foreach ($result->reviews as $review) {
                /**
                 * Не превышаем установленный лимит.
                 */
                if (count($reviewsById) >= $maxReviews) {
                    break;
                }

                $reviewsById[$review->id] = $review;
            }

            $added = count($reviewsById) - $before;
            $collectedReviews = count($reviewsById);

            if ($onProgress !== null) {
                $onProgress(
                    $page,
                    $maxPages,
                    $collectedReviews,
                );
            }

            Log::debug(
                'Yandex page collected',
                [
                    'business_id' => $url->businessId,
                    'page' => $page,
                    'page_reviews' => count($result->reviews),
                    'added_unique_reviews' => $added,
                    'total_reviews' => $collectedReviews,
                ],
            );
        }

        return new ParseResult(
            status: ParseStatus::Ok,
            organization: $firstPage->organization,
            reviews: array_values($reviewsById),
            page: 1,
            hasMore: false,
        );
    }

    /**
     * Преобразует пользовательский URL в нормализованный Url.
     *
     * Поддерживаются:
     *
     * - обычная ссылка организации;
     * - ссылка на отзывы;
     * - короткая ссылка вида /maps/-/XXXXXXXX.
     */
    public function resolve(string $input): ?Url
    {
        $input = trim($input);

        /**
         * Сначала пробуем нормальный URL организации.
         */
        $normal = Url::tryFrom($input);

        if ($normal !== null) {
            return $normal;
        }

        /**
         * Если не получилось — возможно,
         * это короткая ссылка.
         */
        if (! $this->isShortUrl($input)) {
            return null;
        }

        return $this->resolveShortUrl($input);
    }

    /**
     * Проверяет, похож ли URL на short maps link.
     */
    private function isShortUrl(string $url): bool
    {
        $parsed = parse_url($url);

        if ($parsed === false) {
            return false;
        }

        $host = strtolower($parsed['host'] ?? '');
        $path = $parsed['path'] ?? '';

        return in_array(
            $host,
            [
                'yandex.ru',
                'www.yandex.ru',
                'yandex.com',
                'www.yandex.com',
            ],
            true,
        )
            && preg_match(
                '#^/maps/-/[^/]+$#u',
                $path,
            ) === 1;
    }

    /**
     * Разрешает короткий URL через HTTP redirect.
     *
     * Конечный URL может содержать:
     *
     * poi[uri]=ymapsbm1://org?oid=123456789
     *
     * поэтому после redirect дополнительно ищем oid.
     */
    private function resolveShortUrl(string $shortUrl): ?Url
    {
        $effectiveUrl = null;

        try {
            $response = $this->http
                ->withHeaders([
                    'User-Agent' => config(
                        'yandex.user_agent',
                        'Mozilla/5.0 (Windows NT 10.0; Win64; x64) '
                        .'AppleWebKit/537.36 '
                        .'(KHTML, like Gecko) '
                        .'Chrome/140.0 Safari/537.36',
                    ),
                ])
                ->withOptions([
                    'allow_redirects' => [
                        'max' => 10,
                    ],

                    'on_stats' => function (
                        TransferStats $stats,
                    ) use (&$effectiveUrl): void {
                        $effectiveUri = $stats->getEffectiveUri();

                        if ($effectiveUri !== null) {
                            $effectiveUrl = (string) $effectiveUri;
                        }
                    },
                ])
                ->timeout(
                    (int) config(
                        'yandex.timeout',
                        20,
                    )
                )
                ->get($shortUrl);
        } catch (\Throwable $e) {
            Log::warning(
                'Failed to resolve Yandex short URL',
                [
                    'url' => $shortUrl,
                    'exception' => $e::class,
                    'message' => $e->getMessage(),
                ],
            );

            return null;
        }

        if (! $response->successful()) {
            return null;
        }

        /**
         * В идеальном случае effective URL уже обычный
         * organization URL.
         */
        if ($effectiveUrl !== null) {
            $normal = Url::tryFrom($effectiveUrl);

            if ($normal !== null) {
                return $normal;
            }
        }

        /**
         * Альтернативный случай:
         *
         * poi[uri]=ymapsbm1://org?oid=57225207468
         */
        if ($effectiveUrl !== null) {
            $decodedUrl = urldecode($effectiveUrl);

            if (preg_match(
                '#(?:^|[?&])poi\[uri\]=ymapsbm1://org\?oid=(\d+)#u',
                $decodedUrl,
                $matches,
            )) {
                return new Url(
                    businessId: $matches[1],
                    slug: null,
                );
            }
        }

        return null;
    }

    /**
     * Пытается определить, что Яндекс вместо страницы
     * организации вернул CAPTCHA / challenge.
     *
     * Это эвристика, а не официальный API.
     */
    private function looksLikeCaptcha(string $html): bool
    {
        /**
         * Если в странице есть reviewId,
         * это сильный признак нормальной страницы отзывов.
         */
        $hasReviews = str_contains(
            $html,
            '"reviewId"',
        );

        if ($hasReviews) {
            return false;
        }

        return preg_match(
            '/
            smartcaptcha
            |
            showcaptcha
            |
            checkcaptcha
            |
            captcha\.yandex
            |
            yandexcaptcha
        /ix',
            $html,
        ) === 1;
    }
}
