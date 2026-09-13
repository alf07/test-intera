<?php

namespace Tests\Unit\Services\Yandex;

use App\Services\Yandex\Url;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class UrlTest extends TestCase
{
    #[DataProvider('validUrlsProvider')]
    public function test_normal_organization_url_is_parsed(
        string $input,
        string $expectedBusinessId,
        ?string $expectedSlug,
    ): void {
        $url = Url::tryFrom($input);

        $this->assertNotNull($url);
        $this->assertSame($expectedBusinessId, $url->businessId);
        $this->assertSame($expectedSlug, $url->slug);
    }

    public static function validUrlsProvider(): array
    {
        return [
            [
                'https://yandex.ru/maps/org/foo/123456789/',
                '123456789',
                'foo',
            ],
            [
                'https://www.yandex.com/maps/org/foo/123456789/reviews/',
                '123456789',
                'foo',
            ],
            [
                'https://yandex.ru/maps/org/123456789/',
                '123456789',
                null,
            ],
            [
                'https://yandex.com/maps/org/123456789/reviews/',
                '123456789',
                null,
            ],
        ];
    }

    public function test_short_url_is_not_parsed_directly(): void
    {
        $url = Url::tryFrom(
            'https://yandex.ru/maps/-/CTh9mLkf',
        );

        $this->assertNull($url);
    }

    public function test_other_domain_is_rejected(): void
    {
        $url = Url::tryFrom(
            'https://example.com/maps/org/foo/123456789/',
        );

        $this->assertNull($url);
    }

    public function test_invalid_scheme_is_rejected(): void
    {
        $url = Url::tryFrom(
            'javascript://yandex.ru/maps/org/foo/123456789/',
        );

        $this->assertNull($url);
    }

    public function test_reviews_url_without_slug_is_generated(): void
    {
        $url = new Url(
            businessId: '123456789',
        );

        $this->assertSame(
            'https://yandex.com/maps/org/123456789/reviews/',
            $url->reviewsUrl(
                baseUrl: 'https://yandex.com',
            ),
        );

        $this->assertSame(
            'https://yandex.com/maps/org/123456789/reviews/?page=2',
            $url->reviewsUrl(
                baseUrl: 'https://yandex.com',
                page: 2,
            ),
        );
    }

    public function test_reviews_url_with_slug_is_generated(): void
    {
        $url = new Url(
            businessId: '123456789',
            slug: 'foo',
        );

        $this->assertSame(
            'https://yandex.ru/maps/org/foo/123456789/reviews/',
            $url->reviewsUrl(
                baseUrl: 'https://yandex.ru/',
            ),
        );
    }

    public function test_invalid_page_number_throws_exception(): void
    {
        $url = new Url(
            businessId: '123456789',
        );

        $this->expectException(\InvalidArgumentException::class);

        $url->reviewsUrl(
            baseUrl: 'https://yandex.com',
            page: 0,
        );
    }
}
