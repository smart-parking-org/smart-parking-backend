<?php

namespace App\Http\Middleware;

use Closure;
use PHPOpenSourceSaver\JWTAuth\Facades\JWTAuth;

class EnsureAccessToken
{
    public function handle($request, Closure $next)
    {
        try {
            $payload = JWTAuth::parseToken()->getPayload();

            $typ = $payload->get('typ', 'access');
            if ($typ !== 'access') {
                return response()->json([
                    'message' => 'Unauthenticated',
                ], 401);
            }
        } catch (\Throwable $e) {
            return response()->json([
                'message' => 'Unauthenticated',
            ], 401);
        }

        return $next($request);
    }
}
