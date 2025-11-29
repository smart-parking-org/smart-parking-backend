<?php

namespace App\Services;

use App\Models\MonthlyPass;
use App\Models\PricingRule;
use App\Models\PeakHour;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;

class ParkingFeeService
{
    /**
     * Tính tiền đỗ xe dựa trên thời gian check-in và check-out
     * Có kiểm tra monthly pass - nếu có thì trả về 0
     *
     * @param Carbon $checkInAt Thời gian check-in
     * @param Carbon $checkOutAt Thời gian check-out
     * @param int $parkingLotId ID bãi đỗ xe
     * @param string $vehicleType Loại xe (motorbike, car_4_seat, car_7_seat, light_truck)
     * @param int|null $userId User ID (để kiểm tra monthly pass)
     * @param int|null $vehicleId Vehicle ID (để kiểm tra monthly pass)
     * @return int Số tiền (VND)
     */
    public function calculateFee(
        Carbon $checkInAt,
        Carbon $checkOutAt,
        int $parkingLotId,
        string $vehicleType,
        ?int $userId = null,
        ?int $vehicleId = null
    ): int {
        // ✅ Kiểm tra monthly pass nếu có userId và vehicleId
        if ($userId && $vehicleId) {
            $monthlyPass = MonthlyPass::findValidPass(
                $userId,
                $vehicleId,
                $parkingLotId,
                $checkInAt->toDateString()
            );

            if ($monthlyPass && $monthlyPass->isValid($checkInAt)) {
                // Có vé tháng hợp lệ → miễn phí
                return 0;
            }
        }
        // Lấy pricing rule
        $pricingRule = PricingRule::where('parking_lot_id', $parkingLotId)
            ->where('vehicle_type', $vehicleType)
            ->first();

        if (!$pricingRule) {
            Log::warning('Pricing rule not found', [
                'parking_lot_id' => $parkingLotId,
                'vehicle_type' => $vehicleType,
            ]);
            return 0;
        }

        // Tính số phút đỗ
        $durationMinutes = $checkInAt->diffInMinutes($checkOutAt);

        if ($durationMinutes <= 0) {
            return 0;
        }

        if ($durationMinutes <= 30) {
            $hourlyRate = $pricingRule->hourly;

            // Kiểm tra có phải giờ cao điểm không
            $peakHours = PeakHour::where('parking_lot_id', $parkingLotId)
                ->where('is_active', true)
                ->get();

            $isPeakHour = $this->isPeakHour($checkInAt, $peakHours);

            // Tính nửa giờ
            $halfHourFee = $hourlyRate / 2;

            // Nếu có giờ cao điểm thì nhân thêm multiplier
            if ($isPeakHour && $pricingRule->peak_enabled && $pricingRule->peak_multiplier) {
                $halfHourFee = $halfHourFee * $pricingRule->peak_multiplier;
            }

            return (int) ceil($halfHourFee);
        }

        // Lấy giờ cao điểm
        $peakHours = PeakHour::where('parking_lot_id', $parkingLotId)
            ->where('is_active', true)
            ->get();

        // Tính tiền theo từng giờ
        $totalFee = 0;
        $currentTime = $checkInAt->copy();
        $hourlyRate = $pricingRule->hourly;

        while ($currentTime < $checkOutAt) {
            // Tính thời gian của giờ hiện tại (tối đa đến checkOutAt)
            $hourEnd = $currentTime->copy()->addHour()->startOfHour();
            if ($hourEnd > $checkOutAt) {
                $hourEnd = $checkOutAt;
            }

            $minutesInThisHour = $currentTime->diffInMinutes($hourEnd);
            $hoursInThisPeriod = $minutesInThisHour / 60;

            // Kiểm tra có phải giờ cao điểm không
            $isPeakHour = $this->isPeakHour($currentTime, $peakHours);

            if ($isPeakHour && $pricingRule->peak_enabled && $pricingRule->peak_multiplier) {
                $rate = $hourlyRate * $pricingRule->peak_multiplier;
            } else {
                $rate = $hourlyRate;
            }

            // Tính tiền cho khoảng thời gian này (làm tròn lên)
            $feeForThisPeriod = ceil($hoursInThisPeriod * $rate);
            $totalFee += $feeForThisPeriod;

            // Chuyển sang giờ tiếp theo
            $currentTime = $hourEnd;
        }

        // Áp dụng daily cap nếu có
        if ($pricingRule->daily_cap && $totalFee > $pricingRule->daily_cap) {
            $totalFee = $pricingRule->daily_cap;
        }

        return (int) $totalFee;
    }

    /**
     * Tính tiền đỗ xe theo logic reservation:
     * - Phần cố định: số giờ đã đặt (start_time đến end_time) * giá/giờ
     * - Phần vượt quá: nếu check_out_at > end_time, tính thêm từ end_time đến check_out_at
     *
     * @param Carbon $startTime Thời gian bắt đầu đã đặt
     * @param Carbon $endTime Thời gian kết thúc đã đặt
     * @param Carbon $checkOutAt Thời gian check-out thực tế
     * @param int $parkingLotId ID bãi đỗ xe
     * @param string $vehicleType Loại xe (motorbike, car_4_seat, car_7_seat, light_truck)
     * @param int|null $userId User ID (để kiểm tra monthly pass)
     * @param int|null $vehicleId Vehicle ID (để kiểm tra monthly pass)
     * @return int Số tiền (VND)
     */
    public function calculateReservationFee(
        Carbon $startTime,
        Carbon $endTime,
        Carbon $checkOutAt,
        int $parkingLotId,
        string $vehicleType,
        ?int $userId = null,
        ?int $vehicleId = null
    ): int {
        // ✅ Kiểm tra monthly pass nếu có userId và vehicleId
        if ($userId && $vehicleId) {
            $monthlyPass = MonthlyPass::findValidPass(
                $userId,
                $vehicleId,
                $parkingLotId,
                $startTime->toDateString()
            );

            if ($monthlyPass && $monthlyPass->isValid($startTime)) {
                // Có vé tháng hợp lệ → miễn phí
                return 0;
            }
        }

        // Lấy pricing rule
        $pricingRule = PricingRule::where('parking_lot_id', $parkingLotId)
            ->where('vehicle_type', $vehicleType)
            ->first();

        if (!$pricingRule) {
            Log::warning('Pricing rule not found', [
                'parking_lot_id' => $parkingLotId,
                'vehicle_type' => $vehicleType,
            ]);
            return 0;
        }

        $hourlyRate = $pricingRule->hourly;
        $totalFee = 0;

        // 1. Tính phí cố định: số giờ đã đặt (start_time đến end_time)
        $bookedDurationHours = $startTime->diffInHours($endTime);
        if ($bookedDurationHours < 1) {
            // Nếu ít hơn 1 giờ, tính theo giờ tối thiểu
            $bookedDurationHours = 1;
        }
        $fixedFee = $bookedDurationHours * $hourlyRate;
        $totalFee += $fixedFee;

        // 2. Tính phí vượt quá: nếu check_out_at > end_time
        if ($checkOutAt > $endTime) {
            // Lấy giờ cao điểm để tính phí vượt quá
            $peakHours = PeakHour::where('parking_lot_id', $parkingLotId)
                ->where('is_active', true)
                ->get();

            // Tính phí cho từng giờ vượt quá
            $currentTime = $endTime->copy();
            while ($currentTime < $checkOutAt) {
                $hourEnd = $currentTime->copy()->addHour();
                if ($hourEnd > $checkOutAt) {
                    $hourEnd = $checkOutAt;
                }

                $minutesInThisHour = $currentTime->diffInMinutes($hourEnd);
                $hoursInThisPeriod = $minutesInThisHour / 60;

                // Kiểm tra có phải giờ cao điểm không
                $isPeakHour = $this->isPeakHour($currentTime, $peakHours);

                if ($isPeakHour && $pricingRule->peak_enabled && $pricingRule->peak_multiplier) {
                    $rate = $hourlyRate * $pricingRule->peak_multiplier;
                } else {
                    $rate = $hourlyRate;
                }

                // Tính tiền cho khoảng thời gian này (làm tròn lên)
                $feeForThisPeriod = ceil($hoursInThisPeriod * $rate);
                $totalFee += $feeForThisPeriod;

                // Chuyển sang giờ tiếp theo
                $currentTime = $hourEnd;
            }
        }

        // Áp dụng daily cap nếu có
        if ($pricingRule->daily_cap && $totalFee > $pricingRule->daily_cap) {
            $totalFee = $pricingRule->daily_cap;
        }

        return (int) $totalFee;
    }

    /**
     * Kiểm tra thời gian có nằm trong giờ cao điểm không
     */
    private function isPeakHour(Carbon $time, $peakHours): bool
    {
        foreach ($peakHours as $peakHour) {
            if ($peakHour->isWithinPeak($time)) {
                return true;
            }
        }
        return false;
    }
}
