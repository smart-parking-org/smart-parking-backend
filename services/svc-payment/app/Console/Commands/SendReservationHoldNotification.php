<?php

namespace App\Console\Commands;

use App\Models\Notification;
use App\Models\Reservation;
use App\Services\AuthService;
use App\Traits\PushNotification;
use Illuminate\Console\Command;

class SendReservationHoldNotification extends Command
{
    use PushNotification;
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'app:send-reservation-hold-notification';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Send push 5 minutes before reservation hold expires';

    /**
     * Execute the console command.
     */
    public function handle(AuthService $authService)
    {
        $from = now()->addMinutes(5);
        $to = now()->addMinutes(6);

        $holds = Reservation::whereBetween('expires_at', [$from, $to])->get();
        foreach ($holds as $res) {
            $token = $authService->getTokenByUserId($res->user_id);
            if (!$token)
                continue;

            $this->sendNotification(
                $token,
                'Giữ chỗ sắp hết',
                'Còn 5 phút nữa lượt giữ chỗ của bạn sẽ hết hạn.',
            );

            Notification::create([
                'user_id' => $res->user_id,
                'reservation_id' => $res->id ?? null,
                'type' => 'reservation_hold_expiring',
                'title' => 'Giữ chỗ sắp hết',
                'body' => 'Còn 5 phút nữa lượt giữ chỗ của bạn sẽ hết hạn.',
                'is_read' => false,
            ]);
        }
        return self::SUCCESS;
    }
}
