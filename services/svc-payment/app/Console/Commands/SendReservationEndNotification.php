<?php

namespace App\Console\Commands;

use App\Models\Reservation;
use App\Services\AuthService;
use App\Traits\PushNotification;
use Illuminate\Console\Command;

class SendReservationEndNotification extends Command
{
    use PushNotification;
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'app:send-reservation-end-notification';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Send push 10 minutes before reservation finish';

    /**
     * Execute the console command.
     */
    public function handle(AuthService $authService)
    {
        $from = now()->addMinutes(10);
        $to = now()->addMinutes(11);

        $finishes = Reservation::whereBetween('end_time', [$from, $to])->get();
        foreach ($finishes as $res) {
            $token = $authService->getTokenByUserId($res->user_id);
            if (!$token)
                continue;

            $this->sendNotification(
                $token,
                'Phiên giữ xe sắp kết thúc',
                'Còn 10 phút nữa phiên giữ xe của bạn sẽ kết thúc.',
                ['type' => 'reservation_end', 'reservation_id' => (string) $res->id]
            );
        }
        return self::SUCCESS;
    }
}
