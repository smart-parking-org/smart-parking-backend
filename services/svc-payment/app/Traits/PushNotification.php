<?php

namespace App\Traits;

use Exception;
use Google\Auth\ApplicationDefaultCredentials;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

trait PushNotification
{
    public function sendNotification($token, $title, $body, $data = [])
    {
        $fcmurl = config('services.firebase.fcm_url');
        $stringData = [];
        foreach ($data as $key => $value) {
            $stringData[$key] = is_string($value) ? $value : json_encode($value);
        }

        $notification = [
            'notification' => [
                'title' => $title,
                'body' => $body
            ],
            'data' => $stringData,
            'token' => $token
        ];

        try {
            $accessToken = $this->getAccessToken();
            $res = Http::withHeaders([
                'Authorization' => "Bearer $accessToken",
                'Content-Type' => 'application/json'
            ])->post($fcmurl, ['message' => $notification]);
            Log::info(">>> check res: $res");
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
