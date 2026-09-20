<?php

namespace Tests\Feature;

use App\Http\Middleware\HandleInertiaRequests;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class DashboardTest extends TestCase
{
    use RefreshDatabase;

    public function test_guests_are_redirected_to_the_login_page()
    {
        $response = $this->get(route('dashboard'));
        $response->assertRedirect(route('login'));
    }

    public function test_authenticated_users_can_visit_the_dashboard()
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        // /dashboard is a redirect alias for /admin
        $this->get(route('dashboard'))->assertRedirect('/admin');
        $this->get('/admin')->assertOk();
    }

    /**
     * The shared Inertia props -- the station list, the active station, the frequency --
     * used to be resolved eagerly, so every partial reload paid for four queries it then
     * threw away. They are closures now, and Inertia drops a filtered-out prop before
     * resolving it.
     */
    public function test_a_partial_reload_does_not_re_resolve_the_shared_station_props(): void
    {
        $this->actingAs(User::factory()->create());

        DB::enableQueryLog();

        $version = app(HandleInertiaRequests::class)->version(request());

        $this->get('/admin', [
            'X-Inertia' => 'true',
            'X-Inertia-Version' => (string) $version,
            'X-Inertia-Partial-Component' => 'admin/dashboard',
            'X-Inertia-Partial-Data' => 'stats',
        ])->assertOk();

        $queries = array_column(DB::getQueryLog(), 'query');

        // The station list behind the switcher, and the frequency string in the header.
        // EnsureActiveStation still resolves the active station itself -- that is the
        // route's own work, not a shared prop.
        $this->assertEmpty(array_filter($queries, fn (string $q) => str_contains($q, 'from "stations" order by "name"')));
        $this->assertEmpty(array_filter($queries, fn (string $q) => str_contains($q, 'from "settings"')));
    }
}
