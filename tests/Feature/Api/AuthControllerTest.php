<?php

namespace Tests\Feature\Api;

use App\Models\User;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class AuthControllerTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('sanctum.stateful', [
            'localhost:5176',
            '127.0.0.1:5176',
        ]);
    }

    public function test_login_with_valid_credentials_returns_user(): void
    {
        $user = User::factory()->create([
            'email' => 'demo@example.com',
            'password' => 'password',
        ]);

        $response = $this
            ->withoutMiddleware(PreventRequestForgery::class)
            ->withHeaders($this->spaHeaders())
            ->postJson('/api/login', [
                'email' => 'demo@example.com',
                'password' => 'password',
            ]);

        $response
            ->assertOk()
            ->assertJsonPath('user.id', $user->id)
            ->assertJsonPath(
                'user.email',
                'demo@example.com',
            );
    }

    public function test_login_with_invalid_credentials_returns_422(): void
    {
        User::factory()->create([
            'email' => 'demo@example.com',
            'password' => 'password',
        ]);

        $response = $this
            ->withoutMiddleware(PreventRequestForgery::class)
            ->withHeaders($this->spaHeaders())
            ->postJson('/api/login', [
                'email' => 'demo@example.com',
                'password' => 'wrong-password',
            ]);

        $response
            ->assertStatus(422)
            ->assertJson([
                'message' => 'Invalid credentials.',
            ]);
    }

    public function test_login_validates_email_and_password(): void
    {
        $response = $this
            ->withoutMiddleware(PreventRequestForgery::class)
            ->withHeaders($this->spaHeaders())
            ->postJson('/api/login', []);

        $response
            ->assertStatus(422)
            ->assertJsonValidationErrors([
                'email',
                'password',
            ]);
    }

    public function test_authenticated_user_can_get_me(): void
    {
        User::factory()->create([
            'email' => 'demo@example.com',
            'password' => 'password',
        ]);

        $this->login();

        $response = $this
            ->withHeaders($this->spaHeaders())
            ->getJson('/api/me');

        $response
            ->assertOk()
            ->assertJsonPath(
                'user.email',
                'demo@example.com',
            );
    }

    public function test_authenticated_user_can_logout(): void
    {
        User::factory()->create([
            'email' => 'demo@example.com',
            'password' => 'password',
        ]);

        $this->login();

        $response = $this
            ->withoutMiddleware(PreventRequestForgery::class)
            ->withHeaders($this->spaHeaders())
            ->postJson('/api/logout');

        $response
            ->assertOk()
            ->assertJson([
                'message' => 'Logged out.',
            ]);
    }

    public function test_me_requires_authentication(): void
    {
        $response = $this
            ->withHeaders($this->spaHeaders())
            ->getJson('/api/me');

        $response->assertUnauthorized();
    }

    private function login(): void
    {
        $this
            ->withoutMiddleware(PreventRequestForgery::class)
            ->withHeaders($this->spaHeaders())
            ->postJson('/api/login', [
                'email' => 'demo@example.com',
                'password' => 'password',
            ])
            ->assertOk();
    }

    private function spaHeaders(): array
    {
        return [
            'Origin' => 'http://127.0.0.1:5176',
            'Referer' => 'http://127.0.0.1:5176/login',
        ];
    }
}
