<?php

namespace Tests\Feature;

use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class AdminCsrfTokenTest extends TestCase
{
    public function test_login_and_logout_return_the_current_session_csrf_token(): void
    {
        $userId = '05d8cad9-0b1b-414a-a2b2-cb6dbf839266';

        config([
            'services.supabase.url' => 'https://project.supabase.co',
            'services.supabase.anon_key' => 'test-anon-key',
            'services.supabase.service_role_key' => 'test-service-role-key',
        ]);

        Http::fake([
            'project.supabase.co/auth/v1/token*' => Http::response([
                'user' => ['id' => $userId],
                'access_token' => 'test-access-token',
            ]),
            'project.supabase.co/auth/v1/user' => Http::response([
                'id' => $userId,
            ]),
            'project.supabase.co/rest/v1/profiles*' => Http::response([[
                'id' => $userId,
                'plan' => 'free',
                'subscription_status' => 'active',
                'requested_plan' => null,
            ]]),
        ]);

        $roleQuery = \Mockery::mock(Builder::class);
        $roleQuery->shouldReceive('where')->andReturnSelf()->twice();
        $roleQuery->shouldReceive('exists')->andReturnTrue();
        DB::shouldReceive('table')
            ->with('user_roles')
            ->andReturn($roleQuery)
            ->once();

        $initialToken = $this->getJson('/api/csrf-token')
            ->assertOk()
            ->json('csrf_token');

        $login = $this->postJson('/api/admin/login', [
            'email' => 'parent@example.com',
            'password' => 'valid-password-123',
        ], [
            'X-CSRF-TOKEN' => $initialToken,
        ])->assertOk();

        $loginToken = $login->json('csrf_token');
        $this->assertIsString($loginToken);
        $this->assertNotSame($initialToken, $loginToken);

        $logout = $this->postJson('/api/admin/logout', [], [
            'X-CSRF-TOKEN' => $loginToken,
        ])->assertOk();

        $logoutToken = $logout->json('csrf_token');
        $this->assertIsString($logoutToken);
        $this->assertNotSame($loginToken, $logoutToken);
    }

    public function test_logout_clears_expired_supabase_session_without_requiring_supabase(): void
    {
        Http::fake();

        $this->withSession([
            'supabase_access_token' => 'expired-access-token',
            'supabase_user_id' => '05d8cad9-0b1b-414a-a2b2-cb6dbf839266',
        ]);
        $csrfToken = $this->getJson('/api/csrf-token')
            ->assertOk()
            ->json('csrf_token');

        $response = $this->postJson('/api/admin/logout', [], [
            'X-CSRF-TOKEN' => $csrfToken,
        ])->assertOk()
            ->assertJsonPath('message', 'Sesión cerrada.');

        $this->assertIsString($response->json('csrf_token'));
        Http::assertNothingSent();
    }

    public function test_failed_profile_lookup_does_not_rotate_the_login_session_or_break_csrf(): void
    {
        $userId = '05d8cad9-0b1b-414a-a2b2-cb6dbf839266';
        config([
            'services.supabase.url' => 'https://project.supabase.co',
            'services.supabase.anon_key' => 'test-anon-key',
            'services.supabase.service_role_key' => 'sb_secret_backend_key',
        ]);

        Http::fake([
            'project.supabase.co/auth/v1/token*' => Http::response([
                'user' => ['id' => $userId],
                'access_token' => 'test-access-token',
            ]),
            'project.supabase.co/rest/v1/profiles*' => Http::response([
                'code' => 'PGRST301',
                'message' => 'Invalid JWT',
            ], 401),
            'project.supabase.co/auth/v1/user' => Http::response([
                'id' => $userId,
            ]),
        ]);

        $roleQuery = \Mockery::mock(Builder::class);
        $roleQuery->shouldReceive('where')->andReturnSelf()->twice();
        $roleQuery->shouldReceive('exists')->andReturnTrue();
        DB::shouldReceive('table')
            ->with('user_roles')
            ->andReturn($roleQuery);

        $csrfToken = $this->getJson('/api/csrf-token')
            ->assertOk()
            ->json('csrf_token');

        $this->postJson('/api/admin/login', [
            'email' => 'parent@example.com',
            'password' => 'valid-password-123',
        ], [
            'X-CSRF-TOKEN' => $csrfToken,
        ])->assertServiceUnavailable();

        $this->postJson('/api/admin/logout', [], [
            'X-CSRF-TOKEN' => $csrfToken,
        ])->assertOk()
            ->assertJsonPath('message', 'Sesión cerrada.');
    }
}
