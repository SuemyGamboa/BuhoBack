<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Database\QueryException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Symfony\Component\HttpFoundation\Response;

class EnsureSupabaseAdmin
{
    public function handle(Request $request, Closure $next): Response
    {
        $accessToken = $request->session()->get('supabase_access_token');
        $sessionUserId = $request->session()->get('supabase_user_id');
        $supabaseUrl = rtrim((string) config('services.supabase.url'), '/');
        $anonKey = config('services.supabase.anon_key');

        if (! $accessToken || ! $sessionUserId) {
            return response()->json(['message' => 'Inicia sesión para continuar.'], 401);
        }

        if (! $supabaseUrl || ! $anonKey) {
            return response()->json(['message' => 'La autenticación de Supabase no está configurada.'], 503);
        }

        try {
            $response = Http::acceptJson()
                ->withHeaders([
                    'apikey' => $anonKey,
                    'Authorization' => 'Bearer '.$accessToken,
                ])
                ->timeout(8)
                ->get($supabaseUrl.'/auth/v1/user');
        } catch (ConnectionException) {
            return response()->json(['message' => 'No fue posible validar la sesión con Supabase.'], 503);
        }

        if (! $response->successful() || $response->json('id') !== $sessionUserId) {
            $request->session()->forget(['supabase_access_token', 'supabase_user_id']);

            return response()->json(['message' => 'Tu sesión expiró. Inicia sesión nuevamente.'], 401);
        }

        try {
            $isAdmin = DB::table('user_roles')
                ->where('user_id', $sessionUserId)
                ->where('role', 'admin')
                ->exists();
        } catch (QueryException) {
            return response()->json([
                'message' => 'No se pudo consultar el rol en PostgreSQL. Verifica la conexión del Session Pooler y que exista public.user_roles.',
            ], 503);
        }

        if (! $isAdmin) {
            $request->session()->forget(['supabase_access_token', 'supabase_user_id']);

            return response()->json(['message' => 'No tienes permiso para administrar el contenido.'], 403);
        }

        return $next($request);
    }
}
