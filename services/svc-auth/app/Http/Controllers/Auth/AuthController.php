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
    /**
     * @OA\Post(
     *   path="/auth/register",
     *   tags={"Auth"},
     *   summary="Register a new user",
     *   @OA\RequestBody(
     *     required=true,
     *     @OA\JsonContent(
     *       required={"name","email","password"},
     *       @OA\Property(property="name", type="string", example="Nguyen Van A"),
     *       @OA\Property(property="email", type="string", format="email", example="a@example.com"),
     *       @OA\Property(property="password", type="string", format="password", minLength=8, example="P@ssw0rd!"),
     *       @OA\Property(property="phone", type="string", nullable=true, example="0912345678"),
     *       @OA\Property(property="apartment_code", type="string", nullable=true, example="B2-12A"),
     *       @OA\Property(property="cccd", type="string", nullable=true, example="079123456789")
     *     )
     *   ),
     *   @OA\Response(
     *     response=201,
     *     description="Registered successfully",
     *     @OA\JsonContent(
     *       type="object",
     *       required={"message","user"},
     *       @OA\Property(property="message", type="string", example="Registered successfully"),
     *       @OA\Property(
     *         property="user",
     *         type="object",
     *         required={"id","name","email","role"},
     *         @OA\Property(property="id", type="integer", format="int64", example=1),
     *         @OA\Property(property="name", type="string", example="Nguyen Van A"),
     *         @OA\Property(property="email", type="string", format="email", example="a@example.com"),
     *         @OA\Property(property="phone", type="string", nullable=true, example="0912345678"),
     *         @OA\Property(property="role", type="string", example="resident")
     *       )
     *     )
     *   ),
     *   @OA\Response(
     *     response=422,
     *     description="Validation error",
     *     @OA\JsonContent(
     *       type="object",
     *       @OA\Property(property="message", type="string", example="The given data was invalid."),
     *       @OA\Property(
     *         property="errors",
     *         type="object",
     *         example={"email": {"The email has already been taken."}}
     *       )
     *     )
     *   )
     * )
     */
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
            'user' => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'phone' => $user->phone,
                'role' => $user->role,
            ],
        ], 201);
    }

    /**
     * @OA\Post(
     *   path="/auth/login",
     *   tags={"Auth"},
     *   summary="User login",
     *   @OA\RequestBody(
     *     required=true,
     *     @OA\JsonContent(
     *       required={"email","password"},
     *       @OA\Property(property="email", type="string", format="email", example="user@example.com"),
     *       @OA\Property(property="password", type="string", format="password", example="12345678")
     *     )
     *   ),
     *   @OA\Response(
     *     response=200,
     *     description="Successful login",
     *     @OA\JsonContent(
     *       @OA\Property(property="access_token", type="string"),
     *       @OA\Property(property="refresh_token", type="string"),
     *       @OA\Property(property="token_type", type="string", example="bearer"),
     *       @OA\Property(property="expires_in", type="integer", example=3600),
     *       @OA\Property(
     *         property="user",
     *         type="object",
     *         @OA\Property(property="id", type="integer", format="int64", example=1),
     *         @OA\Property(property="name", type="string", example="John Doe"),
     *         @OA\Property(property="email", type="string", format="email", example="john@example.com"),
     *         @OA\Property(property="phone", type="string", nullable=true, example="0912345678"),
     *         @OA\Property(property="role", type="string", example="resident")
     *       )
     *     )
     *   ),
     *   @OA\Response(
     *     response=401,
     *     description="Unauthorized",
     *     @OA\JsonContent(
     *       type="object",
     *       @OA\Property(property="message", type="string", example="Unauthenticated.")
     *     )
     *   )
     * )
     */
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

        return response()->json([
            $this->respondWithToken($token, $refreshToken),
            'user' => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'phone' => $user->phone,
                'role' => $user->role,
            ],
        ]);
    }


    /**
     * @OA\Get(
     *   path="/auth/me",
     *   tags={"Auth"},
     *   summary="Get current authenticated user profile",
     *   security={{"bearerAuth":{}}},
     *   @OA\Response(
     *     response=200,
     *     description="OK",
     *     @OA\JsonContent(
     *       type="object",
     *       required={"message","user"},
     *       @OA\Property(property="message", type="string", example="Get me successfully"),
     *       @OA\Property(
     *         property="user",
     *         type="object",
     *         required={"id","name","email","role"},
     *         @OA\Property(property="id", type="integer", format="int64", example=1),
     *         @OA\Property(property="name", type="string", example="John Doe"),
     *         @OA\Property(property="email", type="string", format="email", example="john@example.com"),
     *         @OA\Property(property="phone", type="string", nullable=true, example="0912345678"),
     *         @OA\Property(property="apartment_code", type="string", nullable=true, example="B2-12A"),
     *         @OA\Property(property="role", type="string", example="resident")
     *       )
     *     )
     *   ),
     *   @OA\Response(
     *     response=401,
     *     description="Unauthorized",
     *     @OA\JsonContent(
     *       type="object",
     *       @OA\Property(property="message", type="string", example="Unauthenticated.")
     *     )
     *   )
     * )
     */
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

    /**
     * @OA\Post(
     *   path="/auth/logout",
     *   tags={"Auth"},
     *   summary="Logout (invalidate access token)",
     *   security={{"bearerAuth":{}}},
     *   @OA\Response(
     *     response=200,
     *     description="Logged out",
     *     @OA\JsonContent(
     *       type="object",
     *       @OA\Property(property="message", type="string", example="Logged out")
     *     )
     *   ),
     *   @OA\Response(
     *     response=401,
     *     description="Unauthorized",
     *     @OA\JsonContent(
     *       type="object",
     *       @OA\Property(property="message", type="string", example="Unauthenticated.")
     *     )
     *   )
     * )
     */
    public function logout()
    {
        auth('api')->logout();
        return response()->json(['message' => 'Logged out']);
    }

    /**
     * @OA\Post(
     *   path="/auth/refresh",
     *   tags={"Auth"},
     *   summary="Refresh access token with refresh_token",
     *   @OA\RequestBody(
     *     required=true,
     *     @OA\JsonContent(
     *       required={"refresh_token"},
     *       @OA\Property(property="refresh_token", type="string")
     *     )
     *   ),
     *   @OA\Response(
     *     response=200,
     *     description="OK",
     *     @OA\JsonContent(
     *       @OA\Property(property="access_token", type="string"),
     *       @OA\Property(property="refresh_token", type="string"),
     *       @OA\Property(property="token_type", type="string", example="bearer"),
     *       @OA\Property(property="expires_in", type="integer", example=3600),
     *       @OA\Property(property="expires_at", type="string", format="date-time", example="2025-09-25T15:30:00+07:00"),
     *     )
     *   ),
     *   @OA\Response(
     *     response=401,
     *     description="Unauthorized",
     *     @OA\JsonContent(
     *       type="object",
     *       @OA\Property(property="message", type="string", example="Unauthenticated.")
     *     )
     *   )
     * )
     */
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

        return response()->json($this->respondWithToken($newAccess, $newRefresh));
    }

    protected function respondWithToken($token, $refreshToken)
    {
        $ttlMinutes = auth('api')->factory()->getTTL();

        return [
            'access_token' => $token,
            'refresh_token' => $refreshToken,
            'token_type' => 'Bearer',
            'expires_in' => $ttlMinutes * 60,
            'expires_at' => now()->addMinutes($ttlMinutes)->toIso8601String(),
        ];
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
