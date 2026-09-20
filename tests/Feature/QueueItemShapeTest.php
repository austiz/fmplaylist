<?php

namespace Tests\Feature;

use App\Models\MediaAsset;
use App\Models\QueueItem;
use App\Models\Station;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * A queue row used to be mapped by hand in four controllers, and the four had
 * drifted: the admin lists carried `status` and `created_at` where the listener
 * pages carried `position`, and only one of them carried `duration_seconds`.
 * These pin the one shape `QueueItemResource` now serializes everywhere.
 */
class QueueItemShapeTest extends TestCase
{
    use RefreshDatabase;

    private const KEYS = ['id', 'position', 'status', 'requested_by_name', 'created_at', 'played_at', 'song'];

    private const SONG_KEYS = ['title', 'artist', 'duration_seconds'];

    public function test_every_list_reports_the_same_keys(): void
    {
        foreach ($this->lists() as $label => $rows) {
            $this->assertNotEmpty($rows, "{$label} had no rows to check");
            $this->assertSame(self::KEYS, array_keys($rows[0]), "{$label} key set");
            $this->assertSame(self::SONG_KEYS, array_keys($rows[0]['song']), "{$label} song key set");
        }
    }

    /**
     * The resource still carries a `(deleted)` fallback, as all four mappers did.
     * It is unreachable through deletion -- this is why.
     */
    public function test_deleting_a_song_takes_its_queue_rows_with_it(): void
    {
        $station = Station::find(Station::defaultId());
        $song = MediaAsset::factory()->create();
        $item = QueueItem::factory()->create([
            'station_id' => $station->id, 'media_asset_id' => $song->id, 'status' => 'pending',
        ]);

        $song->delete();

        $this->assertDatabaseMissing('queue_items', ['id' => $item->id]);
    }

    /**
     * One pending item and one played item, as each surface lists them.
     *
     * @return array<string, array<int, array<string, mixed>>>
     */
    private function lists(): array
    {
        $station = Station::find(Station::defaultId());
        $song = MediaAsset::factory()->create(['duration_seconds' => 184]);

        QueueItem::factory()->create([
            'station_id' => $station->id, 'media_asset_id' => $song->id, 'status' => 'pending', 'position' => 1,
        ]);
        QueueItem::factory()->create([
            'station_id' => $station->id, 'media_asset_id' => $song->id, 'status' => 'played', 'played_at' => now(),
        ]);

        $admin = User::factory()->create();
        $home = $this->get('/')->viewData('page')['props'];
        $queue = $this->get('/queue')->viewData('page')['props'];
        $dashboard = $this->actingAs($admin)->get('/admin')->viewData('page')['props'];
        $history = $this->actingAs($admin)->get('/admin/history')->viewData('page')['props'];

        return [
            'home queue' => $home['queue'],
            'listener queue' => $queue['queue'],
            'listener history' => $queue['history'],
            'dashboard recent' => $dashboard['recentRequests'],
            'admin history' => $history['items']['data'],
        ];
    }
}
