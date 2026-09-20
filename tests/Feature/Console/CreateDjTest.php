<?php

namespace Tests\Feature\Console;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * The break-glass path: nobody can log in and /setup has already been used.
 */
class CreateDjTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_creates_an_operator_from_arguments()
    {
        $this->artisan('dj:create', [
            'name' => 'Graveyard',
            'email' => 'graveyard@example.com',
            '--password' => 'correct-horse-battery-staple',
        ])->assertSuccessful();

        $dj = User::where('email', 'graveyard@example.com')->firstOrFail();

        $this->assertSame('Graveyard', $dj->name);
        $this->assertTrue(Hash::check('correct-horse-battery-staple', $dj->password));
    }

    public function test_it_refuses_an_email_that_is_already_taken()
    {
        $existing = User::factory()->create();

        $this->artisan('dj:create', [
            'name' => 'Impostor',
            'email' => $existing->email,
            '--password' => 'correct-horse-battery-staple',
        ])->assertFailed();

        $this->assertSame(1, User::count());
    }

    public function test_it_refuses_a_password_that_fails_the_rules()
    {
        $this->artisan('dj:create', [
            'name' => 'Too Easy',
            'email' => 'easy@example.com',
            '--password' => 'abc',
        ])->assertFailed();

        $this->assertDatabaseMissing('users', ['email' => 'easy@example.com']);
    }
}
