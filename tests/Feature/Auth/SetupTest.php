<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The one door that makes an operator account without an operator, and the
 * proof that it shuts behind itself.
 */
class SetupTest extends TestCase
{
    use RefreshDatabase;

    public function test_public_registration_is_gone()
    {
        $this->get('/register')->assertNotFound();

        $this->post('/register', [
            'name' => 'Walk In',
            'email' => 'walkin@example.com',
            'password' => 'correct-horse-battery-staple',
            'password_confirmation' => 'correct-horse-battery-staple',
        ])->assertNotFound();

        $this->assertDatabaseMissing('users', ['email' => 'walkin@example.com']);
    }

    public function test_the_setup_page_is_reachable_on_a_fresh_install()
    {
        $this->get('/setup')
            ->assertOk()
            ->assertInertia(fn ($page) => $page->component('auth/setup'));
    }

    public function test_the_login_page_offers_setup_only_while_there_are_no_users()
    {
        $this->get('/login')
            ->assertInertia(fn ($page) => $page->where('canSetUp', true));

        User::factory()->create();

        $this->get('/login')
            ->assertInertia(fn ($page) => $page->where('canSetUp', false));
    }

    public function test_it_creates_the_first_operator_and_logs_them_in()
    {
        $this->post('/setup', [
            'name' => 'First DJ',
            'email' => 'first@example.com',
            'password' => 'correct-horse-battery-staple',
            'password_confirmation' => 'correct-horse-battery-staple',
        ])->assertRedirect();

        $this->assertDatabaseHas('users', ['email' => 'first@example.com']);
        $this->assertAuthenticated();
    }

    public function test_the_door_shuts_once_an_operator_exists()
    {
        User::factory()->create();

        $this->get('/setup')->assertNotFound();

        $this->post('/setup', [
            'name' => 'Second Bite',
            'email' => 'second@example.com',
            'password' => 'correct-horse-battery-staple',
            'password_confirmation' => 'correct-horse-battery-staple',
        ])->assertNotFound();

        $this->assertDatabaseMissing('users', ['email' => 'second@example.com']);
    }
}
