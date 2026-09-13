<?php

namespace Tests\Feature\Api;

use App\Enums\ParseStatus;
use App\Jobs\ParseOrganization;
use App\Models\Organization;
use App\Models\Review;
use App\Models\User;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

final class OrganizationControllerTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        /**
         * Имитируем browser SPA,
         * который работает через Vite.
         */
        config()->set('sanctum.stateful', [
            'localhost:5176',
            '127.0.0.1:5176',
        ]);
    }

    public function test_authenticated_user_can_save_organization(): void
    {
        $user = User::factory()->create();

        Queue::fake();

        $response = $this
            ->actingAs($user, 'sanctum')
            ->withoutMiddleware(PreventRequestForgery::class)
            ->withHeaders($this->spaHeaders())
            ->putJson('/api/organization', [
                'url' => 'https://yandex.ru/maps/org/test/123456789/',
            ]);

        $response
            ->assertOk()
            ->assertJsonPath(
                'data.external_id',
                '123456789',
            )
            ->assertJsonPath(
                'data.url',
                'https://yandex.ru/maps/org/test/123456789/',
            );

        $this->assertDatabaseHas(
            'organizations',
            [
                'external_id' => '123456789',
                'source_url' => 'https://yandex.ru/maps/org/test/123456789/',
                'parse_status' => ParseStatus::Pending->value,
                'parse_progress' => 0,
            ],
        );

        Queue::assertPushed(
            ParseOrganization::class,
            static fn (
                ParseOrganization $job,
            ): bool => $job->organizationId !== 0,
        );
    }

    public function test_invalid_yandex_url_returns_validation_error(): void
    {
        $user = User::factory()->create();

        Queue::fake();

        $response = $this
            ->actingAs($user, 'sanctum')
            ->withoutMiddleware(PreventRequestForgery::class)
            ->withHeaders($this->spaHeaders())
            ->putJson('/api/organization', [
                'url' => 'https://example.com/company',
            ]);

        $response
            ->assertStatus(422)
            ->assertJsonValidationErrors(['url']);

        Queue::assertNothingPushed();

        $this->assertDatabaseCount(
            'organizations',
            0,
        );
    }

    public function test_organization_reviews_are_paginated(): void
    {
        $user = User::factory()->create();

        $organization = Organization::create([
            'external_id' => '123456789',
            'source_url' => 'https://yandex.ru/maps/org/test/123456789/',
            'name' => 'Test organization',
            'rating' => 5,
            'ratings_count' => 100,
            'reviews_count' => 60,
            'parse_status' => ParseStatus::Ok,
            'parse_progress' => 100,
            'parsed_at' => now(),
        ]);

        for ($i = 1; $i <= 60; $i++) {
            Review::create([
                'organization_id' => $organization->id,
                'external_id' => "review-$i",
                'author' => "Author $i",
                'rating' => 5,
                'reviewed_at' => now()->subMinutes($i),
                'text' => "Review $i",
            ]);
        }

        $response = $this
            ->actingAs($user, 'sanctum')
            ->withHeaders($this->spaHeaders())
            ->getJson('/api/organization/reviews?page=1');

        $response
            ->assertOk()
            ->assertJsonCount(50, 'data')
            ->assertJsonPath('meta.per_page', 50)
            ->assertJsonPath('meta.total', 60)
            ->assertJsonPath('meta.current_page', 1)
            ->assertJsonPath('meta.last_page', 2);
    }

    public function test_protected_organization_endpoint_requires_authentication(): void
    {
        $response = $this->getJson('/api/organization');

        $response->assertUnauthorized();
    }

    public function test_reselecting_existing_organization_makes_it_current(): void
    {
        $user = User::factory()->create();

        $first = Organization::create([
            'external_id' => '111111111',
            'source_url' => 'https://yandex.ru/maps/org/first/111111111/',
            'name' => 'First organization',
            'parse_status' => ParseStatus::Ok,
            'parse_progress' => 100,
        ]);

        Organization::create([
            'external_id' => '222222222',
            'source_url' => 'https://yandex.ru/maps/org/second/222222222/',
            'name' => 'Second organization',
            'parse_status' => ParseStatus::Ok,
            'parse_progress' => 100,
        ]);

        Queue::fake();

        /**
         * Повторно выбираем первую организацию.
         */
        $response = $this
            ->actingAs($user, 'sanctum')
            ->withoutMiddleware(PreventRequestForgery::class)
            ->withHeaders($this->spaHeaders())
            ->putJson('/api/organization', [
                'url' => 'https://yandex.ru/maps/org/first/111111111/',
            ]);

        $response
            ->assertOk()
            ->assertJsonPath(
                'data.external_id',
                $first->external_id,
            );

        /**
         * Проверяем, что current organization выбирается
         * не по максимальному ID, а по сохранённому
         * current_organization_id.
         */
        $this
            ->actingAs($user, 'sanctum')
            ->withHeaders($this->spaHeaders())
            ->withSession([
                'current_organization_id' => $first->id,
            ])
            ->getJson('/api/organization')
            ->assertOk()
            ->assertJsonPath(
                'data.external_id',
                $first->external_id,
            );
    }

    private function spaHeaders(): array
    {
        return [
            'Origin' => 'http://127.0.0.1:5176',
            'Referer' => 'http://127.0.0.1:5176/',
        ];
    }
}
