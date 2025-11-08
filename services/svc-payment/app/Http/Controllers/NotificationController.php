<?php

namespace App\Http\Controllers;

use App\Services\AuthService;
use App\Traits\PushNotification;
use Illuminate\Http\Request;

/**
 * @OA\Tag(
 *     name="Notifications",
 *     description="API endpoints for push notifications"
 * )
 */
class NotificationController extends Controller
{
    use PushNotification;
    private AuthService $authService;

    public function __construct(AuthService $authService)
    {
        $this->authService = $authService;
    }

    /**
     * @OA\Post(
     *   path="/send-notification",
     *   tags={"Notifications"},
     *   summary="Gửi push notification đến người dùng",
     *   description="Gửi push notification qua FCM (Firebase Cloud Messaging) đến thiết bị của người dùng theo user_id. Hệ thống sẽ tự động lấy FCM token từ service auth và gửi thông báo.",
     *   @OA\RequestBody(
     *     required=true,
     *     @OA\JsonContent(
     *       required={"user_id", "title", "body"},
     *       @OA\Property(
     *         property="user_id",
     *         type="integer",
     *         description="ID của người dùng nhận notification",
     *         example=1
     *       ),
     *       @OA\Property(
     *         property="title",
     *         type="string",
     *         description="Tiêu đề của notification",
     *         example="Thông báo quan trọng"
     *       ),
     *       @OA\Property(
     *         property="body",
     *         type="string",
     *         description="Nội dung của notification",
     *         example="Bạn có một thông báo mới từ hệ thống"
     *       ),
     *       @OA\Property(
     *         property="data",
     *         type="object",
     *         description="Dữ liệu bổ sung gửi kèm notification (optional)",
     *         example={
     *           "type": "reservation_hold",
     *           "reservation_id": "123",
     *           "action": "view"
     *         },
     *         @OA\AdditionalProperties(type="string")
     *       )
     *     )
     *   ),
     *   @OA\Response(
     *     response=200,
     *     description="Gửi notification thành công",
     *     @OA\JsonContent(
     *       type="object",
     *       @OA\Property(
     *         property="name",
     *         type="string",
     *         description="Message ID từ FCM",
     *         example="projects/smart-parking-d89e0/messages/0:1234567890"
     *       )
     *     )
     *   ),
     *   @OA\Response(
     *     response=404,
     *     description="Không tìm thấy FCM token cho user",
     *     @OA\JsonContent(
     *       type="object",
     *       @OA\Property(
     *         property="message",
     *         type="string",
     *         example="No FCM token"
     *       )
     *     )
     *   ),
     *   @OA\Response(
     *     response=422,
     *     description="Dữ liệu validation không hợp lệ",
     *     @OA\JsonContent(
     *       type="object",
     *       @OA\Property(
     *         property="message",
     *         type="string",
     *         example="The given data was invalid."
     *       ),
     *       @OA\Property(
     *         property="errors",
     *         type="object",
     *         @OA\Property(
     *           property="user_id",
     *           type="array",
     *           @OA\Items(type="string", example="The user id field is required.")
     *         ),
     *         @OA\Property(
     *           property="title",
     *           type="array",
     *           @OA\Items(type="string", example="The title field is required.")
     *         ),
     *         @OA\Property(
     *           property="body",
     *           type="array",
     *           @OA\Items(type="string", example="The body field is required.")
     *         )
     *       )
     *     )
     *   ),
     *   @OA\Response(
     *     response=500,
     *     description="Lỗi máy chủ hoặc lỗi khi gửi notification",
     *     @OA\JsonContent(
     *       type="object",
     *       @OA\Property(
     *         property="message",
     *         type="string",
     *         example="Đã xảy ra lỗi, vui lòng thử lại sau."
     *       )
     *     )
     *   )
     * )
     */
    public function sendPushNotification(Request $request)
    {
        $validated = $request->validate([
            'user_id' => 'required|integer',
            'title' => 'required|string',
            'body' => 'required|string',
            'data' => 'array',
        ]);
        $token = $this->authService->getTokenByUserId($validated['user_id']);
        if (!$token) {
            return response()->json(['message' => 'No FCM token'], 404);
        }

        $response = $this->sendNotification($token, $validated['title'], $validated['body'], $validated['data'] ?? []);
        // Kiểm tra response từ FCM
        if ($response === false) {
            return response()->json([
                'message' => 'Failed to send notification',
                'success' => false
            ], 500);
        }

        // Kiểm tra nếu FCM trả về lỗi
        if (isset($response['error'])) {
            return response()->json([
                'message' => 'FCM error: ' . ($response['error']['message'] ?? 'Unknown error'),
                'error' => $response['error'],
                'success' => false
            ], 400);
        }

        // Thành công
        if (isset($response['name'])) {
            return response()->json([
                'message' => 'Notification sent successfully',
                'success' => true,
                'fcm_message_id' => $response['name'],
                'data' => $response
            ], 200);
        }

        return response()->json([
            'message' => 'Unexpected response from FCM',
            'response' => $response,
            'success' => false
        ], 500);
    }
}
