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
                    'error' => 'invalid_token_type',
                    'message' => 'This endpoint requires an access token.',
                ], 401);
            }
        } catch (\Throwable $e) {
            return response()->json([
                'error' => 'unauthenticated',
                'message' => 'Authentication token is missing or invalid.',
            ], 401);
        }

        return $next($request);
    }
}
