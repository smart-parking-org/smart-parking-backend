<?php

namespace App\Services;

use App\Models\PricingRule;
use App\Models\PeakHour;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;

class ParkingFeeService
{
    /**
     * Tính tiền đỗ xe dựa trên thời gian check-in và check-out
     *
     * @param Carbon $checkInAt Thời gian check-in
     * @param Carbon $checkOutAt Thời gian check-out
     * @param int $parkingLotId ID bãi đỗ xe
     * @param string $vehicleType Loại xe (motorbike, car_4_seat, car_7_seat, light_truck)
     * @return int Số tiền (VND)
     */
    public function calculateFee(
        Carbon $checkInAt,
        Carbon $checkOutAt,
        int $parkingLotId,
        string $vehicleType
    ): int {
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
