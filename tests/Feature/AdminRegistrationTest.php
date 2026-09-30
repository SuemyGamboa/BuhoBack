<?php

namespace Tests\Feature;

use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class AdminRegistrationTest extends TestCase
{
    public function test_supabase_api_key_error_is_explained_without_returning_a_generic_registration_error(): void
    {
        config([
            'services.supabase.url' => 'https://project.supabase.co',
            'services.supabase.anon_key' => 'invalid-test-key',
        ]);

        Http::fake([
            'project.supabase.co/auth/v1/signup' => Http::response([
                'message' => 'Invalid API key',
            ], 401),
        ]);

        $response = $this->postJson('/api/admin/register', [
            'email' => 'parent@example.com',
            'password' => 'valid-password-123',
            'password_confirmation' => 'valid-password-123',
        ]);

        $response
            ->assertStatus(503)
            ->assertJsonPath(
                'message',
                'Supabase rechazó la clave de API (HTTP 401). Verifica que SUPABASE_ANON_KEY sea la clave anon o publishable del mismo proyecto que SUPABASE_URL; no uses service_role. Reinicia Laravel después de cambiar .env.',
            );
    }

    public function test_supabase_registration_validation_message_is_shown_to_the_parent(): void
    {
        config([
            'services.supabase.url' => 'https://project.supabase.co',
            'services.supabase.anon_key' => 'valid-test-key',
        ]);

        Http::fake([
            'project.supabase.co/auth/v1/signup' => Http::response([
                'msg' => 'Password should be at least 12 characters',
            ], 400),
        ]);

        $response = $this->postJson('/api/admin/register', [
            'email' => 'parent@example.com',
            'password' => 'valid-password-123',
            'password_confirmation' => 'valid-password-123',
        ]);

        $response
            ->assertStatus(422)
            ->assertJsonPath(
                'message',
                'Supabase rechazó el registro: Password should be at least 12 characters',
            );
    }

    public function test_paid_plan_selection_is_saved_for_checkout_after_email_confirmation(): void
    {
        $userId = '05d8cad9-0b1b-414a-a2b2-cb6dbf839266';
        config([
            'services.supabase.url' => 'https://project.supabase.co',
            'services.supabase.anon_key' => 'valid-test-key',
            'services.supabase.service_role_key' => 'test-service-role-key',
        ]);

        Http::fake([
            'project.supabase.co/auth/v1/signup' => Http::response([
                'user' => [
                    'id' => $userId,
                    'identities' => [['id' => $userId]],
                ],
            ], 200),
            'project.supabase.co/rest/v1/profiles*' => Http::response([], 204),
        ]);

        $roleQuery = \Mockery::mock(Builder::class);
        $roleQuery->shouldReceive('insertOrIgnore')->once()->andReturn(1);
        DB::shouldReceive('table')
            ->with('user_roles')
            ->andReturn($roleQuery)
            ->once();

        $this->postJson('/api/admin/register', [
            'email' => 'parent@example.com',
            'password' => 'valid-password-123',
            'password_confirmation' => 'valid-password-123',
            'plan' => 'yearly',
        ])->assertCreated()
            ->assertJsonPath('authenticated', false)
            ->assertJsonPath('checkout_plan', 'yearly');

        Http::assertSent(fn ($request) => $request->method() === 'PATCH'
            && str_contains($request->url(), '/rest/v1/profiles')
            && $request['requested_plan'] === 'yearly'
            && $request->hasHeader('Authorization', 'Bearer test-service-role-key'));
    }
}
