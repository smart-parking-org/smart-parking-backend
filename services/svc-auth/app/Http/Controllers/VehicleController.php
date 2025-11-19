<?php

namespace App\Http\Controllers;

use App\Http\Requests\Vehicle\VehicleStoreRequest;
use App\Http\Requests\Vehicle\VehicleUpdateRequest;
use App\Http\Resources\VehicleResource;
use App\Models\Vehicle;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class VehicleController extends Controller
{

    /**
     * @OA\Get(
     *     path="/vehicles",
     *     tags={"Vehicles"},
     *     summary="Danh sách phương tiện",
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
     *         name="search",
     *         in="query",
     *         description="Từ khóa tìm kiếm theo name/code",
     *         required=false,
     *         @OA\Schema(type="string", example="")
     *     ),
     *     @OA\Parameter(
     *         name="is_active",
     *         in="query",
     *         description="Lọc theo trạng thái hoạt động (true/false hoặc 1/0)",
     *         required=false,
     *         @OA\Schema(type="boolean", example=true)
     *     ),
     *     @OA\Parameter(
     *         name="vehicle_type",
     *         in="query",
     *         description="Lọc theo loại xe: 'motorbike', 'car_4_seat', 'car_7_seat', 'light_truck'",
     *         required=false,
     *         @OA\Schema(
     *              type="string",
     *              enum={"motorbike", "car_4_seat", "car_7_seat", "light_truck"}
     *         )
     *     ),
     *     @OA\Parameter(
     *         name="status",
     *         in="query",
     *         description="Lọc theo status: 'pending', 'approved', 'rejected'",
     *         required=false,
     *         @OA\Schema(
     *              type="string",
     *              enum={"pending", "approved", "rejected"}
     *         )
     *     ),
     *     @OA\Parameter(
     *         name="user_id",
     *         in="query",
     *         description="Lọc theo người dùng",
     *         required=false,
     *         @OA\Schema(type="integer", example="")
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="Danh sách phương tiện",
     *         @OA\JsonContent(
     *             type="object",
     *             @OA\Property(
     *                 property="data",
     *                 type="array",
     *                 @OA\Items(ref="#/components/schemas/Vehicle")
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

        try {
            $query = Vehicle::query()
                ->when(
                    $request->filled('is_active'),
                    fn($q) => $q->where('is_active', $request->boolean('is_active'))
                )
                ->when(
                    $request->filled('status'),
                    fn($q) => $q->where('status', $request->query('status'))
                )
                ->when(
                    $request->filled('vehicle_type'),
                    fn($q) => $q->where('vehicle_type', $request->query('vehicle_type'))
                )
                ->when($request->filled('user_id'), fn($q) => $q->where('user_id', $request->query('user_id')))

                ->when($request->filled('search'), function ($q) use ($request) {
                    $s = $request->input('search');
                    $q->where(function ($qq) use ($s) {
                        $qq->where('license_plate', 'like', "%$s%")
                            ->orWhereHas('user', fn($uq) =>
                                $uq->where('name', 'like', "%$s%"));
                    });
                })
                ->with(['user:id,name,email,phone'])
                ->orderByDesc('id');
            $vehicles = $query->paginate($perPage)->appends($request->query());
        } catch (\Throwable $e) {
            Log::error('Lỗi khi lấy danh sách phương tiện: ', ['error' => $e->getMessage()]);
            return response()->json([
                'message' => 'Đã xảy ra lỗi, vui lòng thử lại sau.'
            ], 500);
        }

        return response()->json([
            'data' => VehicleResource::collection($vehicles->items()),
            'pagination' => [
                'total' => $vehicles->total(),
                'per_page' => $vehicles->perPage(),
                'current_page' => $vehicles->currentPage(),
                'last_page' => $vehicles->lastPage()
            ]
        ]);
    }

    /**
     * @OA\Post(
     *   path="/vehicles",
     *   tags={"Vehicles"},
     *   summary="Tạo phương tiện mới (vehicle_type=['motorbike', 'car_4_seat', 'car_7_seat', 'light_truck'])",
     *   @OA\RequestBody(
     *     required=true,
     *     @OA\JsonContent(
     *       required={"user_id","vehicle_type","license_plate"},
     *       @OA\Property(property="user_id", type="integer", example=1),
     *       @OA\Property(property="vehicle_type", type="string", example="motorbike"),
     *       @OA\Property(property="license_plate", type="string", example="94K-123.48"),
     *     )
     *   ),
     *   @OA\Response(
     *     response=201,
     *     description="Created",
     *     @OA\JsonContent(ref="#/components/schemas/Vehicle")
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
     *           property="license_plate",
     *           type="array",
     *           @OA\Items(type="string", example="The license plate has already been taken.")
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
    public function store(VehicleStoreRequest $request)
    {
        $data = $request->validated();
        try {
            $vehicle = Vehicle::create([
                'user_id' => $data['user_id'],
                'vehicle_type' => $data['vehicle_type'],
                'license_plate' => $data['license_plate']
            ]);
        } catch (\Throwable $e) {
            Log::error('Lỗi khi thêm phương tiện mới: ', ['error' => $e->getMessage()]);
            return response()->json([
                'message' => 'Đã xảy ra lỗi, vui lòng thử lại sau.'
            ], 500);
        }
        return (new VehicleResource($vehicle->refresh()))->response()->setStatusCode(201);
    }

    /**
     * @OA\Get(
     *   path="/vehicles/{id}",
     *   tags={"Vehicles"},
     *   summary="Lấy thông tin chi tiết phương tiện",
     *   @OA\Parameter(
     *     name="id", in="path", required=true, description="Vehicle ID",
     *     @OA\Schema(type="integer", example=1)
     *   ),
     *   @OA\Response(
     *     response=200,
     *     description="OK",
     *     @OA\JsonContent(
     *        @OA\Property(property="data", ref="#/components/schemas/Vehicle")
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
            $vehicle = Vehicle::with('user:id,name,email,phone')->find($id);
        } catch (\Throwable $e) {
            Log::error('Lỗi khi xem chi tiết phương tiện mới: ', ['error' => $e->getMessage()]);
            return response()->json([
                'message' => 'Đã xảy ra lỗi, vui lòng thử lại sau.'
            ], 500);
        }
        if (!$vehicle) {
            return response()->json(['message' => 'Vehicle not found'], 404);
        }

        return new VehicleResource($vehicle);
    }

    /**
     * @OA\Patch(
     *   path="/vehicles/{id}",
     *   tags={"Vehicles"},
     *   summary="Cập nhật thông tin phương tiện",
     *   @OA\Parameter(
     *     name="id", in="path", required=true, description="Vehicle ID",
     *     @OA\Schema(type="integer", example=1)
     *   ),
     *   @OA\RequestBody(
     *     required=false,
     *     @OA\JsonContent(
     *       @OA\Property(property="vehicle_type", type="string", example="motorbike"),
     *       @OA\Property(property="license_plate", type="string", example="94K-123.48"),
     *     )
     *   ),
     *   @OA\Response(
     *     response=201,
     *     description="Created",
     *     @OA\JsonContent(ref="#/components/schemas/Vehicle")
     *   ),
     *   @OA\Response(
     *     response=404,
     *     description="Not Found",
     *     @OA\JsonContent(type="object",
     *       @OA\Property(property="message", type="string", example="Vehicle not found")
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
     *           property="license_plate",
     *           type="array",
     *           @OA\Items(type="string", example="The license plate has already been taken.")
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
    public function update(VehicleUpdateRequest $request, string $id)
    {
        try {
            $vehicle = Vehicle::find($id);
            if (!$vehicle) {
                return response()->json(['message' => 'Vehicle not found'], 404);
            }

            $vehicle->update($request->validated());
        } catch (\Throwable $e) {
            Log::error('Lỗi khi cập nhật phương tiện: ', ['error' => $e->getMessage()]);
            return response()->json([
                'message' => 'Đã xảy ra lỗi, vui lòng thử lại sau.'
            ], 500);
        }

        return new VehicleResource($vehicle->refresh());
    }

    /**
     * @OA\Delete(
     *   path="/vehicles/{id}",
     *   tags={"Vehicles"},
     *   summary="Xóa phương tiện",
     *   @OA\Parameter(
     *     name="id", in="path", required=true, description="Vehicle ID",
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
     *       @OA\Property(property="message", type="string", example="Vehicle not found")
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
            $vehicle = Vehicle::find($id);
            if (!$vehicle) {
                return response()->json(['message' => 'Vehicle not found'], 404);
            }

            if ($vehicle->is_primary) {
                $another = Vehicle::where('user_id', $vehicle->user_id)
                    ->where('is_active', true)
                    ->where('id', '!=', $vehicle->id)
                    ->first();
                if ($another) {
                    $another->update(['is_primary' => true]);
                }
            }
            $vehicle->delete();
        } catch (\Throwable $e) {
            Log::error('Lỗi khi xóa phương tiện: ', ['error' => $e->getMessage()]);
            return response()->json([
                'message' => 'Đã xảy ra lỗi, vui lòng thử lại sau.'
            ], 500);
        }

        return response()->noContent();
    }

    /**
     * @OA\Post(
     *   path="/vehicles/primary/{id}",
     *   tags={"Vehicles"},
     *   summary="Cập nhật phương tiện trở thành phương tiện chính",
     *   @OA\Parameter(
     *     name="id", in="path", required=true, description="Vehicle ID",
     *     @OA\Schema(type="integer", example=1)
     *   ),
     *   @OA\Response(
     *     response=200,
     *     description="Success",
     *     @OA\JsonContent(ref="#/components/schemas/Vehicle")
     *   ),
     *   @OA\Response(
     *     response=404,
     *     description="Not Found",
     *     @OA\JsonContent(type="object",
     *       @OA\Property(property="message", type="string", example="Vehicle not found")
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
    public function setPrimary(string $id)
    {
        try {
            $vehicle = Vehicle::find($id);
            if (!$vehicle) {
                return response()->json(['message' => 'Vehicle not found'], 404);
            }

            if ($vehicle->is_active && !$vehicle->is_primary) {
                // Reset tất cả xe của user này về false
                Vehicle::where('user_id', $vehicle->user_id)->update(['is_primary' => false]);
                $vehicle->update(['is_primary' => true]);
            }
        } catch (\Throwable $e) {
            Log::error('Lỗi khi set phương tiện mặc định: ', ['error' => $e->getMessage()]);
            return response()->json([
                'message' => 'Đã xảy ra lỗi, vui lòng thử lại sau.'
            ], 500);
        }

        return new VehicleResource($vehicle->refresh());
    }

    /**
     * @OA\Post(
     *   path="/vehicles/toggle-active/{id}",
     *   tags={"Vehicles"},
     *   summary="Cập nhật trạng thái phương tiện",
     *   @OA\Parameter(
     *     name="id", in="path", required=true, description="Vehicle ID",
     *     @OA\Schema(type="integer", example=1)
     *   ),
     *   @OA\Response(
     *     response=200,
     *     description="Success",
     *     @OA\JsonContent(ref="#/components/schemas/Vehicle")
     *   ),
     *   @OA\Response(
     *     response=404,
     *     description="Not Found",
     *     @OA\JsonContent(type="object",
     *       @OA\Property(property="message", type="string", example="Vehicle not found")
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
    public function toogleActive(string $id)
    {
        try {
            $vehicle = Vehicle::find($id);
            if (!$vehicle) {
                return response()->json(['message' => 'Vehicle not found'], 404);
            }

            $vehicle->is_active = !$vehicle->is_active;
            $vehicle->save();

            if (!$vehicle->is_active && $vehicle->is_primary) {
                $vehicle->update(['is_primary' => false]);
                $another = Vehicle::where('user_id', $vehicle->user_id)
                    ->where('is_active', true)
                    ->where('id', '!=', $vehicle->id)
                    ->first();
                if ($another) {
                    $another->update(['is_primary' => true]);
                }
            }
        } catch (\Throwable $e) {
            Log::error('Lỗi khi toggle active phương tiện: ', ['error' => $e->getMessage()]);
            return response()->json([
                'message' => 'Đã xảy ra lỗi, vui lòng thử lại sau.'
            ], 500);
        }

        return new VehicleResource($vehicle->refresh());
    }

    /**
     * @OA\Post(
     *   path="/vehicles/{id}/review",
     *   security={{"bearerAuth":{}}},
     *   tags={"Vehicles"},
     *   summary="Admin duyệt hoặc từ chối phương tiện",
     *   @OA\Parameter(
     *     name="id", in="path", required=true, description="Vehicle ID",
     *     @OA\Schema(type="integer", example=1)
     *   ),
     *   @OA\RequestBody(
     *     required=true,
     *     @OA\JsonContent(
     *       required={"status"},
     *       @OA\Property(property="status", type="string", enum={"approved","rejected"}, example="approved")
     *     )
     *   ),
     *   @OA\Response(response=200, description="Success", @OA\JsonContent(ref="#/components/schemas/Vehicle")),
     *   @OA\Response(response=404, description="Not Found"),
     *   @OA\Response(response=422, description="Validation error"),
     *   @OA\Response(response=500, description="Lỗi máy chủ")
     * )
     */
    public function review(Request $request, string $id)
    {
        $data = $request->validate([
            'status' => 'required|in:approved,rejected'
        ]);

        try {
            $vehicle = Vehicle::find($id);
            if (!$vehicle) {
                return response()->json(['message' => 'Vehicle not found'], 404);
            }

            $vehicle->status = $data['status'];

            if ($data['status'] === 'approved') {
                $vehicle->is_active = true;
            }

            $vehicle->save();
        } catch (\Throwable $e) {
            Log::error('Lỗi khi duyệt phương tiện: ', ['error' => $e->getMessage()]);
            return response()->json([
                'message' => 'Đã xảy ra lỗi, vui lòng thử lại sau.'
            ], 500);
        }

        return new VehicleResource($vehicle->refresh());
    }

    /**
     * @OA\Post(
     *   path="/vehicles/{id}/resubmit",
     *   tags={"Vehicles"},
     *   summary="Người dùng yêu cầu phê duyệt lại phương tiện sau khi bị từ chối",
     *   @OA\Parameter(
     *     name="id", in="path", required=true, description="Vehicle ID",
     *     @OA\Schema(type="integer", example=1)
     *   ),
     *   @OA\Response(
     *     response=200,
     *     description="Success",
     *     @OA\JsonContent(
     *       @OA\Property(property="message", type="string", example="Đã gửi yêu cầu phê duyệt lại thành công"),
     *       @OA\Property(property="data", ref="#/components/schemas/Vehicle")
     *     )
     *   ),
     *   @OA\Response(
     *     response=404,
     *     description="Not Found",
     *     @OA\JsonContent(type="object",
     *       @OA\Property(property="message", type="string", example="Vehicle not found")
     *     )
     *   ),
     *   @OA\Response(
     *     response=400,
     *     description="Bad Request",
     *     @OA\JsonContent(type="object",
     *       @OA\Property(property="message", type="string", example="Phương tiện này không ở trạng thái bị từ chối")
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
    public function resubmit(string $id)
    {
        try {
            $vehicle = Vehicle::find($id);
            if (!$vehicle) {
                return response()->json(['message' => 'Vehicle not found'], 404);
            }

            // if ($vehicle->status !== 'rejected') {
            //     return response()->json([
            //         'message' => 'Phương tiện này không ở trạng thái bị từ chối'
            //     ], 400);
            // }

            $vehicle->status = 'pending';
            $vehicle->save();
        } catch (\Throwable $e) {
            Log::error('Lỗi khi yêu cầu phê duyệt lại phương tiện: ', ['error' => $e->getMessage()]);
            return response()->json([
                'message' => 'Đã xảy ra lỗi, vui lòng thử lại sau.'
            ], 500);
        }

        return response()->json([
            'message' => 'Đã gửi yêu cầu phê duyệt lại thành công',
            'data' => new VehicleResource($vehicle->refresh())
        ]);
    }
}
