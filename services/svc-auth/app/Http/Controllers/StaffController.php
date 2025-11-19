<?php

namespace App\Http\Controllers;

use App\Http\Resources\UserResource;
use App\Models\User;
use App\Models\Vehicle;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;

/**
 * @OA\Tag(
 *     name="Staff",
 *     description="API endpoints for staff operations"
 * )
 */
class StaffController extends Controller
{
    /**
     * @OA\Get(
     *     path="/staff/search-user",
     *     tags={"Staff"},
     *     summary="Tìm user từ biển số xe",
     *     security={{"bearerAuth":{}}},
     *     @OA\Parameter(
     *         name="license_plate",
     *         in="query",
     *         required=true,
     *         description="Biển số xe",
     *         @OA\Schema(type="string", example="30A-12345")
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="Tìm thấy user",
     *         @OA\JsonContent(
     *             type="object",
     *             @OA\Property(property="data", type="object",
     *                 @OA\Property(property="id", type="integer", example=1),
     *                 @OA\Property(property="name", type="string", example="Nguyễn Văn A"),
     *                 @OA\Property(property="email", type="string", example="user@example.com"),
     *                 @OA\Property(property="phone", type="string", example="0123456789"),
     *                 @OA\Property(property="license_plate", type="string", example="30A-12345")
     *             )
     *         )
     *     ),
     *     @OA\Response(
     *         response=404,
     *         description="Không tìm thấy user",
     *         @OA\JsonContent(
     *             @OA\Property(property="message", type="string", example="Không tìm thấy người dùng với biển số này")
     *         )
     *     ),
     *     @OA\Response(
     *         response=403,
     *         description="Không có quyền truy cập"
     *     )
     * )
     */
    public function searchUserByLicensePlate(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'license_plate' => 'required|string',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => 'Validation error',
                'errors' => $validator->errors()
            ], 422);
        }

        try {
            $licensePlate = $request->input('license_plate');

            // Tìm vehicle theo biển số
            $vehicle = Vehicle::where('license_plate', $licensePlate)
                ->where('is_active', true)
                ->with('user:id,name,email,phone')
                ->first();

            if (!$vehicle || !$vehicle->user) {
                return response()->json([
                    'message' => 'Không tìm thấy người dùng với biển số này'
                ], 404);
            }

            return response()->json([
                'data' => [
                    'id' => $vehicle->user->id,
                    'name' => $vehicle->user->name,
                    'email' => $vehicle->user->email,
                    'phone' => $vehicle->user->phone,
                    'license_plate' => $vehicle->license_plate,
                ]
            ], 200);

        } catch (\Throwable $e) {
            Log::error('Lỗi khi tìm user từ biển số: ', [
                'error' => $e->getMessage(),
                'license_plate' => $request->input('license_plate')
            ]);
            return response()->json([
                'message' => 'Đã xảy ra lỗi, vui lòng thử lại sau.'
            ], 500);
        }
    }
}