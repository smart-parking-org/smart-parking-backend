<?php

namespace App\Console\Commands;

use App\Models\ReservationRequest;
use App\Models\ParkingLot;
use App\Models\ParkingSlot;
use App\Services\SlotAllocationService;
use Illuminate\Console\Command;

class TestSlotAllocation extends Command
{
    protected $signature = 'test:slot-allocation {--algorithm=priority_queue} {--requests=10}';
    protected $description = 'Test thuật toán cấp chỗ với dữ liệu giả lập';

    public function handle()
    {
        $algorithm = $this->option('algorithm');
        $numRequests = (int) $this->option('requests');

        $this->info("Testing {$algorithm} algorithm with {$numRequests} requests...");

        // Lấy bãi đỗ xe đầu tiên
        $parkingLot = ParkingLot::first();
        if (!$parkingLot) {
            $this->error('Không tìm thấy bãi đỗ xe nào');
            return;
        }

        $slotAllocationService = app(SlotAllocationService::class);
        $vehicleTypes = ['motorbike', 'car_4_seat', 'car_7_seat', 'light_truck'];
        $successCount = 0;
        $totalProcessingTime = 0;

        // Reset tất cả slots về available
        ParkingSlot::where('parking_lot_id', $parkingLot->id)->update(['status' => 'available']);

        for ($i = 0; $i < $numRequests; $i++) {
            $vehicleType = $vehicleTypes[array_rand($vehicleTypes)];

            $request = ReservationRequest::create([
                'parking_lot_id' => $parkingLot->id,
                'vehicle_type' => $vehicleType,
                'status' => 'pending',
                'requested_at' => now()->subMinutes(rand(0, 60))
            ]);

            $startTime = microtime(true);

            $allocatedSlot = match ($algorithm) {
                'hungarian' => $slotAllocationService->allocateSlotWithHungarian($request),
                default => $slotAllocationService->allocateSlotWithPriorityQueue($request)
            };

            $processingTime = (microtime(true) - $startTime) * 1000;
            $totalProcessingTime += $processingTime;

            if ($allocatedSlot) {
                $successCount++;
                $this->line("Request " . ($i + 1) . ": ✅ Allocated slot {$allocatedSlot->slot_code} ({$processingTime}ms)");
            } else {
                $this->line("Request " . ($i + 1) . ": ❌ No slot available ({$processingTime}ms)");
            }
        }

        $avgProcessingTime = $totalProcessingTime / $numRequests;
        $successRate = ($successCount / $numRequests) * 100;

        $this->info("\n📊 Results:");
        $this->info("Algorithm: {$algorithm}");
        $this->info("Success Rate: {$successRate}% ({$successCount}/{$numRequests})");
        $this->info("Average Processing Time: " . round($avgProcessingTime, 2) . "ms");

        // Reset slots
        ParkingSlot::where('parking_lot_id', $parkingLot->id)->update(['status' => 'available']);
    }
}
