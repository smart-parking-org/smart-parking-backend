<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\LoginRequest;
use App\Http\Requests\Auth\RegisterRequest;
use App\Models\User;

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
        $user = User::where('email', $credentials['email'])->first();

        if (!$user) {
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

        if (!$token = auth('api')->attempt($credentials)) {
            return response()->json(['message' => 'Invalid credentials'], 401);
        }

        return $this->respondWithToken($token);
    }

    public function me()
    {
        return response()->json([
            'message' => 'Get me successfully',
            'user' => auth('api')->user()
        ]);
    }

    public function logout()
    {
        auth('api')->logout();
        return response()->json(['message' => 'Logged out']);
    }

    protected function respondWithToken($token)
    {
        $user = auth('api')->user();
        return response()->json([
            'access_token' => $token,
            'token_type' => 'bearer',
            'expires_in' => auth('api')->factory()->getTTL() * 60,
            'user' => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'phone' => $user->phone,
                'role' => $user->role,
            ],
        ]);
    }
}
