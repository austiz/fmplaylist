<?php

namespace App\Console\Commands;

use App\Concerns\ProfileValidationRules;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rules\Password;

use function Laravel\Prompts\password;
use function Laravel\Prompts\text;

/**
 * The break-glass path to an operator account.
 *
 * Day to day DJs are added under System -> DJs in the admin, and the very first
 * account comes from /setup. This exists for the case neither covers: nobody
 * can log in and the /setup door has already been used.
 */
class CreateDj extends Command
{
    use ProfileValidationRules;

    protected $signature = 'dj:create {name? : The DJ\'s name} {email? : The email they log in with} {--password= : Skip the prompt (visible in shell history)}';

    protected $description = 'Create an operator account by hand';

    public function handle(): int
    {
        $data = [
            'name' => $this->argument('name') ?: text('Name', required: true),
            'email' => $this->argument('email') ?: text('Email', required: true),
            'password' => $this->option('password') ?: password('Password', required: true),
        ];

        $validator = Validator::make($data, [
            ...$this->profileRules(),
            // Not `confirmed`: there is no second field to confirm against, and
            // a prompt the operator can see is its own check.
            'password' => ['required', 'string', Password::default()],
        ]);

        if ($validator->fails()) {
            foreach ($validator->errors()->all() as $error) {
                $this->error($error);
            }

            return self::FAILURE;
        }

        $dj = User::create($validator->validated());

        $this->info("Created {$dj->name} <{$dj->email}>.");
        $this->line('They can log in at '.url('/login').'.');

        return self::SUCCESS;
    }
}
