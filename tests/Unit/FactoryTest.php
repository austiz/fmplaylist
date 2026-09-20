<?php

namespace Tests\Unit;

use App\Enums\MediaType;
use App\Models\ChatMessage;
use App\Models\DeviceDownload;
use App\Models\MediaAsset;
use App\Models\NowPlaying;
use App\Models\PiCommand;
use App\Models\PiToken;
use App\Models\QueueItem;
use App\Models\Station;
use App\Models\User;
use App\Models\WifiNetwork;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Every model that tests build rows for must have a working factory. Without
 * this the first failure shows up as an unrelated test blowing up on a missing
 * column default, a long way from the cause.
 */
class FactoryTest extends TestCase
{
    use RefreshDatabase;

    /** @return array<string, array{class-string<Model>}> */
    public static function modelProvider(): array
    {
        return [
            'chat message' => [ChatMessage::class],
            'device download' => [DeviceDownload::class],
            'media asset' => [MediaAsset::class],
            'now playing' => [NowPlaying::class],
            'pi command' => [PiCommand::class],
            'pi token' => [PiToken::class],
            'queue item' => [QueueItem::class],
            'station' => [Station::class],
            'user' => [User::class],
            'wifi network' => [WifiNetwork::class],
        ];
    }

    /**
     * @param  class-string<Model>  $model
     */
    #[DataProvider('modelProvider')]
    public function test_factory_persists_a_row(string $model): void
    {
        /** @var Factory<Model> $factory */
        $factory = $model::factory();
        $row = $factory->createOne();

        $this->assertTrue($row->exists);
        $this->assertDatabaseHas($row->getTable(), [$row->getKeyName() => $row->getKey()]);
    }

    public function test_station_factory_produces_distinct_slugs(): void
    {
        $stations = Station::factory()->count(5)->create();

        $this->assertCount(5, $stations->pluck('slug')->unique());
    }

    public function test_pi_token_with_token_state_hashes_the_raw_value(): void
    {
        $token = PiToken::factory()->withToken('known-raw-token')->create();

        $this->assertSame(hash('sha256', 'known-raw-token'), $token->token_hash);
    }

    public function test_queue_item_states_cover_every_status(): void
    {
        $this->assertSame('pending', QueueItem::factory()->create()->status);
        $this->assertSame('playing', QueueItem::factory()->playing()->create()->status);
        $this->assertSame('played', QueueItem::factory()->played()->create()->status);
        $this->assertSame('skipped', QueueItem::factory()->skipped()->create()->status);
    }

    public function test_now_playing_non_song_types_carry_no_song(): void
    {
        $this->assertNull(NowPlaying::factory()->ofType('commercial')->create()->media_asset_id);
    }

    public function test_media_asset_states_set_their_type(): void
    {
        $this->assertSame(MediaType::Song, MediaAsset::factory()->song()->create()->type);
        $this->assertSame(MediaType::Commercial, MediaAsset::factory()->commercial()->create()->type);
        $this->assertSame(MediaType::SoundByte, MediaAsset::factory()->soundByte()->create()->type);
    }

    public function test_device_download_can_point_at_any_media_type(): void
    {
        $commercial = MediaAsset::factory()->commercial()->create();
        $download = DeviceDownload::factory()->forMedia($commercial, 'commercial')->create();

        $this->assertSame('commercial', $download->media_type);
        $this->assertSame($commercial->id, $download->media_id);
    }
}
