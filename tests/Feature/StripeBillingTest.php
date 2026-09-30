<?php

namespace Tests\Feature;

use App\Http\Middleware\EnsureSupabaseAdmin;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class StripeBillingTest extends TestCase
{
    use RefreshDatabase;

    private const USER_ID = '05d8cad9-0b1b-414a-a2b2-cb6dbf839266';

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware(EnsureSupabaseAdmin::class);
        config([
            'services.supabase.url' => 'https://project.supabase.co',
            'services.supabase.service_role_key' => 'test-service-role-key',
            'services.stripe.secret_key' => 'sk_test_example',
            'services.stripe.webhook_secret' => 'whsec_test_example',
            'services.stripe.monthly_price_id' => 'price_monthly_test',
            'services.stripe.yearly_price_id' => 'price_yearly_test',
            'services.stripe.frontend_url' => 'http://localhost:5173',
            'services.stripe.webhook_tolerance' => 300,
        ]);
    }

    public function test_free_plan_is_read_only_for_admin_content_mutations(): void
    {
        Http::fake([
            'project.supabase.co/rest/v1/profiles*' => Http::response([[
                'id' => self::USER_ID,
                'plan' => 'free',
                'subscription_status' => 'active',
                'requested_plan' => null,
            ]]),
        ]);

        $this->withSession(['supabase_user_id' => self::USER_ID])
            ->postJson('/api/admin/subjects', [
                'name' => 'Materia bloqueada',
                'is_active' => true,
            ])
            ->assertForbidden()
            ->assertJsonPath('code', 'PLAN_READ_ONLY');
    }

    public function test_new_supabase_secret_key_is_not_sent_as_a_bearer_jwt(): void
    {
        config(['services.supabase.service_role_key' => 'sb_secret_backend_key']);
        Http::fake([
            'project.supabase.co/rest/v1/profiles*' => Http::response([[
                'id' => self::USER_ID,
                'plan' => 'free',
                'subscription_status' => 'active',
                'requested_plan' => null,
            ]]),
        ]);

        $this->withSession(['supabase_user_id' => self::USER_ID])
            ->getJson('/api/admin/session')
            ->assertOk()
            ->assertJsonPath('billing.plan', 'free');

        Http::assertSent(fn (ClientRequest $request) => str_contains($request->url(), '/rest/v1/profiles')
            && $request->hasHeader('apikey', 'sb_secret_backend_key')
            && ! $request->hasHeader('Authorization'));
    }

    public function test_missing_profile_column_points_to_the_required_migration(): void
    {
        Http::fake([
            'project.supabase.co/rest/v1/profiles*' => Http::response([
                'code' => '42703',
                'message' => 'column profiles.requested_plan does not exist',
            ], 400),
        ]);

        $this->withSession(['supabase_user_id' => self::USER_ID])
            ->getJson('/api/admin/session')
            ->assertServiceUnavailable()
            ->assertJsonPath(
                'message',
                'Falta una columna requerida en public.profiles. Ejecuta php artisan migrate para aplicar la migración de facturación.',
            );
    }

    public function test_checkout_error_names_missing_stripe_environment_variables(): void
    {
        config([
            'services.stripe.secret_key' => null,
            'services.stripe.yearly_price_id' => null,
            'services.stripe.webhook_secret' => null,
            'services.stripe.frontend_url' => null,
        ]);
        Http::fake();

        $this->withSession(['supabase_user_id' => self::USER_ID])
            ->postJson('/api/admin/billing/checkout', ['plan' => 'yearly'])
            ->assertServiceUnavailable()
            ->assertJsonPath(
                'message',
                'Configura STRIPE_SECRET_KEY y STRIPE_YEARLY_PRICE_ID y STRIPE_WEBHOOK_SECRET y APP_FRONTEND_URL en buhoback/.env y reinicia Laravel para habilitar Stripe Checkout.',
            );

        Http::assertNothingSent();
    }

    public function test_active_paid_plan_can_mutate_admin_content(): void
    {
        Http::fake([
            'project.supabase.co/rest/v1/profiles*' => Http::response([[
                'id' => self::USER_ID,
                'plan' => 'monthly',
                'subscription_status' => 'active',
                'requested_plan' => null,
            ]]),
        ]);

        $this->withSession(['supabase_user_id' => self::USER_ID])
            ->postJson('/api/admin/subjects', [
                'name' => 'Materia con plan de pago',
                'is_active' => true,
                'sort_order' => 0,
            ])
            ->assertCreated()
            ->assertJsonPath('name', 'Materia con plan de pago');
    }

    public function test_free_plan_can_open_stripe_checkout_to_upgrade(): void
    {
        Http::fake(function (ClientRequest $request) {
            if ($request->url() === 'https://api.stripe.com/v1/checkout/sessions') {
                return Http::response([
                    'url' => 'https://checkout.stripe.com/c/pay/cs_test_example',
                ]);
            }

            if (str_contains($request->url(), '/rest/v1/profiles')) {
                if ($request->method() === 'GET') {
                    return Http::response([[
                        'id' => self::USER_ID,
                        'plan' => 'free',
                        'subscription_status' => 'active',
                        'stripe_customer_id' => null,
                        'requested_plan' => null,
                    ]]);
                }

                return Http::response([], 204);
            }

            return Http::response(['message' => 'Unexpected request'], 500);
        });

        $this->withSession(['supabase_user_id' => self::USER_ID])
            ->postJson('/api/admin/billing/checkout', ['plan' => 'yearly'])
            ->assertOk()
            ->assertJsonPath('url', 'https://checkout.stripe.com/c/pay/cs_test_example');

        $stripeRequest = Http::recorded()
            ->map(fn (array $recorded) => $recorded[0])
            ->first(fn (ClientRequest $request) => $request->url() === 'https://api.stripe.com/v1/checkout/sessions');

        $this->assertNotNull($stripeRequest);
        parse_str($stripeRequest->body(), $checkoutFields);
        $this->assertSame('price_yearly_test', $checkoutFields['line_items'][0]['price']);
        $this->assertSame(self::USER_ID, $checkoutFields['metadata']['supabase_user_id']);
    }

    public function test_active_subscription_cannot_be_duplicated_through_checkout(): void
    {
        Http::fake([
            'project.supabase.co/rest/v1/profiles*' => Http::response([[
                'id' => self::USER_ID,
                'plan' => 'monthly',
                'subscription_status' => 'active',
                'stripe_subscription_id' => 'sub_example',
            ]]),
        ]);

        $this->withSession(['supabase_user_id' => self::USER_ID])
            ->postJson('/api/admin/billing/checkout', ['plan' => 'yearly'])
            ->assertStatus(409)
            ->assertJsonPath('code', 'SUBSCRIPTION_ALREADY_ACTIVE');

        Http::assertNotSent(fn (ClientRequest $request) => $request->url() === 'https://api.stripe.com/v1/checkout/sessions');
    }

    public function test_active_customer_can_open_the_stripe_billing_portal(): void
    {
        Http::fake(function (ClientRequest $request) {
            if ($request->url() === 'https://api.stripe.com/v1/billing_portal/sessions') {
                return Http::response([
                    'url' => 'https://billing.stripe.com/p/session/example',
                ]);
            }

            if (str_contains($request->url(), '/rest/v1/profiles')) {
                return Http::response([[
                    'id' => self::USER_ID,
                    'stripe_customer_id' => 'cus_example',
                ]]);
            }

            return Http::response(['message' => 'Unexpected request'], 500);
        });

        $this->withSession(['supabase_user_id' => self::USER_ID])
            ->postJson('/api/admin/billing/portal')
            ->assertOk()
            ->assertJsonPath('url', 'https://billing.stripe.com/p/session/example');

        Http::assertSent(fn (ClientRequest $request) => $request->url() === 'https://api.stripe.com/v1/billing_portal/sessions'
            && $request['customer'] === 'cus_example'
            && $request['return_url'] === 'http://localhost:5173/admin');
    }

    public function test_stripe_webhook_rejects_an_invalid_signature(): void
    {
        Http::fake();

        $this->postJson('/stripe/webhook', ['type' => 'customer.subscription.updated'], [
            'Stripe-Signature' => 't='.now()->timestamp.',v1=invalid',
        ])->assertBadRequest();

        Http::assertNothingSent();
    }

    public function test_signed_subscription_webhook_updates_billing_profile_with_service_role(): void
    {
        $profileUpdates = [];
        $event = [
            'id' => 'evt_subscription_updated',
            'type' => 'customer.subscription.updated',
            'data' => [
                'object' => [
                    'id' => 'sub_example',
                    'customer' => 'cus_example',
                    'status' => 'active',
                    'current_period_end' => now()->addMonth()->timestamp,
                    'trial_end' => null,
                    'metadata' => [
                        'supabase_user_id' => self::USER_ID,
                        'plan' => 'monthly',
                    ],
                ],
            ],
        ];
        $payload = json_encode($event, JSON_THROW_ON_ERROR);
        $timestamp = (string) now()->timestamp;
        $signature = hash_hmac('sha256', $timestamp.'.'.$payload, 'whsec_test_example');

        Http::fake(function (ClientRequest $request) use (&$profileUpdates) {
            if (str_contains($request->url(), '/rest/v1/profiles')) {
                if ($request->method() === 'GET') {
                    return Http::response([[
                        'id' => self::USER_ID,
                        'plan' => 'free',
                        'subscription_status' => 'incomplete',
                        'requested_plan' => 'monthly',
                    ]]);
                }

                $profileUpdates = $request->data();

                return Http::response([], 204);
            }

            return Http::response(['message' => 'Unexpected request'], 500);
        });

        $this->call('POST', '/stripe/webhook', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_STRIPE_SIGNATURE' => "t={$timestamp},v1={$signature}",
        ], $payload)
            ->assertOk()
            ->assertJsonPath('received', true);

        $this->assertSame('monthly', $profileUpdates['plan']);
        $this->assertSame('active', $profileUpdates['subscription_status']);
        $this->assertSame('cus_example', $profileUpdates['stripe_customer_id']);
        $this->assertSame('sub_example', $profileUpdates['stripe_subscription_id']);
        $this->assertNull($profileUpdates['requested_plan']);
        Http::assertSent(fn (ClientRequest $request) => $request->url() === 'https://project.supabase.co/rest/v1/profiles?id=eq.'.self::USER_ID
            && $request->hasHeader('Authorization', 'Bearer test-service-role-key'));
    }
}
