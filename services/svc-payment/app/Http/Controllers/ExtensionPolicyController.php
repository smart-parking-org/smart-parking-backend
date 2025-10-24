<?php

namespace App\Http\Controllers;

use App\Models\ExtensionPolicy;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Validation\ValidationException;
use OpenApi\Annotations as OA;

/**
 * @OA\Tag(
 *     name="Extension Policies",
 *     description="Quản lý chính sách gia hạn với cấu trúc key-value"
 * )
 */
class ExtensionPolicyController extends Controller
{
    /**
     * @OA\Get(
     *     path="/extension-policies",
     *     summary="Lấy danh sách cấu hình chính sách gia hạn",
     *     description="Lấy tất cả cấu hình chính sách gia hạn theo key-value",
     *     tags={"Extension Policies"},
     *     @OA\Response(
     *         response=200,
     *         description="Danh sách cấu hình chính sách gia hạn",
     *         @OA\JsonContent(
     *             type="array",
     *             @OA\Items(
     *                 @OA\Property(property="id", type="integer", example=1),
     *                 @OA\Property(property="key", type="string", example="parking_lot_1_extension_policy"),
     *                 @OA\Property(property="value", type="object"),
     *                 @OA\Property(property="created_at", type="string", format="datetime"),
     *                 @OA\Property(property="updated_at", type="string", format="datetime")
     *             )
     *         )
     *     )
     * )
     */
    public function index(): JsonResponse
    {
        $extensionPolicies = ExtensionPolicy::all();

        return response()->json([
            'success' => true,
            'data' => $extensionPolicies
        ]);
    }

    /**
     * @OA\Get(
     *     path="/extension-policies/{key}",
     *     summary="Lấy cấu hình chính sách gia hạn theo key",
     *     description="Lấy cấu hình chính sách gia hạn của một key cụ thể",
     *     tags={"Extension Policies"},
     *     @OA\Parameter(
     *         name="key",
     *         in="path",
     *         description="Key identifier",
     *         required=true,
     *         @OA\Schema(type="string")
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="Cấu hình chính sách gia hạn",
     *         @OA\JsonContent(
     *             @OA\Property(property="success", type="boolean", example=true),
     *             @OA\Property(property="data", type="object")
     *         )
     *     ),
     *     @OA\Response(
     *         response=404,
     *         description="Không tìm thấy cấu hình"
     *     )
     * )
     */
    public function show(string $key): JsonResponse
    {
        $extensionPolicy = ExtensionPolicy::where('key', $key)->first();

        if (!$extensionPolicy) {
            return response()->json([
                'success' => false,
                'message' => 'Không tìm thấy cấu hình chính sách gia hạn'
            ], 404);
        }

        return response()->json([
            'success' => true,
            'data' => $extensionPolicy
        ]);
    }

    /**
     * @OA\Post(
     *     path="/extension-policies",
     *     summary="Tạo cấu hình chính sách gia hạn mới",
     *     description="Tạo cấu hình chính sách gia hạn mới cho một bãi đỗ xe",
     *     tags={"Extension Policies"},
     *     @OA\RequestBody(
     *         required=true,
     *         @OA\JsonContent(
     *             required={"key", "value"},
     *             @OA\Property(property="key", type="string", example="parking_lot_1_extension_policy"),
     *             @OA\Property(
     *                 property="value",
     *                 type="object",
     *                 @OA\Property(property="max_extensions", type="integer", example=3, description="Số lần gia hạn tối đa"),
     *                 @OA\Property(property="extension_minutes", type="integer", example=30, description="Số phút gia hạn mỗi lần"),
     *                 @OA\Property(property="is_active", type="boolean", example=true, description="Có cho phép gia hạn không"),
     *                 @OA\Property(property="description", type="string", example="Chính sách gia hạn cho bãi đỗ xe số 1")
     *             )
     *         )
     *     ),
     *     @OA\Response(
     *         response=201,
     *         description="Tạo thành công",
     *         @OA\JsonContent(
     *             @OA\Property(property="success", type="boolean", example=true),
     *             @OA\Property(property="message", type="string", example="Tạo cấu hình chính sách gia hạn thành công"),
     *             @OA\Property(property="data", type="object")
     *         )
     *     ),
     *     @OA\Response(
     *         response=422,
     *         description="Dữ liệu không hợp lệ"
     *     ),
     *     @OA\Response(
     *         response=409,
     *         description="Cấu hình đã tồn tại"
     *     )
     * )
     */
    public function store(Request $request): JsonResponse
    {
        try {
            $validated = $request->validate([
                'key' => 'required|string|unique:extension_policies,key',
                'value' => 'required|array',
                'value.max_extensions' => 'required|integer|min:0|max:10',
                'value.extension_minutes' => 'required|integer|min:1|max:1440',
                'value.is_active' => 'required|boolean',
                'value.description' => 'nullable|string|max:255'
            ]);

            $extensionPolicy = ExtensionPolicy::create([
                'key' => $validated['key'],
                'value' => $validated['value']
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Tạo cấu hình chính sách gia hạn thành công',
                'data' => $extensionPolicy
            ], 201);

        } catch (ValidationException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Dữ liệu không hợp lệ',
                'errors' => $e->errors()
            ], 422);
        }
    }

    /**
     * @OA\Put(
     *     path="/extension-policies/{key}",
     *     summary="Cập nhật cấu hình chính sách gia hạn",
     *     description="Cập nhật cấu hình chính sách gia hạn theo key",
     *     tags={"Extension Policies"},
     *     @OA\Parameter(
     *         name="key",
     *         in="path",
     *         description="Key identifier",
     *         required=true,
     *         @OA\Schema(type="string")
     *     ),
     *     @OA\RequestBody(
     *         required=true,
     *         @OA\JsonContent(
     *             required={"value"},
     *             @OA\Property(
     *                 property="value",
     *                 type="object",
     *                 @OA\Property(property="max_extensions", type="integer", example=5, description="Số lần gia hạn tối đa"),
     *                 @OA\Property(property="extension_minutes", type="integer", example=60, description="Số phút gia hạn mỗi lần"),
     *                 @OA\Property(property="is_active", type="boolean", example=true, description="Có cho phép gia hạn không"),
     *                 @OA\Property(property="description", type="string", example="Chính sách gia hạn cập nhật cho bãi đỗ xe số 1")
     *             )
     *         )
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="Cập nhật thành công",
     *         @OA\JsonContent(
     *             @OA\Property(property="success", type="boolean", example=true),
     *             @OA\Property(property="message", type="string", example="Cập nhật cấu hình chính sách gia hạn thành công"),
     *             @OA\Property(property="data", type="object")
     *         )
     *     ),
     *     @OA\Response(
     *         response=404,
     *         description="Không tìm thấy cấu hình"
     *     ),
     *     @OA\Response(
     *         response=422,
     *         description="Dữ liệu không hợp lệ"
     *     )
     * )
     */
    public function update(Request $request, string $key): JsonResponse
    {
        $extensionPolicy = ExtensionPolicy::where('key', $key)->first();

        if (!$extensionPolicy) {
            return response()->json([
                'success' => false,
                'message' => 'Không tìm thấy cấu hình chính sách gia hạn'
            ], 404);
        }

        try {
            $validated = $request->validate([
                'value' => 'required|array',
                'value.max_extensions' => 'required|integer|min:0|max:10',
                'value.extension_minutes' => 'required|integer|min:1|max:1440',
                'value.is_active' => 'required|boolean',
                'value.description' => 'nullable|string|max:255'
            ]);

            $extensionPolicy->update(['value' => $validated['value']]);

            return response()->json([
                'success' => true,
                'message' => 'Cập nhật cấu hình chính sách gia hạn thành công',
                'data' => $extensionPolicy
            ]);

        } catch (ValidationException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Dữ liệu không hợp lệ',
                'errors' => $e->errors()
            ], 422);
        }
    }

    /**
     * @OA\Get(
     *     path="/extension-policies/{key}/check",
     *     summary="Kiểm tra khả năng gia hạn",
     *     description="Kiểm tra xem có thể gia hạn không và lấy thông tin gia hạn",
     *     tags={"Extension Policies"},
     *     @OA\Parameter(
     *         name="key",
     *         in="path",
     *         description="Key identifier",
     *         required=true,
     *         @OA\Schema(type="string")
     *     ),
     *     @OA\Parameter(
     *         name="current_extensions",
     *         in="query",
     *         description="Số lần đã gia hạn hiện tại",
     *         required=false,
     *         @OA\Schema(type="integer", default=0)
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="Kết quả kiểm tra khả năng gia hạn",
     *         @OA\JsonContent(
     *             @OA\Property(property="success", type="boolean", example=true),
     *             @OA\Property(property="can_extend", type="boolean", example=true),
     *             @OA\Property(property="max_extensions", type="integer", example=3),
     *             @OA\Property(property="extension_minutes", type="integer", example=30),
     *             @OA\Property(property="remaining_extensions", type="integer", example=2),
     *             @OA\Property(property="current_extensions", type="integer", example=1)
     *         )
     *     )
     * )
     */
    public function checkExtension(Request $request, string $key): JsonResponse
    {
        $currentExtensions = $request->get('current_extensions', 0);
        
        $extensionInfo = ExtensionPolicy::canExtend($key, $currentExtensions);

        return response()->json([
            'success' => true,
            'can_extend' => $extensionInfo['can_extend'],
            'max_extensions' => $extensionInfo['max_extensions'],
            'extension_minutes' => $extensionInfo['extension_minutes'],
            'remaining_extensions' => $extensionInfo['remaining_extensions'],
            'current_extensions' => $currentExtensions
        ]);
    }
}
