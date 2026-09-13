<?php

namespace Tests\Feature\Services\Yandex;

use App\Enums\ParseStatus;
use App\Services\Yandex\Parser;
use App\Services\Yandex\ResponseParser;
use App\Services\Yandex\Url;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

final class ParserTest extends TestCase
{
    private Parser $parser;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('yandex.base_url', 'https://yandex.com');
        config()->set('yandex.timeout', 20);
        config()->set('yandex.page_delay_ms', 0);
        config()->set('yandex.review_cap', 600);
        config()->set('yandex.reviews_per_page', 50);

        $this->parser = new Parser(
            Http::getFacadeRoot(),
            new ResponseParser,
        );
    }

    public function test_403_returns_blocked(): void
    {
        Http::fake([
            '*' => Http::response('Forbidden', 403),
        ]);

        $result = $this->parser->parsePage(
            new Url('123456789'),
        );

        $this->assertSame(
            ParseStatus::Blocked,
            $result->status,
        );

        $this->assertSame(1, $result->page);
    }

    public function test_429_returns_blocked(): void
    {
        Http::fake([
            '*' => Http::response(
                'Too many requests',
                429,
                ['Retry-After' => '120'],
            ),
        ]);

        $result = $this->parser->parsePage(
            new Url('123456789'),
        );

        $this->assertSame(
            ParseStatus::Blocked,
            $result->status,
        );
    }

    public function test_5xx_returns_unavailable(): void
    {
        Http::fake([
            '*' => Http::response('Server error', 503),
        ]);

        $result = $this->parser->parsePage(
            new Url('123456789'),
        );

        $this->assertSame(
            ParseStatus::Unavailable,
            $result->status,
        );
    }

    public function test_empty_body_returns_empty(): void
    {
        Http::fake([
            '*' => Http::response('', 200),
        ]);

        $result = $this->parser->parsePage(
            new Url('123456789'),
        );

        $this->assertSame(
            ParseStatus::Empty,
            $result->status,
        );
    }

    public function test_captcha_returns_captcha(): void
    {
        Http::fake([
            '*' => Http::response(
                '<html><body>smartcaptcha challenge</body></html>',
                200,
            ),
        ]);

        $result = $this->parser->parsePage(
            new Url('123456789'),
        );

        $this->assertSame(
            ParseStatus::Captcha,
            $result->status,
        );
    }

    public function test_valid_page_is_passed_to_response_parser(): void
    {
        Http::fake([
            '*' => Http::response(
                $this->pageHtml(
                    name: 'Test organization',
                    reviews: [
                        [
                            'reviewId' => 'review-1',
                            'author' => 'Ivan',
                            'rating' => 5,
                            'text' => 'Great',
                        ],
                    ],
                ),
            ),
        ]);

        $result = $this->parser->parsePage(
            new Url(
                businessId: '123456789',
                slug: 'test',
            ),
        );

        $this->assertTrue($result->isOk());
        $this->assertSame(
            'Test organization',
            $result->organization?->name,
        );

        $this->assertCount(1, $result->reviews);
    }

    public function test_parse_all_stops_after_empty_page(): void
    {
        Http::fake(function ($request) {
            $url = $request->url();

            if (str_contains($url, 'page=2')) {
                return Http::response(
                    $this->pageHtml(
                        name: 'Organization',
                        reviews: $this->reviews(51, 100),
                    ),
                );
            }

            if (str_contains($url, 'page=3')) {
                return Http::response(
                    $this->pageHtml(
                        name: 'Organization',
                        reviews: $this->reviews(101, 146),
                    ),
                );
            }

            if (str_contains($url, 'page=4')) {
                return Http::response(
                    $this->pageHtml(
                        name: 'Organization',
                        reviews: [],
                    ),
                );
            }

            return Http::response(
                $this->pageHtml(
                    name: 'Organization',
                    reviews: $this->reviews(1, 50),
                ),
            );
        });

        $result = $this->parser->parseAll(
            new Url('123456789'),
        );

        $this->assertTrue($result->isOk());
        $this->assertCount(146, $result->reviews);

        Http::assertSentCount(4);
    }

    public function test_parse_all_deduplicates_reviews(): void
    {
        Http::fake(function ($request) {
            $url = $request->url();

            if (str_contains($url, 'page=2')) {
                return Http::response(
                    $this->pageHtml(
                        name: 'Organization',
                        reviews: array_merge(
                            $this->reviews(26, 75),
                            $this->reviews(1, 10),
                        ),
                    ),
                );
            }

            return Http::response(
                $this->pageHtml(
                    name: 'Organization',
                    reviews: $this->reviews(1, 50),
                ),
            );
        });

        $result = $this->parser->parseAll(
            new Url('123456789'),
        );

        $this->assertTrue($result->isOk());
        $this->assertCount(75, $result->reviews);

        $ids = array_map(
            static fn ($review) => $review->id,
            $result->reviews,
        );

        $this->assertCount(
            count(array_unique($ids)),
            $ids,
        );
    }

    public function test_parse_all_does_not_return_partial_result_on_page_error(): void
    {
        Http::fake(function ($request) {
            if (str_contains($request->url(), 'page=2')) {
                return Http::response('Server error', 503);
            }

            return Http::response(
                $this->pageHtml(
                    name: 'Organization',
                    reviews: $this->reviews(1, 50),
                ),
            );
        });

        $result = $this->parser->parseAll(
            new Url('123456789'),
        );

        $this->assertFalse($result->isOk());
        $this->assertSame(
            ParseStatus::Unavailable,
            $result->status,
        );

        $this->assertSame(2, $result->page);
        $this->assertSame([], $result->reviews);
        $this->assertNull($result->organization);
    }

    public function test_parse_all_stops_at_review_cap(): void
    {
        Http::fake(function ($request) {
            preg_match(
                '/page=(\d+)/',
                $request->url(),
                $matches,
            );

            $page = isset($matches[1])
                ? (int) $matches[1]
                : 1;

            $start = (($page - 1) * 50) + 1;

            return Http::response(
                $this->pageHtml(
                    name: 'Organization',
                    reviews: $this->reviews(
                        $start,
                        $start + 49,
                    ),
                ),
            );
        });

        $result = $this->parser->parseAll(
            new Url('123456789'),
        );

        $this->assertTrue($result->isOk());
        $this->assertCount(600, $result->reviews);

        /**
         * 600 / 50 = 12 страниц.
         *
         * 13-я не нужна, потому что лимит уже достигнут.
         */
        Http::assertSentCount(12);
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
                        'ratingValue' => 5,
                        'ratingCount' => 366,
                        'reviewCount' => count($reviews),
                    ],
                    'reviewResults' => [
                        'reviews' => $reviews,
                    ],
                ],
                JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE,
            ),
        );
    }

    private function reviews(
        int $from,
        int $to,
    ): array {
        $reviews = [];

        for ($i = $from; $i <= $to; $i++) {
            $reviews[] = [
                'reviewId' => "review-$i",
                'author' => "Author $i",
                'rating' => 5,
                'text' => "Review $i",
            ];
        }

        return $reviews;
    }
}
