<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Controllers flash feedback with ->with('success', ...), the ordinary Laravel way.
 * Inertia does not forward that on its own -- its `flash` prop carries only what
 * Inertia::flash() wrote -- so for a long time all 23 admin success messages were
 * dropped between the redirect and the page, and the layout's flash banners could
 * never render. HandleInertiaRequests::resolveToast() bridges the two; these pin it
 * so the wiring cannot go quiet again.
 */
class FlashToastTest extends TestCase
{
    use RefreshDatabase;

    public function test_session_success_is_shared_as_a_toast_prop(): void
    {
        $admin = User::factory()->create();

        $this->actingAs($admin)
            ->withSession(['success' => 'RDS settings saved.'])
            ->get('/admin')
            ->assertInertia(fn ($page) => $page
                ->where('toast.type', 'success')
                ->where('toast.message', 'RDS settings saved.')
            );
    }

    public function test_session_error_is_shared_as_an_error_toast(): void
    {
        $admin = User::factory()->create();

        $this->actingAs($admin)
            ->withSession(['error' => 'Pi is offline.'])
            ->get('/admin')
            ->assertInertia(fn ($page) => $page
                ->where('toast.type', 'error')
                ->where('toast.message', 'Pi is offline.')
            );
    }

    public function test_toast_is_null_without_flashed_feedback(): void
    {
        $admin = User::factory()->create();

        $this->actingAs($admin)
            ->get('/admin')
            ->assertInertia(fn ($page) => $page->where('toast', null));
    }

    /** The real path end to end: a redirect that flashes, followed by the page load. */
    public function test_a_redirecting_admin_action_delivers_its_message(): void
    {
        $admin = User::factory()->create();

        $this->actingAs($admin)
            ->post('/admin/broadcast/rds', [
                'rds_rt_mode' => 'custom',
                'rds_rt' => 'Testing the flash path',
                'rds_ps' => 'TESTFM',
            ])
            ->assertRedirect()
            ->assertSessionHas('success');

        $this->actingAs($admin)
            ->get('/admin/broadcast')
            ->assertInertia(fn ($page) => $page->where('toast.type', 'success'));
    }
}
