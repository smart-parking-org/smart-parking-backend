<?php

namespace Database\Seeders;

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
            'name' => 'System Admin',
            'email' => 'admin@gmail.com',
            'phone' => '0342333084',
            'password' => '12345678',
            'cccd_hash' => sha1('999999999999'),
            'cccd_masked' => '999******999',
            'role' => 'admin',
            'is_active' => true,
            'is_approved' => true,
        ]);
    }
}
