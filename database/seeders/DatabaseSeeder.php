<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

final class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        /**
         * Единственный пользователь тестового задания.
         *
         * В README эти credentials потом явно укажем.
         */
        User::query()->updateOrCreate(
            [
                'email' => 'demo@example.com',
            ],
            [
                'name' => 'Demo User',

                'password' => Hash::make(
                    'password'
                ),
            ],
        );
    }
}
