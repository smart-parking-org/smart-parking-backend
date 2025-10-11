<?php

namespace App\Http\Controllers;

use App\Enums\AccountStatus;
use App\Mail\ApproveUserAccountMail;
use App\Mail\RejectUserAccountMail;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

class UserApprovalController extends Controller
{
    /**
     * @OA\Patch(
     *   path="/admin/users/{id}/approve",
     *   summary="Admin approve user",
     *   tags={"Admin"},
     *   security={{"bearerAuth":{}}},
     *   @OA\Parameter(
     *     name="id", in="path", required=true, description="User ID",
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
    public function approve($id)
    {
        try {
            $user = User::find($id);
            if (!$user) {
                return response()->json([
                    'message' => 'User not found.'
                ], 404);
            }

            if ($user->status === AccountStatus::APPROVED) {
                return response()->json(['message' => 'User already approved'], 400);
            }

            if ($user->status === AccountStatus::REJECTED) {
                return response()->json(['message' => 'Cannot approve an rejected user'], 400);
            }

            $user->update([
                'status' => AccountStatus::APPROVED,
                'approved_by' => auth('api')->id(),
                'approved_at' => now(),
                'rejected_reason' => null,
            ]);
            try {
                Mail::to($user->email)->queue(new ApproveUserAccountMail($user));
            } catch (\Throwable $e) {
                Log::warning('Lỗi khi gửi mail xác nhận duyệt tài khoản: ', [
                    'user_id' => $user->id,
                    'error' => $e->getMessage()
                ]);
            }
        } catch (\Throwable $e) {
            Log::error('Lỗi khi approve tài khoản: ', ['error' => $e->getMessage()]);
            return response()->json([
                'message' => 'Đã xảy ra lỗi, vui lòng thử lại sau.'
            ], 500);
        }

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
     *     description="User approved can't reject",
     *     @OA\JsonContent(
     *       type="object",
     *       @OA\Property(property="message", type="string", example="Cannot reject an approved user.")
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
    public function reject($id, Request $request)
    {
        $data = $request->validate([
            'reason' => ['required', 'string', 'max:500'],
        ]);
        try {
            $user = User::find($id);
            if (!$user) {
                return response()->json([
                    'message' => 'User not found.'
                ], 404);
            }

            if ($user->status === AccountStatus::APPROVED) {
                return response()->json([
                    'message' => 'Cannot reject an approved user.'
                ], 400);
            }
            if ($user->status === AccountStatus::REJECTED) {
                return response()->json([
                    'message' => 'User already rejected.'
                ], 400);
            }

            $user->update([
                'status' => AccountStatus::REJECTED,
                'approved_by' => null,
                'approved_at' => null,
                'rejected_reason' => $data['reason'],
            ]);


        } catch (\Throwable $e) {
            Log::error('Lỗi khi reject tài khoản: ', ['error' => $e->getMessage()]);
            return response()->json([
                'message' => 'Đã xảy ra lỗi, vui lòng thử lại sau.'
            ], 500);
        }

        try {
            Mail::to($user->email)->queue(new RejectUserAccountMail($user, $data['reason']));
        } catch (\Throwable $e) {
            Log::warning('Lỗi khi gửi mail từ chối tài khoản: ', [
                'user_id' => $user->id,
                'error' => $e->getMessage()
            ]);
        }

        return response()->json(['message' => 'User rejected and archived.']);
    }
}
