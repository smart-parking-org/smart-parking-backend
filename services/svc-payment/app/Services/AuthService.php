<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class AuthService
{
    private string $baseUrl;
    private int $timeout;

    public function __construct()
    {
        $this->baseUrl = env('SVC_AUTH_URL', 'http://auth-nginx/api');
        $this->timeout = 10; // seconds
    }

    /**
     * Kiểm tra user có tồn tại và active không
     */
    public function checkUserExists(int $userId): array
    {
        try {
            $response = Http::timeout($this->timeout)
                ->get("{$this->baseUrl}/users/{$userId}");

            if ($response->successful()) {
                $userData = $response->json('data');
                return [
                    'exists' => true,
                    'is_active' => $userData['is_active'] ?? false,
                    'data' => $userData
                ];
            }

            if ($response->status() === 404) {
                return [
                    'exists' => false,
                    'is_active' => false,
                    'data' => null
                ];
            }

            return [
                'exists' => false,
                'is_active' => false,
                'data' => null
            ];

        } catch (\Exception $e) {
            Log::error('Failed to check user existence', [
                'user_id' => $userId,
                'error' => $e->getMessage()
            ]);

            return [
                'exists' => false,
                'is_active' => false,
                'data' => null
            ];
        }
    }

    /**
     * Kiểm tra vehicle có tồn tại và active không
     */
    public function checkVehicleExists(int $vehicleId): array
    {
        try {
            $response = Http::timeout($this->timeout)
                ->get("{$this->baseUrl}/vehicles/{$vehicleId}");

            if ($response->successful()) {
                $vehicleData = $response->json('data');
                return [
                    'exists' => true,
                    'is_active' => $vehicleData['is_active'] ?? false,
                    'data' => $vehicleData
                ];
            }

            if ($response->status() === 404) {
                return [
                    'exists' => false,
                    'is_active' => false,
                    'data' => null
                ];
            }

            return [
                'exists' => false,
                'is_active' => false,
                'data' => null
            ];

        } catch (\Exception $e) {
            Log::error('Failed to check vehicle existence', [
                'vehicle_id' => $vehicleId,
                'error' => $e->getMessage()
            ]);

            return [
                'exists' => false,
                'is_active' => false,
                'data' => null
            ];
        }
    }

    /**
     * Lấy thông tin user snapshot
     */
    public function getUserSnapshot(int $userId): ?array
    {
        $result = $this->checkUserExists($userId);

        if (!$result['exists'] || !$result['is_active']) {
            return null;
        }

        $userData = $result['data'];
        return [
            'id' => $userData['id'],
            'name' => $userData['name'],
            'email' => $userData['email'],
            'phone' => $userData['phone']
        ];
    }

    /**
     * Lấy thông tin vehicle snapshot
     */
    public function getVehicleSnapshot(int $vehicleId): ?array
    {
        $result = $this->checkVehicleExists($vehicleId);

        if (!$result['exists'] || !$result['is_active']) {
            return null;
        }

        $vehicleData = $result['data'];
        return [
            'id' => $vehicleData['id'],
            'license_plate' => $vehicleData['license_plate'],
            'vehicle_type' => $vehicleData['vehicle_type']
        ];
    }
}
