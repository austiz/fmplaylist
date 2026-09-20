<?php

namespace App\Http\Controllers\Auth;

use App\Concerns\PasswordValidationRules;
use App\Concerns\ProfileValidationRules;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rules\Password;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The first operator account, and only the first.
 *
 * EnsureSetupIncomplete takes this whole surface away the moment one user
 * exists, so the ordinary path for every DJ after this is DjController or the
 * `dj:create` command.
 */
class SetupController extends Controller
{
    use PasswordValidationRules, ProfileValidationRules;

    public function create(): Response
    {
        return Inertia::render('auth/setup', [
            'passwordRules' => Password::defaults()->toPasswordRulesString(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            ...$this->profileRules(),
            'password' => $this->passwordRules(),
        ]);

        $user = User::create($data);

        Auth::login($user);
        $request->session()->regenerate();

        return redirect()->route('admin.dashboard')
            ->with('success', "Welcome, {$user->name}. Add the rest of your DJs under System → DJs.");
    }
}
