<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    public function run(): void
    {
        User::factory()->create([
            'name' => 'Admin',
            'email' => 'admin@fmplaylist.com',
        ]);

        $defaults = [
            'station_id_interval' => '3',
            'songs_played_since_last_station_id' => '0',
            'callsign' => '96.9 FM',
            'fallback_song' => 'FTPA.wav',
        ];

        foreach ($defaults as $key => $value) {
            DB::table('settings')->insertOrIgnore([
                'key' => $key,
                'value' => $value,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }
}
