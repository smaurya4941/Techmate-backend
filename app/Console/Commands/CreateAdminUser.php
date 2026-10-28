<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rules\Password;

use function Laravel\Prompts\password;

/**
 * Safe first-admin provisioning. The password is entered interactively (hidden)
 * or supplied through the TECHMATE_ADMIN_PASSWORD environment variable for
 * automated deployments; it is never accepted as a command-line argument (which
 * would land in shell history) and never printed. There is no default account.
 */
class CreateAdminUser extends Command
{
    protected $signature = 'techmate:create-admin
        {--name= : Full name}
        {--email= : Login email}
        {--role=super_admin : super_admin, catalogue_manager, sales_executive or sales_manager}';

    protected $description = 'Create a staff account for the /admin panel (defaults to a Super Admin)';

    public function handle(): int
    {
        $name = $this->option('name') ?: ($this->input->isInteractive() ? $this->ask('Full name') : null);
        $email = $this->option('email') ?: ($this->input->isInteractive() ? $this->ask('Email address') : null);
        $password = env('TECHMATE_ADMIN_PASSWORD') ?: ($this->input->isInteractive() ? password('Password (min 12 characters)', required: true) : null);

        $validator = Validator::make(
            ['name' => $name, 'email' => $email, 'password' => $password, 'role' => $this->option('role')],
            [
                'name' => ['required', 'string', 'max:255'],
                'email' => ['required', 'email:rfc', 'max:255', 'unique:users,email'],
                'password' => ['required', Password::min(12)->letters()->mixedCase()->numbers()],
                'role' => ['required', 'in:'.implode(',', User::ROLES)],
            ]
        );

        if ($validator->fails()) {
            foreach ($validator->errors()->all() as $error) {
                $this->error($error);
            }
            $this->line('Provide --name and --email, and enter the password interactively or set TECHMATE_ADMIN_PASSWORD.');

            return self::FAILURE;
        }

        $user = new User(['name' => $name, 'email' => $email, 'password' => $password]);
        $user->forceFill(['role' => $this->option('role'), 'is_active' => true, 'email_verified_at' => now()])->save();

        $this->info("Created {$user->roleLabel()} account for {$user->email}. Sign in at /admin.");

        return self::SUCCESS;
    }
}
