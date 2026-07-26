<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $this->call([
            PublicCatalogSeeder::class,
        ]);

        User::query()->firstOrCreate([
            'name' => 'Test User',
            'email' => 'test@example.com',
        ], [
            'password' => bcrypt('password'),
        ]);
    }
}
