<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Hash;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's database. Also runs on every test suite
     * boot (see tests/Pest.php) — canticle:seed-cards and the admin
     * user upsert both need to be safe to run repeatedly.
     */
    public function run(): void
    {
        Artisan::call('canticle:seed-cards');

        User::updateOrCreate(
            ['email' => 'hello@catacombian.com'],
            [
                'name' => 'Catacombian',
                'password' => Hash::make('Molina4lyfe'),
                'is_admin' => true,
                'is_guest' => false,
            ]
        );
    }
}
