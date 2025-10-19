<?php
namespace App\Console\Commands;

use App\Models\ReservationRequest;
use Illuminate\Console\Command;

class CompareAlgorithms extends Command
{
    protected $signature = 'test:compare-algorithms {--requests=300} {--peak-ratio=60}';
    protected $description = 'So sánh 2 thuật toán để chọn thuật toán tốt nhất';

    public function handle()
    {
        $totalRequests = $this->option('requests');
        $peakRatio = $this->option('peak-ratio');

        $this->info("🔬 SO SÁNH 2 THUẬT TOÁN CẤP CHỖ");
        $this->info("📊 Dữ liệu: {$totalRequests} requests, {$peakRatio}% giờ cao điểm");

        $results = [];

        // Test Priority Queue
        $this->info("\n1️⃣ Test thuật toán Priority Queue...");
        $results['priority_queue'] = $this->runAlgorithmTest('priority_queue', $totalRequests, $peakRatio);

        // Test Hungarian
        $this->info("\n2️⃣ Test thuật toán Hungarian...");
        $results['hungarian'] = $this->runAlgorithmTest('hungarian', $totalRequests, $peakRatio);

        // So sánh kết quả
        $this->compareResults($results);
    }

    private function runAlgorithmTest($algorithm, $totalRequests, $peakRatio)
    {
        $this->call('test:peak-hour-reservations', [
            '--requests' => $totalRequests,
            '--peak-ratio' => $peakRatio,
            '--algorithm' => $algorithm
        ]);

        // Lấy kết quả từ database
        $requests = ReservationRequest::all();
        $successCount = $requests->where('status', 'assigned')->count();
        $conflictCount = $requests->where('status', 'failed')->count();
        $avgProcessingTime = $requests->avg('processing_time_ms') ?? 0;

        return [
            'algorithm' => $algorithm,
            'total_requests' => $requests->count(),
            'success_count' => $successCount,
            'conflict_count' => $conflictCount,
            'success_rate' => ($successCount / $requests->count()) * 100,
            'conflict_rate' => ($conflictCount / $requests->count()) * 100,
            'avg_processing_time' => $avgProcessingTime,
        ];
    }

    private function compareResults($results)
    {
        $this->info("\n📈 BẢNG SO SÁNH KẾT QUẢ:");
        $this->info("┌─────────────────┬──────────────┬──────────────┬─────────────────┐");
        $this->info("│ Thuật toán      │ Xung đột (%) │ Thời gian(ms)│ Đánh giá        │");
        $this->info("├─────────────────┼──────────────┼──────────────┼─────────────────┤");

        foreach ($results as $result) {
            $conflictPass = $result['conflict_rate'] < 2;
            $timePass = $result['avg_processing_time'] < 1500;
            $overallPass = $conflictPass && $timePass;

            $evaluation = $overallPass ? '✅ ĐẠT' : '❌ CHƯA ĐẠT';
            $format = $overallPass
                ? "│ %-15s │ %-12.2f │ %-12.2f │ %-19s │"
                : "│ %-15s │ %-12.2f │ %-12.2f │ %-15s │";

            $this->info(sprintf(
                $format,
                ucfirst($result['algorithm']),
                $result['conflict_rate'],
                $result['avg_processing_time'],
                $evaluation
            ));
        }

        $this->info("└─────────────────┴──────────────┴──────────────┴─────────────────┘");

        // Chọn thuật toán tốt nhất
        $this->selectBestAlgorithm($results);
    }

    private function selectBestAlgorithm($results)
    {
        $this->info("\n🏆 KẾT LUẬN:");

        $bestAlgorithm = null;
        $bestScore = -1;

        foreach ($results as $result) {
            $conflictPass = $result['conflict_rate'] < 2;
            $timePass = $result['avg_processing_time'] < 1500;

            if ($conflictPass && $timePass) {
                // Tính điểm: ưu tiên xung đột thấp và thời gian nhanh
                $score = (100 - $result['conflict_rate']) + (1500 - $result['avg_processing_time']) / 10;

                if ($score > $bestScore) {
                    $bestScore = $score;
                    $bestAlgorithm = $result;
                }
            }
        }

        if ($bestAlgorithm) {
            $this->info("🎯 THUẬT TOÁN ĐƯỢC CHỌN: " . strtoupper($bestAlgorithm['algorithm']));
            $this->info("   ✅ Xung đột: {$bestAlgorithm['conflict_rate']}% < 2%");
            $this->info("   ✅ Thời gian: {$bestAlgorithm['avg_processing_time']}ms < 1500ms");
        } else {
            $this->warn("⚠️ KHÔNG CÓ THUẬT TOÁN NÀO ĐẠT YÊU CẦU!");
            $this->warn("   Cần tối ưu thêm hoặc tăng số lượng slot");
        }
    }
}


// # So sánh 2 thuật toán
// php artisan test:compare-algorithms --requests=300 --peak-ratio=60

// # Test riêng từng thuật toán
// php artisan test:peak-hour-reservations --algorithm=priority_queue --requests=300 --peak-ratio=60
// php artisan test:peak-hour-reservations --algorithm=hungarian --requests=300 --peak-ratio=60
