<?php

namespace App\Http\Controllers\Auth;

use App\Models\User;
use App\Enums\UserRole;
use App\Enums\AccountStatus;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\LoginRequest;
use App\Http\Requests\Auth\RegisterRequest;
use App\Http\Requests\Auth\VerifyOtpRequest;
use App\Http\Requests\Auth\RequestOtpRequest;
use App\Http\Requests\Auth\ResetPasswordRequest;

use App\Mail\RegisterSuccessMail;
use App\Mail\ForgotPasswordOtpMail;

use Illuminate\Support\Str;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Cache;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    private function otpKey(string $email): string
    {
        return 'pw:otp:' . mb_strtolower($email);
    }

    private function resetKey(string $email): string
    {
        return 'pw:reset:' . mb_strtolower($email);
    }

    /**
     * @OA\Post(
     *   path="/auth/register",
     *   summary="Đăng ký tài khoản mới",
     *   description="Tạo tài khoản mới ở trạng thái pending, gửi email thông báo và chờ admin duyệt.",
     *   tags={"Auth"},
     *   @OA\RequestBody(
     *     required=true,
     *     @OA\JsonContent(
     *       required={"name","email","phone","apartment_code","password"},
     *       @OA\Property(property="name", type="string", example="Nguyễn Văn A", maxLength=100),
     *       @OA\Property(property="email", type="string", format="email", example="user@example.com", maxLength=150),
     *       @OA\Property(property="phone", type="string", example="0901234567", maxLength=20),
     *       @OA\Property(property="apartment_code", type="string", example="B2-1205", maxLength=50),
     *       @OA\Property(property="password", type="string", format="password", example="secret@123", minLength=8)
     *     )
     *   ),
     *   @OA\Response(
     *     response=201,
     *     description="Đăng ký thành công",
     *     @OA\JsonContent(
     *       @OA\Property(property="message", type="string", example="Đăng ký thành công, vui lòng chờ admin duyệt.")
     *     )
     *   ),
     *   @OA\Response(
     *     response=422,
     *     description="Dữ liệu không hợp lệ",
     *     @OA\JsonContent(
     *       @OA\Property(property="message", type="string", example="The given data was invalid."),
     *       @OA\Property(
     *         property="errors",
     *         type="object",
     *         example={
     *           "email": {"Vui lòng nhập email"},
     *           "password": {"Vui lòng nhập mật khẩu"}
     *         }
     *       )
     *     )
     *   ),
     *   @OA\Response(
     *     response=500,
     *     description="Lỗi máy chủ",
     *     @OA\JsonContent(ref="#/components/schemas/ErrorResponse")
     *   )
     * )
     */
    public function register(RegisterRequest $request)
    {
        try {
            DB::beginTransaction();
            $user = User::create([
                'name' => $request->name,
                'email' => $request->email,
                'phone' => $request->phone,
                'apartment_code' => $request->apartment_code,
                'password' => Hash::make($request->password),
                'role' => UserRole::RESIDENT,
                'status' => AccountStatus::PENDING,
            ]);
            DB::commit();
        } catch (\Throwable $e) {
            DB::rollBack();
            Log::error('Lỗi khi đăng ký tài khoản: ', ['error' => $e->getMessage()]);
            return response()->json([
                'message' => 'Đã xảy ra lỗi, vui lòng thử lại sau.'
            ], 500);
        }

        try {
            Mail::to($user->email)->queue(new RegisterSuccessMail($user));
        } catch (\Throwable $e) {
            Log::warning('Lỗi khi gửi mail xác nhận đăng ký: ', [
                'user_id' => $user->id,
                'error' => $e->getMessage()
            ]);
        }

        return response()->json([
            'message' => 'Đăng ký thành công, vui lòng chờ admin duyệt.'
        ], 201);
    }

    /**
     * @OA\Post(
     *   path="/auth/login",
     *   summary="Đăng nhập",
     *   description="Xác thực bằng email/password. Chỉ tài khoản đã được admin duyệt mới đăng nhập được.",
     *   tags={"Auth"},
     *   @OA\RequestBody(
     *     required=true,
     *     @OA\JsonContent(
     *       required={"email","password"},
     *       @OA\Property(property="email", type="string", format="email", example="user@example.com", maxLength=150),
     *       @OA\Property(property="password", type="string", format="password", example="secret@123", minLength=8)
     *     )
     *   ),
     *   @OA\Response(
     *     response=200,
     *     description="Đăng nhập thành công",
     *     @OA\JsonContent(
     *       type="object",
     *       properties={
     *         @OA\Property(property="access_token", type="string", example="eyJ0eXAiOiJKV1QiLCJhbGciOi..."),
     *         @OA\Property(property="token_type", type="string", example="bearer"),
     *         @OA\Property(property="expires_in", type="integer", example=3600)
     *       }
     *     )
     *   ),
     *   @OA\Response(
     *     response=401,
     *     description="Sai thông tin đăng nhập",
     *     @OA\JsonContent(
     *       @OA\Property(property="error", type="string", example="Email hoặc mật khẩu không đúng")
     *     )
     *   ),
     *   @OA\Response(
     *     response=403,
     *     description="Tài khoản chưa được phép đăng nhập (pending/rejected)",
     *     @OA\JsonContent(
     *       oneOf={
     *         @OA\Schema(type="object", @OA\Property(property="error", type="string", example="Tài khoản của bạn đang chờ admin duyệt")),
     *         @OA\Schema(type="object", @OA\Property(property="error", type="string", example="Tài khoản của bạn đã bị từ chối"))
     *       }
     *     )
     *   ),
     *   @OA\Response(
     *     response=500,
     *     description="Lỗi máy chủ",
     *     @OA\JsonContent(ref="#components/schemas/ErrorResponse")
     *   )
     * )
     */
    public function login(LoginRequest $request)
    {
        $credentials = $request->validated();
        try {
            if (!$token = auth('api')->attempt($credentials)) {
                return response()->json(['error' => 'Email hoặc mật khẩu không đúng'], 401);
            }

            $user = auth('api')->user();
            if ($user->status === AccountStatus::PENDING) {
                auth('api')->logout();
                return response()->json(['error' => 'Tài khoản của bạn đang chờ admin duyệt'], 403);
            }

            if ($user->status === AccountStatus::REJECTED) {
                auth('api')->logout();
                return response()->json(['error' => 'Tài khoản của bạn đã bị từ chối'], 403);
            }

            return $this->respondWithToken($token);
        } catch (\Throwable $e) {
            Log::error('Lỗi khi đăng nhập: ', ['error' => $e->getMessage()]);
            return response()->json([
                'message' => 'Đã xảy ra lỗi, vui lòng thử lại sau.'
            ], 500);
        }
    }

    /**
     * @OA\Get(
     *   path="/auth/me",
     *   summary="Lấy thông tin người dùng hiện tại",
     *   description="Yêu cầu gửi kèm JWT bearer token trong header Authorization.",
     *   tags={"Auth"},
     *   security={{"bearerAuth": {}}},
     *   @OA\Response(
     *     response=200,
     *     description="Thông tin người dùng hiện tại",
     *     @OA\JsonContent(
     *       type="object",
     *       @OA\Property(property="id", type="integer", example=1),
     *       @OA\Property(property="name", type="string", example="Nguyen Van A"),
     *       @OA\Property(property="email", type="string", format="email", example="user@example.com"),
     *       @OA\Property(property="phone", type="string", example="0912345678"),
     *       @OA\Property(property="apartment_code", type="string", nullable=true, example="B2-1206"),
     *       @OA\Property(property="role", type="string", example="resident")
     *     )
     *   ),
     *   @OA\Response(
     *     response=401,
     *     description="Chưa xác thực hoặc token không hợp lệ",
     *     @OA\JsonContent(
     *       type="object",
     *       @OA\Property(property="message", type="string", example="Unauthorized")
     *     )
     *   ),
     *   @OA\Response(
     *     response=500,
     *     description="Lỗi máy chủ",
     *     @OA\JsonContent(ref="#components/schemas/ErrorResponse")
     *   )
     * )
     */
    public function me()
    {
        try {
            $user = auth('api')->user();
            return response()->json([
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'phone' => $user->phone,
                'apartment_code' => $user->apartment_code,
                'role' => $user->role
            ]);
        } catch (\Throwable $e) {
            Log::error('Lỗi khi đăng xuất: ', ['error' => $e->getMessage()]);
            return response()->json([
                'message' => 'Đã xảy ra lỗi, vui lòng thử lại sau.'
            ], 500);
        }
    }

    /**
     * @OA\Post(
     *   path="/auth/logout",
     *   summary="Đăng xuất tài khoản người dùng hiện tại",
     *   description="Yêu cầu gửi kèm JWT bearer token trong header Authorization.",
     *   tags={"Auth"},
     *   security={{"bearerAuth": {}}},
     *   @OA\Response(
     *     response=200,
     *     description="Đăng xuất thành công",
     *     @OA\JsonContent(
     *       type="object",
     *       @OA\Property(property="message", type="string", example="Đăng xuất thành công")
     *     )
     *   ),
     *   @OA\Response(
     *     response=401,
     *     description="Chưa xác thực hoặc token không hợp lệ",
     *     @OA\JsonContent(
     *       type="object",
     *       @OA\Property(property="message", type="string", example="Unauthorized")
     *     )
     *   ),
     *   @OA\Response(
     *     response=500,
     *     description="Lỗi máy chủ",
     *     @OA\JsonContent(ref="#components/schemas/ErrorResponse")
     *   )
     * )
     */
    public function logout()
    {
        try {
            auth('api')->logout();
            return response()->json(['message' => 'Đăng xuất thành công']);
        } catch (\Throwable $e) {
            Log::error('Lỗi khi đăng xuất: ', ['error' => $e->getMessage()]);
            return response()->json([
                'message' => 'Đã xảy ra lỗi, vui lòng thử lại sau.'
            ], 500);
        }
    }

    /**
     * @OA\Post(
     *   path="/auth/refresh",
     *   summary="Làm mới access token",
     *   description="Cấp access token mới từ JWT hiện tại (yêu cầu gửi kèm Bearer token hợp lệ).",
     *   tags={"Auth"},
     *   security={{"bearerAuth": {}}},
     *   @OA\Response(
     *     response=200,
     *     description="Làm mới token thành công",
     *     @OA\JsonContent(
     *       type="object",
     *       properties={
     *         @OA\Property(property="access_token", type="string", example="eyJ0eXAiOiJKV1QiLCJhbGciOi..."),
     *         @OA\Property(property="token_type", type="string", example="bearer"),
     *         @OA\Property(property="expires_in", type="integer", example=3600)
     *       }
     *     )
     *   ),
     *   @OA\Response(
     *     response=401,
     *     description="Token không hợp lệ/đã hết hạn/không có quyền làm mới",
     *     @OA\JsonContent(
     *       @OA\Property(property="message", type="string", example="Unauthenticated.")
     *     )
     *   ),
     *   @OA\Response(
     *     response=500,
     *     description="Lỗi máy chủ",
     *     @OA\JsonContent(
     *       @OA\Property(property="message", type="string", example="Đã xảy ra lỗi, vui lòng thử lại sau.")
     *     )
     *   )
     * )
     */
    public function refresh()
    {
        try {
            $newToken = auth('api')->refresh();
            return $this->respondWithToken($newToken);
        } catch (\Throwable $e) {
            Log::error('Lỗi khi refresh token: ', ['error' => $e->getMessage()]);
            return response()->json([
                'message' => 'Đã xảy ra lỗi, vui lòng thử lại sau.'
            ], 500);
        }
    }

    /**
     * @OA\Post(
     *   path="/auth/forgot-password/request-otp",
     *   summary="Yêu cầu mã OTP đặt lại mật khẩu",
     *   description="Nếu email hợp lệ, hệ thống sinh OTP (6 số), lưu vào cache với TTL 5 phút và gửi email. Luôn trả về message chung để tránh lộ sự tồn tại của tài khoản.",
     *   tags={"Auth"},
     *   @OA\RequestBody(
     *     required=true,
     *     @OA\JsonContent(
     *       required={"email"},
     *       @OA\Property(
     *         property="email",
     *         type="string",
     *         format="email",
     *         example="user@example.com",
     *         maxLength=150
     *       )
     *     )
     *   ),
     *   @OA\Response(
     *     response=200,
     *     description="Yêu cầu OTP đã được tiếp nhận (message chung)",
     *     @OA\JsonContent(
     *       type="object",
     *       @OA\Property(property="message", type="string", example="Nếu email hợp lệ, mã OTP đã được gửi. Vui lòng kiểm tra hộp thư."),
     *       @OA\Property(property="expires_in", type="integer", example=300, description="Số giây hiệu lực của OTP (5 phút)")
     *     )
     *   ),
     *   @OA\Response(
     *     response=422,
     *     description="Dữ liệu không hợp lệ (validate thất bại)",
     *     @OA\JsonContent(
     *       type="object",
     *       @OA\Property(property="message", type="string", example="The given data was invalid."),
     *       @OA\Property(
     *         property="errors",
     *         type="object",
     *         example={
     *           "email": {"Vui lòng nhập email hợp lệ"}
     *         }
     *       )
     *     )
     *   ),
     *   @OA\Response(
     *     response=429,
     *     description="Quá nhiều yêu cầu (throttle/cooldown)",
     *     @OA\JsonContent(
     *       type="object",
     *       @OA\Property(property="message", type="string", example="Too Many Requests")
     *     )
     *   ),
     *   @OA\Response(
     *     response=500,
     *     description="Lỗi máy chủ khi gửi email",
     *     @OA\JsonContent(
     *       type="object",
     *       @OA\Property(property="message", type="string", example="Đã xảy ra lỗi, vui lòng thử lại sau.")
     *     )
     *   )
     * )
     */
    public function requestOtp(RequestOtpRequest $request)
    {
        $data = $request->validated();
        $ttlMins = 5;
        $coolSec = 60;
        $coolKey = "pw:otp:cooldown:" . strtolower($data["email"]);

        if (!Cache::add($coolKey, 1, now()->addSeconds($coolSec))) {
            // key đã tồn tại => đang cooldown -> vẫn trả message chung
            return response()->json([
                'message' => 'Nếu email hợp lệ, mã OTP đã được gửi. Vui lòng kiểm tra hộp thư.',
                'expires_in' => $ttlMins * 60,
            ]);
        }

        $user = User::where('email', $data['email'])->first();
        if ($user && $user->status === AccountStatus::APPROVED) {
            $otp = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
            $otpHash = Hash::make($otp);
            $key = $this->otpKey($data['email']);

            $payload = [
                'otp_hash' => $otpHash,
                'expires_at' => now()->addMinutes($ttlMins)->getTimestamp(),
                'attempts' => 0
            ];

            Cache::put($key, $payload, now()->addMinutes($ttlMins));

            try {
                Mail::to($user->email)->queue(new ForgotPasswordOtpMail($user, $otp));
            } catch (\Throwable $e) {
                Log::error('Lỗi khi gửi otp: ', ['error' => $e->getMessage()]);
                return response()->json([
                    'message' => 'Đã xảy ra lỗi, vui lòng thử lại sau.'
                ], 500);
            }
        }

        return response()->json([
            'message' => 'Nếu email hợp lệ, mã OTP đã được gửi. Vui lòng kiểm tra hộp thư.',
            'expires_in' => $ttlMins * 60
        ]);
    }

    /**
     * @OA\Post(
     *   path="/auth/forgot-password/verify-otp",
     *   summary="Xác minh OTP đặt lại mật khẩu",
     *   description="Kiểm tra OTP (6 số) đã gửi tới email. Nếu đúng, trả về reset_token hiệu lực 15 phút để đổi mật khẩu.",
     *   tags={"Auth"},
     *   @OA\RequestBody(
     *     required=true,
     *     @OA\JsonContent(
     *       required={"email","otp"},
     *       @OA\Property(property="email", type="string", format="email", example="user@example.com", maxLength=150),
     *       @OA\Property(property="otp", type="string", example="123456", pattern="^[0-9]{6}$", description="OTP 6 chữ số")
     *     )
     *   ),
     *   @OA\Response(
     *     response=200,
     *     description="OTP hợp lệ",
     *     @OA\JsonContent(
     *       type="object",
     *       @OA\Property(property="otp_verified", type="boolean", example=true),
     *       @OA\Property(property="reset_token", type="string", example="9b9f0d1f5c5e4e7a9c..."),
     *       @OA\Property(property="expires_in", type="integer", example=900, description="Thời gian hiệu lực của reset_token (giây)")
     *     )
     *   ),
     *   @OA\Response(
     *     response=422,
     *     description="OTP không hợp lệ/hết hạn/vượt quá số lần",
     *     @OA\JsonContent(
     *       type="object",
     *       @OA\Property(property="message", type="string", example="The given data was invalid."),
     *       @OA\Property(
     *         property="errors",
     *         type="object",
     *         oneOf={
     *           @OA\Schema(example={"otp": {"Mã OTP không hợp lệ hoặc đã hết hạn."}}),
     *           @OA\Schema(example={"otp": {"Mã OTP đã hết hạn."}}),
     *           @OA\Schema(example={"otp": {"Bạn đã nhập sai quá số lần cho phép."}}),
     *           @OA\Schema(example={"otp": {"Mã OTP không đúng."}})
     *         }
     *       )
     *     )
     *   ),
     *   @OA\Response(
     *     response=429,
     *     description="Quá nhiều yêu cầu (nếu áp throttle ở route)",
     *     @OA\JsonContent(@OA\Property(property="message", type="string", example="Too Many Requests"))
     *   ),
     *   @OA\Response(
     *     response=500,
     *     description="Lỗi máy chủ",
     *     @OA\JsonContent(@OA\Property(property="message", type="string", example="Đã xảy ra lỗi, vui lòng thử lại sau."))
     *   )
     * )
     */
    public function verifyOtp(VerifyOtpRequest $request)
    {
        $data = $request->validated();
        $key = $this->otpKey($data['email']);
        $rec = Cache::get($key);

        if (!$rec) {
            throw ValidationException::withMessages(['otp' => 'Mã OTP không hợp lệ hoặc đã hết hạn.']);
        }

        if (time() > (int) $rec['expires_at']) {
            Cache::forget($key);
            throw ValidationException::withMessages(['otp' => 'Mã OTP đã hết hạn.']);
        }

        if ((int) $rec['attempts'] >= 5) {
            Cache::forget($key);
            throw ValidationException::withMessages(['otp' => 'Bạn đã nhập sai quá số lần cho phép.']);
        }

        if (!Hash::check($data['otp'], $rec['otp_hash'])) {
            $rec['aptempts'] = (int) $rec['attempts'] + 1;
            $ttlSeconds = max(1, (int) $rec['expires_at'] - time());
            Cache::put($key, $rec, now()->addSeconds($ttlSeconds));
            throw ValidationException::withMessages(['otp' => 'Mã OTP không đúng.']);
        }
        try {
            // OTP đúng → tạo reset_token 15 phút
            $resetToken = Str::random(64);
            $resetTokenHash = Hash::make($resetToken);
            $resetTtlMinutes = 15;
            $resetKey = $this->resetKey($data['email']);

            Cache::put($resetKey, [
                'reset_token_hash' => $resetTokenHash,
                'expires_at' => now()->addMinutes($resetTtlMinutes)->getTimestamp(),
            ], now()->addMinutes($resetTtlMinutes));

            // Xoá OTP (không dùng lại)
            Cache::forget($key);
        } catch (\Throwable $e) {
            Log::error('Lỗi khi verify otp: ', ['error' => $e->getMessage()]);
            return response()->json([
                'message' => 'Đã xảy ra lỗi, vui lòng thử lại sau.'
            ], 500);
        }

        return response()->json([
            'otp_verified' => true,
            'reset_token' => $resetToken,
            'expires_in' => $resetTtlMinutes * 60
        ]);
    }

    /**
     * @OA\Post(
     *   path="/auth/forgot-password/reset",
     *   summary="Đặt lại mật khẩu bằng reset_token",
     *   description="Xác thực reset_token (đã lấy từ bước verify OTP). Nếu hợp lệ, đổi mật khẩu và vô hiệu hoá token reset.",
     *   tags={"Auth"},
     *   @OA\RequestBody(
     *     required=true,
     *     @OA\JsonContent(
     *       required={"email","reset_token","password","password_confirmation"},
     *       @OA\Property(property="email", type="string", format="email", example="user@example.com", maxLength=150),
     *       @OA\Property(property="reset_token", type="string", example="a1b2c3d4e5... (64 ký tự)"),
     *       @OA\Property(property="password", type="string", format="password", example="Secret@123", minLength=8)
     *     )
     *   ),
     *   @OA\Response(
     *     response=200,
     *     description="Đổi mật khẩu thành công",
     *     @OA\JsonContent(
     *       @OA\Property(property="message", type="string", example="Đặt lại mật khẩu thành công.")
     *     )
     *   ),
     *   @OA\Response(
     *     response=422,
     *     description="Reset token không hợp lệ/hết hạn hoặc dữ liệu không hợp lệ",
     *     @OA\JsonContent(
     *       @OA\Property(property="message", type="string", example="The given data was invalid."),
     *       @OA\Property(
     *         property="errors",
     *         type="object",
     *         oneOf={
     *           @OA\Schema(example={"reset_token": {"Token không hợp lệ hoặc đã hết hạn."}}),
     *           @OA\Schema(example={"reset_token": {"Token đã hết hạn."}}),
     *           @OA\Schema(example={"reset_token": {"Token không đúng."}}),
     *           @OA\Schema(example={"password": {"Mật khẩu phải tối thiểu 8 ký tự."}})
     *         }
     *       )
     *     )
     *   ),
     *   @OA\Response(
     *     response=429,
     *     description="Quá nhiều yêu cầu (nếu route có throttle)",
     *     @OA\JsonContent(@OA\Property(property="message", type="string", example="Too Many Requests"))
     *   ),
     *   @OA\Response(
     *     response=500,
     *     description="Lỗi máy chủ",
     *     @OA\JsonContent(@OA\Property(property="message", type="string", example="Đã xảy ra lỗi, vui lòng thử lại sau."))
     *   )
     * )
     */
    public function resetPassword(ResetPasswordRequest $request)
    {
        $data = $request->validated();

        $resetKey = $this->resetKey($data['email']);
        $rec = Cache::get($resetKey);

        if (!$rec) {
            throw ValidationException::withMessages(['reset_token' => 'Token không hợp lệ hoặc đã hết hạn.']);
        }

        if (time() > (int) $rec['expires_at']) {
            Cache::forget($resetKey);
            throw ValidationException::withMessages(['reset_token' => 'Token đã hết hạn.']);
        }

        if (!Hash::check($data['reset_token'], $rec['reset_token_hash'])) {
            throw ValidationException::withMessages(['reset_token' => 'Token không đúng.']);
        }
        try {

            if ($user = User::where('email', $data['email'])->first()) {
                $user->password = Hash::make($data['password']);
                $user->save();
                auth('api')->logout(true);
            }

            Cache::forget($resetKey);
            return response()->json(['message' => 'Đặt lại mật khẩu thành công.']);
        } catch (\Throwable $e) {
            Log::error('Lỗi khi gửi otp: ', ['error' => $e->getMessage()]);
            return response()->json([
                'message' => 'Đã xảy ra lỗi, vui lòng thử lại sau.'
            ], 500);
        }
    }

    protected function respondWithToken($token)
    {
        return response()->json([
            'access_token' => $token,
            'token_type' => 'bearer',
            'expires_in' => auth('api')->factory()->getTTL() * 60
        ]);
    }
}
