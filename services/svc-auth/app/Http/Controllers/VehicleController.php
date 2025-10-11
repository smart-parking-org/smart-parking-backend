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
     *         name="type_id",
     *         in="query",
     *         description="Lọc theo loại xe",
     *         required=false,
     *         @OA\Schema(type="integer", example="")
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
                    $request->filled('type_id'),
                    fn($q) => $q->where('type_id', $request->query('type_id'))
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
                ->with(['user:id,name,email,phone', 'type:id,name,code'])
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
     *   summary="Tạo phương tiện mới",
     *   @OA\RequestBody(
     *     required=true,
     *     @OA\JsonContent(
     *       required={"user_id","type_id","license_plate"},
     *       @OA\Property(property="user_id", type="integer", example=1),
     *       @OA\Property(property="type_id", type="integer", example=4),
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
        try {
            $vehicle = Vehicle::create($request->validated())->refresh();
        } catch (\Throwable $e) {
            Log::error('Lỗi khi thêm phương tiện mới: ', ['error' => $e->getMessage()]);
            return response()->json([
                'message' => 'Đã xảy ra lỗi, vui lòng thử lại sau.'
            ], 500);
        }
        return (new VehicleResource($vehicle))->response()->setStatusCode(201);
    }

    public function show(string $id)
    {
        try {
            $vehicle = Vehicle::find($id);
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
     *       @OA\Property(property="user_id", type="integer", example=1),
     *       @OA\Property(property="type_id", type="integer", example=4),
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
            if (!$vehicle->is_primary) {
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
}
