<?php

namespace App\Http\Controllers;

use App\Http\Requests\ParkingZone\ParkingZoneStoreRequest;
use App\Http\Requests\ParkingZone\ParkingZoneUpdateRequest;
use App\Http\Resources\ParkingZoneResource;
use App\Models\ParkingZone;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class ParkingZoneController extends Controller
{
    /**
     * @OA\Get(
     *     path="/parking-zones",
     *     tags={"Parking Zones"},
     *     summary="Danh sách bãi đỗ xe",
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
     *     @OA\Response(
     *         response=200,
     *         description="Danh sách bãi đỗ",
     *         @OA\JsonContent(
     *             type="object",
     *             @OA\Property(
     *                 property="data",
     *                 type="array",
     *                 @OA\Items(ref="#/components/schemas/ParkingZone")
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
            $query = ParkingZone::query()
                ->when(
                    $request->filled('is_active'),
                    fn($q) =>
                    $q->where('is_active', $request->boolean('is_active'))
                )

                ->when($request->filled('search'), function ($q) use ($request) {
                    $s = $request->input('search');
                    $q->where(function ($qq) use ($s) {
                        $qq->where('name', 'like', "%$s%")
                            ->orWhere('code', 'like', "$s%");
                    });
                })->orderByDesc('id');
            $parkingZones = $query->paginate($perPage)->appends($request->query());
        } catch (\Throwable $e) {
            Log::error('Lỗi khi lấy danh sách vùng đậu xe: ', ['error' => $e->getMessage()]);
            return response()->json(['message' => 'Đã xảy ra lỗi, vui lòng thử lại sau.'], 500);
        }
        return response()->json([
            'data' => ParkingZoneResource::collection($parkingZones->items()),
            'pagination' => [
                'total' => $parkingZones->total(),
                'per_page' => $parkingZones->perPage(),
                'current_page' => $parkingZones->currentPage(),
                'last_page' => $parkingZones->lastPage()
            ]
        ]);
    }

    /**
     * @OA\Post(
     *   path="/parking-zones",
     *   tags={"Parking Zones"},
     *   summary="Tạo bãi mới",
     *   @OA\RequestBody(
     *     required=true,
     *     @OA\JsonContent(
     *       required={"code","name"},
     *       @OA\Property(property="name", type="string", example="Basement 1"),
     *       @OA\Property(property="code", type="string", example="B1"),
     *       @OA\Property(property="capacity", type="int", example=10),
     *       @OA\Property(property="description", type="string", example="exmaple"),
     *       @OA\Property(property="is_active", type="boolean", example=true),
     *     )
     *   ),
     *   @OA\Response(
     *     response=201,
     *     description="Created",
     *     @OA\JsonContent(ref="#/components/schemas/ParkingZone")
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
     *           property="code",
     *           type="array",
     *           @OA\Items(type="string", example="The code has already been taken.")
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
    public function store(ParkingZoneStoreRequest $request)
    {
        try {
            $parkingZones = ParkingZone::create($request->validated())->refresh();
        } catch (\Throwable $e) {
            Log::error('Lỗi khi thêm bãi mới: ', ['error' => $e->getMessage()]);
            return response()->json([
                'message' => 'Đã xảy ra lỗi, vui lòng thử lại sau.'
            ], 500);
        }
        return (new ParkingZoneResource($parkingZones))->response()->setStatusCode(201);
    }

    /**
     * @OA\Get(
     *   path="/parking-zones/{id}",
     *   tags={"Parking Zones"},
     *   summary="Xem chi tiết bãi đỗ",
     *   @OA\Parameter(
     *     name="id", in="path", required=true, description="Parking zone ID",
     *     @OA\Schema(type="integer", example=1)
     *   ),
     *   @OA\Response(
     *     response=200,
     *     description="OK",
     *     @OA\JsonContent(
     *        @OA\Property(property="data", ref="#/components/schemas/ParkingZone")
     *     )
     *   ),
     *   @OA\Response(
     *     response=404,
     *     description="Not Found",
     *     @OA\JsonContent(type="object",
     *       @OA\Property(property="message", type="string", example="Không tìm thấy bãi đỗ")
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
            $pz = ParkingZone::with(['slots' => fn($q) => $q->orderByDesc('id')])->find($id);
        } catch (\Throwable $e) {
            Log::error('Lỗi khi xem chi tiết bãi đỗ: ', ['error' => $e->getMessage()]);
            return response()->json([
                'message' => 'Đã xảy ra lỗi, vui lòng thử lại sau.'
            ], 500);
        }
        if (!$pz) {
            return response()->json(['message' => 'Không tìm thấy bãi đỗ'], 404);
        }

        return new ParkingZoneResource($pz);
    }

    /**
     * @OA\Patch(
     *   path="/parking-zones/{id}",
     *   tags={"Parking Zones"},
     *   summary="Cập nhật thông tin bãi đỗ",
     *   @OA\Parameter(
     *     name="id", in="path", required=true, description="Parking zone ID",
     *     @OA\Schema(type="integer", example=1)
     *   ),
     *   @OA\RequestBody(
     *     required=false,
     *     @OA\JsonContent(
     *       @OA\Property(property="name", type="string", example="Basement 1"),
     *       @OA\Property(property="code", type="string", example="B1"),
     *       @OA\Property(property="capacity", type="int", example=10),
     *       @OA\Property(property="description", type="string", example="exmaple"),
     *       @OA\Property(property="is_active", type="boolean", example=true),
     *     )
     *   ),
     *   @OA\Response(
     *     response=200,
     *     description="Cập nhật thành công",
     *     @OA\JsonContent(ref="#/components/schemas/ParkingZone")
     *   ),
     *   @OA\Response(
     *     response=404,
     *     description="Not Found",
     *     @OA\JsonContent(type="object",
     *       @OA\Property(property="message", type="string", example="Không tìm thấy bãi đỗ")
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
     *           property="code",
     *           type="array",
     *           @OA\Items(type="string", example="The code has already been taken.")
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
    public function update(ParkingZoneUpdateRequest $request, string $id)
    {
        try {
            $parkingZone = ParkingZone::find($id);
            if (!$parkingZone) {
                return response()->json(['message' => 'Không tìm thấy bãi đỗ'], 404);
            }

            $parkingZone->update($request->validated());
        } catch (\Throwable $e) {
            Log::error('Lỗi khi cập nhật bãi đỗ: ', ['error' => $e->getMessage()]);
            return response()->json([
                'message' => 'Đã xảy ra lỗi, vui lòng thử lại sau.'
            ], 500);
        }

        return new ParkingZoneResource($parkingZone->refresh());
    }

    /**
     * @OA\Delete(
     *   path="/parking-zones/{id}",
     *   tags={"Parking Zones"},
     *   summary="Xóa bãi đỗ",
     *   @OA\Parameter(
     *     name="id", in="path", required=true, description="Parking zone ID",
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
     *       @OA\Property(property="message", type="string", example="Không tìm thấy bãi đỗ")
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
            $parkingZone = ParkingZone::find($id);
            if (!$parkingZone) {
                return response()->json(['message' => 'Không tìm thấy bãi đỗ'], 404);
            }
            $parkingZone->delete();
        } catch (\Throwable $e) {
            Log::error('Lỗi khi xóa bãi đỗ: ', ['error' => $e->getMessage()]);
            return response()->json([
                'message' => 'Đã xảy ra lỗi, vui lòng thử lại sau.'
            ], 500);
        }

        return response()->noContent();
    }

    /**
     * @OA\Post(
     *   path="/parking-zones/toggle-active/{id}",
     *   tags={"Parking Zones"},
     *   summary="Cập nhật trạng thái bãi đỗ",
     *   @OA\Parameter(
     *     name="id", in="path", required=true, description="Parking zone ID",
     *     @OA\Schema(type="integer", example=1)
     *   ),
     *   @OA\Response(
     *     response=200,
     *     description="Success",
     *     @OA\JsonContent(ref="#/components/schemas/ParkingZone")
     *   ),
     *   @OA\Response(
     *     response=404,
     *     description="Not Found",
     *     @OA\JsonContent(type="object",
     *       @OA\Property(property="message", type="string", example="Không tìm thấy bãi đỗ")
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
            $parkingZone = ParkingZone::find($id);
            if (!$parkingZone) {
                return response()->json(['message' => 'Không tìm thấy bãi đỗ'], 404);
            }

            $parkingZone->is_active = !$parkingZone->is_active;
            $parkingZone->save();
        } catch (\Throwable $e) {
            Log::error('Lỗi khi toggle active bãi đỗ: ', ['error' => $e->getMessage()]);
            return response()->json([
                'message' => 'Đã xảy ra lỗi, vui lòng thử lại sau.'
            ], 500);
        }

        return new ParkingZoneResource($parkingZone->refresh());
    }
}
