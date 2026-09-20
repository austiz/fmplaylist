<?php

namespace App\Http\Controllers\Admin;

use App\Concerns\PasswordValidationRules;
use App\Concerns\ProfileValidationRules;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Operator accounts.
 *
 * There is no public sign-up and no role column: every account here can run a
 * transmitter, so every account here is created by hand by someone who already
 * has one.
 *
 * Passwords are set once rather than confirmed. An admin typing a colleague's
 * password twice catches nothing -- they are not the one who has to remember
 * it -- so a typo is recovered with the reset action on the same page instead.
 */
class DjController extends Controller
{
    use PasswordValidationRules, ProfileValidationRules;

    public function index(): Response
    {
        return Inertia::render('admin/djs', [
            'passwordRules' => Password::defaults()->toPasswordRulesString(),
            'djs' => User::query()
                ->orderBy('name')
                ->get(['id', 'name', 'email', 'created_at'])
                ->map(fn (User $dj) => [
                    'id' => $dj->id,
                    'name' => $dj->name,
                    'email' => $dj->email,
                    'created_at' => $dj->created_at?->toFormattedDateString(),
                ])
                ->all(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            ...$this->profileRules(),
            'password' => ['required', 'string', Password::default()],
        ]);

        $dj = User::create($data);

        return back()->with('success', "{$dj->name} can now log in.");
    }

    public function updatePassword(Request $request, User $user): RedirectResponse
    {
        $data = $request->validate([
            'password' => ['required', 'string', Password::default()],
        ]);

        // The remember token goes with it: a password reset that leaves a
        // "remember me" cookie working on someone else's laptop has not reset
        // anything.
        $user->forceFill([
            'password' => $data['password'],
            'remember_token' => Str::random(60),
        ])->save();

        return back()->with('success', "New password set for {$user->name}.");
    }

    public function destroy(Request $request, User $user): RedirectResponse
    {
        // Also what stops the last account being deleted: with nobody else
        // left, the only account on the page is the one you are signed in as.
        if ($user->is($request->user())) {
            return back()->with('error', 'You cannot remove your own account. Ask another DJ to do it.');
        }

        $name = $user->name;
        $user->delete();

        return back()->with('success', "{$name} removed.");
    }
}
