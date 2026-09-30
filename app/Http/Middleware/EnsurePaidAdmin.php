<?php

namespace App\Http\Middleware;

use App\Services\SupabaseProfileService;
use Closure;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Request;
use RuntimeException;
use Symfony\Component\HttpFoundation\Response;

class EnsurePaidAdmin
{
    public function __construct(private readonly SupabaseProfileService $profiles) {}

    public function handle(Request $request, Closure $next): Response
    {
        $userId = $request->session()->get('supabase_user_id');
        if (! is_string($userId) || $userId === '') {
            return response()->json(['message' => 'Inicia sesión para continuar.'], 401);
        }

        try {
            $profile = $this->profiles->find($userId);
        } catch (ConnectionException) {
            return response()->json(['message' => 'No fue posible consultar el plan en Supabase.'], 503);
        } catch (RuntimeException $exception) {
            return response()->json(['message' => $exception->getMessage()], 503);
        }

        $planIsActive = in_array($profile['plan'] ?? 'free', ['monthly', 'yearly'], true)
            && in_array($profile['subscription_status'] ?? '', ['active', 'trialing'], true);

        if (! $planIsActive) {
            return response()->json([
                'message' => 'El plan gratuito es de solo lectura. Actualiza a un plan mensual o anual para editar el contenido.',
                'code' => 'PLAN_READ_ONLY',
            ], 403);
        }

        return $next($request);
    }
}
