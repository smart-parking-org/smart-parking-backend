<?php

namespace App\Http\Controllers;

use App\Models\Notification;
use App\Models\Reservation;
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
     *   path="/notifications/send",
     *   tags={"Notifications"},
     *   summary="Gửi push notification đến người dùng",
     *   description="Gửi push notification qua FCM và lưu vào database",
     *   @OA\RequestBody(
     *     required=true,
     *     @OA\JsonContent(
     *       required={"title", "body", "type"},
     *       @OA\Property(property="user_id", type="integer", example=2, nullable=true),
     *       @OA\Property(property="license_plate", type="string", example="51H-123.45", nullable=true),
     *       @OA\Property(property="title", type="string", example="Thông báo quan trọng"),
     *       @OA\Property(property="body", type="string", example="Bạn có một thông báo mới"),
     *       @OA\Property(property="type", type="string", example="other"),
     *     )
     *   ),
     *   @OA\Response(response=200, description="Gửi thành công"),
     *   @OA\Response(response=404, description="Không tìm thấy FCM token"),
     *   @OA\Response(response=422, description="Validation error")
     * )
     */
    public function sendPushNotification(Request $request)
    {
        $validated = $request->validate([
            'user_id' => 'nullable|integer|required_without:license_plate',
            'license_plate' => 'nullable|string|required_without:user_id',
            'title' => 'required|string',
            'body' => 'required|string',
            'type' => 'required|string',
            'data' => 'nullable|array',
        ]);

        $recipient = $this->resolveRecipient(
            $validated['user_id'] ?? null,
            $validated['license_plate'] ?? null
        );

        if (!$recipient) {
            $message = empty($validated['license_plate'])
                ? 'User not found'
                : 'Không tìm thấy cư dân cho biển số ' . $validated['license_plate'];

            return response()->json(['message' => $message], 404);
        }

        $token = $this->authService->getTokenByUserId($recipient['user_id']);
        if (!$token) {
            return response()->json(['message' => 'No FCM token'], 404);
        }

        $payloadData = $validated['data'] ?? [];
        if (!empty($recipient['license_plate'])) {
            $payloadData = array_merge([
                'license_plate' => $recipient['license_plate'],
            ], $payloadData);
        }

        $response = $this->sendNotification(
            $token,
            $validated['title'],
            $validated['body'],
            $payloadData,
        );

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
        // Lưu vào database nếu gửi thành công
        $notification = Notification::create([
            'user_id' => $recipient['user_id'],
            'reservation_id' => $recipient['reservation_id'] ?? null,
            'type' => $validated['type'],
            'title' => $validated['title'],
            'body' => $validated['body'],
            'data' => $payloadData ?: null, // Lưu data nếu có
            'is_read' => false,
        ]);

        return response()->json([
            'message' => 'Notification sent successfully',
            'success' => true,
            'notification_id' => $notification->id,
            'fcm_message_id' => $response['name'] ?? null,
        ], 200);
    }

    /**
     * @OA\Get(
     *   path="/notifications",
     *   tags={"Notifications"},
     *   summary="Lấy danh sách notifications của user",
     *   security={{"bearerAuth":{}}},
     *   @OA\Parameter(
     *     name="user_id",
     *     in="query",
     *     required=true,
     *     @OA\Schema(type="integer")
     *   ),
     *   @OA\Parameter(
     *     name="type",
     *     in="query",
     *     required=false,
     *     @OA\Schema(type="string")
     *   ),
     *   @OA\Parameter(
     *     name="is_read",
     *     in="query",
     *     required=false,
     *     @OA\Schema(type="boolean")
     *   ),
     *   @OA\Parameter(
     *     name="per_page",
     *     in="query",
     *     required=false,
     *     @OA\Schema(type="integer", default=20)
     *   ),
     *   @OA\Response(response=200, description="Danh sách notifications")
     * )
     */
    public function index(Request $request)
    {
        $validated = $request->validate([
            'user_id' => 'required|integer',
            'type' => 'nullable|string',
            'is_read' => 'nullable',
            'per_page' => 'nullable|integer|min:1|max:100',
        ]);
        $query = Notification::where('user_id', $validated['user_id']);

        if ($request->has('type')) {
            $query->where('type', $validated['type']);
        }

        if ($request->has('is_read')) {
            $query->where('is_read', $validated['is_read'] === 'true');
        }

        $perPage = $validated['per_page'] ?? 20;
        $notifications = $query->orderByDesc('created_at')->paginate($perPage);

        return response()->json([
            'data' => $notifications->items(),
            'meta' => [
                'current_page' => $notifications->currentPage(),
                'per_page' => $notifications->perPage(),
                'total' => $notifications->total(),
                'last_page' => $notifications->lastPage(),
            ],
            'unread_count' => Notification::where('user_id', $validated['user_id'])
                ->where('is_read', false)
                ->count(),
        ]);
    }

    /**
     * @OA\Put(
     *   path="/notifications/{id}/read",
     *   tags={"Notifications"},
     *   summary="Đánh dấu notification đã đọc",
     *   @OA\Parameter(
     *     name="id",
     *     in="path",
     *     required=true,
     *     @OA\Schema(type="integer")
     *   ),
     *   @OA\Response(response=200, description="Đánh dấu thành công"),
     *   @OA\Response(response=404, description="Không tìm thấy notification")
     * )
     */
    public function markAsRead($id)
    {
        $notification = Notification::find($id);

        if (!$notification) {
            return response()->json(['message' => 'Notification not found'], 404);
        }

        $notification->update([
            'is_read' => true,
        ]);

        return response()->json([
            'message' => 'Notification marked as read',
            'notification' => $notification,
        ]);
    }

    /**
     * @OA\Put(
     *   path="/notifications/mark-all-read",
     *   tags={"Notifications"},
     *   summary="Đánh dấu tất cả notifications của user đã đọc",
     *   @OA\RequestBody(
     *     required=true,
     *     @OA\JsonContent(
     *       required={"user_id"},
     *       @OA\Property(property="user_id", type="integer", example=1)
     *     )
     *   ),
     *   @OA\Response(response=200, description="Đánh dấu thành công")
     * )
     */
    public function markAllAsRead(Request $request)
    {
        $validated = $request->validate([
            'user_id' => 'required|integer',
        ]);

        $updated = Notification::where('user_id', $validated['user_id'])
            ->where('is_read', false)
            ->update([
                'is_read' => true,
            ]);

        return response()->json([
            'message' => 'All notifications marked as read',
            'updated_count' => $updated,
        ]);
    }

    /**
     * @OA\Get(
     *   path="/notifications/unread-count",
     *   tags={"Notifications"},
     *   summary="Lấy số lượng notifications chưa đọc",
     *   @OA\Parameter(
     *     name="user_id",
     *     in="query",
     *     required=true,
     *     @OA\Schema(type="integer")
     *   ),
     *   @OA\Response(response=200, description="Số lượng chưa đọc")
     * )
     */
    public function getUnreadCount(Request $request)
    {
        $validated = $request->validate([
            'user_id' => 'required|integer',
        ]);

        $count = Notification::where('user_id', $validated['user_id'])
            ->where('is_read', false)
            ->count();

        return response()->json([
            'user_id' => $validated['user_id'],
            'unread_count' => $count,
        ]);
    }

    /**
     * @OA\Delete(
     *   path="/notifications/{id}",
     *   tags={"Notifications"},
     *   summary="Xóa một notification",
     *   @OA\Parameter(
     *     name="id",
     *     in="path",
     *     required=true,
     *     @OA\Schema(type="integer")
     *   ),
     *   @OA\Response(response=200, description="Xóa thành công"),
     *   @OA\Response(response=404, description="Không tìm thấy notification")
     * )
     */
    public function destroy($id)
    {
        $notification = Notification::find($id);

        if (!$notification) {
            return response()->json(['message' => 'Notification not found'], 404);
        }

        $notification->delete();

        return response()->json([
            'message' => 'Notification deleted successfully',
        ], 200);
    }

    /**
     * @OA\Delete(
     *   path="/notifications/delete-all",
     *   tags={"Notifications"},
     *   summary="Xóa tất cả notifications của user",
     *   @OA\RequestBody(
     *     required=true,
     *     @OA\JsonContent(
     *       required={"user_id"},
     *       @OA\Property(property="user_id", type="integer", example=1)
     *     )
     *   ),
     *   @OA\Response(response=200, description="Xóa thành công")
     * )
     */
    public function deleteAll(Request $request)
    {
        $validated = $request->validate([
            'user_id' => 'required|integer',
        ]);

        $deleted = Notification::where('user_id', $validated['user_id'])->delete();

        return response()->json([
            'message' => 'All notifications deleted successfully',
            'deleted_count' => $deleted,
        ], 200);
    }

    private function resolveRecipient(?int $userId, ?string $licensePlate): ?array
    {
        if ($userId) {
            return [
                'user_id' => $userId,
                'license_plate' => $licensePlate,
                'reservation_id' => null,
            ];
        }

        if (!$licensePlate) {
            return null;
        }

        $reservation = $this->findReservationByPlate($licensePlate);

        if (!$reservation) {
            return null;
        }

        $snapshotPlate = data_get($reservation->vehicle_snapshot, 'license_plate')
            ?? data_get($reservation->vehicle_snapshot, 'plate');

        return [
            'user_id' => $reservation->user_id,
            'license_plate' => $snapshotPlate ?? $licensePlate,
            'reservation_id' => $reservation->id,
        ];
    }

    private function findReservationByPlate(string $licensePlate): ?Reservation
    {
        $normalizedPlate = $this->normalizePlate($licensePlate);

        return Reservation::query()
            ->where(function ($query) use ($licensePlate, $normalizedPlate) {
                $query->where('vehicle_snapshot->license_plate', $licensePlate)
                    ->orWhere('vehicle_snapshot->plate', $licensePlate)
                    ->orWhereRaw("REPLACE(UPPER(JSON_UNQUOTE(JSON_EXTRACT(vehicle_snapshot, '$.license_plate'))), '-', '') = ?", [$normalizedPlate])
                    ->orWhereRaw("REPLACE(UPPER(JSON_UNQUOTE(JSON_EXTRACT(vehicle_snapshot, '$.plate'))), '-', '') = ?", [$normalizedPlate]);
            })
            ->orderByDesc('created_at')
            ->first();
    }

    private function normalizePlate(string $licensePlate): string
    {
        return strtoupper(preg_replace('/[^A-Z0-9]/', '', $licensePlate));
    }
}
