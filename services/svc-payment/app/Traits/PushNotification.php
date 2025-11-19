<?php

namespace App\Traits;

use Exception;
use Google\Auth\ApplicationDefaultCredentials;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

trait PushNotification
{
    public function sendNotification(
        $token,
        $title,
        $body,
        array $data = [],
    ) {
        $fcmurl = config('services.firebase.fcm_url');


        $notification = [
            'notification' => [
                'title' => $title,
                'body' => $body
            ],
            'token' => $token
        ];

        if (!empty($data)) {
            $notification['data'] = collect($data)
                ->map(function ($value) {
                    if (is_bool($value)) {
                        return $value ? 'true' : 'false';
                    }

                    if (is_scalar($value)) {
                        return (string)$value;
                    }

                    return json_encode($value);
                })
                ->toArray();
        }

        try {
            $accessToken = $this->getAccessToken();
            $res = Http::withHeaders([
                'Authorization' => "Bearer $accessToken",
                'Content-Type' => 'application/json'
            ])->post($fcmurl, ['message' => $notification]);
            return $res->json();
        } catch (Exception $e) {
            Log::error("Error sending push notification to $token: {$e->getMessage()}");
            return false;
        }
    }

    private function getAccessToken()
    {
        $keyPath = config('services.firebase.key_path');
        putenv('GOOGLE_APPLICATION_CREDENTIALS=' . $keyPath);

        // define the scopes for your API call
        $scopes = ['https://www.googleapis.com/auth/firebase.messaging'];

        $credentials = ApplicationDefaultCredentials::getCredentials($scopes);

        $token = $credentials->fetchAuthToken();
        return $token['access_token'] ?? null;
    }
}
