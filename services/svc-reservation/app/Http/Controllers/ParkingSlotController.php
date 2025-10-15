<?php

namespace App\Http\Controllers;

use App\Http\Requests\ParkingSlot\ParkingSlotStoreRequest;
use App\Http\Requests\ParkingSlot\ParkingSlotUpdateRequest;
use App\Http\Resources\ParkingSlotResource;
use App\Models\ParkingSlot;
use App\Models\ParkingZone;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class ParkingSlotController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index()
    {
        //
    }

    /**
     * @OA\Post(
     *   path="/parking-slots",
     *   tags={"Parking Slots"},
     *   summary="Tạo slot mới cho bãi",
     *   @OA\RequestBody(
     *     required=true,
     *     @OA\JsonContent(
     *       @OA\Property(property="zone_id", type="int", example=1),
     *       @OA\Property(property="code", type="string", example="A-MB-001"),
     *       @OA\Property(property="vehicle_type", type="string", example="motorbike"),
     *       @OA\Property(property="status", type="string", example="available"),
     *       @OA\Property(property="is_active", type="boolean", example=true),
     *     )
     *   ),
     *   @OA\Response(
     *     response=201,
     *     description="Created",
     *     @OA\JsonContent(ref="#/components/schemas/ParkingSlot")
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
     *           @OA\Items(type="string", example="The code field is required.")
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
    public function store(ParkingSlotStoreRequest $request)
    {
        try {
            DB::beginTransaction();
            $parkingSlot = ParkingSlot::create($request->validated())->refresh();
            $zone = ParkingZone::findOrFail($parkingSlot->zone_id);
            if ($zone) {
                $zone->capacity += 1;
                $zone->save();
            }
            DB::commit();
        } catch (\Throwable $e) {
            DB::rollBack();
            Log::error('Lỗi khi thêm slot mới: ', ['error' => $e->getMessage()]);
            return response()->json([
                'message' => 'Đã xảy ra lỗi, vui lòng thử lại sau.'
            ], 500);
        }
        return (new ParkingSlotResource($parkingSlot))->response()->setStatusCode(201);
    }

    /**
     * @OA\Get(
     *   path="/parking-slots/{id}",
     *   tags={"Parking Slots"},
     *   summary="Xem chi tiết slot",
     *   @OA\Parameter(
     *     name="id", in="path", required=true, description="Slot ID",
     *     @OA\Schema(type="integer", example=1)
     *   ),
     *   @OA\Response(
     *     response=200,
     *     description="OK",
     *     @OA\JsonContent(
     *        @OA\Property(property="data", ref="#/components/schemas/ParkingSlot")
     *     )
     *   ),
     *   @OA\Response(
     *     response=404,
     *     description="Not Found",
     *     @OA\JsonContent(type="object",
     *       @OA\Property(property="message", type="string", example="Không tìm thấy slot")
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
            $pl = ParkingSlot::find($id);
        } catch (\Throwable $e) {
            Log::error('Lỗi khi xem chi tiết bãi đỗ: ', ['error' => $e->getMessage()]);
            return response()->json([
                'message' => 'Đã xảy ra lỗi, vui lòng thử lại sau.'
            ], 500);
        }
        if (!$pl) {
            return response()->json(['message' => 'Không tìm thấy slot'], 404);
        }

        return new ParkingSlotResource($pl);
    }

    /**
     * @OA\Patch(
     *   path="/parking-slots/{id}",
     *   tags={"Parking Slots"},
     *   summary="Cập nhật thông tin slot đỗ",
     *   @OA\Parameter(
     *     name="id", in="path", required=true, description="Slot ID",
     *     @OA\Schema(type="integer", example=1)
     *   ),
     *   @OA\RequestBody(
     *     required=false,
     *     @OA\JsonContent(
     *       @OA\Property(property="vehicle_type", type="string", example="motorbike"),
     *       @OA\Property(property="status", type="string", example="available"),
     *       @OA\Property(property="is_active", type="boolean", example=true),
     *     )
     *   ),
     *   @OA\Response(
     *     response=200,
     *     description="Cập nhật thành công",
     *     @OA\JsonContent(ref="#/components/schemas/ParkingSlot")
     *   ),
     *   @OA\Response(
     *     response=404,
     *     description="Not Found",
     *     @OA\JsonContent(type="object",
     *       @OA\Property(property="message", type="string", example="Không tìm thấy slot")
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
     *           property="status",
     *           type="array",
     *           @OA\Items(type="string", example="The selected status is invalid.")
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
    public function update(ParkingSlotUpdateRequest $request, string $id)
    {
        try {
            $parkingSlot = ParkingSlot::find($id);
            if (!$parkingSlot) {
                return response()->json(['message' => 'Không tìm thấy slot'], 404);
            }

            $parkingSlot->update($request->validated());
        } catch (\Throwable $e) {
            Log::error('Lỗi khi cập nhật slot: ', ['error' => $e->getMessage()]);
            return response()->json([
                'message' => 'Đã xảy ra lỗi, vui lòng thử lại sau.'
            ], 500);
        }

        return new ParkingSlotResource($parkingSlot->refresh());
    }

    /**
     * @OA\Delete(
     *   path="/parking-slots/{id}",
     *   tags={"Parking Slots"},
     *   summary="Xóa slot",
     *   @OA\Parameter(
     *     name="id", in="path", required=true, description="Slot ID",
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
     *       @OA\Property(property="message", type="string", example="Không tìm thấy slot")
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
            DB::beginTransaction();
            $parkingSlot = ParkingSlot::find($id);
            if (!$parkingSlot) {
                return response()->json(['message' => 'Không tìm thấy slot'], 404);
            }
            $parkingZone = ParkingZone::findOrFail($parkingSlot->zone_id);
            if ($parkingZone) {
                $parkingZone->capacity -= 1;
                $parkingZone->save();
            }
            $parkingSlot->delete();
            DB::commit();
        } catch (\Throwable $e) {
            DB::rollBack();
            Log::error('Lỗi khi xóa slot: ', ['error' => $e->getMessage()]);
            return response()->json([
                'message' => 'Đã xảy ra lỗi, vui lòng thử lại sau.'
            ], 500);
        }

        return response()->noContent();
    }

    /**
     * @OA\Post(
     *   path="/parking-slots/toggle-active/{id}",
     *   tags={"Parking Slots"},
     *   summary="Cập nhật trạng thái slot",
     *   @OA\Parameter(
     *     name="id", in="path", required=true, description="Slot ID",
     *     @OA\Schema(type="integer", example=1)
     *   ),
     *   @OA\Response(
     *     response=200,
     *     description="Success",
     *     @OA\JsonContent(ref="#/components/schemas/ParkingSlot")
     *   ),
     *   @OA\Response(
     *     response=404,
     *     description="Not Found",
     *     @OA\JsonContent(type="object",
     *       @OA\Property(property="message", type="string", example="Không tìm thấy slot")
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
            $parkingSlot = ParkingSlot::find($id);
            if (!$parkingSlot) {
                return response()->json(['message' => 'Không tìm thấy slot'], 404);
            }
            $parkingSlot->is_active = !$parkingSlot->is_active;
            $parkingSlot->save();
        } catch (\Throwable $e) {
            Log::error('Lỗi khi toggle active slot: ', ['error' => $e->getMessage()]);
            return response()->json([
                'message' => 'Đã xảy ra lỗi, vui lòng thử lại sau.'
            ], 500);
        }

        return new ParkingSlotResource($parkingSlot->refresh());
    }
}
