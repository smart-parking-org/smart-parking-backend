<?php

namespace App\Http\Controllers;

use App\Enums\AccountStatus;
use App\Enums\UserRole;
use App\Http\Requests\User\UserStoreRequest;
use App\Http\Requests\User\UserUpdateRequest;
use App\Http\Resources\UserResource;
use App\Http\Resources\VehicleResource;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;

class UserController extends Controller
{
    /**
     * @OA\Get(
     *     path="/users",
     *     tags={"Users"},
     *     summary="Danh sách người dùng",
     *     @OA\Parameter(
     *         name="page",
     *         in="query",
     *         description="Trang hiện tại (bắt đầu từ 1)",
     *         required=false,
     *         @OA\Schema(type="integer", minimum=1,example=1)
     *     ),
     *     @OA\Parameter(
     *         name="limit",
     *         in="query",
     *         description="Số bản ghi mỗi trang (tối đa 100)",
     *         required=false,
     *         @OA\Schema(type="integer", minimum=1, maximum=100, example=10)
     *     ),
     *     @OA\Parameter(
     *         name="trashed",
     *         in="query",
     *         description="Lọc lấy những tài khoản đã bị xóa (without,with,only)",
     *         required=false,
     *         @OA\Schema(type="string", example="without")
     *     ),
     *     @OA\Parameter(
     *         name="status",
     *         in="query",
     *         description="Lọc theo trạng thái người dùng (pending/approved/rejected)",
     *         required=false,
     *         @OA\Schema(type="string", example="approved")
     *     ),
     *     @OA\Parameter(
     *         name="search",
     *         in="query",
     *         description="Từ khóa tìm kiếm theo name/email/phone",
     *         required=false,
     *         @OA\Schema(type="string", example="")
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="Danh sách người dùng",
     *         @OA\JsonContent(
     *             type="object",
     *             @OA\Property(
     *                 property="data",
     *                 type="array",
     *                 @OA\Items(ref="#/components/schemas/User")
     *             ),
     *             @OA\Property(
     *                 property="pagination",
     *                 ref="#/components/schemas/Pagination"
     *             ),
     *         )
     *     ),
     *   @OA\Response(
     *     response=500,
     *     description="Lỗi máy chủ",
     *     @OA\JsonContent(
     *       @OA\Property(property="message", type="string", example="Đã xảy ra lỗi, vui lòng thử lại sau.")
     *     )
     *   )
     * )
     */
    public function index(Request $request)
    {
        $perPage = (int) $request->query('limit', 10);
        $perPage = min($perPage, 100);
        $trashed = $request->query('trashed', 'without');
        if (!in_array($trashed, ['without', 'with', 'only'], true)) {
            $trashed = 'without';
        }

        try {
            $query = User::query();

            if ($trashed === 'with') {
                $query->withTrashed();
            } elseif ($trashed === 'only') {
                $query->onlyTrashed();
            }

            if ($request->filled('status')) {
                $query->where('status', $request->query('status'));
            }

            if ($request->filled('search')) {
                $s = $request->query('search');
                $query->where(function ($qq) use ($s) {
                    $qq->where('email', 'like', "%{$s}%")
                        ->orWhere('name', 'like', "%{$s}%")
                        ->orWhere('phone', 'like', "%{$s}%");
                });
            }

            $query->orderByDesc('id');

            $users = $query->paginate($perPage)->appends($request->query());
        } catch (\Throwable $e) {
            Log::error('Lỗi khi lấy danh sách người dùng: ', ['error' => $e->getMessage()]);
            return response()->json([
                'message' => 'Đã xảy ra lỗi, vui lòng thử lại sau.'
            ], 500);
        }

        return response()->json([
            'data' => UserResource::collection($users->items()),
            'pagination' => [
                'total' => $users->total(),
                'per_page' => $users->perPage(),
                'current_page' => $users->currentPage(),
                'last_page' => $users->lastPage()
            ]
        ]);
    }

    /**
     * @OA\Post(
     *   path="/users",
     *   tags={"Users"},
     *   security={{"bearerAuth":{}}},
     *   summary="Tạo tài khoản người dùng mới",
     *   @OA\RequestBody(
     *     required=true,
     *     @OA\JsonContent(
     *       @OA\Property(property="name", type="string", example="User A"),
     *       @OA\Property(property="email", type="string", example="user@gmail.com"),
     *       @OA\Property(property="phone", type="string", example="0342123564"),
     *       @OA\Property(property="password", type="string", example="password123"),
     *       @OA\Property(property="apartment_code", type="string", example="B-123"),
     *       @OA\Property(property="role", type="string", example="resident"),
     *     )
     *   ),
     *   @OA\Response(
     *     response=201,
     *     description="Thêm người dùng thành công",
     *     @OA\JsonContent(ref="#/components/schemas/User")
     *   ),
     *  @OA\Response(
     *     response=422,
     *     description="Validation error",
     *     @OA\JsonContent(
     *       type="object",
     *       @OA\Property(property="message", type="string", example="The given data was invalid."),
     *       @OA\Property(
     *         property="errors",
     *         type="object",
     *         @OA\Property(
     *           property="name",
     *           type="array",
     *           @OA\Items(type="string", example="Vui lòng nhập họ và tên")
     *         )
     *       )
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
    public function store(UserStoreRequest $request)
    {
        $data = $request->validated();
        try {
            $user = User::create([
                'name' => $data['name'],
                'phone' => $data['phone'],
                'email' => $data['email'],
                'password' => Hash::make($data['password']),
                'apartment_code' => $data['apartment_code'] ?? null,
                'role' => $data['role'],
                'status' => AccountStatus::APPROVED,
                'approved_at' => now(),
                'approved_by' => auth('api')->id()
            ]);
        } catch (\Throwable $e) {
            Log::error('Lỗi khi thêm người dùng: ', ['error' => $e->getMessage()]);
            return response()->json([
                'message' => 'Đã xảy ra lỗi, vui lòng thử lại sau.'
            ], 500);
        }

        return new UserResource($user);
    }


    /**
     * @OA\Get(
     *   path="/users/{id}",
     *   tags={"Users"},
     *   summary="Lấy thông tin chi tiết người dùng",
     *   @OA\Parameter(
     *     name="id", in="path", required=true, description="User ID",
     *     @OA\Schema(type="integer", example=1)
     *   ),
     *   @OA\Response(
     *     response=200,
     *     description="OK",
     *     @OA\JsonContent(
     *        @OA\Property(property="data", ref="#/components/schemas/User"),
     *        @OA\Property(
     *          property="vehicles",
     *          type="array",
     *          @OA\Items(ref="#/components/schemas/Vehicle")
     *        )
     *     )
     *   ),
     *   @OA\Response(
     *     response=404,
     *     description="Không tìm thấy người dùng",
     *     @OA\JsonContent(type="object",
     *       @OA\Property(property="message", type="string", example="Không tìm thấy người dùng")
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
    public function show(string $id)
    {
        try {
            $user = User::find($id);
            if (!$user) {
                return response()->json(['message' => 'Không tìm thấy người dùng'], 404);
            }
        } catch (\Throwable $e) {
            Log::error('Lỗi khi xem chi tiết người dùng: ', ['error' => $e->getMessage()]);
            return response()->json([
                'message' => 'Đã xảy ra lỗi, vui lòng thử lại sau.'
            ], 500);
        }

        return response()->json([
            'data' => new UserResource($user),
            'vehicles' => VehicleResource::collection($user->vehicles ?? []),
        ]);
    }

    /**
     * @OA\Patch(
     *   path="/users/{id}",
     *   tags={"Users"},
     *   security={{"bearerAuth":{}}},
     *   summary="Cập nhật thông tin người dùng",
     *   @OA\Parameter(
     *     name="id", in="path", required=true, description="User ID",
     *     @OA\Schema(type="integer", example=1)
     *   ),
     *   @OA\RequestBody(
     *     required=false,
     *     @OA\JsonContent(
     *       @OA\Property(property="name", type="string", example="User A"),
     *       @OA\Property(property="email", type="string", example="user@gmail.com"),
     *       @OA\Property(property="phone", type="string", example="0342123564"),
     *       @OA\Property(property="apartment_code", type="string", example="B-123"),
     *       @OA\Property(property="role", type="string", example="resident"),
     *       @OA\Property(property="status", type="string", example="rejected"),
     *       @OA\Property(property="rejected_reason", type="string", example="rejected"),
     *     )
     *   ),
     *   @OA\Response(
     *     response=200,
     *     description="Cập nhật thành công",
     *     @OA\JsonContent(
     *        @OA\Property(property="data", ref="#/components/schemas/User")
     *     )
     *   ),
     *   @OA\Response(
     *     response=404,
     *     description="Không tìm thấy người dùng",
     *     @OA\JsonContent(type="object",
     *       @OA\Property(property="message", type="string", example="Không tìm thấy người dùng")
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
     *         @OA\Property(
     *           property="name",
     *           type="array",
     *           @OA\Items(type="string", example="Vui lòng nhập họ và tên")
     *         )
     *       )
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
    public function update(UserUpdateRequest $request, string $id)
    {
        $data = $request->validated();
        $isAdmin = auth('api')->user()->role === UserRole::ADMIN;
        $currentUserId = auth('api')->id();

        try {
            $user = User::find($id);
            if (!$user) {
                return response()->json(['message' => 'Không tìm thấy người dùng'], 404);
            }
            $status = $user->status;

            if ($request->filled('status')) {
                $status = $data['status'] instanceof AccountStatus
                    ? $data['status']
                    : (is_string($data['status']) ? AccountStatus::from($data['status']) : $status);
            }

            $payload = [
                'name' => $data['name'] ?? $user->name,
                'email' => $data['email'] ?? $user->email,
                'phone' => $data['phone'] ?? $user->phone,
                'apartment_code' => $data['apartment_code'] ?? $user->apartment_code,
            ];

            if ($isAdmin) {
                $payload = [
                    ...$payload,
                    'role' => $data['role'] ?? $user->role,
                    'status' => $status,
                    'rejected_reason' => in_array($status, [AccountStatus::APPROVED, AccountStatus::PENDING]) ? null : ($data['rejected_reason'] ?? $user->rejected_reason)
                ];
            }

            if ($isAdmin || $currentUserId === $user->id) {
                $user->update($payload);
            } else {
                return response()->json(['message' => '403 Forbbiden'], 403);
            }
        } catch (\Throwable $e) {
            Log::error('Lỗi khi cập nhật người dùng: ', ['error' => $e->getMessage()]);
            return response()->json([
                'message' => 'Đã xảy ra lỗi, vui lòng thử lại sau.'
            ], 500);
        }
        return new UserResource($user);
    }

    /**
     * @OA\Delete(
     *   path="/users/{id}",
     *   tags={"Users"},
     *   security={{"bearerAuth":{}}},
     *   summary="Xóa người dùng (xóa mềm)",
     *   @OA\Parameter(
     *     name="id", in="path", required=true, description="User ID",
     *     @OA\Schema(type="integer", example=1)
     *   ),
     *   @OA\Response(
     *     response=204,
     *     description="No Content",
     *   ),
     *   @OA\Response(
     *     response=404,
     *     description="Not Found",
     *     @OA\JsonContent(type="object",
     *       @OA\Property(property="message", type="string", example="Không tìm thấy người dùng")
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
    public function destroy(string $id)
    {
        try {
            $user = User::find($id);
            if (!$user) {
                return response()->json(['message' => 'Không tìm thấy người dùng'], 404);
            }
            if ($user->trashed()) {
                return response()->noContent();
            }

            $user->delete();

            return response()->noContent();
        } catch (\Throwable $e) {
            Log::error('Lỗi khi xóa người dùng: ', ['error' => $e->getMessage()]);
            return response()->json([
                'message' => 'Đã xảy ra lỗi, vui lòng thử lại sau.'
            ], 500);
        }
    }

    /**
     * @OA\Post(
     *   path="/users/restore/{id}",
     *   tags={"Users"},
     *   security={{"bearerAuth":{}}},
     *   summary="Khôi phục người dùng sau khi xóa mềm",
     *   @OA\Parameter(
     *     name="id", in="path", required=true, description="User ID",
     *     @OA\Schema(type="integer", example=1)
     *   ),
     *   @OA\Response(
     *     response=200,
     *     description="Khôi phục thành công",
     *     @OA\JsonContent(type="object",
     *       @OA\Property(property="message", type="string", example="Đã khôi phục người dùng")
     *     )
     *   ),
     *   @OA\Response(
     *     response=404,
     *     description="Not Found",
     *     @OA\JsonContent(type="object",
     *       @OA\Property(property="message", type="string", example="Không tìm thấy người dùng")
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
    public function restore(string $id)
    {
        try {
            $user = User::withTrashed()->find($id);
            if (!$user)
                return response()->json(['message' => 'Không tìm thấy người dùng'], 404);
            if (!$user->trashed())
                return response()->json(['message' => 'Người dùng đang hoạt động'], 200);

            $user->restore();
            return response()->json(['message' => 'Đã khôi phục người dùng']);
        } catch (\Throwable $e) {
            Log::error('Lỗi khi khôi phục người dùng: ', ['error' => $e->getMessage()]);
            return response()->json([
                'message' => 'Đã xảy ra lỗi, vui lòng thử lại sau.'
            ], 500);
        }
    }
}
