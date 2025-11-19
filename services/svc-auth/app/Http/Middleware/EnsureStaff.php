<?php

namespace App\Http\Middleware;

use App\Enums\UserRole;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureStaff
{
    /**
     * Handle an incoming request.
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = auth('api')->user();
        if (!$user || ($user->role !== UserRole::STAFF && $user->role !== UserRole::ADMIN)) {
            return response()->json(['message' => '403 Forbidden - Staff access required'], 403);
        }
        return $next($request);
    }
}