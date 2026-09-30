<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\SupabaseProfileService;
use Illuminate\Database\QueryException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Symfony\Component\HttpFoundation\Response;

class AdminAuthController extends Controller
{
    public function csrfToken(Request $request): Response
    {
        return response()
            ->json(['csrf_token' => $request->session()->token()])
            ->header('Cache-Control', 'no-store, private');
    }

    public function session(Request $request, SupabaseProfileService $profiles): Response
    {
        try {
            $profile = $profiles->find($request->session()->get('supabase_user_id'));
        } catch (ConnectionException) {
            return response()->json(['message' => 'No fue posible consultar el plan en Supabase.'], 503);
        } catch (RuntimeException $exception) {
            return response()->json(['message' => $exception->getMessage()], 503);
        }

        return response()->json(['authenticated' => true, 'billing' => $this->billingSummary($profile)]);
    }

    public function login(Request $request, SupabaseProfileService $profiles): Response
    {
        $credentials = $request->validate([
            'email' => ['required', 'email', 'max:255'],
            'password' => ['required', 'string', 'max:1024'],
        ]);
        $supabaseUrl = rtrim((string) config('services.supabase.url'), '/');
        $anonKey = config('services.supabase.anon_key');

        if (! $supabaseUrl || ! $anonKey) {
            return response()->json(['message' => 'La autenticación de Supabase no está configurada.'], 503);
        }

        try {
            $response = Http::acceptJson()
                ->withHeaders(['apikey' => $anonKey])
                ->timeout(8)
                ->post($supabaseUrl.'/auth/v1/token?grant_type=password', $credentials);
        } catch (ConnectionException) {
            return response()->json(['message' => 'No fue posible conectar con el servicio de autenticación.'], 503);
        }

        if (! $response->successful()) {
            throw ValidationException::withMessages([
                'email' => ['El correo o la contraseña no son correctos.'],
            ]);
        }

        $userId = $response->json('user.id');
        $accessToken = $response->json('access_token');

        if (! is_string($userId) || ! is_string($accessToken)) {
            return response()->json(['message' => 'El servicio de autenticación devolvió una respuesta inválida.'], 502);
        }

        try {
            $isAdmin = DB::table('user_roles')
                ->where('user_id', $userId)
                ->where('role', 'admin')
                ->exists();
        } catch (QueryException) {
            return response()->json([
                'message' => 'No se pudo consultar el rol en PostgreSQL. Verifica la contraseña de la base de datos, el usuario del Session Pooler y que exista public.user_roles.',
            ], 503);
        }

        if (! $isAdmin) {
            return response()->json(['message' => 'Esta cuenta no tiene acceso a la zona de administración.'], 403);
        }

        try {
            $profile = $profiles->find($userId);
        } catch (ConnectionException) {
            return response()->json(['message' => 'No fue posible consultar el plan en Supabase.'], 503);
        } catch (RuntimeException $exception) {
            return response()->json(['message' => $exception->getMessage()], 503);
        }

        $request->session()->regenerate();
        $request->session()->put([
            'supabase_access_token' => $accessToken,
            'supabase_user_id' => $userId,
        ]);

        return response()->json([
            'user' => ['id' => $userId],
            'billing' => $this->billingSummary($profile),
            'csrf_token' => $request->session()->token(),
        ]);
    }

    public function register(Request $request, SupabaseProfileService $profiles): Response
    {
        $credentials = $request->validate([
            'email' => ['required', 'email', 'max:255'],
            'password' => ['required', 'string', 'min:8', 'max:1024', 'confirmed'],
            'plan' => ['sometimes', 'in:free,monthly,yearly'],
        ]);
        $plan = $credentials['plan'] ?? 'free';
        $supabaseUrl = rtrim((string) config('services.supabase.url'), '/');
        $anonKey = config('services.supabase.anon_key');

        if (! $supabaseUrl || ! $anonKey) {
            return response()->json(['message' => 'La autenticación de Supabase no está configurada.'], 503);
        }

        try {
            $response = Http::acceptJson()
                ->withHeaders(['apikey' => $anonKey])
                ->timeout(8)
                ->post($supabaseUrl.'/auth/v1/signup', [
                    'email' => $credentials['email'],
                    'password' => $credentials['password'],
                ]);
        } catch (ConnectionException) {
            return response()->json(['message' => 'No fue posible conectar con el servicio de autenticación.'], 503);
        }

        if (! $response->successful()) {
            if ($response->status() === 401) {
                return response()->json([
                    'message' => 'Supabase rechazó la clave de API (HTTP 401). Verifica que SUPABASE_ANON_KEY sea la clave anon o publishable del mismo proyecto que SUPABASE_URL; no uses service_role. Reinicia Laravel después de cambiar .env.',
                ], 503);
            }

            if ($response->status() === 429) {
                return response()->json([
                    'message' => 'Supabase limitó temporalmente los registros. Espera un momento e inténtalo de nuevo.',
                ], 429);
            }

            $providerMessage = $response->json('msg')
                ?? $response->json('message')
                ?? $response->json('error_description');

            return response()->json([
                'message' => is_string($providerMessage)
                    ? 'Supabase rechazó el registro: '.mb_substr(strip_tags($providerMessage), 0, 240)
                    : 'Supabase rechazó el registro (HTTP '.$response->status().'). Revisa la configuración de Auth y los datos enviados.',
            ], in_array($response->status(), [400, 422], true) ? 422 : 502);
        }

        $user = $response->json('user');
        $userId = is_array($user) ? ($user['id'] ?? null) : null;
        $identities = is_array($user) ? ($user['identities'] ?? null) : null;
        $accessToken = $response->json('access_token');

        if (
            ! is_string($userId)
            || ! is_array($identities)
            || $identities === []
        ) {
            return response()->json([
                'message' => 'No se pudo crear la cuenta. Revisa los datos y, si ya tienes una cuenta, inicia sesión.',
            ], 422);
        }

        try {
            DB::table('user_roles')->insertOrIgnore([
                'user_id' => $userId,
                'role' => 'admin',
            ]);
        } catch (QueryException) {
            return response()->json([
                'message' => 'La cuenta se creó, pero no se pudo asignar el rol de administrador. Verifica la conexión a PostgreSQL y public.user_roles antes de intentarlo de nuevo.',
            ], 503);
        }

        if ($plan !== 'free') {
            try {
                $profiles->update($userId, ['requested_plan' => $plan]);
            } catch (ConnectionException) {
                return response()->json(['message' => 'La cuenta se creó, pero no se pudo guardar el plan solicitado en Supabase.'], 503);
            } catch (RuntimeException $exception) {
                return response()->json(['message' => 'La cuenta se creó, pero '.$exception->getMessage()], 503);
            }
        }

        if (is_string($accessToken) && $accessToken !== '') {
            $request->session()->regenerate();
            $request->session()->put([
                'supabase_access_token' => $accessToken,
                'supabase_user_id' => $userId,
            ]);

            return response()->json([
                'authenticated' => true,
                'user' => ['id' => $userId],
                'checkout_plan' => $plan === 'free' ? null : $plan,
                'csrf_token' => $request->session()->token(),
            ], 201);
        }

        return response()->json([
            'authenticated' => false,
            'message' => 'La cuenta se creó y ya tiene acceso de administrador. Confirma tu correo desde el mensaje de Supabase y después inicia sesión.',
            'checkout_plan' => $plan === 'free' ? null : $plan,
            'csrf_token' => $request->session()->token(),
        ], 201);
    }

    public function logout(Request $request): Response
    {
        $request->session()->forget([
            'supabase_access_token',
            'supabase_user_id',
        ]);
        $request->session()->regenerateToken();

        return response()->json([
            'message' => 'Sesión cerrada.',
            'csrf_token' => $request->session()->token(),
        ]);
    }

    private function billingSummary(array $profile): array
    {
        $plan = $profile['plan'] ?? 'free';
        $status = $profile['subscription_status'] ?? 'active';

        return [
            'plan' => $plan,
            'subscription_status' => $status,
            'current_period_end' => $profile['current_period_end'] ?? null,
            'trial_ends_at' => $profile['trial_ends_at'] ?? null,
            'requested_plan' => $profile['requested_plan'] ?? null,
            'can_manage_subscription' => is_string($profile['stripe_customer_id'] ?? null)
                && is_string($profile['stripe_subscription_id'] ?? null)
                && in_array($status, ['active', 'trialing', 'past_due', 'incomplete', 'unpaid', 'paused'], true),
            'read_only' => ! in_array($plan, ['monthly', 'yearly'], true)
                || ! in_array($status, ['active', 'trialing'], true),
        ];
    }
}
