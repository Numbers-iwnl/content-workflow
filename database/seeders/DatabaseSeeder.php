<?php

namespace Database\Seeders;

use App\Enums\Role;
use App\Models\User;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /** Equipe inicial. Os links de acesso saem com: php artisan users:link "Nome" */
    public function run(): void
    {
        User::firstOrCreate(['name' => 'Ana'], ['role' => Role::Admin]);
        User::firstOrCreate(['name' => 'Eduardo'], ['role' => Role::Admin]);
        User::firstOrCreate(['name' => 'Bruna'], ['role' => Role::Aprovadora, 'review_order' => 1]);
        User::firstOrCreate(['name' => 'Carla'], ['role' => Role::Aprovadora, 'review_order' => 2]);
    }
}
