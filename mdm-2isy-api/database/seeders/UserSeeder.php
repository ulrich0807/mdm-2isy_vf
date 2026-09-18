<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Validator;

class UserSeeder extends Seeder
{
    public function run(): void
    {
        $admin = Validator::make(config('mdm.bootstrap_admin'), [
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'email', 'max:255'],
            'password' => ['required', 'string', 'min:12'],
        ])->validate();

        User::updateOrCreate(
            ['email' => $admin['email']],
            [
                'name' => $admin['name'],
                'password' => $admin['password'],
                'role' => 'super_admin',
            ],
        );
    }
}