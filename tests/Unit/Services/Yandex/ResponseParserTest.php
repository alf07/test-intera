<?php

namespace Tests\Unit\Services\Yandex;

use App\Enums\ParseStatus;
use App\Services\Yandex\ResponseParser;
use Tests\TestCase;

final class ResponseParserTest extends TestCase
{
    private ResponseParser $parser;

    protected function setUp(): void
    {
        parent::setUp();

        $this->parser = new ResponseParser;
    }

    public function test_empty_html_returns_empty_status(): void
    {
        $result = $this->parser->parse('');

        $this->assertSame(ParseStatus::Empty, $result->status);
        $this->assertSame(1, $result->page);
        $this->assertFalse($result->isOk());
    }

    public function test_invalid_html_structure_returns_markup_changed(): void
    {
        $result = $this->parser->parse(
            '<html><body>Yandex Maps</body></html>',
        );

        $this->assertSame(
            ParseStatus::MarkupChanged,
            $result->status,
        );

        $this->assertSame(1, $result->page);
        $this->assertFalse($result->isOk());
    }

    public function test_missing_rating_data_returns_markup_changed(): void
    {
        $html = $this->jsonHtml([
            'name' => 'Test organization',
            'reviewResults' => [
                'reviews' => [],
            ],
        ]);

        $result = $this->parser->parse($html);

        $this->assertSame(
            ParseStatus::MarkupChanged,
            $result->status,
        );
    }

    public function test_missing_reviews_structure_returns_markup_changed(): void
    {
        $html = $this->jsonHtml([
            'name' => 'Test organization',
            'ratingData' => [
                'ratingValue' => 4.8,
                'ratingCount' => 120,
                'reviewCount' => 30,
            ],
        ]);

        $result = $this->parser->parse($html);

        $this->assertSame(
            ParseStatus::MarkupChanged,
            $result->status,
        );
    }

    public function test_non_array_reviews_returns_markup_changed(): void
    {
        $html = $this->jsonHtml([
            'name' => 'Test organization',
            'ratingData' => [
                'ratingValue' => 4.8,
                'ratingCount' => 120,
                'reviewCount' => 30,
            ],
            'reviewResults' => [
                'reviews' => 'broken',
            ],
        ]);

        $result = $this->parser->parse($html);

        $this->assertSame(
            ParseStatus::MarkupChanged,
            $result->status,
        );
    }

    public function test_valid_page_is_parsed_into_normalized_dto(): void
    {
        $html = $this->jsonHtml([
            'name' => 'Ресторан Тест',
            'ratingData' => [
                'ratingValue' => 4.87,
                'ratingCount' => 366,
                'reviewCount' => 146,
            ],
            'reviewResults' => [
                'reviews' => [
                    [
                        'reviewId' => 'review-1',
                        'author' => 'Иван',
                        'rating' => 5,
                        'text' => 'Отличное место',
                        'updatedTime' => '2026-09-10T12:30:00+03:00',
                    ],
                ],
            ],
        ]);

        $result = $this->parser->parse(
            html: $html,
            page: 2,
        );

        $this->assertTrue($result->isOk());
        $this->assertSame(ParseStatus::Ok, $result->status);
        $this->assertSame(2, $result->page);

        $this->assertNotNull($result->organization);
        $this->assertSame(
            'Ресторан Тест',
            $result->organization->name,
        );

        $this->assertSame(
            4.87,
            $result->organization->rating,
        );

        $this->assertSame(
            366,
            $result->organization->ratingsCount,
        );

        $this->assertSame(
            146,
            $result->organization->reviewsCount,
        );

        $this->assertCount(1, $result->reviews);

        $review = $result->reviews[0];

        $this->assertSame('review-1', $review->id);
        $this->assertSame('Иван', $review->author);
        $this->assertSame(5, $review->rating);
        $this->assertSame('Отличное место', $review->text);
        $this->assertNotNull($review->date);
    }

    public function test_empty_reviews_is_successful_result(): void
    {
        $html = $this->jsonHtml([
            'name' => 'Организация',
            'ratingData' => [
                'ratingValue' => 4.5,
                'ratingCount' => 100,
                'reviewCount' => 10,
            ],
            'reviewResults' => [
                'reviews' => [],
            ],
        ]);

        $result = $this->parser->parse($html);

        $this->assertTrue($result->isOk());
        $this->assertSame([], $result->reviews);
        $this->assertFalse($result->hasMore);
    }

    public function test_review_without_id_is_skipped(): void
    {
        $html = $this->jsonHtml([
            'name' => 'Организация',
            'ratingData' => [
                'ratingValue' => 4.5,
                'ratingCount' => 100,
                'reviewCount' => 10,
            ],
            'reviewResults' => [
                'reviews' => [
                    [
                        'author' => 'Без ID',
                        'rating' => 5,
                        'text' => 'Такой отзыв нельзя сохранить',
                    ],
                    [
                        'reviewId' => 'review-2',
                        'author' => 'Иван',
                        'rating' => 4,
                        'text' => 'Нормально',
                    ],
                ],
            ],
        ]);

        $result = $this->parser->parse($html);

        $this->assertTrue($result->isOk());
        $this->assertCount(1, $result->reviews);
        $this->assertSame('review-2', $result->reviews[0]->id);
    }

    public function test_author_object_is_normalized(): void
    {
        $html = $this->jsonHtml([
            'name' => 'Организация',
            'ratingData' => [
                'ratingValue' => 4.5,
                'ratingCount' => 100,
                'reviewCount' => 10,
            ],
            'reviewResults' => [
                'reviews' => [
                    [
                        'reviewId' => 'review-1',
                        'author' => [
                            'name' => 'Пётр',
                        ],
                        'rating' => 5,
                        'text' => 'Хорошо',
                    ],
                ],
            ],
        ]);

        $result = $this->parser->parse($html);

        $this->assertSame(
            'Пётр',
            $result->reviews[0]->author,
        );
    }

    public function test_invalid_review_date_does_not_break_page(): void
    {
        $html = $this->jsonHtml([
            'name' => 'Организация',
            'ratingData' => [
                'ratingValue' => 4.5,
                'ratingCount' => 100,
                'reviewCount' => 10,
            ],
            'reviewResults' => [
                'reviews' => [
                    [
                        'reviewId' => 'review-1',
                        'author' => 'Иван',
                        'rating' => 5,
                        'text' => 'Хорошо',
                        'updatedTime' => 'not-a-date',
                    ],
                ],
            ],
        ]);

        $result = $this->parser->parse($html);

        $this->assertTrue($result->isOk());
        $this->assertCount(1, $result->reviews);
        $this->assertNull($result->reviews[0]->date);
    }

    private function jsonHtml(array $node): string
    {
        return sprintf(
            '<html><body><script type="application/json">%s</script></body></html>',
            json_encode(
                $node,
                JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE,
            ),
        );
    }
}
