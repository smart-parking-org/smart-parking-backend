<?php

namespace App\Http\Controllers;

use App\Http\Requests\VehicleType\StoreVehicleTypeRequest;
use App\Http\Requests\VehicleType\UpdateVehicleTypeRequest;
use App\Models\VehicleType;
use Illuminate\Http\Request;

/**
 * @OA\Tag(
 *   name="Vehicle Types",
 *   description="CRUD vehicle types"
 * )
 */
class VehicleTypeController extends Controller
{
    /**
     * @OA\Get(
     *   path="/vehicle-types",
     *   tags={"Vehicle Types"},
     *   summary="List all vehicle types",
     *   description="Trả về danh sách vehicle types (sắp xếp id giảm dần)",
     *   @OA\Response(
     *     response=200,
     *     description="OK",
     *     @OA\JsonContent(
     *       type="array",
     *       @OA\Items(ref="#/components/schemas/VehicleType")
     *     )
     *   )
     * )
     */
    public function index()
    {
        $data = VehicleType::all()->sortByDesc('id')->values();
        return response()->json($data);
    }

    /**
     * @OA\Post(
     *   path="/vehicle-types",
     *   tags={"Vehicle Types"},
     *   summary="Create a vehicle type",
     *   @OA\RequestBody(
     *     required=true,
     *     @OA\JsonContent(ref="#/components/schemas/VehicleTypeCreate")
     *   ),
     *   @OA\Response(
     *     response=201,
     *     description="Created",
     *     @OA\JsonContent(
     *       type="object",
     *       @OA\Property(property="message", type="string", example="success"),
     *       @OA\Property(property="data", ref="#/components/schemas/VehicleType")
     *     )
     *   ),
     *   @OA\Response(response=422, description="Validation error")
     * )
     */
    public function store(StoreVehicleTypeRequest $request)
    {
        $vt = VehicleType::create($request->validated());
        return response()->json(['message' => 'success', 'data' => $vt], 201);
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
     *       type="object",
     *       @OA\Property(property="message", type="string", example="success"),
     *       @OA\Property(property="data", ref="#/components/schemas/VehicleType")
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

        return response()->json(['message' => 'success', 'data' => $vt]);
    }

    /**
     * @OA\Patch(
     *   path="/vehicle-types/{id}",
     *   tags={"Vehicle Types"},
     *   summary="Update a vehicle type (partial)",
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
     *       type="object",
     *       @OA\Property(property="message", type="string", example="success"),
     *       @OA\Property(property="data", ref="#/components/schemas/VehicleType")
     *     )
     *   ),
     *   @OA\Response(
     *     response=404,
     *     description="Not Found",
     *     @OA\JsonContent(type="object",
     *       @OA\Property(property="message", type="string", example="Vehicle type not found")
     *     )
     *   ),
     *   @OA\Response(response=422, description="Validation error")
     * )
     */
    public function update(UpdateVehicleTypeRequest $request, string $id)
    {
        $vt = VehicleType::find($id);
        if (!$vt) {
            return response()->json(['message' => 'Vehicle type not found'], 404);
        }

        $vt->update($request->validated());
        $vt->refresh();
        return response()->json(['message' => 'success', 'data' => $vt]);
    }

    /**
     * @OA\Delete(
     *   path="/vehicle-types/{id}",
     *   tags={"Vehicle Types"},
     *   summary="Soft delete a vehicle type",
     *   @OA\Parameter(
     *     name="id", in="path", required=true, description="VehicleType ID",
     *     @OA\Schema(type="integer", example=1)
     *   ),
     *   @OA\Response(
     *     response=200,
     *     description="OK",
     *     @OA\JsonContent(type="object",
     *       @OA\Property(property="message", type="string", example="success")
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
    public function destroy(string $id)
    {
        $vt = VehicleType::find($id);
        if (!$vt) {
            return response()->json(['message' => 'Vehicle type not found'], 404);
        }
        $vt->is_active = false;
        $vt->save();
        $vt->delete(); // soft delete
        return response()->json(['message' => 'success'], 200);
    }
}
