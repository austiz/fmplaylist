<?php

namespace Tests\Feature\Admin;

use App\Models\QueueItem;
use App\Models\Song;
use App\Models\Station;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class QueueAdminControllerTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = User::factory()->create();
    }

    private function makeQueueItem(int $stationId, string $status = 'pending'): QueueItem
    {
        return QueueItem::create([
            'station_id' => $stationId,
            'song_id' => Song::factory()->create()->id,
            'requested_by_name' => null,
            'position' => 1,
            'status' => $status,
        ]);
    }

    public function test_admin_can_delete_a_pending_item_on_the_active_station(): void
    {
        $item = $this->makeQueueItem(Station::defaultId());

        $this->actingAs($this->admin)
            ->delete("/admin/queue/{$item->id}")
            ->assertRedirect();

        $this->assertDatabaseMissing('queue_items', ['id' => $item->id]);
    }

    public function test_admin_cannot_delete_a_queue_item_belonging_to_another_station(): void
    {
        $stationB = Station::create(['name' => 'Station B', 'slug' => 'station-b']);
        $item = $this->makeQueueItem($stationB->id);

        // Active station defaults to the seeded default station, not Station B.
        $this->actingAs($this->admin)
            ->delete("/admin/queue/{$item->id}")
            ->assertNotFound();

        $this->assertDatabaseHas('queue_items', ['id' => $item->id]);
    }

    public function test_only_pending_items_can_be_removed(): void
    {
        $item = $this->makeQueueItem(Station::defaultId(), status: 'playing');

        $this->actingAs($this->admin)
            ->delete("/admin/queue/{$item->id}")
            ->assertRedirect();

        $this->assertDatabaseHas('queue_items', ['id' => $item->id]);
    }
}
