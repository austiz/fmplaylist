<?php

namespace Tests\Feature\Admin;

use App\Models\MediaAsset;
use App\Models\QueueItem;
use App\Models\Station;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
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

    private function makeQueueItem(int $stationId, string $status = 'pending', int $position = 1): QueueItem
    {
        return QueueItem::create([
            'station_id' => $stationId,
            'media_asset_id' => MediaAsset::factory()->create()->id,
            'requested_by_name' => null,
            'position' => $position,
            'status' => $status,
        ]);
    }

    /**
     * Three pending rows at positions 1, 2, 3 on the default station.
     *
     * @return array<int, QueueItem>
     */
    private function makeQueue(): array
    {
        return array_map(
            fn (int $position) => $this->makeQueueItem(Station::defaultId(), position: $position),
            [1, 2, 3],
        );
    }

    /**
     * @return array<int, int> item ids in queue order
     */
    private function currentOrder(): array
    {
        return QueueItem::withoutGlobalScopes()
            ->where('station_id', Station::defaultId())
            ->where('status', 'pending')
            ->orderBy('position')
            ->pluck('id')
            ->all();
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

    public function test_index_lists_the_live_queue_with_whatever_is_on_air_first(): void
    {
        [$first] = $this->makeQueue();
        $playing = $this->makeQueueItem(Station::defaultId(), status: 'playing', position: 9);
        $played = $this->makeQueueItem(Station::defaultId(), status: 'played', position: 0);
        $elsewhere = $this->makeQueueItem(
            Station::create(['name' => 'Station B', 'slug' => 'station-b'])->id,
        );

        $this->actingAs($this->admin)
            ->get('/admin/queue')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('admin/queue')
                ->has('items', 4)
                // Position 9, but on air, so it leads the list anyway.
                ->where('items.0.id', $playing->id)
                ->where('items.1.id', $first->id));

        // Neither history nor another station's queue belongs on this page.
        $this->assertNotContains($played->id, $this->currentOrder());
        $this->assertNotContains($elsewhere->id, $this->currentOrder());
    }

    public function test_reorder_renumbers_the_queue_to_the_posted_order(): void
    {
        [$a, $b, $c] = $this->makeQueue();

        $this->actingAs($this->admin)
            ->post('/admin/queue/reorder', ['ids' => [$c->id, $a->id, $b->id]])
            ->assertRedirect();

        $this->assertSame([$c->id, $a->id, $b->id], $this->currentOrder());
        $this->assertSame([1, 2, 3], QueueItem::withoutGlobalScopes()
            ->whereIn('id', [$c->id, $a->id, $b->id])
            ->orderBy('position')
            ->pluck('position')
            ->all());
    }

    public function test_reorder_keeps_rows_the_page_never_saw_behind_the_posted_order(): void
    {
        [$a, $b] = $this->makeQueue();

        // Arrived between the page rendering and the operator hitting save --
        // an autofill or a listener request. It must not collide with a position.
        $late = $this->makeQueueItem(Station::defaultId(), position: 4);

        $this->actingAs($this->admin)
            ->post('/admin/queue/reorder', ['ids' => [$b->id, $a->id]])
            ->assertRedirect();

        $order = $this->currentOrder();

        $this->assertSame([$b->id, $a->id], array_slice($order, 0, 2));
        $this->assertContains($late->id, $order);
        $this->assertCount(4, array_unique(
            QueueItem::withoutGlobalScopes()
                ->where('status', 'pending')
                ->pluck('position')
                ->all(),
        ));
    }

    public function test_reorder_ignores_an_id_from_another_station(): void
    {
        [$a, $b, $c] = $this->makeQueue();
        $stationB = Station::create(['name' => 'Station B', 'slug' => 'station-b']);
        $theirs = $this->makeQueueItem($stationB->id);

        $this->actingAs($this->admin)
            ->post('/admin/queue/reorder', ['ids' => [$theirs->id, $c->id, $b->id, $a->id]])
            ->assertRedirect();

        // The forged id drops out; the rest of the reorder still lands.
        $this->assertSame([$c->id, $b->id, $a->id], $this->currentOrder());
        $this->assertSame(1, QueueItem::withoutGlobalScopes()->find($theirs->id)->position);
    }

    public function test_play_next_moves_one_item_to_the_head_without_reshuffling_the_rest(): void
    {
        [$a, $b, $c] = $this->makeQueue();

        $this->actingAs($this->admin)
            ->post("/admin/queue/{$c->id}/play-next")
            ->assertRedirect();

        $this->assertSame([$c->id, $a->id, $b->id], $this->currentOrder());
    }

    public function test_play_next_refuses_an_item_that_is_already_on_air(): void
    {
        $playing = $this->makeQueueItem(Station::defaultId(), status: 'playing', position: 1);

        $this->actingAs($this->admin)
            ->post("/admin/queue/{$playing->id}/play-next")
            ->assertRedirect()
            ->assertSessionHas('error');
    }

    public function test_bulk_destroy_removes_only_the_selected_pending_items(): void
    {
        [$a, $b, $c] = $this->makeQueue();
        $stationB = Station::create(['name' => 'Station B', 'slug' => 'station-b']);
        $theirs = $this->makeQueueItem($stationB->id);

        $this->actingAs($this->admin)
            ->post('/admin/queue/bulk-destroy', ['ids' => [$a->id, $c->id, $theirs->id]])
            ->assertRedirect()
            ->assertSessionHas('success');

        $this->assertSame([$b->id], $this->currentOrder());
        $this->assertDatabaseHas('queue_items', ['id' => $theirs->id]);
    }
}
