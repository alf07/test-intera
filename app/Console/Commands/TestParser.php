<?php

namespace App\Console\Commands;

use App\Services\Yandex\Parser;
use App\Services\Yandex\Url;
use Illuminate\Console\Command;

final class TestParser extends Command
{
    /**
     * Имя команды и её аргументы.
     *
     * --page позволяет проверить конкретную страницу.
     *
     * --all запускает полный сбор отзывов.
     */
    protected $signature = 'yandex:test
                            {url : Ссылка на организацию Яндекс.Карт}
                            {--page=1 : Номер страницы отзывов}
                            {--all : Получить все доступные отзывы}';

    protected $description = 'Проверка parser-а Яндекс.Карт';

    public function handle(Parser $parser): int
    {
        $input = $this->argument('url');

        $this->newLine();

        $this->info('=== Yandex parser test ===');

        $this->line("Input URL: {$input}");

        $this->newLine();

        $normalizedUrl = Url::tryFrom($input);

        if ($normalizedUrl !== null) {
            $this->info('URL type: normalized organization URL');

            $this->line(
                "Business ID: {$normalizedUrl->businessId}"
            );

            $this->line(
                'Slug: '.($normalizedUrl->slug ?? 'null')
            );
        } else {
            $this->info('URL type: short/share URL or non-normalized URL');
        }

        $this->newLine();
        if ($this->option('all')) {
            return $this->testAll($parser, $input);
        }

        return $this->testPage(
            parser: $parser,
            input: $input,
            page: (int) $this->option('page'),
        );
    }

    private function testPage(
        Parser $parser,
        string $input,
        int $page,
    ): int {

        $result = $this->parseSpecificPage(
            parser: $parser,
            input: $input,
            page: $page,
        );
        $this->newLine();

        $this->line(
            "Status: <comment>{$result->status->value}</comment>"
        );

        /*
         * Если parser сообщил ошибку —
         * считаем команду неуспешной.
         */
        if (! $result->isOk()) {
            $this->error(
                "Parser failed with status: {$result->status->value}"
            );

            return self::FAILURE;
        }

        /*
         * Organization может присутствовать
         * даже когда reviews отсутствуют.
         */
        if ($result->organization !== null) {
            $organization = $result->organization;

            $this->newLine();

            $this->info('Organization');

            $this->table(
                [
                    'Field',
                    'Value',
                ],
                [
                    [
                        'Name',
                        $organization->name ?? '-',
                    ],
                    [
                        'Rating',
                        $organization->rating ?? '-',
                    ],
                    [
                        'Ratings count',
                        $organization->ratingsCount ?? '-',
                    ],
                    [
                        'Reviews count',
                        $organization->reviewsCount ?? '-',
                    ],
                ],
            );
        }

        $this->newLine();

        $this->info(
            'Reviews on current page: '.count($result->reviews)
        );

        /*
         * Выводим первые 3 отзыва.
         * - автор есть;
         * - дата распарсилась;
         * - rating есть;
         * - текст есть;
         * - ID есть.
         */
        foreach (array_slice($result->reviews, 0, 3) as $index => $review) {
            $this->newLine();

            $this->line(
                '--- Review #'.($index + 1).' ---'
            );

            $this->line(
                "ID: {$review->id}"
            );

            $this->line(
                'Author: '.($review->author ?? '-')
            );

            $this->line(
                'Rating: '.($review->rating ?? '-')
            );

            $this->line(
                'Date: '.(
                    $review->date?->toIso8601String() ?? '-'
                )
            );

            $this->line(
                'Text: '.($review->text ?? '-')
            );
        }

        $this->newLine();

        $this->info('Page parsing completed successfully.');

        return self::SUCCESS;
    }

    /**
     * Полный тест.
     *
     * Запускается только после успешного page=1.
     */
    private function testAll(
        Parser $parser,
        string $input,
    ): int {
        $this->info('Starting full parsing...');

        $this->newLine();

        $result = $parser->parse($input);

        $this->line(
            "Status: <comment>{$result->status->value}</comment>"
        );

        if (! $result->isOk()) {
            $this->error(
                "Parser failed: {$result->status->value}"
            );

            return self::FAILURE;
        }

        $this->newLine();

        $this->info(
            'Total unique reviews: '.count($result->reviews)
        );

        if ($result->organization !== null) {
            $this->table(
                [
                    'Name',
                    'Rating',
                    'Ratings',
                    'Reviews',
                    'Parsed',
                ],
                [
                    [
                        $result->organization->name ?? '-',
                        $result->organization->rating ?? '-',
                        $result->organization->ratingsCount ?? '-',
                        $result->organization->reviewsCount ?? '-',
                        count($result->reviews),
                    ],
                ],
            );
        }

        return self::SUCCESS;
    }

    /**
     * Парсит конкретную страницу отзывов.
     *
     * В отличие от Parser::parse(), здесь мы НЕ запускаем
     * полный сбор отзывов.
     *
     * Это диагностический метод:
     *
     * --page=1 -> только первая страница
     * --page=2 -> только вторая страница
     * и т.д.
     */
    private function parseSpecificPage(
        Parser $parser,
        string $input,
        int $page,
    ) {
        /**
         * Преобразуем пользовательскую ссылку
         * в нормализованный Url.
         *
         * Для:
         *
         * https://yandex.ru/maps/-/CTh9mLkf
         *
         * Parser сначала выполнит redirect,
         * найдёт business ID,
         * а затем создаст Url.
         */
        $url = $parser->resolve($input);

        if ($url === null) {
            throw new \RuntimeException(
                'Unable to resolve Yandex organization URL.'
            );
        }

        $this->line(
            "Business ID: {$url->businessId}"
        );

        $this->line(
            'Slug: '.($url->slug ?? '-')
        );

        $this->line(
            "Page: {$page}"
        );

        /**
         * Показываем URL, который реально отправим Яндексу.
         *
         * Для page=1:
         * /reviews/
         *
         * Для page=2:
         * /reviews/?page=2
         */
        $reviewsUrl = $url->reviewsUrl(
            baseUrl: config(
                'yandex.base_url',
                'https://yandex.com',
            ),
            page: $page,
        );

        $this->line(
            "Reviews URL: {$reviewsUrl}"
        );

        return $parser->parsePage(
            url: $url,
            page: $page,
        );
    }
}
