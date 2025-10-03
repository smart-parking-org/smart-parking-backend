<?php

namespace App\Http\Controllers;

use App\Enums\AccountStatus;
use App\Models\User;
use Illuminate\Http\Request;

class UserApprovalController extends Controller
{
    /**
     * @OA\Patch(
     *   path="/admin/users/{id}/approve",
     *   summary="Admin approve user",
     *   tags={"Admin"},
     *   security={{"bearerAuth":{}}},
     *   @OA\Parameter(
     *     name="user", in="path", required=true, description="User ID",
     *     @OA\Schema(type="integer", example=123)
     *   ),
     *   @OA\Response(
     *     response=200,
     *     description="User approved",
     *     @OA\JsonContent(type="object",
     *       @OA\Property(property="message", type="string", example="User approved.")
     *     )
     *   ),
     *   @OA\Response(
     *     response=400,
     *     description="User already approved",
     *     @OA\JsonContent(type="object",
     *       @OA\Property(property="message", type="string", example="User already approved")
     *     )
     *   ),
     *   @OA\Response(
     *     response=401,
     *     description="Unauthenticated",
     *     @OA\JsonContent(type="object",
     *       @OA\Property(property="message", type="string", example="Unauthenticated")
     *     )
     *   ),
     *   @OA\Response(
     *     response=403,
     *     description="Forbidden",
     *     @OA\JsonContent(type="object",
     *       @OA\Property(property="message", type="string", example="403 Forbidden")
     *     )
     *   )
     * )
     */
    public function approve($id)
    {
        $user = User::withTrashed()->find($id);
        if (!$user) {
            return response()->json([
                'message' => 'User not found.'
            ], 404);
        }
        if ($user->trashed()) {
            return response()->json([
                'message' => 'User is archived (rejected). Ask the user to register again.'
            ], 400);
        }

        if ($user->status === AccountStatus::APPROVED) {
            return response()->json(['message' => 'User already approved'], 400);
        }

        $user->update([
            'status' => AccountStatus::APPROVED,
            'is_active' => true,
            'approved_by' => auth('api')->id(),
            'approved_at' => now(),
            'rejected_reason' => null,
        ]);

        return response()->json(['message' => 'User approved']);
    }

    /**
     * @OA\Patch(
     *   path="/admin/users/{id}/reject",
     *   summary="Admin reject user",
     *   tags={"Admin"},
     *   security={{"bearerAuth":{}}},
     *   @OA\Parameter(
     *     name="id", in="path", required=true, description="User ID",
     *     @OA\Schema(type="integer", example=123)
     *   ),
     *   @OA\RequestBody(
     *     required=true,
     *     @OA\JsonContent(
     *       type="object",
     *       required={"reason"},
     *       @OA\Property(property="reason", type="string", example="Ảnh CCCD mờ, vui lòng chụp lại.")
     *     )
     *   ),
     *   @OA\Response(
     *     response=200,
     *     description="User rejected and archived",
     *     @OA\JsonContent(
     *       type="object",
     *       @OA\Property(property="message", type="string", example="User rejected and archived.")
     *     )
     *   ),
     *   @OA\Response(
     *     response=400,
     *     description="Already archived / Cannot reject an approved user",
     *     @OA\JsonContent(
     *       type="object",
     *       @OA\Property(property="message", type="string", example="User is already archived (rejected).")
     *     )
     *   ),
     *   @OA\Response(
     *     response=404,
     *     description="User not found",
     *     @OA\JsonContent(
     *       type="object",
     *       @OA\Property(property="message", type="string", example="User not found")
     *     )
     *   ),
     *   @OA\Response(
     *     response=422,
     *     description="Reject reason is required",
     *     @OA\JsonContent(
     *       type="object",
     *       @OA\Property(property="message", type="string", example="The reason field is required.")
     *     )
     *   ),
     *   @OA\Response(
     *     response=401,
     *     description="Unauthenticated",
     *     @OA\JsonContent(
     *       type="object",
     *       @OA\Property(property="message", type="string", example="Unauthenticated")
     *     )
     *   ),
     *   @OA\Response(
     *     response=403,
     *     description="Forbidden",
     *     @OA\JsonContent(
     *       type="object",
     *       @OA\Property(property="message", type="string", example="403 Forbidden")
     *     )
     *   )
     * )
     */
    public function reject($id, Request $request)
    {
        $data = $request->validate([
            'reason' => ['required', 'string', 'max:500'],
        ]);

        $user = User::withTrashed()->find($id);
        if (!$user) {
            return response()->json([
                'message' => 'User not found.'
            ], 404);
        }
        if ($user->trashed()) {
            return response()->json([
                'message' => 'User is already archived (rejected).'
            ], 400);
        }

        if ($user->status === AccountStatus::APPROVED) {
            return response()->json([
                'message' => 'Cannot reject an approved user.'
            ], 400);
        }

        $user->update([
            'status' => AccountStatus::REJECTED,
            'is_active' => false,
            'approved_by' => null,
            'approved_at' => null,
            'rejected_reason' => $data['reason'],
        ]);

        $user->delete();

        return response()->json(['message' => 'User rejected and archived.']);
    }
}
