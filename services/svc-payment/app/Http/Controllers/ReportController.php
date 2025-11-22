<?php

namespace App\Http\Controllers;

use App\Models\Payment;
use App\Models\MonthlyPass;
use App\Models\Reservation;
use App\Models\ReservationRequest;
use App\Models\ParkingLot;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

/**
 * @OA\Tag(
 *     name="📊 Reports",
 *     description="Báo cáo thống kê doanh thu, sử dụng và xung đột"
 * )
 */
class ReportController extends Controller
{
    /**
     * @OA\Get(
     *     path="/reports/revenue",
     *     tags={"📊 Reports"},
     *     summary="Báo cáo doanh thu và thống kê sử dụng",
     *     description="Lấy báo cáo doanh thu (từ gửi xe + vé tháng), chỉ số sử dụng và xung đột. Có thể lọc theo bãi đỗ và thời gian.",
     *     @OA\Parameter(
     *         name="parking_lot_id",
     *         in="query",
     *         required=false,
     *         description="ID bãi đỗ xe (lọc theo bãi)",
     *         @OA\Schema(type="integer", example=1)
     *     ),
     *     @OA\Parameter(
     *         name="date_from",
     *         in="query",
     *         required=false,
     *         description="Từ ngày (ISO 8601 hoặc Y-m-d)",
     *         @OA\Schema(type="string", format="date", example="2025-11-01")
     *     ),
     *     @OA\Parameter(
     *         name="date_to",
     *         in="query",
     *         required=false,
     *         description="Đến ngày (ISO 8601 hoặc Y-m-d)",
     *         @OA\Schema(type="string", format="date", example="2025-11-30")
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="Báo cáo thống kê",
     *         @OA\JsonContent(
     *             type="object",
     *             @OA\Property(property="success", type="boolean", example=true),
     *             @OA\Property(
     *                 property="data",
     *                 type="object",
     *                 @OA\Property(
     *                     property="revenue",
     *                     type="object",
     *                     @OA\Property(property="parking_revenue", type="integer", example=5000000, description="Doanh thu từ gửi xe (VND)"),
     *                     @OA\Property(property="monthly_pass_revenue", type="integer", example=3000000, description="Doanh thu từ vé tháng (VND)"),
     *                     @OA\Property(property="total_revenue", type="integer", example=8000000, description="Tổng doanh thu (VND)")
     *                 ),
     *                 @OA\Property(
     *                     property="usage",
     *                     type="object",
     *                     @OA\Property(property="total_reservations", type="integer", example=150, description="Tổng số đặt chỗ"),
     *                     @OA\Property(property="confirmed", type="integer", example=120, description="Số đặt chỗ đã xác nhận"),
     *                     @OA\Property(property="checked_in", type="integer", example=100, description="Số đã check-in"),
     *                     @OA\Property(property="checked_out", type="integer", example=90, description="Số đã check-out"),
     *                     @OA\Property(property="cancelled", type="integer", example=10, description="Số đã hủy"),
     *                     @OA\Property(property="expired", type="integer", example=5, description="Số đã hết hạn")
     *                 ),
     *                 @OA\Property(
     *                     property="conflicts",
     *                     type="object",
     *                     @OA\Property(property="total_requests", type="integer", example=200, description="Tổng số yêu cầu đặt chỗ"),
     *                     @OA\Property(property="assigned", type="integer", example=180, description="Số yêu cầu đã được cấp slot"),
     *                     @OA\Property(property="failed", type="integer", example=20, description="Số yêu cầu không được cấp slot (xung đột)"),
     *                     @OA\Property(property="pending", type="integer", example=5, description="Số yêu cầu đang chờ xử lý")
     *                 ),
     *                 @OA\Property(property="parking_lot_id", type="integer", nullable=true, example=1),
     *                 @OA\Property(property="parking_lot_name", type="string", nullable=true, example="B1 Basement"),
     *                 @OA\Property(property="date_from", type="string", format="date", nullable=true, example="2025-11-01"),
     *                 @OA\Property(property="date_to", type="string", format="date", nullable=true, example="2025-11-30"),
     *                 @OA\Property(property="generated_at", type="string", format="date-time", example="2025-11-22T12:00:00Z")
     *             )
     *         )
     *     )
     * )
     */
    public function revenue(Request $request)
    {
        // Lấy tham số filter
        $parkingLotId = $request->input('parking_lot_id');
        $dateFrom = $request->input('date_from');
        $dateTo = $request->input('date_to');

        // Validate parking lot nếu có
        $parkingLot = null;
        if ($parkingLotId) {
            $parkingLot = ParkingLot::find($parkingLotId);
            if (!$parkingLot) {
                return response()->json([
                    'success' => false,
                    'message' => 'Không tìm thấy bãi đỗ xe'
                ], 404);
            }
        }

        // Parse dates từ input type="date" (format: YYYY-MM-DD)
        // Input type="date" gửi lên format YYYY-MM-DD (ví dụ: "2025-11-22")
        // Parse theo timezone địa phương, sau đó convert sang UTC để query database
        $dateFromCarbon = null;
        $dateToCarbon = null;
        $appTimezone = config('app.timezone', 'Asia/Ho_Chi_Minh');
        
        if ($dateFrom) {
            try {
                // Parse từ format YYYY-MM-DD (từ input type="date")
                // Set về đầu ngày (00:00:00) theo timezone địa phương
                $dateFromCarbon = Carbon::createFromFormat('Y-m-d', $dateFrom, $appTimezone)
                    ->startOfDay()
                    ->utc(); // Convert sang UTC để query database
            } catch (\Exception $e) {
                // Nếu parse lỗi, thử parse tự động
                $dateFromCarbon = Carbon::parse($dateFrom, $appTimezone)
                    ->startOfDay()
                    ->utc();
            }
        }
        
        if ($dateTo) {
            try {
                // Parse từ format YYYY-MM-DD (từ input type="date")
                // Set về cuối ngày (23:59:59.999) theo timezone địa phương
                $dateToCarbon = Carbon::createFromFormat('Y-m-d', $dateTo, $appTimezone)
                    ->endOfDay()
                    ->utc(); // Convert sang UTC để query database
            } catch (\Exception $e) {
                // Nếu parse lỗi, thử parse tự động
                $dateToCarbon = Carbon::parse($dateTo, $appTimezone)
                    ->endOfDay()
                    ->utc();
            }
        }
        
        // Validate date range
        if ($dateFromCarbon && $dateToCarbon && $dateFromCarbon->gt($dateToCarbon)) {
            return response()->json([
                'success' => false,
                'message' => 'Ngày bắt đầu phải nhỏ hơn hoặc bằng ngày kết thúc'
            ], 422);
        }

        // ========== DOANH THU TỪ GỬI XE ==========
        $parkingRevenueQuery = Payment::where('status', 'PAID')
            ->where(function ($query) {
                // Không phải từ monthly pass (không có meta->is_free = true hoặc không có monthly_pass_id)
                $query->whereNull('meta->is_free')
                    ->orWhere('meta->is_free', false)
                    ->orWhereNull('meta->monthly_pass_id');
            })
            ->whereNotNull('reservation_id'); // Chỉ tính payments từ reservations

        // Lọc theo bãi đỗ (qua reservation -> slot -> parking_lot_id)
        if ($parkingLotId) {
            $parkingRevenueQuery->whereHas('reservation.slot', function ($q) use ($parkingLotId) {
                $q->where('parking_lot_id', $parkingLotId);
            });
        }

        // Lọc theo thời gian (theo updated_at - thời điểm payment chuyển sang PAID hoặc được tạo với status PAID)
        if ($dateFromCarbon) {
            $parkingRevenueQuery->where('updated_at', '>=', $dateFromCarbon);
        }
        if ($dateToCarbon) {
            $parkingRevenueQuery->where('updated_at', '<=', $dateToCarbon);
        }

        $parkingRevenue = (int) $parkingRevenueQuery->sum('amount');

        // ========== DOANH THU TỪ VÉ THÁNG ==========
        // Tính từ payments có order_id bắt đầu bằng "MP-" (monthly pass order)
        $monthlyPassRevenueQuery = Payment::where('status', 'PAID')
            ->where('order_id', 'LIKE', 'MP-%');

        // Lọc theo bãi đỗ (tìm monthly pass qua order_id và lọc theo parking_lot_id)
        if ($parkingLotId) {
            $monthlyPassOrderIds = MonthlyPass::where('parking_lot_id', $parkingLotId)
                ->pluck('order_id')
                ->toArray();
            $monthlyPassRevenueQuery->whereIn('order_id', $monthlyPassOrderIds);
        }

        // Lọc theo thời gian (theo updated_at - thời điểm payment chuyển sang PAID hoặc được tạo với status PAID)
        if ($dateFromCarbon) {
            $monthlyPassRevenueQuery->where('updated_at', '>=', $dateFromCarbon);
        }
        if ($dateToCarbon) {
            $monthlyPassRevenueQuery->where('updated_at', '<=', $dateToCarbon);
        }

        $monthlyPassRevenue = (int) $monthlyPassRevenueQuery->sum('amount');

        $totalRevenue = $parkingRevenue + $monthlyPassRevenue;

        // ========== CHỈ SỐ SỬ DỤNG ==========
        $reservationQuery = Reservation::query();

        // Lọc theo bãi đỗ
        if ($parkingLotId) {
            $reservationQuery->whereHas('slot', function ($q) use ($parkingLotId) {
                $q->where('parking_lot_id', $parkingLotId);
            });
        }

        // Lọc theo thời gian (theo created_at)
        if ($dateFromCarbon) {
            $reservationQuery->where('created_at', '>=', $dateFromCarbon);
        }
        if ($dateToCarbon) {
            $reservationQuery->where('created_at', '<=', $dateToCarbon);
        }

        $usage = [
            'total_reservations' => $reservationQuery->count(),
            'confirmed' => (clone $reservationQuery)->where('status', 'confirmed')->count(),
            'checked_in' => (clone $reservationQuery)->where('status', 'checked_in')->count(),
            'checked_out' => (clone $reservationQuery)->where('status', 'checked_out')->count(),
            'cancelled' => (clone $reservationQuery)->where('status', 'cancelled')->count(),
            'expired' => (clone $reservationQuery)->where('status', 'expired')->count(),
        ];

        // ========== XUNG ĐỘT (CONFLICTS) ==========
        // Xung đột trong slot allocation: các reservation requests không được cấp slot
        $conflictQuery = ReservationRequest::query();

        // Lọc theo bãi đỗ
        if ($parkingLotId) {
            $conflictQuery->where('parking_lot_id', $parkingLotId);
        }

        // Lọc theo thời gian (theo requested_at)
        if ($dateFromCarbon) {
            $conflictQuery->where('requested_at', '>=', $dateFromCarbon);
        }
        if ($dateToCarbon) {
            $conflictQuery->where('requested_at', '<=', $dateToCarbon);
        }

        // Xung đột = các requests không được cấp slot (status = 'failed')
        // Status của ReservationRequest: 'pending' (chờ xử lý), 'assigned' (đã cấp slot), 'failed' (không tìm được slot - xung đột)
        $conflicts = [
            'total_requests' => $conflictQuery->count(),
            'assigned' => (clone $conflictQuery)->where('status', 'assigned')->whereNotNull('allocated_slot_id')->count(),
            'failed' => (clone $conflictQuery)->where('status', 'failed')->count(), // Xung đột: không tìm được slot
            'pending' => (clone $conflictQuery)->where('status', 'pending')->count(), // Đang chờ xử lý
        ];

        return response()->json([
            'success' => true,
            'data' => [
                'revenue' => [
                    'parking_revenue' => $parkingRevenue,
                    'monthly_pass_revenue' => $monthlyPassRevenue,
                    'total_revenue' => $totalRevenue,
                ],
                'usage' => $usage,
                'conflicts' => $conflicts,
                'parking_lot_id' => $parkingLotId,
                'parking_lot_name' => $parkingLot ? $parkingLot->name : null,
                'date_from' => $dateFrom,
                'date_to' => $dateTo,
                'generated_at' => now()->toIso8601String(),
            ]
        ]);
    }
}

