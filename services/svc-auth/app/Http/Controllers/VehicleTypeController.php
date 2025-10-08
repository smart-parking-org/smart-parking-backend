<?php

namespace App\Http\Controllers;

use App\Http\Requests\VehicleType\VehicleTypeStoreRequest;
use App\Http\Requests\VehicleType\VehicleTypeUpdateRequest;
use App\Http\Resources\VehicleTypeResource;
use App\Models\VehicleType;
use Illuminate\Http\Request;

class VehicleTypeController extends Controller
{
    /**
     * @OA\Get(
     *     path="/vehicle-types",
     *     tags={"Vehicle Types"},
     *     summary="List all vehicle types",
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
     *         description="OK",
     *         @OA\JsonContent(
     *             type="object",
     *             @OA\Property(
     *                 property="data",
     *                 type="array",
     *                 @OA\Items(ref="#/components/schemas/VehicleType")
     *             ),
     *             @OA\Property(
     *                 property="pagination",
     *                 ref="#/components/schemas/Pagination"
     *             ),
     *         )
     *     ),
     * )
     */
    public function index(Request $request)
    {
        $perPage = (int) $request->query('limit', 10);
        $perPage = min($perPage, 100);

        $query = VehicleType::query()
            ->when($request->filled('is_active'), fn($q) => $q->where('is_active', $request->boolean('is_active')))
            ->when($request->filled('search'), fn($q) => $q->where(function ($qq) use ($request) {
                $s = $request->input('search');
                $qq->where('name', 'like', "%$s%")
                    ->orWhere('code', 'like', "%$s%");
            }))->orderByDesc('id');

        $types = $query->paginate($perPage)->appends($request->query());

        return response()->json([
            'data' => VehicleTypeResource::collection($types),
            'pagination' => [
                'total' => $types->total(),
                'per_page' => $types->perPage(),
                'current_page' => $types->currentPage(),
                'last_page' => $types->lastPage(),
            ],
        ]);
    }

    /**
     * @OA\Post(
     *   path="/vehicle-types",
     *   tags={"Vehicle Types"},
     *   summary="Create a vehicle type",
     *   security={{"bearerAuth":{}}},
     *   @OA\RequestBody(
     *     required=true,
     *     @OA\JsonContent(ref="#/components/schemas/VehicleTypeCreate")
     *   ),
     *   @OA\Response(
     *     response=201,
     *     description="Created",
     *     @OA\JsonContent(
     *       @OA\Property(property="data", ref="#/components/schemas/VehicleType")
     *     )
     *   ),
     *   @OA\Response(
     *     response=422,
     *     description="Validation error",
     *     @OA\JsonContent(ref="#/components/schemas/VehicleTypeValidationError")
     *   )
     * )
     */
    public function store(VehicleTypeStoreRequest $request)
    {
        $vt = VehicleType::create($request->validated());
        return (new VehicleTypeResource($vt))->response()->setStatusCode(201);
    }

    /**
     * @OA\Get(
     *   path="/vehicle-types/{id}",
     *   tags={"Vehicle Types"},
     *   summary="Get a vehicle type by id",
     *   @OA\Parameter(
     *     name="id", in="path", required=true, description="VehicleType ID",
     *     @OA\Schema(type="integer", example=1)
     *   ),
     *   @OA\Response(
     *     response=200,
     *     description="OK",
     *     @OA\JsonContent(
     *        @OA\Property(property="data", ref="#/components/schemas/VehicleType")
     *     )
     *   ),
     *   @OA\Response(
     *     response=404,
     *     description="Not Found",
     *     @OA\JsonContent(type="object",
     *       @OA\Property(property="message", type="string", example="Vehicle type not found")
     *     )
     *   )
     * )
     */
    public function show($id)
    {
        $vt = VehicleType::find($id);
        if (!$vt) {
            return response()->json(['message' => 'Vehicle type not found'], 404);
        }

        return new VehicleTypeResource($vt);
    }

    /**
     * @OA\Patch(
     *   path="/vehicle-types/{id}",
     *   tags={"Vehicle Types"},
     *   summary="Update a vehicle type (partial)",
     *   security={{"bearerAuth":{}}},
     *   @OA\Parameter(
     *     name="id", in="path", required=true, description="VehicleType ID",
     *     @OA\Schema(type="integer", example=1)
     *   ),
     *   @OA\RequestBody(
     *     required=true,
     *     @OA\JsonContent(ref="#/components/schemas/VehicleTypeUpdate")
     *   ),
     *   @OA\Response(
     *     response=200,
     *     description="OK",
     *     @OA\JsonContent(
     *        @OA\Property(property="data", ref="#/components/schemas/VehicleType")
     *      )
     *   ),
     *   @OA\Response(
     *     response=404,
     *     description="Not Found",
     *     @OA\JsonContent(type="object",
     *       @OA\Property(property="message", type="string", example="Vehicle type not found")
     *     )
     *   ),
     *   @OA\Response(
     *     response=422,
     *     description="Validation error",
     *     @OA\JsonContent(ref="#/components/schemas/VehicleTypeValidationError")
     *   )
     * )
     */
    public function update(VehicleTypeUpdateRequest $request, string $id)
    {
        $vt = VehicleType::find($id);
        if (!$vt) {
            return response()->json(['message' => 'Vehicle type not found'], 404);
        }

        $vt->update($request->validated());
        return new VehicleTypeResource($vt->refresh());
    }

    /**
     * @OA\Delete(
     *   path="/vehicle-types/{id}",
     *   tags={"Vehicle Types"},
     *   summary="Delete a vehicle type",
     *   security={{"bearerAuth":{}}},
     *   @OA\Parameter(
     *     name="id", in="path", required=true, description="VehicleType ID",
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
     *       @OA\Property(property="message", type="string", example="Vehicle type not found")
     *     )
     *   ),
     *   @OA\Response(
     *     response=409,
     *     description="Conflict",
     *     @OA\JsonContent(
     *       type="object",
     *       example={"message": "Cannot delete: resource is referenced by other records."}
     *     )
     *   )
     * )
     */
    public function destroy(string $id)
    {
        $vt = VehicleType::find($id);
        if (!$vt) {
            return response()->json(['message' => 'Vehicle type not found'], 404);
        }

        $vt->delete();
        return response()->noContent();
    }

    /**
     * @OA\Post(
     *   path="/admin/vehicle-types/{id}/activate",
     *   tags={"Admin"},
     *   summary="Activate a vehicle type",
     *   security={{"bearerAuth":{}}},
     *   @OA\Parameter(name="id", in="path", required=true, @OA\Schema(type="integer", example=5)),
     *   @OA\Response(response=200, description="OK",
     *     @OA\JsonContent(type="object", @OA\Property(property="message", example="Vehicle type activated successfully."))
     *   ),
     *   @OA\Response(response=404, description="Not Found",
     *     @OA\JsonContent(type="object", example={"message":"Vehicle type not found"}))
     * )
     */
    public function activate(string $id)
    {
        $vt = VehicleType::find($id);
        if (!$vt) {
            return response()->json(['message' => 'Vehicle type not found'], 404);
        }

        if (!$vt->is_active) {
            $vt->is_active = true;
            $vt->save();
        }

        return response()->json(['message' => 'Vehicle type activated successfully.']);
    }

    /**
     * @OA\Post(
     *   path="/admin/vehicle-types/{id}/deactivate",
     *   tags={"Admin"},
     *   summary="Deactivate a vehicle type",
     *   security={{"bearerAuth":{}}},
     *   @OA\Parameter(name="id", in="path", required=true, @OA\Schema(type="integer", example=5)),
     *   @OA\Response(response=200, description="OK",
     *     @OA\JsonContent(type="object", @OA\Property(property="message", example="Vehicle type deactivated successfully."))
     *   ),
     *   @OA\Response(response=404, description="Not Found",
     *     @OA\JsonContent(type="object", example={"message":"Vehicle type not found"}))
     * )
     */
    public function deactivate(string $id)
    {
        $vt = VehicleType::find($id);
        if (!$vt) {
            return response()->json(['message' => 'Vehicle type not found'], 404);
        }

        if ($vt->is_active) {
            $vt->is_active = false;
            $vt->save();
        }

        return response()->json(['message' => 'Vehicle type deactivated successfully.']);
    }
}
