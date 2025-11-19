<?php

namespace App\Http\Controllers;

use App\Enums\UserRole;
use Illuminate\Http\Request;

class StaffController extends Controller
{
    public function navigation(Request $request)
    {
        $role = $request->input('role');
        $user = $request->user('api');

        if (!$role && $user) {
            $role = $user->role ?? null;
        }

        if (!$role) {
            return response()->json([
                'message' => 'Role is required',
            ], 422);
        }

        if (!$this->isStaff($role)) {
            return response()->json([
                'role' => $role,
                'pages' => [],
                'message' => 'Navigation config is only available for staff role',
            ], 200);
        }

        return response()->json([
            'role' => UserRole::STAFF->value,
            'pages' => [
                [
                    'key' => 'notifications',
                    'title' => 'Gửi thông báo',
                    'description' => 'Nhập biển số xe để gửi push notification',
                    'endpoint' => '/api/notifications/send',
                    'inputs' => [
                        [
                            'name' => 'license_plate',
                            'label' => 'Biển số xe',
                            'type' => 'text',
                            'placeholder' => 'VD: 51H-123.45',
                            'required' => true,
                        ],
                        [
                            'name' => 'title',
                            'label' => 'Tiêu đề',
                            'type' => 'text',
                            'required' => true,
                        ],
                        [
                            'name' => 'body',
                            'label' => 'Nội dung',
                            'type' => 'textarea',
                            'required' => true,
                        ],
                    ],
                ],
                [
                    'key' => 'account',
                    'title' => 'Tài khoản',
                    'description' => 'Quản lý thông tin cá nhân và đăng xuất',
                ],
            ],
        ]);
    }

    private function isStaff($role): bool
    {
        if ($role instanceof UserRole) {
            return $role === UserRole::STAFF;
        }

        return $role === UserRole::STAFF->value || $role === 'staff';
    }
}

