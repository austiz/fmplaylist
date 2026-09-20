<?php

namespace Tests\Feature\Admin;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * Operator accounts are created by hand, by an operator, and nowhere else.
 *
 * Public registration used to be on, and `User` has no role column -- any
 * account is a full transmitter operator -- so these tests are the only thing
 * standing between a stranger and the air.
 */
class DjControllerTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::factory()->create();
    }

    public function test_guests_cannot_see_the_dj_list()
    {
        $this->get('/admin/djs')->assertRedirect('/login');
    }

    public function test_guests_cannot_create_a_dj()
    {
        $this->post('/admin/djs', [
            'name' => 'Walk In',
            'email' => 'walkin@example.com',
            'password' => 'correct-horse-battery-staple',
        ])->assertRedirect('/login');

        $this->assertDatabaseMissing('users', ['email' => 'walkin@example.com']);
    }

    public function test_it_lists_every_operator()
    {
        $other = User::factory()->create(['name' => 'Second DJ']);

        $this->actingAs($this->admin)
            ->get('/admin/djs')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('admin/djs')
                ->has('djs', 2)
                ->where('djs.0.email', fn ($email) => in_array($email, [
                    $this->admin->email,
                    $other->email,
                ], true))
            );
    }

    public function test_an_operator_can_add_a_dj()
    {
        $this->actingAs($this->admin)
            ->post('/admin/djs', [
                'name' => 'Night Shift',
                'email' => 'night@example.com',
                'password' => 'correct-horse-battery-staple',
            ])
            ->assertRedirect()
            ->assertSessionHas('success');

        $dj = User::where('email', 'night@example.com')->firstOrFail();

        $this->assertSame('Night Shift', $dj->name);
        $this->assertTrue(Hash::check('correct-horse-battery-staple', $dj->password));
    }

    public function test_the_new_dj_can_log_in()
    {
        $this->actingAs($this->admin)->post('/admin/djs', [
            'name' => 'Night Shift',
            'email' => 'night@example.com',
            'password' => 'correct-horse-battery-staple',
        ]);

        $this->post('/login', [
            'email' => 'night@example.com',
            'password' => 'correct-horse-battery-staple',
        ]);

        $this->assertAuthenticated();
    }

    public function test_a_duplicate_email_is_rejected()
    {
        $this->actingAs($this->admin)
            ->post('/admin/djs', [
                'name' => 'Impostor',
                'email' => $this->admin->email,
                'password' => 'correct-horse-battery-staple',
            ])
            ->assertSessionHasErrors('email');

        $this->assertSame(1, User::count());
    }

    public function test_a_password_can_be_reset_and_the_remember_token_rotates()
    {
        $dj = User::factory()->create(['remember_token' => 'stale-token']);

        $this->actingAs($this->admin)
            ->post("/admin/djs/{$dj->id}/password", [
                'password' => 'a-brand-new-passphrase',
            ])
            ->assertRedirect()
            ->assertSessionHas('success');

        $dj->refresh();

        $this->assertTrue(Hash::check('a-brand-new-passphrase', $dj->password));
        $this->assertNotSame('stale-token', $dj->remember_token);
    }

    public function test_an_operator_can_remove_another_dj()
    {
        $dj = User::factory()->create();

        $this->actingAs($this->admin)
            ->delete("/admin/djs/{$dj->id}")
            ->assertRedirect()
            ->assertSessionHas('success');

        $this->assertDatabaseMissing('users', ['id' => $dj->id]);
    }

    /**
     * Which is also what keeps the last account alive: with nobody else left,
     * the only account on the page is the one you are signed in as.
     */
    public function test_an_operator_cannot_remove_themselves()
    {
        $this->actingAs($this->admin)
            ->delete("/admin/djs/{$this->admin->id}")
            ->assertRedirect()
            ->assertSessionHas('error');

        $this->assertDatabaseHas('users', ['id' => $this->admin->id]);
    }
}
