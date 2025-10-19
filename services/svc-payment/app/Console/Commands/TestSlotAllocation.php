<?php

namespace App\Console\Commands;

use App\Models\ReservationRequest;
use App\Models\ParkingLot;
use App\Models\ParkingSlot;
use App\Services\SlotAllocationService;
use Illuminate\Console\Command;

class TestSlotAllocation extends Command
{
    protected $signature = 'test:slot-allocation
                            {--algorithm=priority_queue : Thuật toán sử dụng}
                            {--requests=300 : Số lượng requests}
                            {--peak-ratio=60 : Tỷ lệ giờ cao điểm (%)}';

    protected $description = 'Test thuật toán cấp chỗ với dữ liệu giả lập phân bố giờ cao điểm';

    public function handle()
    {
        $algorithm = $this->option('algorithm');
        $numRequests = (int) $this->option('requests');
        $peakRatio = (int) $this->option('peak-ratio');

        $algorithmNameMap = [
            'priority_queue' => 'Priority Queue',
            'hungarian' => 'Hungarian Algorithm',
        ];

        $this->info("🧪 Kiểm tra thuật toán " . $algorithmNameMap[$algorithm] . " với {$numRequests} yêu cầu");
        $this->info("📈 Phân bố giờ cao điểm: {$peakRatio}%");

        // Chạy command tạo dữ liệu
        $this->call('test:peak-hour-reservations', [
            '--requests' => $numRequests,
            '--peak-ratio' => $peakRatio,
            '--algorithm' => $algorithm
        ]);
    }
}
# So sánh cả 2 thuật toán
// php artisan test:slot-allocation --algorithm=priority_queue --requests=300 --peak-ratio=60
// php artisan test:slot-allocation --algorithm=hungarian --requests=300 --peak-ratio=60
