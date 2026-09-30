<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\SupabaseProfileService;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;
use RuntimeException;
use Symfony\Component\HttpFoundation\Response;

class StripeBillingController extends Controller
{
    public function __construct(private readonly SupabaseProfileService $profiles) {}

    public function checkout(Request $request): Response
    {
        $validated = $request->validate([
            'plan' => ['required', Rule::in(['monthly', 'yearly'])],
        ]);
        $plan = $validated['plan'];
        $priceId = config("services.stripe.{$plan}_price_id");
        $secretKey = config('services.stripe.secret_key');
        $webhookSecret = config('services.stripe.webhook_secret');
        $frontendUrl = config('services.stripe.frontend_url');

        $requiredSettings = [
            'STRIPE_SECRET_KEY' => $secretKey,
            $plan === 'monthly' ? 'STRIPE_MONTHLY_PRICE_ID' : 'STRIPE_YEARLY_PRICE_ID' => $priceId,
            'STRIPE_WEBHOOK_SECRET' => $webhookSecret,
            'APP_FRONTEND_URL' => $frontendUrl,
        ];
        $missing = array_keys(array_filter(
            $requiredSettings,
            fn ($value) => ! is_string($value) || $value === '',
        ));

        if ($missing !== []) {
            return response()->json([
                'message' => 'Configura '.implode(' y ', $missing).' en buhoback/.env y reinicia Laravel para habilitar Stripe Checkout.',
            ], 503);
        }

        $userId = $request->session()->get('supabase_user_id');
        try {
            $profile = $this->profiles->find($userId);
            if (
                is_string($profile['stripe_subscription_id'] ?? null)
                && in_array($profile['subscription_status'] ?? '', [
                    'active', 'trialing', 'past_due', 'incomplete', 'unpaid', 'paused',
                ], true)
            ) {
                return response()->json([
                    'message' => 'Administra tu suscripción actual desde el portal de facturación.',
                    'code' => 'SUBSCRIPTION_ALREADY_ACTIVE',
                ], 409);
            }
            $this->profiles->update($userId, ['requested_plan' => $plan]);
        } catch (ConnectionException) {
            return response()->json(['message' => 'No fue posible conectar con Supabase para preparar el plan.'], 503);
        } catch (RuntimeException $exception) {
            return response()->json(['message' => $exception->getMessage()], 503);
        }

        $frontendUrl = rtrim($frontendUrl, '/');
        $checkoutData = [
            'mode' => 'subscription',
            'line_items[0][price]' => $priceId,
            'line_items[0][quantity]' => 1,
            'client_reference_id' => $userId,
            'success_url' => $frontendUrl.'/admin?billing=success',
            'cancel_url' => $frontendUrl.'/admin?billing=cancelled',
            'metadata[supabase_user_id]' => $userId,
            'metadata[plan]' => $plan,
            'subscription_data[metadata][supabase_user_id]' => $userId,
            'subscription_data[metadata][plan]' => $plan,
        ];
        if (is_string($profile['stripe_customer_id'] ?? null) && $profile['stripe_customer_id'] !== '') {
            $checkoutData['customer'] = $profile['stripe_customer_id'];
        }

        try {
            $response = Http::asForm()
                ->withToken($secretKey)
                ->acceptJson()
                ->timeout(12)
                ->post('https://api.stripe.com/v1/checkout/sessions', $checkoutData);
        } catch (ConnectionException) {
            return response()->json(['message' => 'No fue posible conectar con Stripe. Inténtalo de nuevo.'], 503);
        }

        if (! $response->successful()) {
            Log::warning('Stripe Checkout session creation failed.', [
                'status' => $response->status(),
                'code' => $response->json('error.code'),
                'type' => $response->json('error.type'),
            ]);

            return response()->json([
                'message' => 'Stripe no pudo iniciar el pago. Revisa la clave secreta y el Price ID en el Dashboard de Stripe; el código HTTP quedó registrado en Laravel.',
            ], 502);
        }

        $checkoutUrl = $response->json('url');
        if (! is_string($checkoutUrl) || ! str_starts_with($checkoutUrl, 'https://checkout.stripe.com/')) {
            return response()->json(['message' => 'Stripe devolvió una sesión de pago inválida.'], 502);
        }

        return response()->json(['url' => $checkoutUrl]);
    }

    public function portal(Request $request): Response
    {
        $userId = $request->session()->get('supabase_user_id');
        $secretKey = config('services.stripe.secret_key');
        $frontendUrl = rtrim((string) config('services.stripe.frontend_url'), '/');
        if (! is_string($secretKey) || $secretKey === '' || $frontendUrl === '') {
            return response()->json([
                'message' => 'La administración de pagos aún no está configurada.',
            ], 503);
        }

        try {
            $profile = $this->profiles->find($userId);
        } catch (ConnectionException) {
            return response()->json(['message' => 'No fue posible consultar la cuenta en Supabase.'], 503);
        } catch (RuntimeException $exception) {
            return response()->json(['message' => $exception->getMessage()], 503);
        }

        $customerId = $profile['stripe_customer_id'] ?? null;
        if (! is_string($customerId) || $customerId === '') {
            return response()->json([
                'message' => 'No hay una cuenta de facturación de Stripe asociada.',
            ], 409);
        }

        try {
            $response = Http::asForm()
                ->withToken($secretKey)
                ->acceptJson()
                ->timeout(12)
                ->post('https://api.stripe.com/v1/billing_portal/sessions', [
                    'customer' => $customerId,
                    'return_url' => $frontendUrl.'/admin',
                ]);
        } catch (ConnectionException) {
            return response()->json(['message' => 'No fue posible conectar con Stripe. Inténtalo de nuevo.'], 503);
        }

        if (! $response->successful()) {
            return response()->json([
                'message' => 'Stripe no pudo abrir el portal. Verifica la configuración del portal de clientes.',
            ], 502);
        }

        $portalUrl = $response->json('url');
        if (! is_string($portalUrl) || ! str_starts_with($portalUrl, 'https://billing.stripe.com/')) {
            return response()->json(['message' => 'Stripe devolvió una URL de portal inválida.'], 502);
        }

        return response()->json(['url' => $portalUrl]);
    }

    public function webhook(Request $request): JsonResponse
    {
        $webhookSecret = config('services.stripe.webhook_secret');
        if (! is_string($webhookSecret) || $webhookSecret === '') {
            return response()->json(['message' => 'El webhook de Stripe no está configurado.'], 503);
        }

        if (! $this->validSignature(
            $request->getContent(),
            (string) $request->header('Stripe-Signature'),
            $webhookSecret,
        )) {
            return response()->json(['message' => 'La firma del webhook no es válida.'], 400);
        }

        $event = $request->json()->all();
        $eventType = $event['type'] ?? null;
        $object = $event['data']['object'] ?? null;
        if (! is_string($eventType) || ! is_array($object)) {
            return response()->json(['message' => 'El evento de Stripe está incompleto.'], 400);
        }

        try {
            if ($eventType === 'checkout.session.completed') {
                $this->handleCheckoutCompleted($object);
            } elseif (in_array($eventType, [
                'customer.subscription.created',
                'customer.subscription.updated',
                'customer.subscription.deleted',
            ], true)) {
                $this->handleSubscriptionChanged($object);
            }
        } catch (ConnectionException) {
            return response()->json(['message' => 'No fue posible conectar con Supabase para guardar el pago.'], 503);
        } catch (RuntimeException $exception) {
            return response()->json(['message' => $exception->getMessage()], 503);
        }

        return response()->json(['received' => true]);
    }

    private function handleCheckoutCompleted(array $checkout): void
    {
        $userId = $checkout['metadata']['supabase_user_id'] ?? $checkout['client_reference_id'] ?? null;
        $plan = $checkout['metadata']['plan'] ?? null;
        if (! is_string($userId) || ! in_array($plan, ['monthly', 'yearly'], true)) {
            return;
        }

        $isPaid = in_array($checkout['payment_status'] ?? '', ['paid', 'no_payment_required'], true);
        $customerId = $checkout['customer'] ?? null;
        $subscriptionId = $checkout['subscription'] ?? null;
        $values = [
            'stripe_customer_id' => is_string($customerId) ? $customerId : null,
            'stripe_subscription_id' => is_string($subscriptionId) ? $subscriptionId : null,
            'subscription_status' => $isPaid ? 'active' : 'incomplete',
            'plan' => $isPaid ? $plan : 'free',
            'requested_plan' => $isPaid ? null : $plan,
        ];

        $this->profiles->update($userId, $values);
    }

    private function handleSubscriptionChanged(array $subscription): void
    {
        $userId = $subscription['metadata']['supabase_user_id'] ?? null;
        if (! is_string($userId)) {
            return;
        }

        $status = $subscription['status'] ?? null;
        if (! is_string($status)) {
            return;
        }

        $profile = $this->profiles->find($userId);
        $metadataPlan = $subscription['metadata']['plan'] ?? null;
        $plan = in_array($metadataPlan, ['monthly', 'yearly'], true)
            ? $metadataPlan
            : ($profile['requested_plan'] ?? $profile['plan'] ?? 'free');
        $periodEnd = $subscription['current_period_end']
            ?? $subscription['items']['data'][0]['current_period_end']
            ?? null;
        $trialEnd = $subscription['trial_end'] ?? null;
        $customerId = $subscription['customer'] ?? null;
        $isEntitled = in_array($status, ['active', 'trialing'], true);
        $periodEndDate = is_numeric($periodEnd) ? gmdate(DATE_ATOM, (int) $periodEnd) : null;
        $trialEndDate = is_numeric($trialEnd) ? gmdate(DATE_ATOM, (int) $trialEnd) : null;
        $this->profiles->update($userId, [
            'plan' => $isEntitled ? $plan : 'free',
            'subscription_status' => $status,
            'stripe_customer_id' => is_string($customerId) ? $customerId : null,
            'stripe_subscription_id' => $subscription['id'] ?? null,
            'current_period_end' => $periodEndDate,
            'trial_ends_at' => $trialEndDate,
            'requested_plan' => $isEntitled ? null : ($profile['requested_plan'] ?? null),
        ]);
    }

    private function validSignature(string $payload, string $signatureHeader, string $secret): bool
    {
        $timestamp = null;
        $signatures = [];
        foreach (explode(',', $signatureHeader) as $part) {
            [$key, $value] = array_pad(explode('=', trim($part), 2), 2, null);
            if ($key === 't' && is_string($value)) {
                $timestamp = $value;
            } elseif ($key === 'v1' && is_string($value)) {
                $signatures[] = $value;
            }
        }

        if (! is_string($timestamp) || ! ctype_digit($timestamp) || $signatures === []) {
            return false;
        }

        $tolerance = max(0, (int) config('services.stripe.webhook_tolerance', 300));
        if (abs(now()->timestamp - (int) $timestamp) > $tolerance) {
            return false;
        }

        $expected = hash_hmac('sha256', $timestamp.'.'.$payload, $secret);
        foreach ($signatures as $signature) {
            if (hash_equals($expected, $signature)) {
                return true;
            }
        }

        return false;
    }
}
