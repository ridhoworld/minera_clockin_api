<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class UserSeeder extends Seeder
{
    public function run(): void
    {
        User::create([
            'name' => 'Administrator',
            'username' => 'admin',
            'password' => Hash::make('admin123'),
            'role' => 'admin',
        ]);

        User::create([
            'name' => 'Barge Crew',
            'username' => 'bargecrew',
            'password' => Hash::make('barge123'),
            'role' => 'barge_crew',
        ]);
        User::create([
            'name' => 'Santoso',
            'username' => 'santoso',
            'password' => Hash::make('santoso123'),
            'role' => 'barge_crew',
        ]);
    }
}
