<?php

namespace Database\Seeders;

use App\Enums\AccountStatus;
use App\Models\User;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class UserSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        User::create([
            'name' => 'Quản trị hệ thống',
            'email' => 'admin@gmail.com',
            'phone' => '0919999999',
            'password' => '12345678',
            'role' => 'admin',
            'status' => AccountStatus::APPROVED
        ]);
    }
}
