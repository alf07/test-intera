<?php

namespace Tests\Feature\Jobs;

use App\Enums\ParseStatus;
use App\Jobs\ParseOrganization;
use App\Models\Organization;
use App\Models\Review;
use App\Services\Yandex\Parser;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

final class ParseOrganizationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('yandex.base_url', 'https://yandex.com');
        config()->set('yandex.timeout', 20);
        config()->set('yandex.page_delay_ms', 0);
        config()->set('yandex.review_cap', 600);
        config()->set('yandex.reviews_per_page', 50);
    }

    public function test_successful_job_saves_organization_and_reviews(): void
    {
        $organization = $this->createOrganization();

        Http::fake([
            '*' => Http::sequence()
                ->push(
                    $this->pageHtml(
                        name: 'Test organization',
                        reviews: [
                            [
                                'reviewId' => 'review-1',
                                'author' => 'Иван',
                                'rating' => 5,
                                'text' => 'Отлично',
                                'updatedTime' => '2026-09-10T12:00:00+03:00',
                            ],
                        ],
                    ),
                    200,
                )
                ->push(
                    $this->pageHtml(
                        name: 'Test organization',
                        reviews: [],
                    ),
                    200,
                ),
        ]);

        $job = new ParseOrganization(
            organizationId: $organization->id,
        );

        $job->handle(
            app(Parser::class),
        );

        $this->assertDatabaseHas(
            'organizations',
            [
                'id' => $organization->id,
                'name' => 'Test organization',
                'rating' => '4.80',
                'ratings_count' => 366,
                'reviews_count' => 146,
                'parse_status' => 'ok',
                'parse_progress' => 100,
            ],
        );

        $this->assertDatabaseHas(
            'reviews',
            [
                'organization_id' => $organization->id,
                'external_id' => 'review-1',
                'author' => 'Иван',
                'rating' => 5,
                'text' => 'Отлично',
            ],
        );
    }

    public function test_retryable_parser_failure_is_saved_and_exception_is_thrown(): void
    {
        $organization = $this->createOrganization();

        Http::fake([
            '*' => Http::response(
                '<html><body>smartcaptcha challenge</body></html>',
                200,
            ),
        ]);

        $job = new ParseOrganization(
            organizationId: $organization->id,
        );

        $this->expectException(\RuntimeException::class);

        try {
            $job->handle(app(Parser::class));
        } finally {
            $this->assertDatabaseHas(
                'organizations',
                [
                    'id' => $organization->id,
                    'parse_status' => 'captcha',
                    'parse_progress' => 0,
                ],
            );

            $organization->refresh();

            $this->assertSame(
                'Yandex parser failed on page 1 with status "captcha".',
                $organization->parse_error,
            );
        }
    }

    public function test_permanent_failure_preserves_parser_reason(): void
    {
        $organization = Organization::create([
            'external_id' => '123456789',
            'source_url' => 'https://yandex.ru/maps/-/demo',
            'parse_status' => ParseStatus::Captcha,
            'parse_progress' => 0,
            'parse_error' => 'Yandex parser failed on page 4 with status "captcha".',
        ]);

        $job = new ParseOrganization(
            organizationId: $organization->id,
        );

        $job->failed(
            new \RuntimeException(
                'The job has been attempted too many times.',
            ),
        );

        $this->assertDatabaseHas(
            'organizations',
            [
                'id' => $organization->id,
                'parse_status' => 'failed',
                'parse_error' => 'Yandex parser failed on page 4 with status "captcha".',
            ],
        );
    }

    public function test_markup_changed_does_not_throw_for_retry(): void
    {
        $organization = $this->createOrganization();

        Http::fake([
            '*' => Http::response(
                '<html><body>unsupported page</body></html>',
                200,
            ),
        ]);

        $job = new ParseOrganization(
            organizationId: $organization->id,
        );

        $job->handle(app(Parser::class));

        $this->assertDatabaseHas(
            'organizations',
            [
                'id' => $organization->id,
                'parse_status' => 'markup_changed',
            ],
        );
    }

    public function test_upsert_does_not_create_duplicate_review(): void
    {
        $organization = $this->createOrganization();

        Review::create([
            'organization_id' => $organization->id,
            'external_id' => 'review-1',
            'author' => 'Old author',
            'rating' => 4,
            'text' => 'Old text',
            'reviewed_at' => now()->subDay(),
        ]);

        Http::fake([
            '*' => Http::sequence()
                ->push(
                    $this->pageHtml(
                        name: 'Organization',
                        reviews: [
                            [
                                'reviewId' => 'review-1',
                                'author' => 'New author',
                                'rating' => 5,
                                'text' => 'New text',
                            ],
                        ],
                    ),
                    200,
                )
                ->push(
                    $this->pageHtml(
                        name: 'Organization',
                        reviews: [],
                    ),
                    200,
                ),
        ]);

        $job = new ParseOrganization(
            organizationId: $organization->id,
        );

        $job->handle(app(Parser::class));

        $this->assertDatabaseCount(
            'reviews',
            1,
        );

        $this->assertDatabaseHas(
            'reviews',
            [
                'organization_id' => $organization->id,
                'external_id' => 'review-1',
                'author' => 'New author',
                'rating' => 5,
                'text' => 'New text',
            ],
        );
    }

    private function createOrganization(): Organization
    {
        return Organization::create([
            'external_id' => '123456789',
            'source_url' => 'https://yandex.ru/maps/-/demo',
            'parse_status' => ParseStatus::Pending,
            'parse_progress' => 0,
        ]);
    }

    private function pageHtml(
        string $name,
        array $reviews,
    ): string {
        return sprintf(
            '<html><body><script type="application/json">%s</script></body></html>',
            json_encode(
                [
                    'name' => $name,
                    'ratingData' => [
                        'ratingValue' => 4.8,
                        'ratingCount' => 366,
                        'reviewCount' => 146,
                    ],
                    'reviewResults' => [
                        'reviews' => $reviews,
                    ],
                ],
                JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE,
            ),
        );
    }

    public function test_database_changes_are_rolled_back_when_review_persistence_fails(): void
    {
        $organization = $this->createOrganization();

        Http::fake([
            '*' => Http::sequence()
                ->push(
                    $this->pageHtml(
                        name: 'Updated organization',
                        reviews: [
                            [
                                'reviewId' => 'review-1',
                                'author' => 'Иван',
                                'rating' => 5,
                                'text' => 'Отлично',
                            ],
                        ],
                    ),
                    200,
                )
                ->push(
                    $this->pageHtml(
                        name: 'Updated organization',
                        reviews: [],
                    ),
                    200,
                ),
        ]);

        /**
         * Намеренно выбрасываем exception после выполнения
         * SQL-запроса для reviews.
         *
         * Исключение произойдёт внутри DB::transaction(),
         * поэтому Laravel должен выполнить rollback.
         */
        DB::listen(
            function (QueryExecuted $query): void {
                if (str_contains($query->sql, 'reviews')) {
                    throw new \RuntimeException(
                        'Simulated review persistence failure.',
                    );
                }
            },
        );

        $job = new ParseOrganization(
            organizationId: $organization->id,
        );

        $this->expectException(\RuntimeException::class);

        try {
            $job->handle(app(Parser::class));
        } finally {
            $organization->refresh();

            /**
             * Изменение организации внутри транзакции
             * тоже должно быть откатано.
             */
            $this->assertNull($organization->name);

            $this->assertNull($organization->rating);
            $this->assertNull($organization->ratings_count);
            $this->assertNull($organization->reviews_count);

            /**
             * Успешный status также не должен быть установлен.
             */
            $this->assertSame(
                ParseStatus::Processing,
                $organization->parse_status,
            );

            /**
             * Отзыв не должен остаться в БД.
             */
            $this->assertDatabaseMissing(
                'reviews',
                [
                    'organization_id' => $organization->id,
                    'external_id' => 'review-1',
                ],
            );
        }
    }

    public function test_only_one_job_is_queued_for_same_organization(): void
    {
        Cache::flush();

        Queue::fake();

        $organization = $this->createOrganization();

        ParseOrganization::dispatch(
            $organization->id,
        );

        ParseOrganization::dispatch(
            $organization->id,
        );

        Queue::assertPushedTimes(
            ParseOrganization::class,
            1,
        );
    }

    public function test_jobs_for_different_organizations_are_both_queued(): void
    {
        Cache::flush();

        Queue::fake();

        $organizationOne = $this->createOrganization();

        $organizationTwo = Organization::create([
            'external_id' => '987654321',
            'source_url' => 'https://yandex.ru/maps/-/demo-2',
            'parse_status' => ParseStatus::Pending,
            'parse_progress' => 0,
        ]);

        ParseOrganization::dispatch(
            $organizationOne->id,
        );

        ParseOrganization::dispatch(
            $organizationTwo->id,
        );

        Queue::assertPushedTimes(
            ParseOrganization::class,
            2,
        );
    }

    public function test_unique_id_is_based_on_organization_id(): void
    {
        $job = new ParseOrganization(
            organizationId: 42,
        );

        $this->assertSame(
            '42',
            $job->uniqueId(),
        );
    }

    public function test_job_retry_configuration_is_expected(): void
    {
        $job = new ParseOrganization(
            organizationId: 42,
        );

        $this->assertSame(3, $job->tries);
        $this->assertSame(360, $job->timeout);
        $this->assertSame(600, $job->uniqueFor);
        $this->assertSame(
            [30, 120],
            $job->backoff(),
        );
    }
}
