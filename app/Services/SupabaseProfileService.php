<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;

class SupabaseProfileService
{
    public function find(string $userId): array
    {
        $response = $this->request()
            ->get($this->baseUrl().'/rest/v1/profiles', [
                'id' => 'eq.'.$userId,
                'select' => 'id,plan,subscription_status,stripe_customer_id,stripe_subscription_id,current_period_end,trial_ends_at,requested_plan',
                'limit' => 1,
            ]);

        if (! $response->successful()) {
            $code = $response->json('code');
            Log::warning('Supabase profile lookup failed.', [
                'status' => $response->status(),
                'code' => $code,
            ]);
            throw new RuntimeException($this->profileErrorMessage($response->status(), $code));
        }

        $profile = $response->json('0');
        if (! is_array($profile)) {
            throw new RuntimeException('No se encontró el perfil de la cuenta en Supabase.');
        }

        return $profile;
    }

    public function update(string $userId, array $values): void
    {
        $response = $this->request()
            ->patch(
                $this->baseUrl().'/rest/v1/profiles?id=eq.'.rawurlencode($userId),
                $values,
            );

        if (! $response->successful()) {
            $code = $response->json('code');
            Log::warning('Supabase profile update failed.', [
                'status' => $response->status(),
                'code' => $code,
            ]);
            throw new RuntimeException($this->profileErrorMessage($response->status(), $code));
        }
    }

    private function request()
    {
        $serviceRoleKey = config('services.supabase.service_role_key');
        if (! is_string($serviceRoleKey) || $serviceRoleKey === '') {
            throw new RuntimeException('Falta configurar SUPABASE_SERVICE_ROLE_KEY en Laravel.');
        }

        $headers = [
            'apikey' => $serviceRoleKey,
            'Prefer' => 'return=minimal',
        ];
        if (! str_starts_with($serviceRoleKey, 'sb_secret_')) {
            $headers['Authorization'] = 'Bearer '.$serviceRoleKey;
        }

        return Http::acceptJson()
            ->asJson()
            ->withHeaders($headers)
            ->timeout(10);
    }

    private function baseUrl(): string
    {
        $url = rtrim((string) config('services.supabase.url'), '/');
        if ($url === '') {
            throw new RuntimeException('Falta configurar SUPABASE_URL en Laravel.');
        }

        return $url;
    }

    private function profileErrorMessage(int $status, mixed $code): string
    {
        if ($code === '42703') {
            return 'Falta una columna requerida en public.profiles. Ejecuta php artisan migrate para aplicar la migración de facturación.';
        }

        $providerCode = is_string($code) ? ', código '.$code : '';

        return "Supabase rechazó la operación de perfil (HTTP {$status}{$providerCode}). Revisa SUPABASE_SERVICE_ROLE_KEY y las columnas de public.profiles.";
    }
}
