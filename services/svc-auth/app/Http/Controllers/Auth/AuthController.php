<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\LoginRequest;
use App\Http\Requests\Auth\RegisterRequest;
use App\Models\User;
use Illuminate\Support\Str;
use PHPOpenSourceSaver\JWTAuth\Facades\JWTAuth;


class AuthController extends Controller
{
    public function register(RegisterRequest $request)
    {
        $user = User::create([
            'name' => $request->input('name'),
            'email' => $request->input('email'),
            'phone' => $request->input('phone'),
            'password' => $request->input('password'),
            'apartment_code' => $request->input('apartment_code'),
            'cccd_hash' => $request->input('cccd') ? sha1($request->input('cccd')) : null,
            'cccd_masked' => $request->input('cccd') ? substr($request->input('cccd'), 0, 3) . '******' . substr($request->input('cccd'), -3) : null,
        ]);
        $user->refresh();

        return response()->json([
            'message' => 'Registered successfully',
            'user' => $user,
        ], 201);
    }

    public function login(LoginRequest $request)
    {
        $credentials = $request->only('email', 'password');
        try {
            $user = User::where('email', $credentials['email'])->firstOrFail();
        } catch (\Throwable $th) {
            return response()->json(['message' => 'Invalid credentials'], 401);
        }

        // Check trạng thái active
        if (!$user->is_active) {
            return response()->json(['message' => 'Your account has been deactivated'], 403);
        }

        // Check đã được admin duyệt chưa
        if (!$user->is_approved) {
            return response()->json(['message' => 'Your account is pending approval'], 403);
        }

        if (!$token = auth('api')->claims(['typ' => 'access'])->attempt($credentials)) {
            return response()->json(['message' => 'Invalid credentials'], 401);
        }

        $user = auth('api')->user();
        $refreshToken = $this->createRefreshToken($user);

        return $this->respondWithToken($token, $refreshToken);
    }

    public function me()
    {
        $user = auth('api')->user();
        return response()->json([
            'message' => 'Get me successfully',
            'user' => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'phone' => $user->phone,
                'apartment_code' => $user->apartment_code,
                'role' => $user->role,
            ]
        ]);
    }

    public function logout()
    {
        auth('api')->logout();
        return response()->json(['message' => 'Logged out']);
    }

    public function refreshToken()
    {
        $raw = request()->input('refresh_token');
        if (!$raw) {
            return response()->json(['message' => 'Refresh token is required'], 400);
        }

        try {
            $payload = JWTAuth::getJWTProvider()->decode($raw);
        } catch (\Throwable $th) {
            return response()->json(['message' => 'Invalid refresh token'], 401);
        }

        $typ = $payload['typ'] ?? 'refresh';
        if ($typ !== 'refresh') {
            return response()->json(['message' => 'Invalid token type'], 401);
        }

        if (!isset($payload['exp']) || $payload['exp'] <= time()) {
            return response()->json(['message' => 'Refresh token expired'], 401);
        }

        $userId = $payload['sub'] ?? null;
        $user = $userId ? User::find($userId) : null;
        if (!$user) {
            return response()->json(['message' => 'Invalid refresh token'], 401);
        }

        $newAccess = auth('api')->claims(['typ' => 'access'])->login($user);
        $newRefresh = $this->createRefreshToken($user);

        return $this->respondWithToken($newAccess, $newRefresh);
    }

    protected function respondWithToken($token, $refreshToken)
    {
        $user = auth('api')->user();
        $ttlMinutes = auth('api')->factory()->getTTL();

        return response()->json([
            'access_token' => $token,
            'refresh_token' => $refreshToken,
            'token_type' => 'Bearer',
            'expires_in' => $ttlMinutes * 60,
            'expires_at' => now()->addMinutes($ttlMinutes)->toIso8601String(),
            'user' => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'phone' => $user->phone,
                'role' => $user->role,
            ],
        ]);
    }

    protected function createRefreshToken($user)
    {
        $now = time();
        $ttlMinutes = (int) config('jwt.refresh_ttl');

        $data = [
            'sub' => $user->id,
            'typ' => 'refresh',
            'iat' => $now,
            'exp' => $now + $ttlMinutes * 60,
            'jti' => (string) Str::uuid(),
        ];
        return JWTAuth::getJWTProvider()->encode($data);
    }
}
