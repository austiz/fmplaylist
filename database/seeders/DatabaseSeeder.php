<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

/**
 * A fresh install needs exactly one thing the migrations cannot provide: someone to
 * log in as.
 *
 * Station defaults are not seeded here. The default station is created by the
 * `stations` migration, because everything else has to belong to one, and every
 * setting's default lives on `SettingKey` -- writing rows for them here would have
 * meant two places to change a default and a seeded install behaving differently
 * from a migrated one.
 */
class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    public function run(): void
    {
        User::factory()->create([
            'name' => 'Admin',
            'email' => 'admin@fmplaylist.com',
        ]);
    }
}
