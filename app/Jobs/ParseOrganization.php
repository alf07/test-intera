<?php

namespace App\Jobs;

use App\Enums\ParseStatus;
use App\Models\Organization;
use App\Models\Review;
use App\Services\Yandex\Parser;
use App\Services\Yandex\Url;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Фоновый парсинг организации и её отзывов.
 *
 * Ответственности Job:
 *
 * - запустить parser;
 * - хранить состояние процесса;
 * - обновлять progress;
 * - сохранить результат в БД;
 * - выполнять retry временных ошибок.
 *
 * Parser при этом ничего не знает:
 *
 * - о моделях;
 * - о БД;
 * - о Queue;
 * - о progress.
 */
final class ParseOrganization implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    /**
     * Максимальное количество попыток Job.
     *
     * Всего выполняется не более трёх попыток.
     */
    public int $tries = 3;

    /**
     * Максимальное время выполнения одной попытки.
     *
     * Парсер может последовательно запросить несколько страниц.
     */
    public int $timeout = 360;

    /**
     * Как долго Laravel будет считать Job уникальной.
     *
     * Это защищает от одновременного запуска нескольких
     * одинаковых Job для одной организации.
     */
    public int $uniqueFor = 600;

    /**
     * ID организации.
     *
     * Передаём в Queue только scalar ID,
     * а модель загружаем непосредственно перед выполнением.
     */
    public function __construct(
        public int $organizationId,
    ) {}

    /**
     * Уникальность Job определяется организацией.
     *
     * Если пользователь дважды нажал "Сохранить",
     * две одинаковые Job одновременно не должны выполняться.
     */
    public function uniqueId(): string
    {
        return (string) $this->organizationId;
    }

    /**
     * Задержка между повторными попытками.
     */
    public function backoff(): array
    {
        return [
            30,
            120,
        ];
    }

    /**
     * Основная логика Job.
     */
    public function handle(Parser $parser): void
    {
        /**
         * Загружаем актуальную организацию из БД.
         *
         * Это лучше, чем хранить целую модель внутри Job:
         * к моменту выполнения модель могла измениться.
         */
        $organization = Organization::query()
            ->findOrFail($this->organizationId);

        /**
         * --------------------------------------------------------------
         * START
         * --------------------------------------------------------------
         *
         * С этого момента frontend может показать:
         *
         * "Парсинг выполняется".
         */
        $organization->update([
            'parse_status' => ParseStatus::Processing,
            'parse_progress' => 0,
        ]);

        try {
            /**
             * ----------------------------------------------------------
             * RESOLVE URL
             * ----------------------------------------------------------
             *
             * В Organization хранится уже нормализованный
             * business ID, поэтому здесь достаточно создать Url.
             *
             * Поддержка short URL выполняется раньше,
             * в OrganizationController через Parser::resolve().
             */
            $url = new Url(
                businessId: $organization->external_id,
            );

            /**
             * ----------------------------------------------------------
             * PARSING
             * ----------------------------------------------------------
             *
             * Parser получает только URL и callback progress.
             *
             * DB здесь остаётся ответственностью Job.
             */
            $result = $parser->parseAll(
                url: $url,

                /**
                 * Callback вызывается после каждой успешно
                 * обработанной страницы.
                 */
                onProgress: function (
                    int $page,
                    int $maxPages,
                    int $collectedReviews,
                ) use ($organization): void {
                    /**
                     * Прогресс считаем по страницам.
                     *
                     * Мы не можем считать:
                     *
                     * collectedReviews / reviewsCount
                     *
                     * потому что parser может получить
                     * только часть доступных отзывов,
                     * а reviewsCount относится ко всей карточке.
                     */
                    $progress = min(
                        99,
                        (int) round(
                            ($page / $maxPages) * 100,
                        ),
                    );

                    /**
                     * 100% будет установлено только
                     * после успешного сохранения результата.
                     */
                    $organization->update([
                        'parse_progress' => $progress,
                    ]);

                    Log::debug(
                        'Organization parsing progress',
                        [
                            'organization_id' => $organization->id,
                            'page' => $page,
                            'max_pages' => $maxPages,
                            'reviews' => $collectedReviews,
                            'progress' => $progress,
                        ],
                    );
                },
            );

            /**
             * ----------------------------------------------------------
             * CONTROLLED PARSER ERROR
             * ----------------------------------------------------------
             *
             * Parser может вернуть:
             *
             * - unavailable
             * - blocked
             * - captcha
             * - markup_changed
             * - empty
             *
             * Частичный результат не сохраняем.
             */
            if (! $result->isOk()) {
                $this->handleParserFailure(
                    organization: $organization,
                    status: $result->status,
                    page: $result->page,
                );

                return;
            }

            /**
             * Дополнительная защита от некорректного результата parser-а.
             */
            if ($result->organization === null) {
                $this->recordParserFailure(
                    organization: $organization,
                    status: ParseStatus::MarkupChanged,
                    message: 'Parser returned empty organization data.',
                );

                return;
            }

            /**
             * ----------------------------------------------------------
             * SAVE
             * ----------------------------------------------------------
             *
             * Организация и отзывы сохраняются одной транзакцией.
             *
             * Если любой SQL-запрос внутри транзакции завершится
             * исключением — изменения откатятся.
             */
            DB::transaction(function () use (
                $organization,
                $result,
            ): void {
                /**
                 * ------------------------------------------------------
                 * ORGANIZATION
                 * ------------------------------------------------------
                 */
                $organization->update([
                    'name' => $result->organization->name,
                    'rating' => $result->organization->rating,
                    'ratings_count' => $result->organization->ratingsCount,
                    'reviews_count' => $result->organization->reviewsCount,
                ]);

                /**
                 * ------------------------------------------------------
                 * REVIEWS
                 * ------------------------------------------------------
                 *
                 * Подготавливаем массив для bulk upsert.
                 */
                $rows = [];

                /**
                 * Используем один timestamp для всех строк.
                 */
                $now = now();

                foreach ($result->reviews as $review) {
                    $rows[] = [
                        'organization_id' => $organization->id,
                        'external_id' => $review->id,
                        'author' => $review->author,
                        'rating' => $review->rating,
                        'reviewed_at' => $review->date,
                        'text' => $review->text,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ];
                }

                /**
                 * Для организации без отзывов
                 * вставлять нечего.
                 */
                if ($rows === []) {
                    return;
                }

                /**
                 * Идемпотентное сохранение.
                 *
                 * Уникальный ключ:
                 *
                 * organization_id + external_id
                 *
                 * Поэтому повторный запуск parser-а
                 * не создаст дубликатов.
                 */
                Review::query()->upsert(
                    $rows,
                    [
                        'organization_id',
                        'external_id',
                    ],
                    [
                        'author',
                        'rating',
                        'reviewed_at',
                        'text',
                        'updated_at',
                    ],
                );
            });

            /**
             * ----------------------------------------------------------
             * SUCCESS
             * ----------------------------------------------------------
             *
             * Только теперь операция считается полностью успешной.
             */
            $organization->update([
                'parse_status' => ParseStatus::Ok,
                'parse_progress' => 100,
                'parse_error' => null,
                'parsed_at' => now(),
            ]);

            Log::info(
                'Organization parsing completed',
                [
                    'organization_id' => $organization->id,
                    'reviews' => count($result->reviews),
                    'rating' => $result->organization->rating,
                    'ratings_count' => $result->organization->ratingsCount,
                    'reviews_count' => $result->organization->reviewsCount,
                ],
            );
        } catch (Throwable $e) {
            /**
             * ==========================================================
             * UNEXPECTED ERROR
             * ==========================================================
             *
             * Не глотаем exception.
             *
             * Повторный throw сообщает Queue:
             *
             * "эта попытка завершилась ошибкой".
             *
             * Laravel применит $tries и backoff().
             */
            Log::error(
                'Organization parsing job failed',
                [
                    'organization_id' => $organization->id,
                    'exception' => $e::class,
                    'message' => $e->getMessage(),
                ],
            );

            throw $e;
        }
    }

    /**
     * Обрабатывает контролируемую ошибку parser-а.
     */
    private function handleParserFailure(
        Organization $organization,
        ParseStatus $status,
        int $page,
    ): void {
        /**
         * Временные ошибки.
         *
         * Есть смысл повторить операцию:
         *
         * - unavailable
         * - blocked
         * - captcha
         * - empty
         */
        if (in_array(
            $status,
            [
                ParseStatus::Unavailable,
                ParseStatus::Blocked,
                ParseStatus::Captcha,
                ParseStatus::Empty,
            ],
            true,
        )) {
            $message = sprintf(
                'Yandex parser failed on page %d with status "%s".',
                $page,
                $status->value,
            );

            /**
             * Сохраняем конкретную причину последней
             * контролируемой ошибки.
             *
             * Следующая попытка не обязана сбрасывать
             * это значение, поэтому при окончательном
             * failed() причина не потеряется.
             */
            $this->recordParserFailure(
                organization: $organization,
                status: $status,
                message: $message,
            );

            /**
             * Exception необходим, чтобы Laravel Queue
             * действительно выполнил retry.
             */
            throw new \RuntimeException($message);
        }

        /**
         * Постоянная ошибка.
         *
         * Например, parser больше не понимает структуру
         * ответа Яндекса.
         *
         * Повторять тот же код бессмысленно.
         */
        $this->recordParserFailure(
            organization: $organization,
            status: $status,
            message: sprintf(
                'Yandex response structure is not supported (page %d).',
                $page,
            ),
        );
    }

    /**
     * Сохраняет контролируемое состояние ошибки parser-а.
     */
    private function recordParserFailure(
        Organization $organization,
        ParseStatus $status,
        string $message,
    ): void {
        $organization->update([
            'parse_status' => $status,
            'parse_error' => $message,
        ]);

        Log::warning(
            'Organization parsing failed',
            [
                'organization_id' => $organization->id,
                'status' => $status->value,
                'message' => $message,
            ],
        );
    }

    /**
     * Вызывается Laravel после окончательного провала Job.
     *
     * Здесь сохраняем конкретную причину последней ошибки,
     * если она уже была записана parser-ом.
     */
    public function failed(?Throwable $exception): void
    {
        $organization = Organization::query()
            ->find($this->organizationId);

        if ($organization === null) {
            return;
        }

        /**
         * Если parser уже сохранил конкретную причину,
         * например "captcha" или "unavailable",
         * не заменяем её общей ошибкой Queue.
         */
        $message = $organization->parse_error
            ?? $exception?->getMessage()
            ?? 'Parsing job failed after all retries.';

        $organization->update([
            'parse_status' => ParseStatus::Failed,
            'parse_error' => $message,
        ]);

        Log::error(
            'Organization parsing permanently failed',
            [
                'organization_id' => $organization->id,
                'exception' => $exception !== null
                    ? $exception::class
                    : null,
                'message' => $message,
            ],
        );
    }
}
